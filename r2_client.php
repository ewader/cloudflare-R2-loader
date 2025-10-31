<?php
// r2_client.php
require 'vendor/autoload.php';
require 'config.php';

use Aws\S3\S3Client;

/**
 * 初始化并返回一个连接到 Cloudflare R2 的 S3Client 实例。
 * @return S3Client
 */
function getR2Client() {
    $r2_config = [
        'version'     => 'latest',
        'region'      => R2_REGION,
        'endpoint'    => R2_ENDPOINT,
        'credentials' => [
            'key'    => R2_KEY,
            'secret' => R2_SECRET,
        ],
        // 强制使用路径样式访问，通常是 R2 所需的
        'use_path_style_endpoint' => true,
    ];

    try {
        $r2Client = new S3Client($r2_config);
        return $r2Client;
    } catch (Exception $e) {
        // 在生产环境中应记录错误而非直接暴露
        die("❌ 无法连接到 R2: " . $e->getMessage());
    }{
    "require": {
        "aws/aws-sdk-php": "^3.0"
    }
}
}
?>
