<?php
// index.php - Cloudflare R2 文件管理面板 (最终版本 - 优化复制链接和缩放)

// ----------------------------------------------------
// I. 配置与依赖加载
// ----------------------------------------------------
require_once 'r2_client.php';
require_once 'config.php'; // 包含 R2 凭证, ADMIN_USERNAME, ADMIN_PASSWORD_HASH

session_start();

// --- PHP 运行时配置 ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED); 
ini_set('memory_limit', '256M'); 

// --- 分页配置 ---
$pageSize = 20; 
$continuationToken = $_GET['token'] ?? null;
$currentPage = (int)($_GET['page'] ?? 1); 

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// ----------------------------------------------------
// II. 认证和会话逻辑
// ----------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    if ($username === ADMIN_USERNAME && defined('ADMIN_PASSWORD_HASH') && password_verify($password, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true); 
        $_SESSION['is_logged_in'] = true;
        $_SESSION['message'] = '✅ 登录成功！';
    } else {
        $_SESSION['message'] = '❌ 登录失败：用户名或密码错误。';
    }
    header('Location: index.php');
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit();
}

// --- 未登录界面 (未登录时终止脚本并显示登录表单) ---
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    echo '<!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="UTF-8">
        <title>R2 图片管理 - 登录</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>body { padding-top: 50px; background-color: #f8f9fa; }</style>
    </head>
    <body>
        <div class="container col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white"><h3>🔐 R2 管理系统登录</h3></div>
                <div class="card-body">
                    ' . ($message ? '<div class="alert alert-danger">' . $message . '</div>' : '') . '
                    <form method="POST">
                        <input type="hidden" name="action" value="login">
                        <div class="mb-3">
                            <label for="username" class="form-label">用户名</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">密码</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100">登录</button>
                    </form>
                </div>
            </div>
        </div>
    </body>
    </html>';
    exit();
}

// ----------------------------------------------------
// III. R2 客户端操作 (登录后执行)
// ----------------------------------------------------

$r2Client = getR2Client();

// --- 文件删除处理 (单文件) ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['key'])) {
    $keyToDelete = $_GET['key'];
    try {
        $r2Client->deleteObject(['Bucket' => R2_BUCKET_NAME, 'Key' => $keyToDelete]);
        $_SESSION['message'] = '✅ 文件 <strong>' . htmlspecialchars($keyToDelete) . '</strong> 已成功删除。';
    } catch (Aws\S3\Exception\S3Exception $e) {
        $_SESSION['message'] = "❌ 删除失败: " . $e->getMessage();
    }
    header('Location: index.php');
    exit;
}

// --- 文件上传处理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image_file'])) {
    $file = $_FILES['image_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK || !str_starts_with($file['type'], 'image/')) {
        $_SESSION['message'] = '❌ 文件上传失败或文件不是图片。';
    } else {
        $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $key = 'images/' . time() . '_' . $originalName . '.' . $extension; 

        try {
            $r2Client->putObject([
                'Bucket'      => R2_BUCKET_NAME,
                'Key'         => $key,
                'SourceFile'  => $file['tmp_name'],
                'ContentType' => $file['type'],
            ]);

            $publicUrl = CUSTOM_DOMAIN . '/' . $key;
            $_SESSION['message'] = '✅ 文件上传成功！<br>
                                    <strong>链接:</strong> <a href="' . htmlspecialchars($publicUrl) . '" target="_blank">' . htmlspecialchars($publicUrl) . '</a>';
        } catch (Aws\S3\Exception\S3Exception $e) {
            $_SESSION['message'] = "❌ 上传失败: " . $e->getMessage();
        }
    }
    header('Location: index.php');
    exit;
}

// ----------------------------------------------------
// IV. R2 文件列表获取函数
// ----------------------------------------------------
function listR2ImagesPaginated($client, $bucket, $pageSize, $token) {
    $params = [
        'Bucket'    => $bucket,
        'MaxKeys'   => $pageSize,
        'ContinuationToken' => $token,
    ];

    try {
        $result = $client->listObjectsV2($params);
        return [
            'Contents'          => $result['Contents'] ?? [],
            'NextContinuationToken' => $result['NextContinuationToken'] ?? null,
            'IsTruncated'       => $result['IsTruncated'] ?? false,
        ];
    } catch (Aws\S3\Exception\S3Exception $e) {
        return ['error' => '无法获取图片列表: ' . $e->getMessage()];
    }
}

$listResult = listR2ImagesPaginated($r2Client, R2_BUCKET_NAME, $pageSize, $continuationToken);
$imageList = $listResult['Contents'] ?? [];
$nextContinuationToken = $listResult['NextContinuationToken'] ?? null;
$isTruncated = $listResult['IsTruncated'] ?? false;

// ----------------------------------------------------
// V. 管理界面 HTML 输出
// ----------------------------------------------------
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>R2 图片管理面板</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLMDJc5GIsBqfKx128p/DkL+x5y5t1eKq90jWk55z4F4B3F11I8t6M6C2f51fGg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">☁️ R2 图片管理</a>
        <div class="d-flex">
            <span class="navbar-text me-3 text-white">
                欢迎您，管理员！
            </span>
            <a class="btn btn-outline-light" href="?action=logout">退出登录</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            
            <h1 class="mb-4 text-center">🚀 Cloudflare R2 文件管理</h1>

            <?php if ($message): ?>
                <div class="alert <?= str_starts_with($message, '✅') ? 'alert-success' : 'alert-danger' ?>" role="alert">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-lg mb-5">
                <div class="card-header bg-success text-white">
                    <strong>⬆️ 图片上传区 (单文件)</strong>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="imageFile" class="form-label">选择图片文件</label>
                            <input class="form-control" type="file" id="imageFile" name="image_file" accept="image/*"<?php
// index.php - Cloudflare R2 文件管理面板 (最终版本 - 优化复制链接和缩放)

// ----------------------------------------------------
// I. 配置与依赖加载
// ----------------------------------------------------
require_once 'r2_client.php';
require_once 'config.php'; // 包含 R2 凭证, ADMIN_USERNAME, ADMIN_PASSWORD_HASH

session_start();

// --- PHP 运行时配置 ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED); 
ini_set('memory_limit', '256M'); 

// --- 分页配置 ---
$pageSize = 20; 
$continuationToken = $_GET['token'] ?? null;
$currentPage = (int)($_GET['page'] ?? 1); 

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// ----------------------------------------------------
// II. 认证和会话逻辑
// ----------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    if ($username === ADMIN_USERNAME && defined('ADMIN_PASSWORD_HASH') && password_verify($password, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true); 
        $_SESSION['is_logged_in'] = true;
        $_SESSION['message'] = '✅ 登录成功！';
    } else {
        $_SESSION['message'] = '❌ 登录失败：用户名或密码错误。';
    }
    header('Location: index.php');
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit();
}

// --- 未登录界面 (未登录时终止脚本并显示登录表单) ---
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    echo '<!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="UTF-8">
        <title>R2 图片管理 - 登录</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>body { padding-top: 50px; background-color: #f8f9fa; }</style>
    </head>
    <body>
        <div class="container col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white"><h3>🔐 R2 管理系统登录</h3></div>
                <div class="card-body">
                    ' . ($message ? '<div class="alert alert-danger">' . $message . '</div>' : '') . '
                    <form method="POST">
                        <input type="hidden" name="action" value="login">
                        <div class="mb-3">
                            <label for="username" class="form-label">用户名</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">密码</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100">登录</button>
                    </form>
                </div>
            </div>
        </div>
    </body>
    </html>';
    exit();
}

// ----------------------------------------------------
// III. R2 客户端操作 (登录后执行)
// ----------------------------------------------------

$r2Client = getR2Client();

// --- 文件删除处理 (单文件) ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['key'])) {
    $keyToDelete = $_GET['key'];
    try {
        $r2Client->deleteObject(['Bucket' => R2_BUCKET_NAME, 'Key' => $keyToDelete]);
        $_SESSION['message'] = '✅ 文件 <strong>' . htmlspecialchars($keyToDelete) . '</strong> 已成功删除。';
    } catch (Aws\S3\Exception\S3Exception $e) {
        $_SESSION['message'] = "❌ 删除失败: " . $e->getMessage();
    }
    header('Location: index.php');
    exit;
}

// --- 文件上传处理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image_file'])) {
    $file = $_FILES['image_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK || !str_starts_with($file['type'], 'image/')) {
        $_SESSION['message'] = '❌ 文件上传失败或文件不是图片。';
    } else {
        $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $key = 'images/' . time() . '_' . $originalName . '.' . $extension; 

        try {
            $r2Client->putObject([
                'Bucket'      => R2_BUCKET_NAME,
                'Key'         => $key,
                'SourceFile'  => $file['tmp_name'],
                'ContentType' => $file['type'],
            ]);

            $publicUrl = CUSTOM_DOMAIN . '/' . $key;
            $_SESSION['message'] = '✅ 文件上传成功！<br>
                                    <strong>链接:</strong> <a href="' . htmlspecialchars($publicUrl) . '" target="_blank">' . htmlspecialchars($publicUrl) . '</a>';
        } catch (Aws\S3\Exception\S3Exception $e) {
            $_SESSION['message'] = "❌ 上传失败: " . $e->getMessage();
        }
    }
    header('Location: index.php');
    exit;
}

// ----------------------------------------------------
// IV. R2 文件列表获取函数
// ----------------------------------------------------
function listR2ImagesPaginated($client, $bucket, $pageSize, $token) {
    $params = [
        'Bucket'    => $bucket,
        'MaxKeys'   => $pageSize,
        'ContinuationToken' => $token,
    ];

    try {
        $result = $client->listObjectsV2($params);
        return [
            'Contents'          => $result['Contents'] ?? [],
            'NextContinuationToken' => $result['NextContinuationToken'] ?? null,
            'IsTruncated'       => $result['IsTruncated'] ?? false,
        ];
    } catch (Aws\S3\Exception\S3Exception $e) {
        return ['error' => '无法获取图片列表: ' . $e->getMessage()];
    }
}

$listResult = listR2ImagesPaginated($r2Client, R2_BUCKET_NAME, $pageSize, $continuationToken);
$imageList = $listResult['Contents'] ?? [];
$nextContinuationToken = $listResult['NextContinuationToken'] ?? null;
$isTruncated = $listResult['IsTruncated'] ?? false;

// ----------------------------------------------------
// V. 管理界面 HTML 输出
// ----------------------------------------------------
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>R2 图片管理面板</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLMDJc5GIsBqfKx128p/DkL+x5y5t1eKq90jWk55z4F4B3F11I8t6M6C2f51fGg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">☁️ R2 图片管理</a>
        <div class="d-flex">
            <span class="navbar-text me-3 text-white">
                欢迎您，管理员！
            </span>
            <a class="btn btn-outline-light" href="?action=logout">退出登录</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            
            <h1 class="mb-4 text-center">🚀 Cloudflare R2 文件管理</h1>

            <?php if ($message): ?>
                <div class="alert <?= str_starts_with($message, '✅') ? 'alert-success' : 'alert-danger' ?>" role="alert">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-lg mb-5">
                <div class="card-header bg-success text-white">
                    <strong>⬆️ 图片上传区 (单文件)</strong>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="imageFile" class="form-label">选择图片文件</label>
                            <input class="form-control" type="file" id="imageFile" name="image_file" accept="image/*" required>
                        </div>
                        <p class="text-muted">文件将通过您的自定义域名 <code><?= CUSTOM_DOMAIN ?></code> 访问。</p>
                        <button type="submit" class="btn btn-primary w-100">上传到 R2</button>
                    </form>
                </div>
            </div>

            <h2 class="mt-5">📁 文件列表 (当前页显示 <?= count($imageList) ?> 个)</h2>
            <div class="card shadow-lg">
                <div class="card-body">
                    <?php if (isset($listResult['error'])): ?>
                        <div class="alert alert-danger"><?= $listResult['error'] ?></div>
                    <?php elseif (empty($imageList) && $currentPage == 1): ?>
                        <div class="alert alert-info">存储桶中没有文件。</div>
                    <?php else: ?>
                        
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr class="align-middle">
                                        <th>预览</th>
                                        <th>文件路径 (Key)</th>
                                        <th>修改时间</th>
                                        <th>大小 (KB)</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($imageList as $object): 
                                        $key = $object['Key'];
                                        // 存储完整的公共访问 URL
                                        $publicUrl = CUSTOM_DOMAIN . '/' . htmlspecialchars($key);
                                        $sizeKb = round($object['Size'] / 1024, 2);
                                        $lastModified = date('Y-m-d H:i:s', strtotime($object['LastModified']));
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="#" data-bs-toggle="modal" data-bs-target="#imageModal" data-image-url="<?= $publicUrl ?>">
                                                <img src="<?= $publicUrl ?>" alt="Preview" style="max-height: 50px; max-width: 50px; border-radius: 5px;">
                                            </a>
                                        </td>
                                        <td class="text-break"><code><?= htmlspecialchars($key) ?></code></td>
                                        <td><?= $lastModified ?></td>
                                        <td><?= $sizeKb ?> KB</td>
                                        <td>
                                            <button class="btn btn-sm btn-info me-2 copy-btn" 
                                                    data-clipboard-text="<?= $publicUrl ?>"
                                                    data-bs-toggle="tooltip" data-bs-placement="top" title="已复制!">
                                                <i class="fas fa-copy"></i> 复制
                                            </button>
                                            
                                            <a href="?action=delete&key=<?= urlencode($key) ?>" 
                                               class="btn btn-sm btn-danger"
                                               onclick="return confirm('您确定要删除文件: <?= addslashes($key) ?> 吗？');">
                                                <i class="fas fa-trash"></i> 删除
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <nav aria-label="文件分页">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= ($currentPage > 1) ? '' : 'disabled' ?>">
                                    <a class="page-link" href="?page=1" aria-label="Previous">
                                        <span aria-hidden="true">&laquo; 回到首页</span>
                                    </a>
                                </li>
                                <li class="page-item active" aria-current="page">
                                    <span class="page-link">第 <?= $currentPage ?> 页</span>
                                </li>
                                <?php
                                $nextPageUrl = '';
                                $nextPageActive = false;
                                if ($isTruncated && $nextContinuationToken) {
                                    $nextPageUrl = '?page=' . ($currentPage + 1) . '&token=' . urlencode($nextContinuationToken);
                                    $nextPageActive = true;
                                }
                                ?>
                                <li class="page-item <?= $nextPageActive ? '' : 'disabled' ?>">
                                    <a class="page-link" href="<?= $nextPageUrl ?>" aria-label="Next">
                                        <span aria-hidden="true">下一页 &raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php
// index.php - Cloudflare R2 文件管理面板 (最终版本 - 优化复制链接和缩放)

// ----------------------------------------------------
// I. 配置与依赖加载
// ----------------------------------------------------
require_once 'r2_client.php';
require_once 'config.php'; // 包含 R2 凭证, ADMIN_USERNAME, ADMIN_PASSWORD_HASH

session_start();

// --- PHP 运行时配置 ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED); 
ini_set('memory_limit', '256M'); 

// --- 分页配置 ---
$pageSize = 20; 
$continuationToken = $_GET['token'] ?? null;
$currentPage = (int)($_GET['page'] ?? 1); 

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// ----------------------------------------------------
// II. 认证和会话逻辑
// ----------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    if ($username === ADMIN_USERNAME && defined('ADMIN_PASSWORD_HASH') && password_verify($password, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true); 
        $_SESSION['is_logged_in'] = true;
        $_SESSION['message'] = '✅ 登录成功！';
    } else {
        $_SESSION['message'] = '❌ 登录失败：用户名或密码错误。';
    }
    header('Location: index.php');
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit();
}

// --- 未登录界面 (未登录时终止脚本并显示登录表单) ---
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    echo '<!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="UTF-8">
        <title>R2 图片管理 - 登录</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>body { padding-top: 50px; background-color: #f8f9fa; }</style>
    </head>
    <body>
        <div class="container col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white"><h3>🔐 R2 管理系统登录</h3></div>
                <div class="card-body">
                    ' . ($message ? '<div class="alert alert-danger">' . $message . '</div>' : '') . '
                    <form method="POST">
                        <input type="hidden" name="action" value="login">
                        <div class="mb-3">
                            <label for="username" class="form-label">用户名</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">密码</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100">登录</button>
                    </form>
                </div>
            </div>
        </div>
    </body>
    </html>';
    exit();
}

// ----------------------------------------------------
// III. R2 客户端操作 (登录后执行)
// ----------------------------------------------------

$r2Client = getR2Client();

// --- 文件删除处理 (单文件) ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['key'])) {
    $keyToDelete = $_GET['key'];
    try {
        $r2Client->deleteObject(['Bucket' => R2_BUCKET_NAME, 'Key' => $keyToDelete]);
        $_SESSION['message'] = '✅ 文件 <strong>' . htmlspecialchars($keyToDelete) . '</strong> 已成功删除。';
    } catch (Aws\S3\Exception\S3Exception $e) {
        $_SESSION['message'] = "❌ 删除失败: " . $e->getMessage();
    }
    header('Location: index.php');
    exit;
}

// --- 文件上传处理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image_file'])) {
    $file = $_FILES['image_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK || !str_starts_with($file['type'], 'image/')) {
        $_SESSION['message'] = '❌ 文件上传失败或文件不是图片。';
    } else {
        $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $key = 'images/' . time() . '_' . $originalName . '.' . $extension; 

        try {
            $r2Client->putObject([
                'Bucket'      => R2_BUCKET_NAME,
                'Key'         => $key,
                'SourceFile'  => $file['tmp_name'],
                'ContentType' => $file['type'],
            ]);

            $publicUrl = CUSTOM_DOMAIN . '/' . $key;
            $_SESSION['message'] = '✅ 文件上传成功！<br>
                                    <strong>链接:</strong> <a href="' . htmlspecialchars($publicUrl) . '" target="_blank">' . htmlspecialchars($publicUrl) . '</a>';
        } catch (Aws\S3\Exception\S3Exception $e) {
            $_SESSION['message'] = "❌ 上传失败: " . $e->getMessage();
        }
    }
    header('Location: index.php');
    exit;
}

// ----------------------------------------------------
// IV. R2 文件列表获取函数
// ----------------------------------------------------
function listR2ImagesPaginated($client, $bucket, $pageSize, $token) {
    $params = [
        'Bucket'    => $bucket,
        'MaxKeys'   => $pageSize,
        'ContinuationToken' => $token,
    ];

    try {
        $result = $client->listObjectsV2($params);
        return [
            'Contents'          => $result['Contents'] ?? [],
            'NextContinuationToken' => $result['NextContinuationToken'] ?? null,
            'IsTruncated'       => $result['IsTruncated'] ?? false,
        ];
    } catch (Aws\S3\Exception\S3Exception $e) {
        return ['error' => '无法获取图片列表: ' . $e->getMessage()];
    }
}

$listResult = listR2ImagesPaginated($r2Client, R2_BUCKET_NAME, $pageSize, $continuationToken);
$imageList = $listResult['Contents'] ?? [];
$nextContinuationToken = $listResult['NextContinuationToken'] ?? null;
$isTruncated = $listResult['IsTruncated'] ?? false;

// ----------------------------------------------------
// V. 管理界面 HTML 输出
// ----------------------------------------------------
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>R2 图片管理面板</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLMDJc5GIsBqfKx128p/DkL+x5y5t1eKq90jWk55z4F4B3F11I8t6M6C2f51fGg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">☁️ R2 图片管理</a>
        <div class="d-flex">
            <span class="navbar-text me-3 text-white">
                欢迎您，管理员！
            </span>
            <a class="btn btn-outline-light" href="?action=logout">退出登录</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            
            <h1 class="mb-4 text-center">🚀 Cloudflare R2 文件管理</h1>

            <?php if ($message): ?>
                <div class="alert <?= str_starts_with($message, '✅') ? 'alert-success' : 'alert-danger' ?>" role="alert">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-lg mb-5">
                <div class="card-header bg-success text-white">
                    <strong>⬆️ 图片上传区 (单文件)</strong>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="imageFile" class="form-label">选择图片文件</label>
                            <input class="form-control" type="file" id="imageFile" name="image_file" accept="image/*" required>
                        </div>
                        <p class="text-muted">文件将通过您的自定义域名 <code><?= CUSTOM_DOMAIN ?></code> 访问。</p>
                        <button type="submit" class="btn btn-primary w-100">上传到 R2</button>
                    </form>
                </div>
            </div>

            <h2 class="mt-5">📁 文件列表 (当前页显示 <?= count($imageList) ?> 个)</h2>
            <div class="card shadow-lg">
                <div class="card-body">
                    <?php if (isset($listResult['error'])): ?>
                        <div class="alert alert-danger"><?= $listResult['error'] ?></div>
                    <?php elseif (empty($imageList) && $currentPage == 1): ?>
                        <div class="alert alert-info">存储桶中没有文件。</div>
                    <?php else: ?>
                        
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr class="align-middle">
                                        <th>预览</th>
                                        <th>文件路径 (Key)</th>
                                        <th>修改时间</th>
                                        <th>大小 (KB)</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($imageList as $object): 
                                        $key = $object['Key'];
                                        // 存储完整的公共访问 URL
                                        $publicUrl = CUSTOM_DOMAIN . '/' . htmlspecialchars($key);
                                        $sizeKb = round($object['Size'] / 1024, 2);
                                        $lastModified = date('Y-m-d H:i:s', strtotime($object['LastModified']));
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="#" data-bs-toggle="modal" data-bs-target="#imageModal" data-image-url="<?= $publicUrl ?>">
                                                <img src="<?= $publicUrl ?>" alt="Preview" style="max-height: 50px; max-width: 50px; border-radius: 5px;">
                                            </a>
                                        </td>
                                        <td class="text-break"><code><?= htmlspecialchars($key) ?></code></td>
                                        <td><?= $lastModified ?></td>
                                        <td><?= $sizeKb ?> KB</td>
                                        <td>
                                            <button class="btn btn-sm btn-info me-2 copy-btn" 
                                                    data-clipboard-text="<?= $publicUrl ?>"
                                                    data-bs-toggle="tooltip" data-bs-placement="top" title="已复制!">
                                                <i class="fas fa-copy"></i> 复制
                                            </button>
                                            
                                            <a href="?action=delete&key=<?= urlencode($key) ?>" 
                                               class="btn btn-sm btn-danger"
                                               onclick="return confirm('您确定要删除文件: <?= addslashes($key) ?> 吗？');">
                                                <i class="fas fa-trash"></i> 删除
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <nav aria-label="文件分页">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= ($currentPage > 1) ? '' : 'disabled' ?>">
                                    <a class="page-link" href="?page=1" aria-label="Previous">
                                        <span aria-hidden="true">&laquo; 回到首页</span>
                                    </a>
                                </li>
                                <li class="page-item active" aria-current="page">
                                    <span class="page-link">第 <?= $currentPage ?> 页</span>
                                </li>
                                <?php
                                $nextPageUrl = '';
                                $nextPageActive = false;
                                if ($isTruncated && $nextContinuationToken) {
                                    $nextPageUrl = '?page=' . ($currentPage + 1) . '&token=' . urlencode($nextContinuationToken);
                                    $nextPageActive = true;
                                }
                                ?>
                                <li class="page-item <?= $nextPageActive ? '' : 'disabled' ?>">
                                    <a class="page-link" href="<?= $nextPageUrl ?>" aria-label="Next">
                                        <span aria-hidden="true">下一页 &raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="imageModalLabel">文件预览</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <img id="modalImage" src="" class="img-fluid" alt="Full Image" style="max-height: 80vh;">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.11/clipboard.min.js"></script>
<?php
// index.php - Cloudflare R2 文件管理面板 (最终版本 - 优化复制链接和缩放)

// ----------------------------------------------------
// I. 配置与依赖加载
// ----------------------------------------------------
require_once 'r2_client.php';
require_once 'config.php'; // 包含 R2 凭证, ADMIN_USERNAME, ADMIN_PASSWORD_HASH

session_start();

// --- PHP 运行时配置 ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED); 
ini_set('memory_limit', '256M'); 

// --- 分页配置 ---
$pageSize = 20; 
$continuationToken = $_GET['token'] ?? null;
$currentPage = (int)($_GET['page'] ?? 1); 

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// ----------------------------------------------------
// II. 认证和会话逻辑
// ----------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    if ($username === ADMIN_USERNAME && defined('ADMIN_PASSWORD_HASH') && password_verify($password, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true); 
        $_SESSION['is_logged_in'] = true;
        $_SESSION['message'] = '✅ 登录成功！';
    } else {
        $_SESSION['message'] = '❌ 登录失败：用户名或密码错误。';
    }
    header('Location: index.php');
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit();
}

// --- 未登录界面 (未登录时终止脚本并显示登录表单) ---
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    echo '<!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="UTF-8">
        <title>R2 图片管理 - 登录</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>body { padding-top: 50px; background-color: #f8f9fa; }</style>
    </head>
    <body>
        <div class="container col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white"><h3>🔐 R2 管理系统登录</h3></div>
                <div class="card-body">
                    ' . ($message ? '<div class="alert alert-danger">' . $message . '</div>' : '') . '
                    <form method="POST">
                        <input type="hidden" name="action" value="login">
                        <div class="mb-3">
                            <label for="username" class="form-label">用户名</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">密码</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100">登录</button>
                    </form>
                </div>
            </div>
        </div>
    </body>
    </html>';
    exit();
}

// ----------------------------------------------------
// III. R2 客户端操作 (登录后执行)
// ----------------------------------------------------

$r2Client = getR2Client();

// --- 文件删除处理 (单文件) ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['key'])) {
    $keyToDelete = $_GET['key'];
    try {
        $r2Client->deleteObject(['Bucket' => R2_BUCKET_NAME, 'Key' => $keyToDelete]);
        $_SESSION['message'] = '✅ 文件 <strong>' . htmlspecialchars($keyToDelete) . '</strong> 已成功删除。';
    } catch (Aws\S3\Exception\S3Exception $e) {
        $_SESSION['message'] = "❌ 删除失败: " . $e->getMessage();
    }
    header('Location: index.php');
    exit;
}

// --- 文件上传处理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image_file'])) {
    $file = $_FILES['image_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK || !str_starts_with($file['type'], 'image/')) {
        $_SESSION['message'] = '❌ 文件上传失败或文件不是图片。';
    } else {
        $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $key = 'images/' . time() . '_' . $originalName . '.' . $extension; 

        try {
            $r2Client->putObject([
                'Bucket'      => R2_BUCKET_NAME,
                'Key'         => $key,
                'SourceFile'  => $file['tmp_name'],
                'ContentType' => $file['type'],
            ]);

            $publicUrl = CUSTOM_DOMAIN . '/' . $key;
            $_SESSION['message'] = '✅ 文件上传成功！<br>
                                    <strong>链接:</strong> <a href="' . htmlspecialchars($publicUrl) . '" target="_blank">' . htmlspecialchars($publicUrl) . '</a>';
        } catch (Aws\S3\Exception\S3Exception $e) {
            $_SESSION['message'] = "❌ 上传失败: " . $e->getMessage();
        }
    }
    header('Location: index.php');
    exit;
}

// ----------------------------------------------------
// IV. R2 文件列表获取函数
// ----------------------------------------------------
function listR2ImagesPaginated($client, $bucket, $pageSize, $token) {
    $params = [
        'Bucket'    => $bucket,
        'MaxKeys'   => $pageSize,
        'ContinuationToken' => $token,
    ];

    try {
        $result = $client->listObjectsV2($params);
        return [
            'Contents'          => $result['Contents'] ?? [],
            'NextContinuationToken' => $result['NextContinuationToken'] ?? null,
            'IsTruncated'       => $result['IsTruncated'] ?? false,
        ];
    } catch (Aws\S3\Exception\S3Exception $e) {
        return ['error' => '无法获取图片列表: ' . $e->getMessage()];
    }
}

$listResult = listR2ImagesPaginated($r2Client, R2_BUCKET_NAME, $pageSize, $continuationToken);
$imageList = $listResult['Contents'] ?? [];
$nextContinuationToken = $listResult['NextContinuationToken'] ?? null;
$isTruncated = $listResult['IsTruncated'] ?? false;

// ----------------------------------------------------
// V. 管理界面 HTML 输出
// ----------------------------------------------------
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>R2 图片管理面板</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLMDJc5GIsBqfKx128p/DkL+x5y5t1eKq90jWk55z4F4B3F11I8t6M6C2f51fGg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">☁️ R2 图片管理</a>
        <div class="d-flex">
            <span class="navbar-text me-3 text-white">
                欢迎您，管理员！
            </span>
            <a class="btn btn-outline-light" href="?action=logout">退出登录</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            
            <h1 class="mb-4 text-center">🚀 Cloudflare R2 文件管理</h1>

            <?php if ($message): ?>
                <div class="alert <?= str_starts_with($message, '✅') ? 'alert-success' : 'alert-danger' ?>" role="alert">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-lg mb-5">
                <div class="card-header bg-success text-white">
                    <strong>⬆️ 图片上传区 (单文件)</strong>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="imageFile" class="form-label">选择图片文件</label>
                            <input class="form-control" type="file" id="imageFile" name="image_file" accept="image/*" required>
                        </div>
                        <p class="text-muted">文件将通过您的自定义域名 <code><?= CUSTOM_DOMAIN ?></code> 访问。</p>
                        <button type="submit" class="btn btn-primary w-100">上传到 R2</button>
                    </form>
                </div>
            </div>

            <h2 class="mt-5">📁 文件列表 (当前页显示 <?= count($imageList) ?> 个)</h2>
            <div class="card shadow-lg">
                <div class="card-body">
                    <?php if (isset($listResult['error'])): ?>
                        <div class="alert alert-danger"><?= $listResult['error'] ?></div>
                    <?php elseif (empty($imageList) && $currentPage == 1): ?>
                        <div class="alert alert-info">存储桶中没有文件。</div>
                    <?php else: ?>
                        
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr class="align-middle">
                                        <th>预览</th>
                                        <th>文件路径 (Key)</th>
                                        <th>修改时间</th>
                                        <th>大小 (KB)</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($imageList as $object): 
                                        $key = $object['Key'];
                                        // 存储完整的公共访问 URL
                                        $publicUrl = CUSTOM_DOMAIN . '/' . htmlspecialchars($key);
                                        $sizeKb = round($object['Size'] / 1024, 2);
                                        $lastModified = date('Y-m-d H:i:s', strtotime($object['LastModified']));
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="#" data-bs-toggle="modal" data-bs-target="#imageModal" data-image-url="<?= $publicUrl ?>">
                                                <img src="<?= $publicUrl ?>" alt="Preview" style="max-height: 50px; max-width: 50px; border-radius: 5px;">
                                            </a>
                                        </td>
                                        <td class="text-break"><code><?= htmlspecialchars($key) ?></code></td>
                                        <td><?= $lastModified ?></td>
                                        <td><?= $sizeKb ?> KB</td>
                                        <td>
                                            <button class="btn btn-sm btn-info me-2 copy-btn" 
                                                    data-clipboard-text="<?= $publicUrl ?>"
                                                    data-bs-toggle="tooltip" data-bs-placement="top" title="已复制!">
                                                <i class="fas fa-copy"></i> 复制
                                            </button>
                                            
                                            <a href="?action=delete&key=<?= urlencode($key) ?>" 
                                               class="btn btn-sm btn-danger"
                                               onclick="return confirm('您确定要删除文件: <?= addslashes($key) ?> 吗？');">
                                                <i class="fas fa-trash"></i> 删除
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <nav aria-label="文件分页">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= ($currentPage > 1) ? '' : 'disabled' ?>">
                                    <a class="page-link" href="?page=1" aria-label="Previous">
                                        <span aria-hidden="true">&laquo; 回到首页</span>
                                    </a>
                                </li>
                                <li class="page-item active" aria-current="page">
                                    <span class="page-link">第 <?= $currentPage ?> 页</span>
                                </li>
                                <?php
                                $nextPageUrl = '';
                                $nextPageActive = false;
                                if ($isTruncated && $nextContinuationToken) {
                                    $nextPageUrl = '?page=' . ($currentPage + 1) . '&token=' . urlencode($nextContinuationToken);
                                    $nextPageActive = true;
                                }
                                ?>
                                <li class="page-item <?= $nextPageActive ? '' : 'disabled' ?>">
                                    <a class="page-link" href="<?= $nextPageUrl ?>" aria-label="Next">
                                        <span aria-hidden="true">下一页 &raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="imageModalLabel">文件预览</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <img id="modalImage" src="" class="img-fluid" alt="Full Image" style="max-height: 80vh;">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.11/clipboard.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- 1. 图片放大模态框逻辑 ---
    const imageModal = document.getElementById('imageModal');
    if (imageModal) {
        imageModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const imageUrl = button.getAttribute('data-image-url');
            
            const modalImage = imageModal.querySelector('#modalImage');
            const modalTitle = imageModal.querySelector('#imageModalLabel');
            
            modalImage.src = imageUrl;
            // 在标题中显示文件名
            const fileName = imageUrl.substring(imageUrl.lastIndexOf('/') + 1);
            modalTitle.textContent = `文件预览: ${fileName}`;
        });
    }

    // --- 2. 复制按钮逻辑 (使用 Clipboard.js) ---
    // 初始化 Clipboard.js
    const clipboard = new ClipboardJS('.copy-btn');
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

    clipboard.on('success', function(e) {
        // 复制成功后显示 Tooltip (提示已复制)
        const tooltipInstance = bootstrap.Tooltip.getInstance(e.trigger);
        if (tooltipInstance) {
            tooltipInstance.show(); // 显示提示
            
            // 2秒后隐藏提示
            setTimeout(() => {
                tooltipInstance.hide();
            }, 1000);
        }
        e.clearSelection();
    });

    clipboard.on('error', function(e) {
        // 复制失败（通常是浏览器权限问题），提示用户手动复制
        alert('自动复制失败，请手动复制图片链接: ' + e.trigger.getAttribute('data-clipboard-text'));
    });
});
</script>

</body>
</html>
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- 1. 图片放大模态框逻辑 ---
    const imageModal = document.getElementById('imageModal');
    if (imageModal) {
        imageModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const imageUrl = button.getAttribute('data-image-url');
            
            const modalImage = imageModal.querySelector('#modalImage');
            const modalTitle = imageModal.querySelector('#imageModalLabel');
            
            modalImage.src = imageUrl;
            // 在标题中显示文件名
            const fileName = imageUrl.substring(imageUrl.lastIndexOf('/') + 1);
            modalTitle.textContent = `文件预览: ${fileName}`;
        });
    }

    // --- 2. 复制按钮逻辑 (使用 Clipboard.js) ---
    // 初始化 Clipboard.js
    const clipboard = new ClipboardJS('.copy-btn');
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

    clipboard.on('success', function(e) {
        // 复制成功后显示 Tooltip (提示已复制)
        const tooltipInstance = bootstrap.Tooltip.getInstance(e.trigger);
        if (tooltipInstance) {
            tooltipInstance.show(); // 显示提示
            
            // 2秒后隐藏提示
            setTimeout(() => {
                tooltipInstance.hide();
            }, 1000);
        }
        e.clearSelection();
    });

    clipboard.on('error', function(e) {
        // 复制失败（通常是浏览器权限问题），提示用户手动复制
        alert('自动复制失败，请手动复制图片链接: ' + e.trigger.getAttribute('data-clipboard-text'));
    });
});
</script>

</body>
</html></div>
            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="imageModalLabel">文件预览</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <img id="modalImage" src="" class="img-fluid" alt="Full Image" style="max-height: 80vh;">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.11/clipboard.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- 1. 图片放大模态框逻辑 ---
    const imageModal = document.getElementById('imageModal');
    if (imageModal) {
        imageModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const imageUrl = button.getAttribute('data-image-url');
            
            const modalImage = imageModal.querySelector('#modalImage');
            const modalTitle = imageModal.querySelector('#imageModalLabel');
            
            modalImage.src = imageUrl;
            // 在标题中显示文件名
            const fileName = imageUrl.substring(imageUrl.lastIndexOf('/') + 1);
            modalTitle.textContent = `文件预览: ${fileName}`;
        });
    }

    // --- 2. 复制按钮逻辑 (使用 Clipboard.js) ---
    // 初始化 Clipboard.js
    const clipboard = new ClipboardJS('.copy-btn');
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

    clipboard.on('success', function(e) {
        // 复制成功后显示 Tooltip (提示已复制)
        const tooltipInstance = bootstrap.Tooltip.getInstance(e.trigger);
        if (tooltipInstance) {
            tooltipInstance.show(); // 显示提示
            
            // 2秒后隐藏提示
            setTimeout(() => {
                tooltipInstance.hide();
            }, 1000);
        }
        e.clearSelection();
    });

    clipboard.on('error', function(e) {
        // 复制失败（通常是浏览器权限问题），提示用户手动复制
        alert('自动复制失败，请手动复制图片链接: ' + e.trigger.getAttribute('data-clipboard-text'));
    });
});
</script>

</body>
</html> required>
                        </div>
                        <p class="text-muted">文件将通过您的自定义域名 <code><?= CUSTOM_DOMAIN ?></code> 访问。</p>
                        <button type="submit" class="btn btn-primary w-100">上传到 R2</button>
                    </form>
                </div>
            </div>

            <h2 class="mt-5">📁 文件列表 (当前页显示 <?= count($imageList) ?> 个)</h2>
            <div class="card shadow-lg">
                <div class="card-body">
                    <?php if (isset($listResult['error'])): ?>
                        <div class="alert alert-danger"><?= $listResult['error'] ?></div>
                    <?php elseif (empty($imageList) && $currentPage == 1): ?>
                        <div class="alert alert-info">存储桶中没有文件。</div>
                    <?php else: ?>
                        
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr class="align-middle">
                                        <th>预览</th>
                                        <th>文件路径 (Key)</th>
                                        <th>修改时间</th>
                                        <th>大小 (KB)</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($imageList as $object): 
                                        $key = $object['Key'];
                                        // 存储完整的公共访问 URL
                                        $publicUrl = CUSTOM_DOMAIN . '/' . htmlspecialchars($key);
                                        $sizeKb = round($object['Size'] / 1024, 2);
                                        $lastModified = date('Y-m-d H:i:s', strtotime($object['LastModified']));
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="#" data-bs-toggle="modal" data-bs-target="#imageModal" data-image-url="<?= $publicUrl ?>">
                                                <img src="<?= $publicUrl ?>" alt="Preview" style="max-height: 50px; max-width: 50px; border-radius: 5px;">
                                            </a>
                                        </td>
                                        <td class="text-break"><code><?= htmlspecialchars($key) ?></code></td>
                                        <td><?= $lastModified ?></td>
                                        <td><?= $sizeKb ?> KB</td>
                                        <td>
                                            <button class="btn btn-sm btn-info me-2 copy-btn" 
                                                    data-clipboard-text="<?= $publicUrl ?>"
                                                    data-bs-toggle="tooltip" data-bs-placement="top" title="已复制!">
                                                <i class="fas fa-copy"></i> 复制
                                            </button>
                                            
                                            <a href="?action=delete&key=<?= urlencode($key) ?>" 
                                               class="btn btn-sm btn-danger"
                                               onclick="return confirm('您确定要删除文件: <?= addslashes($key) ?> 吗？');">
                                                <i class="fas fa-trash"></i> 删除
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <nav aria-label="文件分页">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= ($currentPage > 1) ? '' : 'disabled' ?>">
                                    <a class="page-link" href="?page=1" aria-label="Previous">
                                        <span aria-hidden="true">&laquo; 回到首页</span>
                                    </a>
                                </li>
                                <li class="page-item active" aria-current="page">
                                    <span class="page-link">第 <?= $currentPage ?> 页</span>
                                </li>
                                <?php
                                $nextPageUrl = '';
                                $nextPageActive = false;
                                if ($isTruncated && $nextContinuationToken) {
                                    $nextPageUrl = '?page=' . ($currentPage + 1) . '&token=' . urlencode($nextContinuationToken);
                                    $nextPageActive = true;
                                }
                                ?>
                                <li class="page-item <?= $nextPageActive ? '' : 'disabled' ?>">
                                    <a class="page-link" href="<?= $nextPageUrl ?>" aria-label="Next">
                                        <span aria-hidden="true">下一页 &raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="imageModalLabel">文件预览</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <img id="modalImage" src="" class="img-fluid" alt="Full Image" style="max-height: 80vh;">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.11/clipboard.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- 1. 图片放大模态框逻辑 ---
    const imageModal = document.getElementById('imageModal');
    if (imageModal) {
        imageModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const imageUrl = button.getAttribute('data-image-url');
            
            const modalImage = imageModal.querySelector('#modalImage');
            const modalTitle = imageModal.querySelector('#imageModalLabel');
            
            modalImage.src = imageUrl;
            // 在标题中显示文件名
            const fileName = imageUrl.substring(imageUrl.lastIndexOf('/') + 1);
            modalTitle.textContent = `文件预览: ${fileName}`;
        });
    }

    // --- 2. 复制按钮逻辑 (使用 Clipboard.js) ---
    // 初始化 Clipboard.js
    const clipboard = new ClipboardJS('.copy-btn');<?php
// index.php - Cloudflare R2 文件管理面板 (最终版本 - 优化复制链接和缩放)

// ----------------------------------------------------
// I. 配置与依赖加载
// ----------------------------------------------------
require_once 'r2_client.php';
require_once 'config.php'; // 包含 R2 凭证, ADMIN_USERNAME, ADMIN_PASSWORD_HASH

session_start();

// --- PHP 运行时配置 ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED); 
ini_set('memory_limit', '256M'); 

// --- 分页配置 ---
$pageSize = 20; 
$continuationToken = $_GET['token'] ?? null;
$currentPage = (int)($_GET['page'] ?? 1); 

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// ----------------------------------------------------
// II. 认证和会话逻辑
// ----------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    if ($username === ADMIN_USERNAME && defined('ADMIN_PASSWORD_HASH') && password_verify($password, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true); 
        $_SESSION['is_logged_in'] = true;
        $_SESSION['message'] = '✅ 登录成功！';
    } else {
        $_SESSION['message'] = '❌ 登录失败：用户名或密码错误。';
    }
    header('Location: index.php');
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit();
}

// --- 未登录界面 (未登录时终止脚本并显示登录表单) ---
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    echo '<!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="UTF-8">
        <title>R2 图片管理 - 登录</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>body { padding-top: 50px; background-color: #f8f9fa; }</style>
    </head>
    <body>
        <div class="container col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white"><h3>🔐 R2 管理系统登录</h3></div>
                <div class="card-body">
                    ' . ($message ? '<div class="alert alert-danger">' . $message . '</div>' : '') . '
                    <form method="POST">
                        <input type="hidden" name="action" value="login">
                        <div class="mb-3">
                            <label for="username" class="form-label">用户名</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">密码</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100">登录</button>
                    </form>
                </div>
            </div>
        </div>
    </body>
    </html>';
    exit();
}

// ----------------------------------------------------
// III. R2 客户端操作 (登录后执行)
// ----------------------------------------------------

$r2Client = getR2Client();

// --- 文件删除处理 (单文件) ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['key'])) {
    $keyToDelete = $_GET['key'];
    try {
        $r2Client->deleteObject(['Bucket' => R2_BUCKET_NAME, 'Key' => $keyToDelete]);
        $_SESSION['message'] = '✅ 文件 <strong>' . htmlspecialchars($keyToDelete) . '</strong> 已成功删除。';
    } catch (Aws\S3\Exception\S3Exception $e) {
        $_SESSION['message'] = "❌ 删除失败: " . $e->getMessage();
    }
    header('Location: index.php');
    exit;
}

// --- 文件上传处理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image_file'])) {
    $file = $_FILES['image_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK || !str_starts_with($file['type'], 'image/')) {
        $_SESSION['message'] = '❌ 文件上传失败或文件不是图片。';
    } else {
        $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $key = 'images/' . time() . '_' . $originalName . '.' . $extension; 

        try {
            $r2Client->putObject([
                'Bucket'      => R2_BUCKET_NAME,
                'Key'         => $key,
                'SourceFile'  => $file['tmp_name'],
                'ContentType' => $file['type'],
            ]);

            $publicUrl = CUSTOM_DOMAIN . '/' . $key;
            $_SESSION['message'] = '✅ 文件上传成功！<br>
                                    <strong>链接:</strong> <a href="' . htmlspecialchars($publicUrl) . '" target="_blank">' . htmlspecialchars($publicUrl) . '</a>';
        } catch (Aws\S3\Exception\S3Exception $e) {
            $_SESSION['message'] = "❌ 上传失败: " . $e->getMessage();
        }
    }
    header('Location: index.php');
    exit;
}

// ----------------------------------------------------
// IV. R2 文件列表获取函数
// ----------------------------------------------------
function listR2ImagesPaginated($client, $bucket, $pageSize, $token) {
    $params = [
        'Bucket'    => $bucket,
        'MaxKeys'   => $pageSize,
        'ContinuationToken' => $token,
    ];

    try {
        $result = $client->listObjectsV2($params);
        return [
            'Contents'          => $result['Contents'] ?? [],
            'NextContinuationToken' => $result['NextContinuationToken'] ?? null,
            'IsTruncated'       => $result['IsTruncated'] ?? false,
        ];
    } catch (Aws\S3\Exception\S3Exception $e) {
        return ['error' => '无法获取图片列表: ' . $e->getMessage()];
    }
}

$listResult = listR2ImagesPaginated($r2Client, R2_BUCKET_NAME, $pageSize, $continuationToken);
$imageList = $listResult['Contents'] ?? [];
$nextContinuationToken = $listResult['NextContinuationToken'] ?? null;
$isTruncated = $listResult['IsTruncated'] ?? false;

// ----------------------------------------------------
// V. 管理界面 HTML 输出
// ----------------------------------------------------
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>R2 图片管理面板</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLMDJc5GIsBqfKx128p/DkL+x5y5t1eKq90jWk55z4F4B3F11I8t6M6C2f51fGg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">☁️ R2 图片管理</a>
        <div class="d-flex">
            <span class="navbar-text me-3 text-white">
                欢迎您，管理员！
            </span>
            <a class="btn btn-outline-light" href="?action=logout">退出登录</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            
            <h1 class="mb-4 text-center">🚀 Cloudflare R2 文件管理</h1>

            <?php if ($message): ?>
                <div class="alert <?= str_starts_with($message, '✅') ? 'alert-success' : 'alert-danger' ?>" role="alert">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-lg mb-5">
                <div class="card-header bg-success text-white">
                    <strong>⬆️ 图片上传区 (单文件)</strong>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="imageFile" class="form-label">选择图片文件</label>
                            <input class="form-control" type="file" id="imageFile" name="image_file" accept="image/*"<?php
// index.php - Cloudflare R2 文件管理面板 (最终版本 - 优化复制链接和缩放)

// ----------------------------------------------------
// I. 配置与依赖加载
// ----------------------------------------------------
require_once 'r2_client.php';
require_once 'config.php'; // 包含 R2 凭证, ADMIN_USERNAME, ADMIN_PASSWORD_HASH

session_start();

// --- PHP 运行时配置 ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED); 
ini_set('memory_limit', '256M'); 

// --- 分页配置 ---
$pageSize = 20; 
$continuationToken = $_GET['token'] ?? null;
$currentPage = (int)($_GET['page'] ?? 1); 

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// ----------------------------------------------------
// II. 认证和会话逻辑
// ----------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    if ($username === ADMIN_USERNAME && defined('ADMIN_PASSWORD_HASH') && password_verify($password, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true); 
        $_SESSION['is_logged_in'] = true;
        $_SESSION['message'] = '✅ 登录成功！';
    } else {
        $_SESSION['message'] = '❌ 登录失败：用户名或密码错误。';
    }
    header('Location: index.php');
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit();
}

// --- 未登录界面 (未登录时终止脚本并显示登录表单) ---
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    echo '<!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="UTF-8">
        <title>R2 图片管理 - 登录</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>body { padding-top: 50px; background-color: #f8f9fa; }</style>
    </head>
    <body>
        <div class="container col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white"><h3>🔐 R2 管理系统登录</h3></div>
                <div class="card-body">
                    ' . ($message ? '<div class="alert alert-danger">' . $message . '</div>' : '') . '
                    <form method="POST">
                        <input type="hidden" name="action" value="login">
                        <div class="mb-3">
                            <label for="username" class="form-label">用户名</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">密码</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100">登录</button>
                    </form>
                </div>
            </div>
        </div>
    </body>
    </html>';
    exit();
}

// ----------------------------------------------------
// III. R2 客户端操作 (登录后执行)
// ----------------------------------------------------

$r2Client = getR2Client();

// --- 文件删除处理 (单文件) ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['key'])) {
    $keyToDelete = $_GET['key'];
    try {
        $r2Client->deleteObject(['Bucket' => R2_BUCKET_NAME, 'Key' => $keyToDelete]);
        $_SESSION['message'] = '✅ 文件 <strong>' . htmlspecialchars($keyToDelete) . '</strong> 已成功删除。';
    } catch (Aws\S3\Exception\S3Exception $e) {
        $_SESSION['message'] = "❌ 删除失败: " . $e->getMessage();
    }
    header('Location: index.php');
    exit;
}

// --- 文件上传处理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image_file'])) {
    $file = $_FILES['image_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK || !str_starts_with($file['type'], 'image/')) {
        $_SESSION['message'] = '❌ 文件上传失败或文件不是图片。';
    } else {
        $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $key = 'images/' . time() . '_' . $originalName . '.' . $extension; 

        try {
            $r2Client->putObject([
                'Bucket'      => R2_BUCKET_NAME,
                'Key'         => $key,
                'SourceFile'  => $file['tmp_name'],
                'ContentType' => $file['type'],
            ]);

            $publicUrl = CUSTOM_DOMAIN . '/' . $key;
            $_SESSION['message'] = '✅ 文件上传成功！<br>
                                    <strong>链接:</strong> <a href="' . htmlspecialchars($publicUrl) . '" target="_blank">' . htmlspecialchars($publicUrl) . '</a>';
        } catch (Aws\S3\Exception\S3Exception $e) {
            $_SESSION['message'] = "❌ 上传失败: " . $e->getMessage();
        }
    }
    header('Location: index.php');
    exit;
}

// ----------------------------------------------------
// IV. R2 文件列表获取函数
// ----------------------------------------------------
function listR2ImagesPaginated($client, $bucket, $pageSize, $token) {
    $params = [
        'Bucket'    => $bucket,
        'MaxKeys'   => $pageSize,
        'ContinuationToken' => $token,
    ];

    try {
        $result = $client->listObjectsV2($params);
        return [
            'Contents'          => $result['Contents'] ?? [],
            'NextContinuationToken' => $result['NextContinuationToken'] ?? null,
            'IsTruncated'       => $result['IsTruncated'] ?? false,
        ];
    } catch (Aws\S3\Exception\S3Exception $e) {
        return ['error' => '无法获取图片列表: ' . $e->getMessage()];
    }
}

$listResult = listR2ImagesPaginated($r2Client, R2_BUCKET_NAME, $pageSize, $continuationToken);
$imageList = $listResult['Contents'] ?? [];
$nextContinuationToken = $listResult['NextContinuationToken'] ?? null;
$isTruncated = $listResult['IsTruncated'] ?? false;

// ----------------------------------------------------
// V. 管理界面 HTML 输出
// ----------------------------------------------------
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>R2 图片管理面板</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLMDJc5GIsBqfKx128p/DkL+x5y5t1eKq90jWk55z4F4B3F11I8t6M6C2f51fGg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">☁️ R2 图片管理</a>
        <div class="d-flex">
            <span class="navbar-text me-3 text-white">
                欢迎您，管理员！
            </span>
            <a class="btn btn-outline-light" href="?action=logout">退出登录</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            
            <h1 class="mb-4 text-center">🚀 Cloudflare R2 文件管理</h1>

            <?php if ($message): ?>
                <div class="alert <?= str_starts_with($message, '✅') ? 'alert-success' : 'alert-danger' ?>" role="alert">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-lg mb-5">
                <div class="card-header bg-success text-white">
                    <strong>⬆️ 图片上传区 (单文件)</strong>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="imageFile" class="form-label">选择图片文件</label>
                            <input class="form-control" type="file" id="imageFile" name="image_file" accept="image/*" required>
                        </div>
                        <p class="text-muted">文件将通过您的自定义域名 <code><?= CUSTOM_DOMAIN ?></code> 访问。</p>
                        <button type="submit" class="btn btn-primary w-100">上传到 R2</button>
                    </form>
                </div>
            </div>

            <h2 class="mt-5">📁 文件列表 (当前页显示 <?= count($imageList) ?> 个)</h2>
            <div class="card shadow-lg">
                <div class="card-body">
                    <?php if (isset($listResult['error'])): ?>
                        <div class="alert alert-danger"><?= $listResult['error'] ?></div>
                    <?php elseif (empty($imageList) && $currentPage == 1): ?>
                        <div class="alert alert-info">存储桶中没有文件。</div>
                    <?php else: ?>
                        
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr class="align-middle">
                                        <th>预览</th>
                                        <th>文件路径 (Key)</th>
                                        <th>修改时间</th>
                                        <th>大小 (KB)</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($imageList as $object): 
                                        $key = $object['Key'];
                                        // 存储完整的公共访问 URL
                                        $publicUrl = CUSTOM_DOMAIN . '/' . htmlspecialchars($key);
                                        $sizeKb = round($object['Size'] / 1024, 2);
                                        $lastModified = date('Y-m-d H:i:s', strtotime($object['LastModified']));
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="#" data-bs-toggle="modal" data-bs-target="#imageModal" data-image-url="<?= $publicUrl ?>">
                                                <img src="<?= $publicUrl ?>" alt="Preview" style="max-height: 50px; max-width: 50px; border-radius: 5px;">
                                            </a>
                                        </td>
                                        <td class="text-break"><code><?= htmlspecialchars($key) ?></code></td>
                                        <td><?= $lastModified ?></td>
                                        <td><?= $sizeKb ?> KB</td>
                                        <td>
                                            <button class="btn btn-sm btn-info me-2 copy-btn" 
                                                    data-clipboard-text="<?= $publicUrl ?>"
                                                    data-bs-toggle="tooltip" data-bs-placement="top" title="已复制!">
                                                <i class="fas fa-copy"></i> 复制
                                            </button>
                                            
                                            <a href="?action=delete&key=<?= urlencode($key) ?>" 
                                               class="btn btn-sm btn-danger"
                                               onclick="return confirm('您确定要删除文件: <?= addslashes($key) ?> 吗？');">
                                                <i class="fas fa-trash"></i> 删除
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <nav aria-label="文件分页">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= ($currentPage > 1) ? '' : 'disabled' ?>">
                                    <a class="page-link" href="?page=1" aria-label="Previous">
                                        <span aria-hidden="true">&laquo; 回到首页</span>
                                    </a>
                                </li>
                                <li class="page-item active" aria-current="page">
                                    <span class="page-link">第 <?= $currentPage ?> 页</span>
                                </li>
                                <?php
                                $nextPageUrl = '';
                                $nextPageActive = false;
                                if ($isTruncated && $nextContinuationToken) {
                                    $nextPageUrl = '?page=' . ($currentPage + 1) . '&token=' . urlencode($nextContinuationToken);
                                    $nextPageActive = true;
                                }
                                ?>
                                <li class="page-item <?= $nextPageActive ? '' : 'disabled' ?>">
                                    <a class="page-link" href="<?= $nextPageUrl ?>" aria-label="Next">
                                        <span aria-hidden="true">下一页 &raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php
// index.php - Cloudflare R2 文件管理面板 (最终版本 - 优化复制链接和缩放)

// ----------------------------------------------------
// I. 配置与依赖加载
// ----------------------------------------------------
require_once 'r2_client.php';
require_once 'config.php'; // 包含 R2 凭证, ADMIN_USERNAME, ADMIN_PASSWORD_HASH

session_start();

// --- PHP 运行时配置 ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED); 
ini_set('memory_limit', '256M'); 

// --- 分页配置 ---
$pageSize = 20; 
$continuationToken = $_GET['token'] ?? null;
$currentPage = (int)($_GET['page'] ?? 1); 

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// ----------------------------------------------------
// II. 认证和会话逻辑
// ----------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    if ($username === ADMIN_USERNAME && defined('ADMIN_PASSWORD_HASH') && password_verify($password, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true); 
        $_SESSION['is_logged_in'] = true;
        $_SESSION['message'] = '✅ 登录成功！';
    } else {
        $_SESSION['message'] = '❌ 登录失败：用户名或密码错误。';
    }
    header('Location: index.php');
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit();
}

// --- 未登录界面 (未登录时终止脚本并显示登录表单) ---
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    echo '<!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="UTF-8">
        <title>R2 图片管理 - 登录</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>body { padding-top: 50px; background-color: #f8f9fa; }</style>
    </head>
    <body>
        <div class="container col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white"><h3>🔐 R2 管理系统登录</h3></div>
                <div class="card-body">
                    ' . ($message ? '<div class="alert alert-danger">' . $message . '</div>' : '') . '
                    <form method="POST">
                        <input type="hidden" name="action" value="login">
                        <div class="mb-3">
                            <label for="username" class="form-label">用户名</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">密码</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100">登录</button>
                    </form>
                </div>
            </div>
        </div>
    </body>
    </html>';
    exit();
}

// ----------------------------------------------------
// III. R2 客户端操作 (登录后执行)
// ----------------------------------------------------

$r2Client = getR2Client();

// --- 文件删除处理 (单文件) ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['key'])) {
    $keyToDelete = $_GET['key'];
    try {
        $r2Client->deleteObject(['Bucket' => R2_BUCKET_NAME, 'Key' => $keyToDelete]);
        $_SESSION['message'] = '✅ 文件 <strong>' . htmlspecialchars($keyToDelete) . '</strong> 已成功删除。';
    } catch (Aws\S3\Exception\S3Exception $e) {
        $_SESSION['message'] = "❌ 删除失败: " . $e->getMessage();
    }
    header('Location: index.php');
    exit;
}

// --- 文件上传处理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image_file'])) {
    $file = $_FILES['image_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK || !str_starts_with($file['type'], 'image/')) {
        $_SESSION['message'] = '❌ 文件上传失败或文件不是图片。';
    } else {
        $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $key = 'images/' . time() . '_' . $originalName . '.' . $extension; 

        try {
            $r2Client->putObject([
                'Bucket'      => R2_BUCKET_NAME,
                'Key'         => $key,
                'SourceFile'  => $file['tmp_name'],
                'ContentType' => $file['type'],
            ]);

            $publicUrl = CUSTOM_DOMAIN . '/' . $key;
            $_SESSION['message'] = '✅ 文件上传成功！<br>
                                    <strong>链接:</strong> <a href="' . htmlspecialchars($publicUrl) . '" target="_blank">' . htmlspecialchars($publicUrl) . '</a>';
        } catch (Aws\S3\Exception\S3Exception $e) {
            $_SESSION['message'] = "❌ 上传失败: " . $e->getMessage();
        }
    }
    header('Location: index.php');
    exit;
}

// ----------------------------------------------------
// IV. R2 文件列表获取函数
// ----------------------------------------------------
function listR2ImagesPaginated($client, $bucket, $pageSize, $token) {
    $params = [
        'Bucket'    => $bucket,
        'MaxKeys'   => $pageSize,
        'ContinuationToken' => $token,
    ];

    try {
        $result = $client->listObjectsV2($params);
        return [
            'Contents'          => $result['Contents'] ?? [],
            'NextContinuationToken' => $result['NextContinuationToken'] ?? null,
            'IsTruncated'       => $result['IsTruncated'] ?? false,
        ];
    } catch (Aws\S3\Exception\S3Exception $e) {
        return ['error' => '无法获取图片列表: ' . $e->getMessage()];
    }
}

$listResult = listR2ImagesPaginated($r2Client, R2_BUCKET_NAME, $pageSize, $continuationToken);
$imageList = $listResult['Contents'] ?? [];
$nextContinuationToken = $listResult['NextContinuationToken'] ?? null;
$isTruncated = $listResult['IsTruncated'] ?? false;

// ----------------------------------------------------
// V. 管理界面 HTML 输出
// ----------------------------------------------------
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>R2 图片管理面板</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLMDJc5GIsBqfKx128p/DkL+x5y5t1eKq90jWk55z4F4B3F11I8t6M6C2f51fGg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">☁️ R2 图片管理</a>
        <div class="d-flex">
            <span class="navbar-text me-3 text-white">
                欢迎您，管理员！
            </span>
            <a class="btn btn-outline-light" href="?action=logout">退出登录</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            
            <h1 class="mb-4 text-center">🚀 Cloudflare R2 文件管理</h1>

            <?php if ($message): ?>
                <div class="alert <?= str_starts_with($message, '✅') ? 'alert-success' : 'alert-danger' ?>" role="alert">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-lg mb-5">
                <div class="card-header bg-success text-white">
                    <strong>⬆️ 图片上传区 (单文件)</strong>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="imageFile" class="form-label">选择图片文件</label>
                            <input class="form-control" type="file" id="imageFile" name="image_file" accept="image/*" required>
                        </div>
                        <p class="text-muted">文件将通过您的自定义域名 <code><?= CUSTOM_DOMAIN ?></code> 访问。</p>
                        <button type="submit" class="btn btn-primary w-100">上传到 R2</button>
                    </form>
                </div>
            </div>

            <h2 class="mt-5">📁 文件列表 (当前页显示 <?= count($imageList) ?> 个)</h2>
            <div class="card shadow-lg">
                <div class="card-body">
                    <?php if (isset($listResult['error'])): ?>
                        <div class="alert alert-danger"><?= $listResult['error'] ?></div>
                    <?php elseif (empty($imageList) && $currentPage == 1): ?>
                        <div class="alert alert-info">存储桶中没有文件。</div>
                    <?php else: ?>
                        
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr class="align-middle">
                                        <th>预览</th>
                                        <th>文件路径 (Key)</th>
                                        <th>修改时间</th>
                                        <th>大小 (KB)</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($imageList as $object): 
                                        $key = $object['Key'];
                                        // 存储完整的公共访问 URL
                                        $publicUrl = CUSTOM_DOMAIN . '/' . htmlspecialchars($key);
                                        $sizeKb = round($object['Size'] / 1024, 2);
                                        $lastModified = date('Y-m-d H:i:s', strtotime($object['LastModified']));
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="#" data-bs-toggle="modal" data-bs-target="#imageModal" data-image-url="<?= $publicUrl ?>">
                                                <img src="<?= $publicUrl ?>" alt="Preview" style="max-height: 50px; max-width: 50px; border-radius: 5px;">
                                            </a>
                                        </td>
                                        <td class="text-break"><code><?= htmlspecialchars($key) ?></code></td>
                                        <td><?= $lastModified ?></td>
                                        <td><?= $sizeKb ?> KB</td>
                                        <td>
                                            <button class="btn btn-sm btn-info me-2 copy-btn" 
                                                    data-clipboard-text="<?= $publicUrl ?>"
                                                    data-bs-toggle="tooltip" data-bs-placement="top" title="已复制!">
                                                <i class="fas fa-copy"></i> 复制
                                            </button>
                                            
                                            <a href="?action=delete&key=<?= urlencode($key) ?>" 
                                               class="btn btn-sm btn-danger"
                                               onclick="return confirm('您确定要删除文件: <?= addslashes($key) ?> 吗？');">
                                                <i class="fas fa-trash"></i> 删除
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <nav aria-label="文件分页">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= ($currentPage > 1) ? '' : 'disabled' ?>">
                                    <a class="page-link" href="?page=1" aria-label="Previous">
                                        <span aria-hidden="true">&laquo; 回到首页</span>
                                    </a>
                                </li>
                                <li class="page-item active" aria-current="page">
                                    <span class="page-link">第 <?= $currentPage ?> 页</span>
                                </li>
                                <?php
                                $nextPageUrl = '';
                                $nextPageActive = false;
                                if ($isTruncated && $nextContinuationToken) {
                                    $nextPageUrl = '?page=' . ($currentPage + 1) . '&token=' . urlencode($nextContinuationToken);
                                    $nextPageActive = true;
                                }
                                ?>
                                <li class="page-item <?= $nextPageActive ? '' : 'disabled' ?>">
                                    <a class="page-link" href="<?= $nextPageUrl ?>" aria-label="Next">
                                        <span aria-hidden="true">下一页 &raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="imageModalLabel">文件预览</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <img id="modalImage" src="" class="img-fluid" alt="Full Image" style="max-height: 80vh;">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.11/clipboard.min.js"></script>
<?php
// index.php - Cloudflare R2 文件管理面板 (最终版本 - 优化复制链接和缩放)

// ----------------------------------------------------
// I. 配置与依赖加载
// ----------------------------------------------------
require_once 'r2_client.php';
require_once 'config.php'; // 包含 R2 凭证, ADMIN_USERNAME, ADMIN_PASSWORD_HASH

session_start();

// --- PHP 运行时配置 ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED); 
ini_set('memory_limit', '256M'); 

// --- 分页配置 ---
$pageSize = 20; 
$continuationToken = $_GET['token'] ?? null;
$currentPage = (int)($_GET['page'] ?? 1); 

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// ----------------------------------------------------
// II. 认证和会话逻辑
// ----------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    if ($username === ADMIN_USERNAME && defined('ADMIN_PASSWORD_HASH') && password_verify($password, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true); 
        $_SESSION['is_logged_in'] = true;
        $_SESSION['message'] = '✅ 登录成功！';
    } else {
        $_SESSION['message'] = '❌ 登录失败：用户名或密码错误。';
    }
    header('Location: index.php');
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit();
}

// --- 未登录界面 (未登录时终止脚本并显示登录表单) ---
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    echo '<!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="UTF-8">
        <title>R2 图片管理 - 登录</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>body { padding-top: 50px; background-color: #f8f9fa; }</style>
    </head>
    <body>
        <div class="container col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white"><h3>🔐 R2 管理系统登录</h3></div>
                <div class="card-body">
                    ' . ($message ? '<div class="alert alert-danger">' . $message . '</div>' : '') . '
                    <form method="POST">
                        <input type="hidden" name="action" value="login">
                        <div class="mb-3">
                            <label for="username" class="form-label">用户名</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">密码</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100">登录</button>
                    </form>
                </div>
            </div>
        </div>
    </body>
    </html>';
    exit();
}

// ----------------------------------------------------
// III. R2 客户端操作 (登录后执行)
// ----------------------------------------------------

$r2Client = getR2Client();

// --- 文件删除处理 (单文件) ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['key'])) {
    $keyToDelete = $_GET['key'];
    try {
        $r2Client->deleteObject(['Bucket' => R2_BUCKET_NAME, 'Key' => $keyToDelete]);
        $_SESSION['message'] = '✅ 文件 <strong>' . htmlspecialchars($keyToDelete) . '</strong> 已成功删除。';
    } catch (Aws\S3\Exception\S3Exception $e) {
        $_SESSION['message'] = "❌ 删除失败: " . $e->getMessage();
    }
    header('Location: index.php');
    exit;
}

// --- 文件上传处理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image_file'])) {
    $file = $_FILES['image_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK || !str_starts_with($file['type'], 'image/')) {
        $_SESSION['message'] = '❌ 文件上传失败或文件不是图片。';
    } else {
        $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $key = 'images/' . time() . '_' . $originalName . '.' . $extension; 

        try {
            $r2Client->putObject([
                'Bucket'      => R2_BUCKET_NAME,
                'Key'         => $key,
                'SourceFile'  => $file['tmp_name'],
                'ContentType' => $file['type'],
            ]);

            $publicUrl = CUSTOM_DOMAIN . '/' . $key;
            $_SESSION['message'] = '✅ 文件上传成功！<br>
                                    <strong>链接:</strong> <a href="' . htmlspecialchars($publicUrl) . '" target="_blank">' . htmlspecialchars($publicUrl) . '</a>';
        } catch (Aws\S3\Exception\S3Exception $e) {
            $_SESSION['message'] = "❌ 上传失败: " . $e->getMessage();
        }
    }
    header('Location: index.php');
    exit;
}

// ----------------------------------------------------
// IV. R2 文件列表获取函数
// ----------------------------------------------------
function listR2ImagesPaginated($client, $bucket, $pageSize, $token) {
    $params = [
        'Bucket'    => $bucket,
        'MaxKeys'   => $pageSize,
        'ContinuationToken' => $token,
    ];

    try {
        $result = $client->listObjectsV2($params);
        return [
            'Contents'          => $result['Contents'] ?? [],
            'NextContinuationToken' => $result['NextContinuationToken'] ?? null,
            'IsTruncated'       => $result['IsTruncated'] ?? false,
        ];
    } catch (Aws\S3\Exception\S3Exception $e) {
        return ['error' => '无法获取图片列表: ' . $e->getMessage()];
    }
}

$listResult = listR2ImagesPaginated($r2Client, R2_BUCKET_NAME, $pageSize, $continuationToken);
$imageList = $listResult['Contents'] ?? [];
$nextContinuationToken = $listResult['NextContinuationToken'] ?? null;
$isTruncated = $listResult['IsTruncated'] ?? false;

// ----------------------------------------------------
// V. 管理界面 HTML 输出
// ----------------------------------------------------
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>R2 图片管理面板</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLMDJc5GIsBqfKx128p/DkL+x5y5t1eKq90jWk55z4F4B3F11I8t6M6C2f51fGg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">☁️ R2 图片管理</a>
        <div class="d-flex">
            <span class="navbar-text me-3 text-white">
                欢迎您，管理员！
            </span>
            <a class="btn btn-outline-light" href="?action=logout">退出登录</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            
            <h1 class="mb-4 text-center">🚀 Cloudflare R2 文件管理</h1>

            <?php if ($message): ?>
                <div class="alert <?= str_starts_with($message, '✅') ? 'alert-success' : 'alert-danger' ?>" role="alert">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-lg mb-5">
                <div class="card-header bg-success text-white">
                    <strong>⬆️ 图片上传区 (单文件)</strong>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="imageFile" class="form-label">选择图片文件</label>
                            <input class="form-control" type="file" id="imageFile" name="image_file" accept="image/*" required>
                        </div>
                        <p class="text-muted">文件将通过您的自定义域名 <code><?= CUSTOM_DOMAIN ?></code> 访问。</p>
                        <button type="submit" class="btn btn-primary w-100">上传到 R2</button>
                    </form>
                </div>
            </div>

            <h2 class="mt-5">📁 文件列表 (当前页显示 <?= count($imageList) ?> 个)</h2>
            <div class="card shadow-lg">
                <div class="card-body">
                    <?php if (isset($listResult['error'])): ?>
                        <div class="alert alert-danger"><?= $listResult['error'] ?></div>
                    <?php elseif (empty($imageList) && $currentPage == 1): ?>
                        <div class="alert alert-info">存储桶中没有文件。</div>
                    <?php else: ?>
                        
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr class="align-middle">
                                        <th>预览</th>
                                        <th>文件路径 (Key)</th>
                                        <th>修改时间</th>
                                        <th>大小 (KB)</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($imageList as $object): 
                                        $key = $object['Key'];
                                        // 存储完整的公共访问 URL
                                        $publicUrl = CUSTOM_DOMAIN . '/' . htmlspecialchars($key);
                                        $sizeKb = round($object['Size'] / 1024, 2);
                                        $lastModified = date('Y-m-d H:i:s', strtotime($object['LastModified']));
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="#" data-bs-toggle="modal" data-bs-target="#imageModal" data-image-url="<?= $publicUrl ?>">
                                                <img src="<?= $publicUrl ?>" alt="Preview" style="max-height: 50px; max-width: 50px; border-radius: 5px;">
                                            </a>
                                        </td>
                                        <td class="text-break"><code><?= htmlspecialchars($key) ?></code></td>
                                        <td><?= $lastModified ?></td>
                                        <td><?= $sizeKb ?> KB</td>
                                        <td>
                                            <button class="btn btn-sm btn-info me-2 copy-btn" 
                                                    data-clipboard-text="<?= $publicUrl ?>"
                                                    data-bs-toggle="tooltip" data-bs-placement="top" title="已复制!">
                                                <i class="fas fa-copy"></i> 复制
                                            </button>
                                            
                                            <a href="?action=delete&key=<?= urlencode($key) ?>" 
                                               class="btn btn-sm btn-danger"
                                               onclick="return confirm('您确定要删除文件: <?= addslashes($key) ?> 吗？');">
                                                <i class="fas fa-trash"></i> 删除
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <nav aria-label="文件分页">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= ($currentPage > 1) ? '' : 'disabled' ?>">
                                    <a class="page-link" href="?page=1" aria-label="Previous">
                                        <span aria-hidden="true">&laquo; 回到首页</span>
                                    </a>
                                </li>
                                <li class="page-item active" aria-current="page">
                                    <span class="page-link">第 <?= $currentPage ?> 页</span>
                                </li>
                                <?php
                                $nextPageUrl = '';
                                $nextPageActive = false;
                                if ($isTruncated && $nextContinuationToken) {
                                    $nextPageUrl = '?page=' . ($currentPage + 1) . '&token=' . urlencode($nextContinuationToken);
                                    $nextPageActive = true;
                                }
                                ?>
                                <li class="page-item <?= $nextPageActive ? '' : 'disabled' ?>">
                                    <a class="page-link" href="<?= $nextPageUrl ?>" aria-label="Next">
                                        <span aria-hidden="true">下一页 &raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="imageModalLabel">文件预览</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <img id="modalImage" src="" class="img-fluid" alt="Full Image" style="max-height: 80vh;">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.11/clipboard.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- 1. 图片放大模态框逻辑 ---
    const imageModal = document.getElementById('imageModal');
    if (imageModal) {
        imageModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const imageUrl = button.getAttribute('data-image-url');
            
            const modalImage = imageModal.querySelector('#modalImage');
            const modalTitle = imageModal.querySelector('#imageModalLabel');
            
            modalImage.src = imageUrl;
            // 在标题中显示文件名
            const fileName = imageUrl.substring(imageUrl.lastIndexOf('/') + 1);
            modalTitle.textContent = `文件预览: ${fileName}`;
        });
    }

    // --- 2. 复制按钮逻辑 (使用 Clipboard.js) ---
    // 初始化 Clipboard.js
    const clipboard = new ClipboardJS('.copy-btn');
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

    clipboard.on('success', function(e) {
        // 复制成功后显示 Tooltip (提示已复制)
        const tooltipInstance = bootstrap.Tooltip.getInstance(e.trigger);
        if (tooltipInstance) {
            tooltipInstance.show(); // 显示提示
            
            // 2秒后隐藏提示
            setTimeout(() => {
                tooltipInstance.hide();
            }, 1000);
        }
        e.clearSelection();
    });

    clipboard.on('error', function(e) {
        // 复制失败（通常是浏览器权限问题），提示用户手动复制
        alert('自动复制失败，请手动复制图片链接: ' + e.trigger.getAttribute('data-clipboard-text'));
    });
});
</script>

</body>
</html>
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- 1. 图片放大模态框逻辑 ---
    const imageModal = document.getElementById('imageModal');
    if (imageModal) {
        imageModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const imageUrl = button.getAttribute('data-image-url');
            
            const modalImage = imageModal.querySelector('#modalImage');
            const modalTitle = imageModal.querySelector('#imageModalLabel');
            
            modalImage.src = imageUrl;
            // 在标题中显示文件名
            const fileName = imageUrl.substring(imageUrl.lastIndexOf('/') + 1);
            modalTitle.textContent = `文件预览: ${fileName}`;
        });
    }

    // --- 2. 复制按钮逻辑 (使用 Clipboard.js) ---
    // 初始化 Clipboard.js
    const clipboard = new ClipboardJS('.copy-btn');
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

    clipboard.on('success', function(e) {
        // 复制成功后显示 Tooltip (提示已复制)
        const tooltipInstance = bootstrap.Tooltip.getInstance(e.trigger);
        if (tooltipInstance) {
            tooltipInstance.show(); // 显示提示
            
            // 2秒后隐藏提示
            setTimeout(() => {
                tooltipInstance.hide();
            }, 1000);
        }
        e.clearSelection();
    });

    clipboard.on('error', function(e) {
        // 复制失败（通常是浏览器权限问题），提示用户手动复制
        alert('自动复制失败，请手动复制图片链接: ' + e.trigger.getAttribute('data-clipboard-text'));
    });
});
</script>

</body>
</html></div>
            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="imageModalLabel">文件预览</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <img id="modalImage" src="" class="img-fluid" alt="Full Image" style="max-height: 80vh;">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.11/clipboard.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- 1. 图片放大模态框逻辑 ---
    const imageModal = document.getElementById('imageModal');
    if (imageModal) {
        imageModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const imageUrl = button.getAttribute('data-image-url');
            
            const modalImage = imageModal.querySelector('#modalImage');
            const modalTitle = imageModal.querySelector('#imageModalLabel');
            
            modalImage.src = imageUrl;
            // 在标题中显示文件名
            const fileName = imageUrl.substring(imageUrl.lastIndexOf('/') + 1);
            modalTitle.textContent = `文件预览: ${fileName}`;
        });
    }

    // --- 2. 复制按钮逻辑 (使用 Clipboard.js) ---
    // 初始化 Clipboard.js
    const clipboard = new ClipboardJS('.copy-btn');
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

    clipboard.on('success', function(e) {
        // 复制成功后显示 Tooltip (提示已复制)
        const tooltipInstance = bootstrap.Tooltip.getInstance(e.trigger);
        if (tooltipInstance) {
            tooltipInstance.show(); // 显示提示
            
            // 2秒后隐藏提示
            setTimeout(() => {
                tooltipInstance.hide();
            }, 1000);
        }
        e.clearSelection();
    });

    clipboard.on('error', function(e) {
        // 复制失败（通常是浏览器权限问题），提示用户手动复制
        alert('自动复制失败，请手动复制图片链接: ' + e.trigger.getAttribute('data-clipboard-text'));
    });
});
</script>

</body>
</html> required>
                        </div>
                        <p class="text-muted">文件将通过您的自定义域名 <code><?= CUSTOM_DOMAIN ?></code> 访问。</p>
                        <button type="submit" class="btn btn-primary w-100">上传到 R2</button>
                    </form>
                </div>
            </div>

            <h2 class="mt-5">📁 文件列表 (当前页显示 <?= count($imageList) ?> 个)</h2>
            <div class="card shadow-lg">
                <div class="card-body">
                    <?php if (isset($listResult['error'])): ?>
                        <div class="alert alert-danger"><?= $listResult['error'] ?></div>
                    <?php elseif (empty($imageList) && $currentPage == 1): ?>
                        <div class="alert alert-info">存储桶中没有文件。</div>
                    <?php else: ?>
                        
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr class="align-middle">
                                        <th>预览</th>
                                        <th>文件路径 (Key)</th>
                                        <th>修改时间</th>
                                        <th>大小 (KB)</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($imageList as $object): 
                                        $key = $object['Key'];
                                        // 存储完整的公共访问 URL
                                        $publicUrl = CUSTOM_DOMAIN . '/' . htmlspecialchars($key);
                                        $sizeKb = round($object['Size'] / 1024, 2);
                                        $lastModified = date('Y-m-d H:i:s', strtotime($object['LastModified']));
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="#" data-bs-toggle="modal" data-bs-target="#imageModal" data-image-url="<?= $publicUrl ?>">
                                                <img src="<?= $publicUrl ?>" alt="Preview" style="max-height: 50px; max-width: 50px; border-radius: 5px;">
                                            </a>
                                        </td>
                                        <td class="text-break"><code><?= htmlspecialchars($key) ?></code></td>
                                        <td><?= $lastModified ?></td>
                                        <td><?= $sizeKb ?> KB</td>
                                        <td>
                                            <button class="btn btn-sm btn-info me-2 copy-btn" 
                                                    data-clipboard-text="<?= $publicUrl ?>"
                                                    data-bs-toggle="tooltip" data-bs-placement="top" title="已复制!">
                                                <i class="fas fa-copy"></i> 复制
                                            </button>
                                            
                                            <a href="?action=delete&key=<?= urlencode($key) ?>" 
                                               class="btn btn-sm btn-danger"
                                               onclick="return confirm('您确定要删除文件: <?= addslashes($key) ?> 吗？');">
                                                <i class="fas fa-trash"></i> 删除
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <nav aria-label="文件分页">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= ($currentPage > 1) ? '' : 'disabled' ?>">
                                    <a class="page-link" href="?page=1" aria-label="Previous">
                                        <span aria-hidden="true">&laquo; 回到首页</span>
                                    </a>
                                </li>
                                <li class="page-item active" aria-current="page">
                                    <span class="page-link">第 <?= $currentPage ?> 页</span>
                                </li>
                                <?php
                                $nextPageUrl = '';
                                $nextPageActive = false;
                                if ($isTruncated && $nextContinuationToken) {
                                    $nextPageUrl = '?page=' . ($currentPage + 1) . '&token=' . urlencode($nextContinuationToken);
                                    $nextPageActive = true;
                                }
                                ?>
                                <li class="page-item <?= $nextPageActive ? '' : 'disabled' ?>">
                                    <a class="page-link" href="<?= $nextPageUrl ?>" aria-label="Next">
                                        <span aria-hidden="true">下一页 &raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="imageModalLabel">文件预览</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <img id="modalImage" src="" class="img-fluid" alt="Full Image" style="max-height: 80vh;">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.11/clipboard.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- 1. 图片放大模态框逻辑 ---
    const imageModal = document.getElementById('imageModal');
    if (imageModal) {
        imageModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const imageUrl = button.getAttribute('data-image-url');
            
            const modalImage = imageModal.querySelector('#modalImage');
            const modalTitle = imageModal.querySelector('#imageModalLabel');
            
            modalImage.src = imageUrl;
            // 在标题中显示文件名
            const fileName = imageUrl.substring(imageUrl.lastIndexOf('/') + 1);
            modalTitle.textContent = `文件预览: ${fileName}`;
        });
    }

    // --- 2. 复制按钮逻辑 (使用 Clipboard.js) ---
    // 初始化 Clipboard.js
    const clipboard = new ClipboardJS('.copy-btn');
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

    clipboard.on('success', function(e) {
        // 复制成功后显示 Tooltip (提示已复制)
        const tooltipInstance = bootstrap.Tooltip.getInstance(e.trigger);
        if (tooltipInstance) {
            tooltipInstance.show(); // 显示提示
            
            // 2秒后隐藏提示
            setTimeout(() => {
                tooltipInstance.hide();
            }, 1000);
        }
        e.clearSelection();
    });

    clipboard.on('error', function(e) {
        // 复制失败（通常是浏览器权限问题），提示用户手动复制
        alert('自动复制失败，请手动复制图片链接: ' + e.trigger.getAttribute('data-clipboard-text'));
    });
});
</script>

</body>
</html>

```
## r2_client.php

```
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
    }
}
?>
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

    clipboard.on('success', function(e) {
        // 复制成功后显示 Tooltip (提示已复制)
        const tooltipInstance = bootstrap.Tooltip.getInstance(e.trigger);
        if (tooltipInstance) {
            tooltipInstance.show(); // 显示提示```
## r2_client.php

```
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
    }
}
?>
            
            // 2秒后隐藏提示
            setTimeout(() => {
                tooltipInstance.hide();
            }, 1000);
        }
        e.clearSelection();
    });

    clipboard.on('error', function(e) {
        // 复制失败（通常是浏览器权限问题），提示用户手动复制
        alert('自动复制失败，请手动复制图片链接: ' + e.trigger.getAttribute('data-clipboard-text'));
    });
});
</script>

</body>
</html>


