<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_admin();

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

// For repopulating form after errors - get from URL parameters
$form = [];
foreach (['first_name', 'last_name', 'email', 'is_admin'] as $field) {
    if (isset($_GET[$field])) {
        $form[$field] = $_GET[$field];
    }
}

header_html('Add User');
?>

<h2>Add User</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/admin/user_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">

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

    <label class="inline">
      <input type="checkbox" name="is_admin" value="1" <?= !empty($form['is_admin']) ? 'checked' : '' ?>>
      Admin user
    </label>

    <p class="small">The new user will receive an activation email with a link to set their password.</p>

    <div class="actions">
      <button class="button primary" type="submit">Create User</button>
      <a class="button" href="/admin/users.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
