<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/windows-releases.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once __DIR__ . '/lib/tab-permissions.php';
        requireTabPermissionFromSession('devsettings:windows-app', true);
        $token = (string)($_POST['csrf'] ?? '');
        if ($token === '' || !hash_equals((string)($_SESSION['windows_release_csrf'] ?? ''), $token)) throw new RuntimeException('توکن امنیتی معتبر نیست؛ صفحه را تازه کنید.');
        $file = $_FILES['build'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) throw new RuntimeException('آپلود انجام نشد؛ حجم مجاز سرور را بررسی کنید.');
        $release = windowsReleasePublish(trim((string)($_POST['version'] ?? '')), trim((string)($_POST['notes'] ?? '')), $file['tmp_name'], strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)));
        echo json_encode(['status'=>'ok', 'release'=>$release], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else echo json_encode(windowsReleaseRead(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    // Preserve JSON through the hosting CDN's HTML error-page substitution.
    echo json_encode(['status'=>'error', 'message'=>$error->getMessage()], JSON_UNESCAPED_UNICODE);
}
