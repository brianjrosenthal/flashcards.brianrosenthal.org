<?php
// Evaluates the new category form (POST from manage/category_add.php).
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

$target = ManageUI::targetUser($_POST['user_id'] ?? null);
$userId = (int)$target['id'];
$data = [
    'name' => (string)($_POST['name'] ?? ''),
    'slug' => (string)($_POST['slug'] ?? ''),
    'description' => (string)($_POST['description'] ?? ''),
    'is_public' => !empty($_POST['is_public']),
];
$next = validate_relative_next_path($_POST['next'] ?? '');

try {
    $ctx = UserContext::getLoggedInUserContext();
    $id = CategoryManagement::create($ctx, $userId, $data);
    if ($next !== '') {
        header('Location: ' . $next);
    } else {
        $dash = ManageUI::dashboardUrl($userId);
        header('Location: ' . $dash . (strpos($dash, '?') === false ? '?' : '&') . 'msg=' . urlencode('Category created. Now add a deck inside it.'));
    }
    exit;
} catch (Throwable $e) {
    ManageUI::stashForm('category_add_' . $userId, $data, $e->getMessage());
    header('Location: /manage/category_add.php?user_id=' . $userId . ($next !== '' ? '&next=' . urlencode($next) : ''));
    exit;
}
