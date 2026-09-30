<?php
// Moves a card one step up or down within its deck (POST from the card list).
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
$direction = (string)($_POST['direction'] ?? '');
$card = $id > 0 ? CardManagement::findById($id) : null;
$next = validate_relative_next_path($_POST['next'] ?? '');
$listUrl = $card ? '/manage/cards.php?subcategory_id=' . (int)$card['subcategory_id'] : '/manage/';
$sep = strpos($listUrl, '?') === false ? '?' : '&';

try {
    CardManagement::moveInOrder(UserContext::getLoggedInUserContext(), $id, $direction);
    header('Location: ' . ($next !== '' ? $next : $listUrl . '#card-' . $id));
} catch (Throwable $e) {
    header('Location: ' . $listUrl . $sep . 'err=' . urlencode('Could not move the card: ' . $e->getMessage()));
}
exit;
