<?php
// Admin: every user's site with its routing, for setting up domains.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/SiteManagement.php';
require_once __DIR__ . '/../lib/SiteResolver.php';
Application::init();
require_admin();

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;
$sites = SiteManagement::listSites();

header_html('Pages');
?>
<div class="page-head">
  <h2>Pages</h2>
  <a class="button" href="/admin/users.php">Users</a>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>
<p class="small">A public page is created automatically when a user is added and is live at /{slug}/ right away. Once a dedicated domain exists (MAIN_HOST), each page is also served at {slug}.MAIN_HOST; a custom domain is optional.</p>

<?php if (empty($sites)): ?>
  <p class="small">No pages yet.</p>
<?php else: ?>
  <div class="card"><div class="table-scroll">
    <table class="list">
      <thead><tr><th>Owner</th><th>Title</th><th>Subdomain</th><th>Path</th><th>Custom domain</th><th>Public</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($sites as $s): ?>
          <tr>
            <td><?=h(trim($s['first_name'] . ' ' . $s['last_name']))?><br><span class="small"><?=h($s['email'])?></span></td>
            <td><?=h($s['title'])?></td>
            <td><?php $sub = SiteResolver::subdomainHostFor($s); ?><?php if ($sub !== ''): ?><a href="https://<?=h($sub)?>/"><?=h($sub)?></a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td><a href="/<?=h($s['slug'])?>/">/<?=h($s['slug'])?>/</a></td>
            <td><?php if (!empty($s['domain'])): ?><a href="https://<?=h($s['domain'])?>/"><?=h($s['domain'])?></a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td><?= !empty($s['is_public']) ? '<span class="status-verified">Yes</span>' : '<span class="status-pending">No</span>' ?></td>
            <td class="small">
              <a class="button small" href="/manage/site_settings.php?site_id=<?= (int)$s['id'] ?>">Settings</a>
              <a class="button small" href="/manage/?user_id=<?= (int)$s['user_id'] ?>">Content</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
<?php endif; ?>
<?php footer_html(); ?>
