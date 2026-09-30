<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_admin();

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

// Handle search
$search = trim($_GET['q'] ?? '');
$users = UserManagement::listUsers($search);

header_html('Users');
?>

<div class="page-head">
  <h2>Users</h2>
  <a class="button primary" href="/admin/user_add.php">Add User</a>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="get" data-auto-submit class="stack">
    <label>Search
      <input type="text" name="q" value="<?=h($search)?>" placeholder="Name or email">
    </label>
  </form>
</div>

<?php if (empty($users)): ?>
  <p class="small">No users found.</p>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>Admin</th>
          <th>Status</th>
          <th>Created</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): ?>
          <tr>
            <td><?= h(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?></td>
            <td><?= h($user['email'] ?? '') ?></td>
            <td><?= !empty($user['is_admin']) ? 'Yes' : 'No' ?></td>
            <td>
              <?php if (!empty($user['email_verified_at'])): ?>
                <span class="status-verified">Active</span>
              <?php else: ?>
                <span class="status-pending">Pending activation</span>
              <?php endif; ?>
            </td>
            <td><?= h(date('M j, Y', strtotime($user['created_at']))) ?></td>
            <td class="small">
              <a class="button small" href="/admin/user_edit.php?id=<?= (int)$user['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
