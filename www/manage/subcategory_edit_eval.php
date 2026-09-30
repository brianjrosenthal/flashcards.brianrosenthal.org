<?php
// Evaluates the edit subcategory form (POST from manage/subcategory_edit.php).
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
$data = [
    'name' => (string)($_POST['name'] ?? ''),
    'slug' => (string)($_POST['slug'] ?? ''),
    'description' => (string)($_POST['description'] ?? ''),
    'sort_order' => (int)($_POST['sort_order'] ?? 0),
];
$next = validate_relative_next_path($_POST['next'] ?? '');

try {
    $ctx = UserContext::getLoggedInUserContext();
    SubcategoryManagement::update($ctx, $id, $data);
    if ($next !== '') {
        $sub = SubcategoryManagement::findById($id);
        $cat = CategoryManagement::findById((int)$sub['category_id']);
        $site = SiteManagement::findByUserId((int)$cat['user_id']);
        $isPublicPage = $site && strpos($next, SiteResolver::basePathFor($site) . '/') === 0;
        header('Location: ' . ($isPublicPage ? SiteResolver::urlFor(SiteResolver::basePathFor($site), (string)$cat['slug'], (string)$sub['slug']) : $next));
    } else {
        header('Location: /manage/subcategory_edit.php?id=' . $id . '&msg=' . urlencode('Deck saved.'));
    }
    exit;
} catch (Throwable $e) {
    ManageUI::stashForm('subcategory_edit_' . $id, $data, $e->getMessage());
    header('Location: /manage/subcategory_edit.php?id=' . $id . ($next !== '' ? '&next=' . urlencode($next) : ''));
    exit;
}
