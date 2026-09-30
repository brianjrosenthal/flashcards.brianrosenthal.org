<?php
// Admin: database migrations — every db_migrations/*.sql with its applied
// status (from schema_migrations), and a form to apply the pending ones.
// Evaluates to migrations_apply_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/MigrationRunner.php';
Application::init();
require_admin();

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

$trackingReady = MigrationRunner::trackingTableExists();
$rows = MigrationRunner::status();
$pendingCount = 0;
foreach ($rows as $r) {
    if (!$r['applied']) { $pendingCount++; }
}

header_html('Migrations');
?>
<div class="page-head"><h2>Database Migrations</h2></div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>
<p class="small">Reads <code><?=h(MigrationRunner::dir())?></code>. Applied files are recorded in <code>schema_migrations</code>, shared with <code>db_migrations/migrate.sh</code>. A fresh install (loaded from schema.sql) already has everything applied.</p>

<?php if (!$trackingReady): ?>
  <p class="notice">The <code>schema_migrations</code> table does not exist yet. It will be created the first time you apply a migration; migrations that were applied by hand before that should be recorded by running them again (they are idempotent).</p>
<?php endif; ?>

<div class="card">
  <form method="post" action="/admin/migrations_apply_eval.php" id="migrations-form">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="table-scroll">
      <table class="list">
        <thead><tr><th style="width:36px"><input type="checkbox" id="select-all" title="Select all pending" <?= $pendingCount > 0 ? '' : 'disabled' ?>></th><th>Migration</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php if (!$r['applied'] && !$r['missing_file']): ?><input type="checkbox" class="migration-check" name="filenames[]" value="<?=h($r['filename'])?>"><?php endif; ?></td>
              <td><code><?=h($r['filename'])?></code><?php if ($r['missing_file']): ?> <span class="small">(file no longer on disk)</span><?php endif; ?></td>
              <td><?php if ($r['applied']): ?><span class="status-verified">Applied</span> <span class="small"><?=h(substr((string)$r['applied_at'], 0, 16))?></span><?php else: ?><span class="status-pending">Not applied</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?><tr><td colspan="3" class="small">No migration files found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="actions" style="margin-top:12px">
      <button type="submit" class="button primary" <?= $pendingCount > 0 ? '' : 'disabled' ?> data-confirm="Apply the selected migrations to the database?">Apply selected</button>
      <span class="small"><?= $pendingCount ?> migration<?= $pendingCount === 1 ? '' : 's' ?> pending.</span>
    </div>
  </form>
</div>
<script>
(function () {
  var all = document.getElementById('select-all');
  if (!all) return;
  all.addEventListener('change', function () {
    document.querySelectorAll('.migration-check').forEach(function (c) { c.checked = all.checked; });
  });
})();
</script>
<?php footer_html(); ?>
