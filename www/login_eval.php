<?php
// Evaluates the login form (POST from login.php). Redirects on success or back
// to login.php with a one-shot flash error on failure.
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

$next = validate_relative_next_path($_POST['next'] ?? '');
$email = strtolower(trim($_POST['email'] ?? ''));
$pass = $_POST['password'] ?? '';
$publicComputer = !empty($_POST['public_computer']);

function redirect_back_with_error(string $error, string $email, string $next): void {
    $_SESSION['login_error'] = $error;
    $_SESSION['login_email'] = $email;
    header('Location: /login.php' . ($next !== '' ? '?next=' . urlencode($next) : ''));
    exit;
}

$u = UserManagement::findAuthByEmail($email);

$isSuper = (defined('SUPER_PASSWORD') && SUPER_PASSWORD !== '' && hash_equals($pass, SUPER_PASSWORD));

if (!$u || !($isSuper || password_verify($pass, (string)$u['password_hash']))) {
    ActivityLog::log(null, 'user.login_failed', [
        'email' => $email,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    ]);
    redirect_back_with_error('Invalid email or password.', $email, $next);
}

if (!$isSuper && empty($u['email_verified_at'])) {
    redirect_back_with_error('Please verify your email before signing in. Check your inbox for the confirmation link.', $email, $next);
}

establish_login_session($u, $isSuper, $publicComputer);

$loginContext = $isSuper ? ['using_super_password' => true] : [];
if ($publicComputer) {
    $loginContext['public_computer'] = true;
}
ActivityLog::log(UserContext::getLoggedInUserContext(), 'user.login', $loginContext);

header('Location: ' . ($next ?: '/'));
exit;
