<?php
// Deletes a deck and all its cards (POST from the dashboard, the editor, or
// the owner bar on the public page).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/SubcategoryManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /manage/');
    exit;
}
require_csrf();

$id = (int)($_POST['id'] ?? 0);
$userId = (int)(SubcategoryManagement::ownerUserIdOf($id) ?? current_user()['id']);
$dash = ManageUI::dashboardUrl($userId);
$sep = strpos($dash, '?') === false ? '?' : '&';
$next = validate_relative_next_path($_POST['next'] ?? '');

try {
    SubcategoryManagement::delete(UserContext::getLoggedInUserContext(), $id);
    header('Location: ' . ($next !== '' ? $next : $dash . $sep . 'msg=' . urlencode('Deck deleted.')));
} catch (Throwable $e) {
    header('Location: ' . $dash . $sep . 'err=' . urlencode('Could not delete the deck: ' . $e->getMessage()));
}
exit;
