 <?php
// config.php

// R2 存储配置
define('R2_ENDPOINT', 'https://YOUR_ACCOUNT_ID.r2.cloudflarestorage.com');
define('R2_KEY', 'YOUR_ACCESS_KEY_ID');
define('R2_SECRET', 'YOUR_SECRET_ACCESS_KEY');
define('R2_BUCKET_NAME', 'YOUR_BUCKET_NAME');
define('R2_REGION', 'auto'); // R2 兼容性设置

// 自定义域名（用于显示链接）
define('CUSTOM_DOMAIN', 'https://cdn.yourdomain.com');

// 简单的认证信息
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD_HASH', '密码的哈希值');
