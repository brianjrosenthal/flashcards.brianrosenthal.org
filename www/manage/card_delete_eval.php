<?php
// Deletes one card (POST from the card list or the card editor).
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

$id = (int)($_POST['id'] ?? 0);
$card = $id > 0 ? CardManagement::findById($id) : null;
$next = validate_relative_next_path($_POST['next'] ?? '');
$listUrl = $card ? '/manage/cards.php?subcategory_id=' . (int)$card['subcategory_id'] : '/manage/';
$sep = strpos($listUrl, '?') === false ? '?' : '&';

try {
    CardManagement::delete(UserContext::getLoggedInUserContext(), $id);
    header('Location: ' . ($next !== '' ? $next : $listUrl . $sep . 'msg=' . urlencode('Card deleted.')));
} catch (Throwable $e) {
    header('Location: ' . $listUrl . $sep . 'err=' . urlencode('Could not delete the card: ' . $e->getMessage()));
}
exit;
