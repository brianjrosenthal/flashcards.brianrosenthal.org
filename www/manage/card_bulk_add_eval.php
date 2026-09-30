<?php
// Evaluates the bulk "front | back" add form (POST from manage/cards.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/CardManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /manage/');
    exit;
}
require_csrf();

$subcategoryId = (int)($_POST['subcategory_id'] ?? 0);
$lines = (string)($_POST['lines'] ?? '');
$listUrl = '/manage/cards.php?subcategory_id=' . $subcategoryId;

try {
    $count = CardManagement::bulkCreateFromLines(UserContext::getLoggedInUserContext(), $subcategoryId, $lines);
    header('Location: ' . $listUrl . '&msg=' . urlencode($count . ' card' . ($count === 1 ? '' : 's') . ' added.'));
    exit;
} catch (Throwable $e) {
    ManageUI::stashForm('card_bulk_' . $subcategoryId, ['lines' => $lines], $e->getMessage());
    header('Location: ' . $listUrl . '#bulk');
    exit;
}
