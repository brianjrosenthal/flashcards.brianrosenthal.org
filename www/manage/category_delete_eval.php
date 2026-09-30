<?php
// Deletes a category with everything in it (POST from the dashboard, the
// editor, or the owner bar on the public page).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/CategoryManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /manage/');
    exit;
}
require_csrf();

$id = (int)($_POST['id'] ?? 0);
$cat = $id > 0 ? CategoryManagement::findById($id) : null;
$userId = $cat ? (int)$cat['user_id'] : (int)current_user()['id'];
$dash = ManageUI::dashboardUrl($userId);
$sep = strpos($dash, '?') === false ? '?' : '&';
$next = validate_relative_next_path($_POST['next'] ?? '');

try {
    CategoryManagement::delete(UserContext::getLoggedInUserContext(), $id);
    header('Location: ' . ($next !== '' ? $next : $dash . $sep . 'msg=' . urlencode('Category deleted.')));
} catch (Throwable $e) {
    header('Location: ' . $dash . $sep . 'err=' . urlencode('Could not delete the category: ' . $e->getMessage()));
}
exit;
