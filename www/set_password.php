<?php
// Initial password setup form (from the account activation email).
// Evaluates to set_password_eval.php.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Application.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/ApplicationUI.php';
require_once __DIR__ . '/settings.php';

Application::init();

function h($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// If already logged in, redirect to home
if (current_user()) {
    header('Location: /manage/');
    exit;
}

$token = $_GET['token'] ?? '';
$errorParam = $_GET['error'] ?? '';
$error = null;
$user = null;

// Handle error messages from eval page
switch ($errorParam) {
    case 'missing_fields':
        $error = 'Please fill in both password fields.';
        break;
    case 'password_too_short':
        $error = 'Password must be at least 8 characters long.';
        break;
    case 'passwords_dont_match':
        $error = 'Passwords do not match.';
        break;
    case 'system_error':
        $error = 'System error occurred. Please try again.';
        break;
}

// Verify token and get user
if ($token) {
    $user = UserManagement::findPendingPasswordSetupByToken($token);
    if (!$user) {
        $error = 'Invalid or expired setup link.';
    }
} else {
    $error = 'Invalid setup link.';
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Set Your Password - <?=h(Settings::siteTitle())?></title>
<?=ApplicationUI::cssLink('/styles.css')?></head>
<body class="auth">
  <div class="card auth-card">
    <div class="auth-mark" aria-hidden="true">&#10024;</div>
    <h1>Set Your Password</h1>
    <p class="subtitle"><?=h(Settings::siteTitle())?></p>

    <?php if ($user): ?>
      <p>Welcome, <strong><?=h($user['first_name'])?> <?=h($user['last_name'])?></strong>!</p>
      <p>Please set your password to complete your account setup.</p>

      <?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

      <form method="post" action="/set_password_eval.php" class="stack">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="token" value="<?=h($token)?>">
        <label>Password
          <input type="password" name="password" required minlength="8">
        </label>
        <label>Confirm Password
          <input type="password" name="confirm_password" required minlength="8">
        </label>
        <div class="actions">
          <button type="submit" class="button primary">Set Password</button>
        </div>
      </form>
    <?php else: ?>
      <p class="error"><?=h($error)?></p>
      <p><a href="/login.php">Back to Login</a></p>
    <?php endif; ?>
  </div>
</body></html>
