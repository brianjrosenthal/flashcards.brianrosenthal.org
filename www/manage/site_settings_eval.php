<?php
// Evaluates the site settings form (POST from manage/site_settings.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /manage/');
    exit;
}
require_csrf();

$siteId = (int)($_POST['site_id'] ?? 0);
$site = $siteId > 0 ? SiteManagement::findById($siteId) : null;
if (!$site) {
    header('Location: /manage/?err=' . urlencode('Site not found.'));
    exit;
}
$ctx = UserContext::getLoggedInUserContext();
$formUrl = '/manage/site_settings.php?site_id=' . $siteId . ManageUI::nextParam();

$data = [
    'title' => (string)($_POST['title'] ?? ''),
    'tagline' => (string)($_POST['tagline'] ?? ''),
    'accent_color' => (string)($_POST['accent_color'] ?? 'violet'),
    'is_public' => !empty($_POST['is_public']),
];
$routing = [
    'slug' => (string)($_POST['slug'] ?? $site['slug']),
    'domain' => (string)($_POST['domain'] ?? ($site['domain'] ?? '')),
];

try {
    SiteManagement::updateSiteContent($ctx, $siteId, $data);
    if ($ctx->admin && isset($_POST['slug'])) {
        SiteManagement::updateSiteRouting($ctx, $siteId, $routing['slug'], $routing['domain']);
    }
    $next = validate_relative_next_path($_POST['next'] ?? '');
    if ($next !== '') {
        header('Location: ' . $next);
    } else {
        header('Location: /manage/site_settings.php?site_id=' . $siteId . '&msg=' . urlencode('Settings saved.'));
    }
    exit;
} catch (Throwable $e) {
    ManageUI::stashForm('site_' . $siteId, $data + $routing, $e->getMessage());
    header('Location: ' . $formUrl);
    exit;
}
