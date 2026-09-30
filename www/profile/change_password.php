<?php
// Change my password — form. Evaluates to profile/change_password_eval.php.
require_once __DIR__ . '/../partials.php';
Application::init();
require_login();

// One-shot flashes from change_password_eval.php
$err = $_SESSION['change_password_error'] ?? null;
$msg = $_SESSION['change_password_success'] ?? null;
unset($_SESSION['change_password_error'], $_SESSION['change_password_success']);

header_html('Change Password');
?>
<h2>Change Password</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
    <form method="post" action="/profile/change_password_eval.php" class="stack">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <label>Current Password
            <input type="password" name="current_password" required>
        </label>
        <label>New Password
            <input type="password" name="new_password" required minlength="8">
        </label>
        <label>Confirm New Password
            <input type="password" name="confirm_password" required minlength="8">
        </label>
        <div class="actions">
            <button type="submit" class="button primary">Update Password</button>
            <a class="button" href="/manage/">Cancel</a>
        </div>
    </form>
</div>
<?php footer_html(); ?>
