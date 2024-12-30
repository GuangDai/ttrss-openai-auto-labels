# TTRSS OpenAI 自动标签插件

使用 OpenAI 的 GPT 模型为您的 Tiny Tiny RSS 文章自动添加标签。该插件通过分析文章内容，基于已有标签和内容分析来推荐相关标签。

## 功能特点

- 🤖 使用 OpenAI API 自动为文章添加标签
- 🏷️ 智能复用已有标签
- 🎨 ~~新标签自动生成配色~~
- 🌍 可配置标签语言
- 🔄 每篇文章最多 5 个标签
- ⚡ 通过内容截断优化 API 使用
- 🎯 精确的错误处理和日志记录

**注意：** 新标签的自动配色功能暂时不可用。由于某些原因，该功能代码目前无法正常工作。如果您有兴趣解决这个问题，欢迎贡献代码！

## 系统要求

- Tiny Tiny RSS v2.0.0 或更高版本
- PHP 7.4 或更高版本
- OpenAI API 密钥

## 安装步骤

1. 从 GitHub 下载最新版本
2. 将 `openai_auto_labels` 文件夹解压到您的 TTRSS 插件目录：
   ```bash
   cd /path/to/ttrss/plugins
   git clone https://github.com/fangd123/ttrss-openai-labels.git openai_auto_labels
   ```
3. 在 TTRSS 偏好设置 -> 插件 中启用该插件
4. 在 偏好设置 -> 订阅源 -> 插件 -> OpenAI 自动标签设置 中配置您的 OpenAI API 密钥

## 配置说明

1. 进入 偏好设置 -> 订阅源 -> OpenAI 自动标签设置
2. 输入您的 OpenAI API 密钥
3. 设置您偏好的标签语言（默认使用您的 TTRSS 系统语言）
   - 使用标准语言代码，如 'en'、'zh-CN' 等
   - 如果您的 TTRSS 系统语言设置为 'auto'，将使用英语

## 工作原理

1. 收到新文章时，插件提取标题和内容
2. 将内容截断至 1500 字符以优化 API 使用
3. 插件获取您 TTRSS 中已有的标签
4. OpenAI API 分析内容并推荐相关标签
5. 标签可能来自已有标签或创建新标签
6. 新标签会自动分配对比色
7. 每篇文章最多应用 5 个最相关的标签

## 错误处理

插件包含全面的错误处理机制，覆盖多种场景：

- 网络连接问题
- API 密钥验证
- 速率限制
- 配额管理
- 响应解析
- 超时处理

错误会记录到 TTRSS 的日志系统中以便排查。

## 参与贡献

欢迎贡献代码！请随时提交 Pull Request。

1. Fork 仓库
2. 创建功能分支（`git checkout -b feature/amazing-feature`）
3. 提交更改（`git commit -m '添加某个很棒的功能'`）
4. 推送到分支（`git push origin feature/amazing-feature`）
5. 创建 Pull Request

## 开源协议

本项目采用 MIT 协议 - 详见 [LICENSE](LICENSE) 文件。

## 作者

**fangd123** - [GitHub 主页](https://github.com/fangd123)

## 致谢

- 感谢 TTRSS 社区
- 使用 OpenAI 的 GPT API 构建
- 源于对更好的文章组织方式的需求

## 支持

如果您遇到任何问题或有疑问，请：

1. 查看 [Issues](https://github.com/fangd123/ttrss-openai-labels/issues) 页面
2. 如果您的问题尚未列出，创建新的 issue
3. 提供尽可能详细的信息，包括：
   - TTRSS 版本
   - PHP 版本
   - 错误信息
   - 重现步骤