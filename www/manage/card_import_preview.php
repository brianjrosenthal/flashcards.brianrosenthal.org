<?php
// Streams one uploaded picture from a pending import for the review page
// (GET ?token=&n=). Only the uploader (or an admin) may see it.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Application.php';
require_once __DIR__ . '/../lib/CardImageImport.php';
Application::init();
require_login();

try {
    $manifest = CardImageImport::load((string)($_GET['token'] ?? ''), UserContext::getLoggedInUserContext());
    $path = CardImageImport::entryFilePath($manifest, (int)($_GET['n'] ?? -1));
    if ($path === null) {
        http_response_code(404);
        exit;
    }
    $info = @getimagesize($path);
    $type = $info !== false ? (string)($info['mime'] ?? 'application/octet-stream') : 'application/octet-stream';
    header('Content-Type: ' . $type);
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
} catch (Throwable $e) {
    http_response_code(404);
}
