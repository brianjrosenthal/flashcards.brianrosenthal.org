<?php
// Evaluates the reset-password form (POST from reset_password.php).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Application.php';
require_once __DIR__ . '/lib/UserManagement.php';

Application::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /login.php');
    exit;
}

require_csrf();

$token = $_POST['token'] ?? '';
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

function redirect_back_with_error(string $error, string $token): void {
    $_SESSION['reset_password_error'] = $error;
    header('Location: /reset_password.php?token=' . urlencode($token));
    exit;
}

if ($token === '' || !UserManagement::getUserByResetToken($token)) {
    redirect_back_with_error('Invalid or expired reset link.', $token);
}

if ($password === '') {
    redirect_back_with_error('Password is required.', $token);
}
if (strlen($password) < 8) {
    redirect_back_with_error('Password must be at least 8 characters long.', $token);
}
if ($password !== $confirmPassword) {
    redirect_back_with_error('Passwords do not match.', $token);
}

try {
    if (!UserManagement::completePasswordReset($token, $password)) {
        redirect_back_with_error('Failed to reset password. Please try again.', $token);
    }
    header('Location: /login.php?reset=1');
    exit;
} catch (Throwable $e) {
    redirect_back_with_error('Failed to reset password: ' . $e->getMessage(), $token);
}
