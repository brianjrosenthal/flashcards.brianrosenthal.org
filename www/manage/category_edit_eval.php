<?php
// Evaluates the edit category form (POST from manage/category_edit.php).
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
$data = [
    'name' => (string)($_POST['name'] ?? ''),
    'slug' => (string)($_POST['slug'] ?? ''),
    'description' => (string)($_POST['description'] ?? ''),
    'is_public' => !empty($_POST['is_public']),
    'sort_order' => (int)($_POST['sort_order'] ?? 0),
];
$next = validate_relative_next_path($_POST['next'] ?? '');

try {
    $ctx = UserContext::getLoggedInUserContext();
    CategoryManagement::update($ctx, $id, $data);
    if ($next !== '') {
        // The slug may have changed, so re-derive the public URL of the page
        // rather than trusting the old one.
        $cat = CategoryManagement::findById($id);
        $site = SiteManagement::findByUserId((int)$cat['user_id']);
        $isPublicPage = $site && strpos($next, SiteResolver::basePathFor($site) . '/') === 0;
        header('Location: ' . ($isPublicPage ? SiteResolver::urlFor(SiteResolver::basePathFor($site), (string)$cat['slug']) : $next));
    } else {
        header('Location: /manage/category_edit.php?id=' . $id . '&msg=' . urlencode('Category saved.'));
    }
    exit;
} catch (Throwable $e) {
    ManageUI::stashForm('category_edit_' . $id, $data, $e->getMessage());
    header('Location: /manage/category_edit.php?id=' . $id . ($next !== '' ? '&next=' . urlencode($next) : ''));
    exit;
}
