<?php
// Login form. Evaluates to login_eval.php (see docs/php-guidelines.md: one purpose per file).
// Works on the main host and on every user's custom domain; on a custom domain
// the card is branded with that user's page title.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Application.php';
require_once __DIR__ . '/lib/ApplicationUI.php';
require_once __DIR__ . '/lib/SiteResolver.php';
require_once __DIR__ . '/settings.php';

Application::init();

function h($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

$next = validate_relative_next_path($_GET['next'] ?? '');

// If already logged in, honor safe next target if present
if (current_user()) {
    header('Location: ' . ($next ?: '/'));
    exit;
}

$resolved = SiteResolver::resolveFromRequest();
$brandTitle = ($resolved['site'] !== null && $resolved['is_custom_domain'])
    ? (string)$resolved['site']['title']
    : Settings::siteTitle();

// One-shot flash values set by login_eval.php on failure
$error = $_SESSION['login_error'] ?? null;
$prefillEmail = $_SESSION['login_email'] ?? '';
unset($_SESSION['login_error'], $_SESSION['login_email']);

$verified = !empty($_GET['verified']);
$verifyError = !empty($_GET['verify_error']);
$resetDone = !empty($_GET['reset']);
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login - <?=h($brandTitle)?></title>
<?=ApplicationUI::cssLink('/styles.css')?></head>
<body class="auth">
  <div class="card auth-card">
    <div class="auth-mark" aria-hidden="true">&#127183;</div>
    <h1><?=h($brandTitle)?></h1>
    <p class="subtitle">Sign in to study your flashcards</p>
    <?php if (!empty($verified)): ?><p class="flash">Email verified. You can now sign in.</p><?php endif; ?>
    <?php if (!empty($resetDone)): ?><p class="flash">Your password has been reset. You can now sign in.</p><?php endif; ?>
    <?php if (!empty($verifyError)): ?><p class="error">Invalid or expired verification link.</p><?php endif; ?>

    <?php if($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

    <form method="post" action="/login_eval.php" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <?php if (!empty($next)): ?>
        <input type="hidden" name="next" value="<?= h($next) ?>">
      <?php endif; ?>
      <label>Email
        <input type="text" name="email" autocomplete="username" value="<?=h($prefillEmail)?>" required>
      </label>
      <label>Password
        <input type="password" name="password" required>
      </label>
      <label class="inline"><input type="checkbox" name="public_computer" value="1"> This is a public computer</label>
      <div class="actions">
        <button type="submit" class="button primary">Sign in</button>
      </div>
    </form>
    <p class="small" style="margin-bottom:0;margin-top:0.75rem;"><a href="/forgot_password.php">Forgot your password?</a></p>
  </div>
</body></html>
