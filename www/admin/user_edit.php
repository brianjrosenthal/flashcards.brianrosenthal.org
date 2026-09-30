<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/SiteManagement.php';
Application::init();
require_admin();

// Get user ID
$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: /admin/users.php');
    exit;
}

// Load user data
$user = UserManagement::findById($userId);
if (!$user) {
    header('Location: /admin/users.php?err=' . urlencode('User not found.'));
    exit;
}

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

// For repopulating form after errors - get from URL parameters or use current user data
$form = [];
foreach (['first_name', 'last_name', 'email', 'is_admin'] as $field) {
    $form[$field] = $_GET[$field] ?? ($user[$field] ?? '');
}

$me = current_user();
$canEditAdmin = ((int)$user['id'] !== (int)$me['id']); // Can't change own admin status
$hasPassword = ($user['password_hash'] !== '');

$userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$site = SiteManagement::findByUserId($userId);
header_html('Edit ' . $userName);
?>

<div class="page-head">
  <h2>Edit <?= h($userName) ?></h2>
  <div class="actions">
    <?php if ($site): ?>
      <a class="button" href="/manage/?user_id=<?= (int)$userId ?>">Decks</a>
      <a class="button" href="/manage/site_settings.php?site_id=<?= (int)$site['id'] ?>">Page settings</a>
    <?php else: ?>
      <a class="button" href="/manage/?user_id=<?= (int)$userId ?>">Create page</a>
    <?php endif; ?>
    <a class="button" href="/admin/users.php">Back to Users</a>
  </div>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/admin/user_edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?= (int)$userId ?>">

    <div class="grid form-grid">
      <label>First name
        <input type="text" name="first_name" value="<?=h($form['first_name'] ?? '')?>" required>
      </label>
      <label>Last name
        <input type="text" name="last_name" value="<?=h($form['last_name'] ?? '')?>" required>
      </label>
      <label>Email
        <input type="email" name="email" value="<?=h($form['email'] ?? '')?>" required>
      </label>
    </div>

    <?php if ($canEditAdmin): ?>
      <label class="inline">
        <input type="checkbox" name="is_admin" value="1" <?= !empty($form['is_admin']) ? 'checked' : '' ?>>
        Admin user
      </label>
    <?php else: ?>
      <p class="small">Admin status: <?= !empty($user['is_admin']) ? 'Yes' : 'No' ?> (cannot change your own admin status)</p>
    <?php endif; ?>

    <p class="small">
      <strong>Login:</strong>
      <?php if (!empty($user['email_verified_at'])): ?>
        <span class="status-verified">Active</span>
      <?php elseif (!$hasPassword): ?>
        <span class="status-pending">Awaiting account activation</span>
      <?php else: ?>
        <span class="status-pending">Pending email verification</span>
      <?php endif; ?>
      &nbsp;·&nbsp; <strong>Created:</strong> <?= h(date('M j, Y g:i A', strtotime($user['created_at']))) ?>
    </p>

    <div class="actions">
      <button class="button primary" type="submit">Update User</button>
    </div>
  </form>
</div>

<?php if ($canEditAdmin): ?>
  <div class="card">
    <h3>Account Actions</h3>
    <div class="actions">
      <form method="post" action="/admin/user_edit_eval.php">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="id" value="<?= (int)$userId ?>">
        <input type="hidden" name="action" value="send_verification">
        <button class="button" type="submit"><?= $hasPassword ? 'Re-send Email Verification' : 'Re-send Account Activation' ?></button>
      </form>
      <?php if ($hasPassword): ?>
        <form method="post" action="/admin/user_edit_eval.php">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="id" value="<?= (int)$userId ?>">
          <input type="hidden" name="action" value="send_reset">
          <button class="button" type="submit">Send Password Reset</button>
        </form>
      <?php endif; ?>
      <form method="post" action="/admin/user_edit_eval.php">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="id" value="<?= (int)$userId ?>">
        <input type="hidden" name="action" value="delete">
        <button class="button danger" type="submit" data-confirm="Delete this user and their page? Only possible once their categories have been deleted. This cannot be undone.">Delete User</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
