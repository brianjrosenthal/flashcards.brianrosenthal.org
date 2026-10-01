<?php
// Admin: diagnostics and setup for the Cloudflare R2 bucket that holds card
// images. Checks the credentials, shows the bucket, compares it with the
// database (every card image has a main object and a _thumb sibling), and
// offers to create the bucket, run a test upload and delete orphans. Every
// storage call is caught and reported inline — this page exists precisely
// for the case where storage is misconfigured.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ImageStorage.php';
require_once __DIR__ . '/../lib/CardManagement.php';
Application::init();
require_admin();

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

$configured = ImageStorage::isConfigured();
$bucket = ImageStorage::bucket();
$dbKeys = CardManagement::listImageObjectKeys();

// What the bucket should hold: each recorded key and its thumbnail, mapped
// back to the card so a missing object can link to the editor.
$expected = [];
foreach ($dbKeys as $key) {
    $cardId = preg_match('#^cards/\d+/(\d+)/#', $key, $m) === 1 ? (int)$m[1] : 0;
    $expected[$key] = $cardId;
    $expected[ImageStorage::thumbKeyFor($key)] = $cardId;
}

$probe = ['exists' => false, 'count' => null, 'bytes' => null, 'keys' => [], 'error' => null];
if ($configured) {
    try {
        $client = ImageStorage::storage();
        $probe['exists'] = $client->bucketExists($bucket);
        if ($probe['exists']) {
            $objects = $client->listObjects($bucket);
            $probe['count'] = count($objects);
            $probe['bytes'] = array_sum(array_column($objects, 'size'));
            $probe['keys'] = array_column($objects, 'key');
        }
    } catch (Throwable $e) {
        $probe['error'] = $e->getMessage();
    }
}
$missing = $probe['exists'] ? array_values(array_diff(array_keys($expected), $probe['keys'])) : [];
$orphans = $probe['exists'] ? array_values(array_diff($probe['keys'], array_keys($expected))) : [];

function image_storage_action_form(string $action, string $label, string $class = '', string $confirm = ''): string {
    return '<form method="post" action="/admin/image_storage_eval.php">'
         . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
         . '<input type="hidden" name="action" value="' . h($action) . '">'
         . '<button type="submit" class="button ' . h($class) . '"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . h($label) . '</button>'
         . '</form>';
}

header_html('Image Storage');
?>
<div class="page-head"><h2>Image Storage</h2></div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>
<p class="small">Card images live in a private Cloudflare R2 bucket, not on this server or in the database. Uploads pass through PHP, which resizes each picture to <?= ImageStorage::MAX_LONG_EDGE ?>px and stores it with a <?= ImageStorage::THUMB_LONG_EDGE ?>px thumbnail; pages show them through short-lived signed URLs.</p>

<div class="card">
  <h3>Cloudflare R2</h3>
  <table class="list">
    <tr><th style="width:240px">Credentials</th><td>
      <?php if ($configured): ?><span class="status-verified">Configured</span>
      <?php else: ?><span class="status-failed">Not configured</span> — set <code>R2_ENDPOINT</code>, <code>R2_ACCESS_KEY</code>, <code>R2_SECRET_KEY</code> and <code>R2_IMAGE_BUCKET</code> in <code>config.local.php</code>. Image uploads are disabled until then (text cards still work).<?php if ($dbKeys !== []): ?> <strong><?= count($dbKeys) ?> card image(s) are recorded and cannot be shown until it is configured.</strong><?php endif; ?><?php endif; ?>
    </td></tr>
    <tr><th>Endpoint</th><td><code><?=h(ImageStorage::endpoint() !== '' ? ImageStorage::endpoint() : '(unset)')?></code></td></tr>
    <tr><th>Region</th><td><code><?=h(ImageStorage::region())?></code></td></tr>
    <tr><th>Bucket</th><td><code><?=h($bucket !== '' ? $bucket : '(unset)')?></code></td></tr>
    <tr><th>Upload limit</th><td><?=h(ImageStorage::humanBytes(ImageStorage::maxBytes()))?> per image (<code>IMAGE_MAX_BYTES</code>; PHP's <code>upload_max_filesize</code> is <?=h((string)ini_get('upload_max_filesize'))?>, <code>post_max_size</code> <?=h((string)ini_get('post_max_size'))?>)</td></tr>
    <tr><th>Diagnose a failed upload</th><td><a class="button small" href="/admin/image_size_test.php">Image size test</a> <span class="small">— every limit in play, and a dry-run upload of the photo that failed.</span></td></tr>
    <tr><th>Images in the database</th><td><?= count($dbKeys) ?> card image(s), so <?= count($expected) ?> object(s) expected in the bucket (each has a thumbnail)</td></tr>
    <?php if ($configured): ?>
      <?php if ($probe['error'] !== null): ?>
        <tr><th>Status</th><td><span class="status-failed">Storage error:</span> <?=h($probe['error'])?></td></tr>
      <?php else: ?>
        <tr><th>Status</th><td><?= $probe['exists'] ? '<span class="status-verified">Ready</span>' : '<span class="status-pending">Bucket missing</span>' ?></td></tr>
        <?php if ($probe['exists']): ?>
          <tr><th>Objects in the bucket</th><td><?= number_format((int)$probe['count']) ?> (<?=h(ImageStorage::humanBytes((int)$probe['bytes']))?>)</td></tr>
          <tr><th>Missing from bucket</th><td><?= count($missing) === 0 ? '<span class="status-verified">None</span>' : '<span class="status-failed">' . count($missing) . '</span> object(s) the database expects are gone' ?></td></tr>
          <tr><th>Orphans in bucket</th><td><?= count($orphans) === 0 ? '<span class="status-verified">None</span>' : count($orphans) . ' object(s) not referenced by any card' ?></td></tr>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </table>
  <?php if ($configured && $probe['error'] === null): ?>
    <div class="actions" style="margin-top:12px">
      <?php if (!$probe['exists']): ?>
        <?= image_storage_action_form('create_bucket', 'Create bucket', 'primary') ?>
      <?php else: ?>
        <?= image_storage_action_form('test_upload', 'Test upload') ?>
        <?php if ($orphans !== []): ?>
          <?= image_storage_action_form('delete_orphans', 'Delete orphans', 'danger', 'Delete ' . count($orphans) . ' orphaned object(s) from the bucket? They are not referenced by any card.') ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($missing !== []): ?>
<div class="card">
  <h3>Missing objects</h3>
  <p class="small">These cards point at objects the bucket no longer holds. Open the card and upload the image again (or remove it).</p>
  <table class="list">
    <tr><th>Card</th><th>Object key</th></tr>
    <?php foreach (array_slice($missing, 0, 100) as $key): $cardId = (int)($expected[$key] ?? 0); ?>
      <tr><td><?php if ($cardId > 0): ?><a href="/manage/card_edit.php?id=<?= $cardId ?>">Card #<?= $cardId ?></a><?php else: ?>?<?php endif; ?></td><td><code><?=h($key)?></code></td></tr>
    <?php endforeach; ?>
    <?php if (count($missing) > 100): ?><tr><td colspan="2" class="small">… and <?= count($missing) - 100 ?> more</td></tr><?php endif; ?>
  </table>
</div>
<?php endif; ?>

<?php if ($orphans !== []): ?>
<div class="card">
  <h3>Orphaned objects</h3>
  <p class="small">Objects in the bucket that no card references (left behind by a failed save or a deleted card whose storage delete failed). Safe to delete.</p>
  <table class="list">
    <tr><th>Object key</th></tr>
    <?php foreach (array_slice($orphans, 0, 100) as $key): ?>
      <tr><td><code><?=h($key)?></code></td></tr>
    <?php endforeach; ?>
    <?php if (count($orphans) > 100): ?><tr><td class="small">… and <?= count($orphans) - 100 ?> more</td></tr><?php endif; ?>
  </table>
</div>
<?php endif; ?>

<p class="small"><strong>Test upload</strong> stores a tiny generated image exactly as a card upload would (main object and thumbnail), fetches it back through a signed display URL as a browser would, and deletes it, reporting the raw storage response so a failure can be diagnosed here.</p>
<?php footer_html(); ?>
