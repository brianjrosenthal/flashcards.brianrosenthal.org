<?php
// Evaluates the forgot-password form (POST from forgot_password.php).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Application.php';
require_once __DIR__ . '/lib/UserManagement.php';

Application::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /forgot_password.php');
    exit;
}

require_csrf();

$email = strtolower(trim($_POST['email'] ?? ''));

if ($email === '') {
    $_SESSION['forgot_password_error'] = 'Email is required.';
    header('Location: /forgot_password.php');
    exit;
}

try {
    // Always show the same success message so the form doesn't reveal which emails exist
    UserManagement::setPasswordResetToken($email);
    header('Location: /forgot_password.php?sent=1');
    exit;
} catch (Throwable $e) {
    error_log('Forgot password error: ' . $e->getMessage());
    $_SESSION['forgot_password_error'] = 'Failed to send reset email: ' . $e->getMessage();
    $_SESSION['forgot_password_email'] = $email;
    header('Location: /forgot_password.php');
    exit;
}
