<?php
// Throws a pending picture import away (POST from card_import_review.php).
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
$ctx = UserContext::getLoggedInUserContext();
try {
    $manifest = CardImageImport::load($token, $ctx);
    CardImageImport::discard($token, $ctx);
    header('Location: /manage/cards.php?subcategory_id=' . (int)$manifest['subcategory_id'] . '&msg=' . urlencode('Import cancelled; nothing was added.'));
} catch (Throwable $e) {
    header('Location: /manage/?err=' . urlencode($e->getMessage()));
}
exit;
