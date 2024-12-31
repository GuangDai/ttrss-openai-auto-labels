<?php
class OpenAI_Auto_Labels extends Plugin {
    private $host;
    private $openai_api_key;
    private $label_language;
    private $openai_base_url; // 新增: OpenAI API基础URL
    private $openai_model;    // 新增: OpenAI模型
    private $max_labels;      // 新增: 最大标签数
    private $max_text_length; // 新增: 最大文本长度
    protected $pdo;

    function about() {
        return array(1.1,
            "Automatically assign labels to articles using OpenAI API",
            "fangd123");
    }

    function init($host) {
        $this->host = $host;
        $this->openai_api_key = $this->host->get($this, "openai_api_key");
        $this->label_language = $this->host->get($this, "label_language");
        $this->openai_base_url = $this->host->get($this, "openai_base_url", "https://api.openai.com/v1"); // 默认值
        $this->openai_model = $this->host->get($this, "openai_model", "gpt-4o-mini"); // 默认值
        $this->max_labels = (int)$this->host->get($this, "max_labels", 5); // 默认值
        $this->max_text_length = (int)$this->host->get($this, "max_text_length", 1500); // 默认值
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

    // 新增：获取用户所有已有的标签
    private function get_existing_labels($owner_uid) {
        $sth = $this->pdo->prepare("SELECT caption FROM ttrss_labels2 WHERE owner_uid = ?");
        $sth->execute([$owner_uid]);

        $labels = array();
        while ($row = $sth->fetch()) {
            $labels[] = $row['caption'];
        }

        return $labels;
    }

    private function call_openai_api($text, $existing_labels) {
        $url = rtrim($this->openai_base_url, '/') . '/chat/completions';

        $text = mb_substr($text, 0, $this->max_text_length);

        $system_prompt = 'You are a bot in a read-it-later app and your responsibility is to help with automatic tagging.';

        // 修改提示，包含现有标签和目标语言
        $existing_labels_json = json_encode($existing_labels, JSON_UNESCAPED_UNICODE);
        $language = $this->label_language == "auto"? "English" : $this->label_language;
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

        // Parse the response to get detailed error information
        $response_data = json_decode($response, true);

        if ($http_code !== 200) {
            $error_type = isset($response_data['error']['type']) ? $response_data['error']['type'] : 'Unknown error type';
            $error_message = isset($response_data['error']['message']) ? $response_data['error']['message'] : 'Unknown error';
            $error_code = isset($response_data['error']['code']) ? $response_data['error']['code'] : 'Unknown error code';

            // Log detailed error information
            Logger::log(E_USER_WARNING, "OpenAI_Auto_Labels", sprintf(
                "OpenAI API error - Type: %s, Message: %s, Code: %s",
                $error_type,
                $error_message,
                $error_code
            ));

            // Log more specific warnings for certain error types
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

        private $colors = [];

    private function initialize_colors() {
        // Generate colors based on the same quantization as colorPalette function
        for ($r = 0; $r <= 0xFF; $r += 0x33) {
            for ($g = 0; $g <= 0xFF; $g += 0x33) {
                for ($b = 0; $b <= 0xFF; $b += 0x33) {
                    $this->colors[] = sprintf('%02X%02X%02X', $r, $g, $b);
                }
            }
        }
    }

    private function generate_random_color() {
        if (empty($this->colors)) {
            $this->initialize_colors();
        }

        // Select two different colors
        $color_indices = array_rand($this->colors, 2);

        // Calculate perceived brightness for both colors
        $fg_color = $this->colors[$color_indices[0]];
        $bg_color = $this->colors[$color_indices[1]];

        // Convert hex to RGB and calculate brightness
        $fg_brightness = $this->get_brightness($fg_color);
        $bg_brightness = $this->get_brightness($bg_color);

        // Ensure adequate contrast by swapping if necessary
        if (abs($fg_brightness - $bg_brightness) < 125) {
            // If contrast is too low, select darker color for foreground
            if ($fg_brightness > $bg_brightness) {
                list($fg_color, $bg_color) = array($bg_color, $fg_color);
            }
        }

        return array($fg_color, $bg_color);
    }

    private function get_brightness($hex_color) {
        // Convert hex to RGB
        $r = hexdec(substr($hex_color, 0, 2));
        $g = hexdec(substr($hex_color, 2, 2));
        $b = hexdec(substr($hex_color, 4, 2));

        // Calculate perceived brightness
        // Using ITU-R BT.709 coefficients
        return (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
    }

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

        // 生成随机颜色
        list($fg_color, $bg_color) = $this->generate_random_color();

        // 如果标签不存在，创建新标签
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

    function hook_article_filter($article) {
        if (!$this->openai_api_key) {
            return $article;
        }


        $owner_uid = $article["owner_uid"];
        $content = $article["title"] . "\n" . strip_tags($article["content"]);

        // 获取现有标签
        $existing_labels = $this->get_existing_labels($owner_uid);

        // 调用OpenAI API获取标签，传入现有标签
        $suggested_tags = $this->call_openai_api($content, $existing_labels);

        if (!empty($suggested_tags)) {
            foreach ($suggested_tags as $tag) {
                $label = $this->get_or_create_label($tag, $owner_uid);

                // 检查文章是否已经有这个标签
                if (!RSSUtils::labels_contains_caption($article["labels"], $label[1])) {
                    array_push($article["labels"], $label);
                }
            }
        }

        return $article;
    }

    function api_version() {
        return 2;
    }


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

        // API Key 设置
        print "<div class=\"form-group\">";
        print "<input dojoType=\"dijit.form.ValidationTextBox\"
            required=\"1\"
            name=\"openai_api_key\"
            style=\"width: 30em;\"
            value=\"$openai_api_key\">";
        print "&nbsp;<label for=\"openai_api_key\">" .
            __("Your OpenAI API Key") . "</label>";
        print "</div>";

        // API Base URL 设置
        print "<div class=\"form-group\">";
        print "<input dojoType=\"dijit.form.ValidationTextBox\"
            required=\"1\"
            name=\"openai_base_url\"
            style=\"width: 30em;\"
            value=\"$openai_base_url\">";
        print "&nbsp;<label for=\"openai_base_url\">" .
            __("OpenAI API Base URL") . "</label>";
        print "</div>";

        // 模型设置
        print "<div class=\"form-group\">";
        print "<input dojoType=\"dijit.form.ValidationTextBox\"
            required=\"1\"
            name=\"openai_model\"
            style=\"width: 20em;\"
            value=\"$openai_model\">";
        print "&nbsp;<label for=\"openai_model\">" .
            __("OpenAI Model") . "</label>";
        print "</div>";

        // 最大标签数设置
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

        // 最大文本长度设置
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

        // 标签语言设置
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