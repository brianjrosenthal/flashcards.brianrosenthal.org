<?php
// Removes a card's image, keeping the card (POST from the card editor).
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
$next = validate_relative_next_path($_POST['next'] ?? '');
$editUrl = '/manage/card_edit.php?id=' . $id;

try {
    CardManagement::removeImage(UserContext::getLoggedInUserContext(), $id);
    header('Location: ' . ($next !== '' ? $next : $editUrl . '&msg=' . urlencode('Image removed.')));
} catch (Throwable $e) {
    header('Location: ' . $editUrl . '&err=' . urlencode('Could not remove the image: ' . $e->getMessage()));
}
exit;
