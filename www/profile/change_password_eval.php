<?php
// Evaluates the change-password form (POST from profile/change_password.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /profile/change_password.php');
    exit;
}

require_csrf();

$me = current_user();

$current = $_POST['current_password'] ?? '';
$new = $_POST['new_password'] ?? '';
$confirm = $_POST['confirm_password'] ?? '';

function redirect_back_with_error(string $error): void {
    $_SESSION['change_password_error'] = $error;
    header('Location: /profile/change_password.php');
    exit;
}

if ($new !== $confirm) {
    redirect_back_with_error('New password and confirmation do not match.');
}
if (strlen($new) < 8) {
    redirect_back_with_error('New password must be at least 8 characters.');
}
if (!password_verify($current, (string)$me['password_hash'])) {
    redirect_back_with_error('Current password is incorrect.');
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    UserManagement::changePassword($ctx, (int)$me['id'], $new);
    session_regenerate_id(true);
    $_SESSION['change_password_success'] = 'Password updated successfully.';
    header('Location: /profile/change_password.php');
    exit;
} catch (Throwable $e) {
    redirect_back_with_error('Failed to update password: ' . $e->getMessage());
}
