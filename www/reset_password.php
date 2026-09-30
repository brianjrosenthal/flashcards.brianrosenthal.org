<?php
// Reset-password form (from an emailed link). Evaluates to reset_password_eval.php.
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

// One-shot flash from reset_password_eval.php
$error = $_SESSION['reset_password_error'] ?? null;
unset($_SESSION['reset_password_error']);

// Verify token is valid
$user = null;
if ($token) {
    $user = UserManagement::getUserByResetToken($token);
    if (!$user) {
        $error = 'Invalid or expired reset link.';
    }
} else {
    $error = 'Invalid reset link.';
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset Password - <?=h(Settings::siteTitle())?></title>
<?=ApplicationUI::cssLink('/styles.css')?></head>
<body class="auth">
  <div class="card auth-card">
    <div class="auth-mark" aria-hidden="true">&#10024;</div>
    <h1>Reset Password</h1>
    <p class="subtitle"><?=h(Settings::siteTitle())?></p>

    <?php if ($user): ?>
      <?php if($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

      <p>Enter your new password for <strong><?=h($user['email'])?></strong>.</p>

      <form method="post" action="/reset_password_eval.php" class="stack">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="token" value="<?=h($token)?>">
        <label>New Password
          <input type="password" name="password" required minlength="8">
        </label>
        <label>Confirm New Password
          <input type="password" name="confirm_password" required minlength="8">
        </label>
        <div class="actions">
          <button type="submit" class="button primary">Reset Password</button>
          <a href="/login.php" class="button">Cancel</a>
        </div>
      </form>
    <?php else: ?>
      <p class="error"><?=h($error)?></p>
      <p><a href="/forgot_password.php">Request a new reset link</a></p>
    <?php endif; ?>
  </div>
</body></html>
