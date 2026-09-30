<?php
// Creates the public page for a user who has none (POST from manage/index.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /manage/');
    exit;
}
require_csrf();

$target = ManageUI::targetUser($_POST['user_id'] ?? null);
$userId = (int)$target['id'];
$dash = ManageUI::dashboardUrl($userId);
$sep = strpos($dash, '?') === false ? '?' : '&';

try {
    SiteManagement::createForUser(UserContext::getLoggedInUserContext(), $userId, (string)$target['first_name'], (string)($_POST['title'] ?? ''));
    header('Location: ' . $dash . $sep . 'msg=' . urlencode('Your page is ready. Add a category to get started.'));
} catch (Throwable $e) {
    header('Location: ' . $dash . $sep . 'err=' . urlencode($e->getMessage()));
}
exit;
