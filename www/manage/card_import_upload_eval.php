<?php
// Step 1 → 2 of the picture import: unpacks the upload into a private
// folder and sends the user to the review page (POST from card_import.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/CardImageImport.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /manage/');
    exit;
}

// A body over post_max_size arrives with $_POST and $_FILES empty; the deck
// id on the action URL lets us send the user back with a real message.
$subcategoryId = (int)($_POST['subcategory_id'] ?? ($_GET['subcategory_id'] ?? 0));
$formUrl = '/manage/card_import.php?subcategory_id=' . $subcategoryId;
if ($_POST === [] && $_FILES === [] && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    header('Location: ' . $formUrl . '&err=' . urlencode('That upload is larger than the server accepts in one go (' . ini_get('post_max_size') . '). Split the pictures into smaller ZIPs, or shrink the photos first.'));
    exit;
}
require_csrf();

try {
    $ctx = UserContext::getLoggedInUserContext();
    $uploads = CardImageImport::normalizeFilesArray($_FILES['files'] ?? []);
    $token = CardImageImport::stashUpload($ctx, $subcategoryId, $uploads);
    header('Location: /manage/card_import_review.php?token=' . urlencode($token));
} catch (Throwable $e) {
    header('Location: ' . $formUrl . '&err=' . urlencode($e->getMessage()));
}
exit;
