<?php
// Forgot-password form. Evaluates to forgot_password_eval.php.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Application.php';
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

$success = !empty($_GET['sent']);

// One-shot flash from forgot_password_eval.php
$error = $_SESSION['forgot_password_error'] ?? null;
$prefillEmail = $_SESSION['forgot_password_email'] ?? '';
unset($_SESSION['forgot_password_error'], $_SESSION['forgot_password_email']);
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot Password - <?=h(Settings::siteTitle())?></title>
<?=ApplicationUI::cssLink('/styles.css')?></head>
<body class="auth">
  <div class="card auth-card">
    <div class="auth-mark" aria-hidden="true">&#10024;</div>
    <h1>Forgot Password</h1>
    <p class="subtitle"><?=h(Settings::siteTitle())?></p>

    <?php if ($success): ?>
      <p class="flash">If an account with that email exists, we've sent you a password reset link.</p>
      <p><a href="/login.php">Back to Login</a></p>
    <?php else: ?>
      <?php if($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

      <p>Enter your email address and we'll send you a link to reset your password.</p>

      <form method="post" action="/forgot_password_eval.php" class="stack">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <label>Email
          <input type="email" name="email" value="<?=h($prefillEmail)?>" required>
        </label>
        <div class="actions">
          <button type="submit" class="button primary">Send Reset Link</button>
          <a href="/login.php" class="button">Cancel</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</body></html>
