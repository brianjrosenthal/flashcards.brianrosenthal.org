<?php
// Evaluates the new subcategory form (POST from manage/subcategory_add.php).
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

$categoryId = (int)($_POST['category_id'] ?? 0);
$data = [
    'name' => (string)($_POST['name'] ?? ''),
    'slug' => (string)($_POST['slug'] ?? ''),
    'description' => (string)($_POST['description'] ?? ''),
];
$next = validate_relative_next_path($_POST['next'] ?? '');

try {
    $ctx = UserContext::getLoggedInUserContext();
    $id = SubcategoryManagement::create($ctx, $categoryId, $data);
    if ($next !== '') {
        header('Location: ' . $next);
    } else {
        header('Location: /manage/cards.php?subcategory_id=' . $id . '&msg=' . urlencode('Deck created. Now add some cards.'));
    }
    exit;
} catch (Throwable $e) {
    ManageUI::stashForm('subcategory_add_' . $categoryId, $data, $e->getMessage());
    header('Location: /manage/subcategory_add.php?category_id=' . $categoryId . ($next !== '' ? '&next=' . urlencode($next) : ''));
    exit;
}
