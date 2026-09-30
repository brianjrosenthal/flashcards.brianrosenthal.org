<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Application.php';
require_once __DIR__ . '/lib/ActivityLog.php';
require_once __DIR__ . '/lib/UserContext.php';

Application::init();

// Log logout before clearing session
$currentUser = current_user();
if ($currentUser) {
    ActivityLog::log(new UserContext((int)$currentUser['id'], !empty($currentUser['is_admin'])), 'user.logout', []);
}

// Clear session
session_destroy();

// Clear remember me cookie
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', cookie_domain(), true, true);
}

// Redirect to login
header('Location: /login.php');
exit;
