<?php
class OpenAI_Auto_Labels extends Plugin {
    private $host;
    private $openai_api_key;
    private $label_language;
    private $openai_base_url;
    private $openai_model;
    private $max_labels;
    private $max_text_length;
    protected $pdo;
    
    // In‑memory queue and rate‐limiting tracking (static so they persist across method calls in a long‑running process)
    private static $queue = [];
    private static $request_timestamps = [];
    
    // For generating label colors
    private $colors = [];

    function about() {
        return array(1.1,
            "Automatically assign labels to articles using OpenAI API",
            "fangd123");
    }

    function init($host) {
        $this->host = $host;
        $this->openai_api_key = $this->host->get($this, "openai_api_key");
        $this->label_language = $this->host->get($this, "label_language");
        $this->openai_base_url = $this->host->get($this, "openai_base_url", "https://api.openai.com/v1");
        $this->openai_model = $this->host->get($this, "openai_model", "gpt-4o-mini");
        $this->max_labels = (int)$this->host->get($this, "max_labels", 5);
        $this->max_text_length = (int)$this->host->get($this, "max_text_length", 1500);
        $this->pdo = Db::pdo();

        if (empty($this->label_language)) {
            $owner_uid = $_SESSION["uid"];
            $this->label_language = Prefs::get("USER_LANGUAGE", $owner_uid);
        }

        if (empty($this->openai_base_url)) {
            $this->openai_base_url = "https://api.openai.com/v1";
        }

        $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
    }

    // Retrieve all existing labels for a given user
    private function get_existing_labels($owner_uid) {
        $sth = $this->pdo->prepare("SELECT caption FROM ttrss_labels2 WHERE owner_uid = ?");
        $sth->execute([$owner_uid]);
        $labels = array();
        while ($row = $sth->fetch()) {
            $labels[] = $row['caption'];
        }
        return $labels;
    }

    // Calls the OpenAI API using the provided text and existing labels.
    private function call_openai_api($text, $existing_labels) {
        $url = rtrim($this->openai_base_url, '/') . '/chat/completions';

        // Truncate text to the allowed maximum length
        $text = mb_substr($text, 0, $this->max_text_length);

        $system_prompt = 'You are a bot in a read-it-later app and your responsibility is to help with automatic tagging.';

        $existing_labels_json = json_encode($existing_labels, JSON_UNESCAPED_UNICODE);
        $language = ($this->label_language == "auto") ? "English" : $this->label_language;
        $max_labels = $this->max_labels;

        $user_prompt = <<<EOT
Please analyze the text between the sentences "CONTENT START HERE" and "CONTENT END HERE" and suggest relevant tags. Here are the existing tags in the system:
$existing_labels_json

The rules are:
- First, try to use appropriate tags from the existing tags list provided above.
- If you can't find suitable existing tags, you can suggest new ones.
- Aim for a variety of tags, including broad categories, specific keywords, and potential sub-genres.
- The tags language must be in {$language}.
- If it's a famous website you may also include a tag for the website. If the tag is not generic enough, don't include it.
- The content can include text for cookie consent and privacy policy, ignore those while tagging.
- Aim for 1-{$max_labels} tags total (combination of existing and new tags).
- If there are no good tags, leave the array empty.
- For each tag, specify whether it's from existing tags or newly suggested.

CONTENT START HERE

{$text}

CONTENT END HERE
You must respond in JSON with two keys:
- "existing_tags": array of selected tags from the existing list
- "new_tags": array of newly suggested tags
EOT;

        $data = array(
            'model' => $this->openai_model,
            'messages' => array(
                array(
                    'role' => 'system',
                    'content' => $system_prompt
                ),
                array(
                    'role' => 'user',
                    'content' => $user_prompt
                )
            ),
            'temperature' => 0.3,
            'max_tokens' => 150,
            'response_format' => array('type' => 'json_object')
        );

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->openai_api_key
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            Logger::log(E_USER_WARNING, "OpenAI_Auto_Labels", "OpenAI API connection error: " . $error_msg);

            if (curl_errno($ch) == CURLE_OPERATION_TIMEDOUT) {
                Logger::log(E_USER_WARNING, "OpenAI_Auto_Labels", "OpenAI API request timed out, please check your network connection or proxy settings");
            } else if (curl_errno($ch) == CURLE_COULDNT_CONNECT) {
                Logger::log(E_USER_WARNING, "OpenAI_Auto_Labels", "Failed to connect to OpenAI API, please check if the URL is correct or if the network is available");
            }

            curl_close($ch);
            return array();
        }

        curl_close($ch);

        $response_data = json_decode($response, true);

        if ($http_code !== 200) {
            $error_type = isset($response_data['error']['type']) ? $response_data['error']['type'] : 'Unknown error type';
            $error_message = isset($response_data['error']['message']) ? $response_data['error']['message'] : 'Unknown error';
            $error_code = isset($response_data['error']['code']) ? $response_data['error']['code'] : 'Unknown error code';

            Logger::log(E_USER_WARNING, "OpenAI_Auto_Labels", sprintf(
                "OpenAI API error - Type: %s, Message: %s, Code: %s",
                $error_type,
                $error_message,
                $error_code
            ));

            if ($error_type === 'invalid_request_error' && strpos($error_message, 'API key') !== false) {
                Logger::log(E_USER_WARNING, "OpenAI_Auto_Labels", "Invalid or malformed API Key, please check the configured API Key");
            } else if ($error_type === 'invalid_api_key') {
                Logger::log(E_USER_WARNING, "OpenAI_Auto_Labels", "Invalid API Key, please ensure you are using the correct API Key");
            } else if ($error_type === 'insufficient_quota') {
                Logger::log(E_USER_WARNING, "OpenAI_Auto_Labels", "Insufficient API quota, please check your account balance or upgrade your plan");
            } else if ($http_code === 429) {
                Logger::log(E_USER_WARNING, "OpenAI_Auto_Labels", "Request rate limit exceeded, please reduce the request frequency or increase the quota");
            }

            return array();
        }

        try {
            if (!isset($response_data['choices'][0]['message']['content'])) {
                Logger::log(E_USER_WARNING, "OpenAI_Auto_Labels", "OpenAI API response format error: " . json_encode($response_data));
                return array();
            }

            $content = json_decode($response_data['choices'][0]['message']['content'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Logger::log(E_USER_WARNING, "OpenAI_Auto_Labels", "Failed to parse content returned by OpenAI: " . json_last_error_msg());
                return array();
            }

            $all_tags = array();
            if (isset($content['existing_tags']) && is_array($content['existing_tags'])) {
                $all_tags = array_merge($all_tags, $content['existing_tags']);
            }
            if (isset($content['new_tags']) && is_array($content['new_tags'])) {
                $all_tags = array_merge($all_tags, $content['new_tags']);
            }

            $final_tags = array_slice($all_tags, 0, $this->max_labels);
            return $final_tags;

        } catch (Exception $e) {
            Logger::log(E_USER_WARNING, "OpenAI_Auto_Labels", "Error occurred while parsing OpenAI response: " . $e->getMessage());
        }

        return array();
    }

    // Initialize color palette for labels.
    private function initialize_colors() {
        for ($r = 0; $r <= 0xFF; $r += 0x33) {
            for ($g = 0; $g <= 0xFF; $g += 0x33) {
                for ($b = 0; $b <= 0xFF; $b += 0x33) {
                    $this->colors[] = sprintf('%02X%02X%02X', $r, $g, $b);
                }
            }
        }
    }

    // Generate a pair of random colors ensuring adequate contrast.
    private function generate_random_color() {
        if (empty($this->colors)) {
            $this->initialize_colors();
        }
        $color_indices = array_rand($this->colors, 2);
        $fg_color = $this->colors[$color_indices[0]];
        $bg_color = $this->colors[$color_indices[1]];

        $fg_brightness = $this->get_brightness($fg_color);
        $bg_brightness = $this->get_brightness($bg_color);

        if (abs($fg_brightness - $bg_brightness) < 125) {
            if ($fg_brightness > $bg_brightness) {
                list($fg_color, $bg_color) = array($bg_color, $fg_color);
            }
        }

        return array($fg_color, $bg_color);
    }

    // Calculate perceived brightness from a hex color.
    private function get_brightness($hex_color) {
        $r = hexdec(substr($hex_color, 0, 2));
        $g = hexdec(substr($hex_color, 2, 2));
        $b = hexdec(substr($hex_color, 4, 2));
        return (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
    }

    // Retrieve an existing label or create a new one if it doesn't exist.
    private function get_or_create_label($caption, $owner_uid) {
        $sth = $this->pdo->prepare("SELECT id, fg_color, bg_color FROM ttrss_labels2
            WHERE caption = ? AND owner_uid = ?");
        $sth->execute([$caption, $owner_uid]);

        if ($row = $sth->fetch()) {
            return array(
                Labels::label_to_feed_id($row["id"]),
                $caption,
                $row["fg_color"],
                $row["bg_color"]
            );
        }

        list($fg_color, $bg_color) = $this->generate_random_color();

        $sth = $this->pdo->prepare("INSERT INTO ttrss_labels2
            (caption, owner_uid, fg_color, bg_color)
            VALUES (?, ?, ?, ?)");
        $sth->execute([$caption, $owner_uid, $fg_color, $bg_color]);
        $label_id = $this->pdo->lastInsertId();

        return array(
            Labels::label_to_feed_id($label_id),
            $caption,
            $fg_color,
            $bg_color
        );
    }

    // Add an article to the in-memory queue.
    private function add_to_queue($article) {
        self::$queue[] = $article;
    }

    // Process one item from the queue, ensuring no more than 12 requests are made per minute.
    private function process_queue() {
        if (empty(self::$queue)) {
            return;
        }
        
        $current_time = time();
        // Remove timestamps older than 60 seconds.
        self::$request_timestamps = array_filter(self::$request_timestamps, function($timestamp) use ($current_time) {
            return $timestamp > $current_time - 60;
        });
        
        if (count(self::$request_timestamps) >= 12) {
            // Rate limit reached; do not process a new request yet.
            return;
        }
        
        $article = array_shift(self::$queue);
        $existing_labels = $this->get_existing_labels($article["owner_uid"]);
        $content = $article["title"] . "\n" . strip_tags($article["content"]);
        $suggested_tags = $this->call_openai_api($content, $existing_labels);
        
        if (!empty($suggested_tags)) {
            foreach ($suggested_tags as $tag) {
                $label = $this->get_or_create_label($tag, $article["owner_uid"]);
                if (!RSSUtils::labels_contains_caption($article["labels"], $label[1])) {
                    array_push($article["labels"], $label);
                }
            }
        }
        
        self::$request_timestamps[] = $current_time;
    }

    // Hook that is called for each article. Instead of processing immediately,
    // the article is added to the in-memory queue and the queue is processed
    // (subject to the rate limit).
    function hook_article_filter($article) {
        if (!$this->openai_api_key) {
            return $article;
        }

        $this->add_to_queue($article);
        $this->process_queue();

        return $article;
    }

    function api_version() {
        return 2;
    }

    // Render the settings tab in the preferences.
    function hook_prefs_tab($args) {
        if ($args != "prefFeeds") return;

        print "<div dojoType=\"dijit.layout.AccordionPane\"
            title=\"<i class='material-icons'>label</i> ".__("OpenAI Auto Labels Settings")."\">";
        print "<h2>" . __("OpenAI API Configuration") . "</h2>";
        print "<form dojoType=\"dijit.form.Form\">";
        print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
            evt.preventDefault();
            if (this.validate()) {
                xhr.post('backend.php', this.getValues(), (reply) => {
                    Notify.info(reply);
                });
            }
            </script>";
        print \Controls\pluginhandler_tags($this, "save");

        $openai_api_key = $this->host->get($this, "openai_api_key");
        $label_language = $this->host->get($this, "label_language");
        $openai_base_url = $this->host->get($this, "openai_base_url", "https://api.openai.com/v1");
        $openai_model = $this->host->get($this, "openai_model", "gpt-4o-mini");
        $max_labels = $this->host->get($this, "max_labels", 5);
        $max_text_length = $this->host->get($this, "max_text_length", 1500);

        if (empty($label_language)) {
            $owner_uid = $_SESSION["uid"];
            $label_language = Prefs::get("USER_LANGUAGE", $owner_uid);
        }

        print "<div class=\"form-group\">";
        print "<input dojoType=\"dijit.form.ValidationTextBox\"
            required=\"1\"
            name=\"openai_api_key\"
            style=\"width: 30em;\"
            value=\"$openai_api_key\">";
        print "&nbsp;<label for=\"openai_api_key\">" .
            __("Your OpenAI API Key") . "</label>";
        print "</div>";

        print "<div class=\"form-group\">";
        print "<input dojoType=\"dijit.form.ValidationTextBox\"
            required=\"1\"
            name=\"openai_base_url\"
            style=\"width: 30em;\"
            value=\"$openai_base_url\">";
        print "&nbsp;<label for=\"openai_base_url\">" .
            __("OpenAI API Base URL") . "</label>";
        print "</div>";

        print "<div class=\"form-group\">";
        print "<input dojoType=\"dijit.form.ValidationTextBox\"
            required=\"1\"
            name=\"openai_model\"
            style=\"width: 20em;\"
            value=\"$openai_model\">";
        print "&nbsp;<label for=\"openai_model\">" .
            __("OpenAI Model") . "</label>";
        print "</div>";

        print "<div class=\"form-group\">";
        print "<input dojoType=\"dijit.form.NumberSpinner\"
            required=\"1\"
            name=\"max_labels\"
            style=\"width: 7em;\"
            value=\"$max_labels\"
            min=\"1\"
            max=\"10\">";
        print "&nbsp;<label for=\"max_labels\">" .
            __("Maximum number of labels") . "</label>";
        print "</div>";

        print "<div class=\"form-group\">";
        print "<input dojoType=\"dijit.form.NumberSpinner\"
            required=\"1\"
            name=\"max_text_length\"
            style=\"width: 7em;\"
            value=\"$max_text_length\"
            min=\"500\"
            max=\"4000\">";
        print "&nbsp;<label for=\"max_text_length\">" .
            __("Maximum text length for analysis") . "</label>";
        print "</div>";

        print "<div class=\"form-group\">";
        print "<input dojoType=\"dijit.form.ValidationTextBox\"
            required=\"1\"
            name=\"label_language\"
            style=\"width: 10em;\"
            value=\"$label_language\">";
        print "&nbsp;<label for=\"label_language\">" .
            __("Labels Language (e.g. en, zh-CN)") . "</label>";
        print "</div>";

        print "<p>" . __("Enter your OpenAI API key to enable automatic labeling of articles.") . "</p>";
        print "<p>" . __("Specify the language for generated labels. By default, it uses your TTRSS system language.") . "</p>";
        print "<p>" . __("If your TTRSS system language was 'auto', it will use English as the default language.") . "</p>";
        print "<p>" . __("You can customize the OpenAI API Base URL if you're using a proxy or alternative endpoint.") . "</p>";
        print "<p>" . __("Select the OpenAI model to use for generating labels.") . "</p>";
        print "<p>" . __("Choose how many labels should be generated for each article (maximum 10).") . "</p>";
        print "<p>" . __("Set the maximum length of text to be analyzed by the API (500-4000 characters).") . "</p>";

        print "<button dojoType=\"dijit.form.Button\"
            type=\"submit\"
            class=\"alt-primary\">".
            __("Save")."</button>";

        print "</form>";
        print "</div>";
    }

    // Save the configuration settings.
    function save() {
        $this->host->set($this, "openai_api_key", $_POST["openai_api_key"]);
        $this->host->set($this, "label_language", $_POST["label_language"]);
        $this->host->set($this, "openai_base_url", $_POST["openai_base_url"]);
        $this->host->set($this, "openai_model", $_POST["openai_model"]);
        $this->host->set($this, "max_labels", (int)$_POST["max_labels"]);
        $this->host->set($this, "max_text_length", (int)$_POST["max_text_length"]);
        echo __("Settings have been saved.");
    }
}
?>
