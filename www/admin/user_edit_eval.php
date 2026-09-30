<?php
// Evaluates the edit-user form and its account actions (POST from admin/user_edit.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/users.php');
    exit;
}

require_csrf();

// Get user ID
$userId = (int)($_POST['id'] ?? 0);
if ($userId <= 0) {
    header('Location: /admin/users.php?err=' . urlencode('Invalid user ID.'));
    exit;
}

// Load user to verify it exists
$user = UserManagement::findById($userId);
if (!$user) {
    header('Location: /admin/users.php?err=' . urlencode('User not found.'));
    exit;
}

$me = current_user();
$canEditAdmin = ((int)$user['id'] !== (int)$me['id']); // Can't change own admin status
$hasPassword = ($user['password_hash'] !== '');

$action = $_POST['action'] ?? 'update';

if ($action === 'delete') {
    if (!$canEditAdmin) {
        header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Cannot delete your own account.'));
        exit;
    }

    try {
        $ctx = UserContext::getLoggedInUserContext();
        if (UserManagement::deleteUser($ctx, $userId)) {
            header('Location: /admin/users.php?msg=' . urlencode('User deleted successfully.'));
        } else {
            header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Failed to delete user.'));
        }
        exit;
    } catch (Throwable $e) {
        header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Error deleting user: ' . $e->getMessage()));
        exit;
    }
}

if ($action === 'send_verification') {
    try {
        $ctx = UserContext::getLoggedInUserContext();
        $token = UserManagement::setEmailVerificationToken($ctx, $userId);

        require_once __DIR__ . '/../mailer.php';
        $sent = send_verification_email($user['email'], $token, $user['first_name']);

        if ($sent) {
            header('Location: /admin/user_edit.php?id=' . $userId . '&msg=' . urlencode('Verification email sent successfully.'));
        } else {
            header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Failed to send verification email (see the email log).'));
        }
        exit;
    } catch (Throwable $e) {
        header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Error sending verification email: ' . $e->getMessage()));
        exit;
    }
}

if ($action === 'send_reset') {
    if (!$hasPassword) {
        header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Password reset requires an activated account.'));
        exit;
    }

    try {
        $token = UserManagement::setPasswordResetToken((string)$user['email']);

        if ($token) {
            header('Location: /admin/user_edit.php?id=' . $userId . '&msg=' . urlencode('Password reset email sent successfully.'));
        } else {
            header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Failed to send password reset email.'));
        }
        exit;
    } catch (Throwable $e) {
        header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Error sending password reset email: ' . $e->getMessage()));
        exit;
    }
}

// Handle update action
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$is_admin = $canEditAdmin && !empty($_POST['is_admin']) ? 1 : ($canEditAdmin ? 0 : (int)$user['is_admin']);

// Validation
$errors = [];
if ($first_name === '') {
    $errors[] = 'First name is required.';
}
if ($last_name === '') {
    $errors[] = 'Last name is required.';
}
if ($email === '') {
    $errors[] = 'Email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email is invalid.';
}

// Check if email already exists (but allow keeping the same email)
if (empty($errors) && strtolower($email) !== strtolower((string)$user['email']) && UserManagement::emailExists($email)) {
    $errors[] = 'Email already exists.';
}

if (!empty($errors)) {
    // Redirect back to form with errors and form data
    $query = http_build_query([
        'id' => $userId,
        'err' => implode(' ', $errors),
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'is_admin' => $is_admin,
    ]);
    header('Location: /admin/user_edit.php?' . $query);
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();

    UserManagement::updateProfile($ctx, $userId, [
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
    ]);

    if ($canEditAdmin) {
        UserManagement::setAdminFlag($ctx, $userId, (bool)$is_admin);
    }

    header('Location: /admin/user_edit.php?id=' . $userId . '&msg=' . urlencode('User updated successfully.'));
    exit;

} catch (Throwable $e) {
    $query = http_build_query([
        'id' => $userId,
        'err' => 'Error updating user: ' . $e->getMessage(),
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'is_admin' => $is_admin,
    ]);
    header('Location: /admin/user_edit.php?' . $query);
    exit;
}
