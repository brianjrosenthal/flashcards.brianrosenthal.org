<?php
// Evaluates the initial password setup form (POST from set_password.php).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Application.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/ActivityLog.php';
require_once __DIR__ . '/lib/UserContext.php';

Application::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /login.php');
    exit;
}

require_csrf();

$token = $_POST['token'] ?? '';
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if ($token === '') {
    header('Location: /login.php?verify_error=1');
    exit;
}

if ($password === '' || $confirmPassword === '') {
    header('Location: /set_password.php?token=' . urlencode($token) . '&error=missing_fields');
    exit;
}

if (strlen($password) < 8) {
    header('Location: /set_password.php?token=' . urlencode($token) . '&error=password_too_short');
    exit;
}

if ($password !== $confirmPassword) {
    header('Location: /set_password.php?token=' . urlencode($token) . '&error=passwords_dont_match');
    exit;
}

try {
    $user = UserManagement::completeInitialPasswordSetup($token, $password);

    if (!$user) {
        header('Location: /login.php?verify_error=1');
        exit;
    }

    // Log the user in automatically
    establish_login_session($user);

    ActivityLog::log(UserContext::getLoggedInUserContext(), 'user.login', [
        'automatic_after_password_setup' => true
    ]);

    header('Location: /manage/');
    exit;

} catch (Throwable $e) {
    error_log('Password setup error: ' . $e->getMessage());
    header('Location: /set_password.php?token=' . urlencode($token) . '&error=system_error');
    exit;
}
