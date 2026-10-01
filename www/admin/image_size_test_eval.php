<?php
// Admin: runs one image through the same checks as a card upload and reports
// where it stops (POST from admin/image_size_test.php). The image is never
// stored.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ImageStorage.php';
Application::init();
require_admin();

$back = '/admin/image_size_test.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $back);
    exit;
}

// Over post_max_size: PHP drops the whole body, so there is no CSRF token to
// check and nothing in $_FILES. Report that rather than dying on CSRF.
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($_POST === [] && $_FILES === [] && $contentLength > 0) {
    header('Location: ' . $back . '?' . http_build_query([
        'ok' => 0,
        'result' => 'Stopped by post_max_size (' . ini_get('post_max_size') . '): the request was ' . ImageStorage::humanBytes($contentLength) . ' and PHP discarded it before this app saw it.',
        'detail' => 'Raise post_max_size (and upload_max_filesize) in phprc, then kill the PHP processes.',
    ]));
    exit;
}
require_csrf();

$file = $_FILES['image'] ?? null;
$error = $file === null ? UPLOAD_ERR_NO_FILE : (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
$size = $file !== null ? (int)($file['size'] ?? 0) : 0;
$name = $file !== null ? (string)($file['name'] ?? '') : '';
$params = ['ok' => 0];

if ($error === UPLOAD_ERR_INI_SIZE) {
    $params['result'] = 'Stopped by upload_max_filesize (' . ini_get('upload_max_filesize') . '): PHP refused "' . $name . '" before this app saw it.';
    $params['detail'] = 'Raise upload_max_filesize in phprc (keep post_max_size above it), then kill the PHP processes.';
} elseif ($error !== UPLOAD_ERR_OK) {
    $params['result'] = 'The upload failed with PHP error code ' . $error . ' for "' . $name . '".';
} else {
    $tmp = (string)$file['tmp_name'];
    $info = @getimagesize($tmp);
    $dims = $info !== false ? $info[0] . '×' . $info[1] . ' ' . ($info['mime'] ?? '') : 'not a readable image';
    try {
        $t0 = microtime(true);
        $prepared = ImageStorage::prepareUpload($file);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $params['ok'] = 1;
        $params['result'] = '"' . $name . '" (' . ImageStorage::humanBytes($size) . ', ' . $dims . ') passed every check and would be stored as '
            . $prepared['width'] . '×' . $prepared['height'] . ' ' . $prepared['content_type'] . ', ' . ImageStorage::humanBytes($prepared['size'])
            . ' (resized in ' . $ms . ' ms).';
        $params['detail'] = 'So uploads of this size work here. If a card upload with this same file still fails, the failure is after this point: the upload to R2 (check Admin → Image Storage → Test upload).';
    } catch (Throwable $e) {
        $params['result'] = '"' . $name . '" (' . ImageStorage::humanBytes($size) . ', ' . $dims . ') was refused by this app: ' . $e->getMessage();
    }
}
header('Location: ' . $back . '?' . http_build_query($params));
exit;
