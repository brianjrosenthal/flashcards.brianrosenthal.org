<?php
// Step 2 → 3 of the picture import: records the review choices and sends
// the user to the progress page, which imports in batches (POST from
// card_import_review.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CardImageImport.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /manage/');
    exit;
}
require_csrf();

$token = (string)($_POST['token'] ?? '');
try {
    CardImageImport::setOptions($token, UserContext::getLoggedInUserContext(), ['import_existing' => !empty($_POST['import_existing'])]);
    header('Location: /manage/card_import_progress.php?token=' . urlencode($token));
} catch (Throwable $e) {
    header('Location: /manage/?err=' . urlencode($e->getMessage()));
}
exit;
