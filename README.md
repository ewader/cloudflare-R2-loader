
# ☁️ R2-PHP-Manager: Cloudflare R2 轻量级单文件管理面板

一个基于原生 PHP 和 AWS SDK for PHP v3 构建的极简 R2 存储桶管理面板。适用于个人用户快速部署、管理和分享 R2 中的图片等文件资源。

## 💡 项目特性

  * **轻量级：** 单文件核心逻辑，易于部署和维护。
  * **安全认证：** 基于 Session 和 `password_hash()` 的管理员登录保护。
  * **文件操作：** 支持文件上传和单文件删除。
  * **高效管理：**
      * **点击放大预览：** 优化图片查看体验。
      * **一键复制链接：** 快速复制图片的完整公共 URL。
  * **分页浏览：** 支持 Cloudflare R2 的 Continuation Token 分页机制，高效加载大量文件。

## 🚀 部署指南

### 1\. 环境要求

  * **PHP 版本：** PHP 8.0 或更高版本。
  * **依赖管理：** 必须安装 [Composer](https://getcomposer.org/)。
  * **R2 密钥：** 需准备您的 R2 S3 API **Access Key ID** 和 **Secret Access Key**。

### 2\. 初始化项目

将本项目文件下载到您的 Web 服务器目录后，执行以下步骤：

#### A. 安装依赖

在项目根目录运行 Composer 命令，安装 AWS SDK for PHP：

```bash
composer require aws/aws-sdk-php
```

#### B. 配置 R2 凭证

1.  将 `config-example.php` 文件复制并重命名为 **`config.php`**。

2.  打开 `config.php`，填入您的 Cloudflare R2 密钥和自定义域名信息。

    > ⚠️ **安全警告：** `config.php` 包含敏感密钥，请确保将其添加到您的 `.gitignore` 文件中，避免意外提交到 GitHub。

#### C. 设置管理员密码

在 `config.php` 中，您需要设置管理员登录所需的用户名和密码哈希：

1.  设置 `ADMIN_USERNAME`。

2.  使用 PHP 命令行或临时文件，生成您密码的哈希值，并将其填入 `ADMIN_PASSWORD_HASH`。

    ```php
    // 示例：在命令行运行以生成哈希值
    echo password_hash('您的安全密码', PASSWORD_DEFAULT);
    ```

### 3\. 运行项目

通过浏览器访问 `index.php` 即可开始使用管理面板。

## 🛠️ 使用功能一览

登录后，面板提供以下核心功能：

| 功能模块 | 操作说明 |
| :--- | :--- |
| **文件上传** | 在上传区域选择图片文件，点击上传到 R2 存储桶。 |
| **分页浏览** | 使用底部的分页链接加载更多文件列表。 |
| **图片预览** | 直接点击缩略图，图片将在模态框中放大显示。 |
| **复制链接** | 点击文件列表中的 **"复制"** 按钮，一键获取该文件的公共 URL。 |
| **文件删除** | 点击 **"删除"** 按钮，确认后即可移除文件。 |

