<?php
// Admin: Image Storage actions (POST from admin/image_storage.php).
//   create_bucket   — create the configured bucket if missing.
//   test_upload     — store, fetch and delete a tiny image, reporting the raw result.
//   delete_orphans  — delete objects in the bucket that no card references.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ImageStorage.php';
require_once __DIR__ . '/../lib/CardManagement.php';
require_once __DIR__ . '/../lib/ActivityLog.php';
Application::init();
require_admin();

$returnTo = '/admin/image_storage.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $returnTo);
    exit;
}
require_csrf();

$ctx = UserContext::getLoggedInUserContext();
$action = (string)($_POST['action'] ?? '');

try {
    if (!ImageStorage::isConfigured()) {
        throw new RuntimeException('Image storage is not configured.');
    }
    $client = ImageStorage::storage();
    $bucket = ImageStorage::bucket();

    switch ($action) {
        case 'create_bucket':
            $created = $client->createBucketIfMissing($bucket);
            ActivityLog::log($ctx, 'image_storage.create_bucket', ['bucket' => $bucket, 'created' => $created]);
            $msg = $created ? 'Bucket "' . $bucket . '" created. Run a test upload to confirm it works.' : 'The bucket "' . $bucket . '" already existed.';
            break;

        case 'test_upload':
            $msg = ImageStorage::describeTestUpload();
            ActivityLog::log($ctx, 'image_storage.test_upload', ['bucket' => $bucket, 'result' => $msg]);
            break;

        case 'delete_orphans':
            $expected = [];
            foreach (CardManagement::listImageObjectKeys() as $key) {
                $expected[] = $key;
                $expected[] = ImageStorage::thumbKeyFor($key);
            }
            $inBucket = array_column($client->listObjects($bucket), 'key');
            $orphans = array_values(array_diff($inBucket, $expected));
            $client->deleteObjects($bucket, $orphans);
            ActivityLog::log($ctx, 'image_storage.delete_orphans', ['bucket' => $bucket, 'deleted' => count($orphans)]);
            $msg = count($orphans) . ' orphaned object(s) deleted.';
            break;

        default:
            throw new RuntimeException('Unknown action.');
    }
    header('Location: ' . $returnTo . '?msg=' . urlencode($msg));
} catch (Throwable $e) {
    header('Location: ' . $returnTo . '?err=' . urlencode($e->getMessage()));
}
exit;
