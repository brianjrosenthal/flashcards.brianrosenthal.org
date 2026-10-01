<?php
// Admin: upload-limit diagnostics. Shows every setting that can stop a card
// image on its way in (PHP's own limits, which ini files set them, the app's
// IMAGE_MAX_BYTES, the image extensions) and lets you try a real upload to
// see exactly where it stops. Nothing is stored. Evaluates to
// image_size_test_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ImageStorage.php';
Application::init();
require_admin();

function ini_bytes(string $value): int {
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return -1;
    }
    $unit = strtolower(substr($value, -1));
    $n = (float)$value;
    switch ($unit) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return (int)$n;
}

$uploadMax = (string)ini_get('upload_max_filesize');
$postMax = (string)ini_get('post_max_size');
$memory = (string)ini_get('memory_limit');
$appMax = ImageStorage::maxBytes();
$effective = min(array_filter([ini_bytes($uploadMax), ini_bytes($postMax), $appMax], static fn(int $b): bool => $b > 0));
$limits = [
    ['upload_max_filesize', $uploadMax, ini_bytes($uploadMax), 'PHP: largest single file. Set in phprc.'],
    ['post_max_size', $postMax, ini_bytes($postMax), 'PHP: whole request (file + form fields). Must be above upload_max_filesize. Set in phprc.'],
    ['IMAGE_MAX_BYTES', ImageStorage::humanBytes($appMax), $appMax, 'This app: config.local.php.'],
];
$result = $_GET['result'] ?? null;
$scanned = (string)php_ini_scanned_files();

header_html('Image size test');
?>
<div class="crumbs"><a href="/admin/image_storage.php">Image Storage</a> › Image size test</div>
<div class="page-head"><h2>Image size test</h2></div>
<p class="small">A card image has to get past three limits. The smallest one wins: right now that is
  <strong><?=h(ImageStorage::humanBytes($effective))?></strong>. Pictures are resized to <?= ImageStorage::MAX_LONG_EDGE ?>px once they arrive, so the limits only concern the file as uploaded.</p>

<?php if ($result !== null): ?>
  <div class="card">
    <h3>Upload result</h3>
    <?php if (!empty($_GET['ok'])): ?>
      <p class="flash"><?=h($result)?></p>
    <?php else: ?>
      <p class="error"><?=h($result)?></p>
    <?php endif; ?>
    <?php if (!empty($_GET['detail'])): ?><p class="small"><?=h($_GET['detail'])?></p><?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h3>Try an upload</h3>
  <p class="small">Choose the photo that failed. It is checked exactly as a card upload would be, then thrown away.</p>
  <form method="post" action="/admin/image_size_test_eval.php" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <label>Image
      <input type="file" name="image" accept="image/*" required>
    </label>
    <div class="actions"><button type="submit" class="button primary">Test this file</button></div>
  </form>
</div>

<div class="card">
  <h3>Limits</h3>
  <table class="list">
    <thead><tr><th>Setting</th><th>Value</th><th>Where it comes from</th></tr></thead>
    <tbody>
      <?php foreach ($limits as [$name, $value, $bytes, $where]): ?>
        <tr>
          <td><code><?=h($name)?></code></td>
          <td><?=h($value)?><?php if ($bytes > 0 && $bytes === $effective): ?> <span class="status-pending">← the smallest</span><?php endif; ?></td>
          <td class="small"><?=h($where)?></td>
        </tr>
      <?php endforeach; ?>
      <tr><td><code>memory_limit</code></td><td><?=h($memory)?></td><td class="small">Decoding a big photo needs RAM; the image code raises this to 256M itself where allowed.</td></tr>
      <tr><td><code>max_execution_time</code></td><td><?=h((string)ini_get('max_execution_time'))?>s</td><td class="small">Resize + upload to R2 must finish within this.</td></tr>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Which PHP, which ini files</h3>
  <table class="list">
    <tbody>
      <tr><th style="width:220px">PHP version</th><td><?=h(PHP_VERSION)?> (<?=h(PHP_SAPI)?>)</td></tr>
      <tr><th>Loaded php.ini</th><td><code><?=h((string)(php_ini_loaded_file() ?: '(none)'))?></code></td></tr>
      <tr><th>Extra ini files read</th><td><?= $scanned !== '' ? '<code>' . h(str_replace(',', "</code><br><code>", $scanned)) . '</code>' : '<span class="muted">none</span>' ?></td></tr>
      <tr><th>Expected phprc</th><td><code><?=h(($_SERVER['HOME'] ?? '~') . '/.php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '/phprc')?></code>
        <?php $phprc = ($_SERVER['HOME'] ?? '') . '/.php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '/phprc'; ?>
        <?php if (($_SERVER['HOME'] ?? '') !== '' && is_file($phprc)): ?>
          <br><span class="status-verified">exists</span><pre class="small" style="white-space:pre-wrap;margin:6px 0 0"><?=h((string)file_get_contents($phprc))?></pre>
        <?php elseif (($_SERVER['HOME'] ?? '') !== ''): ?>
          <br><span class="status-failed">not found</span> <span class="small">— DreamHost reads the phprc for the PHP version the domain runs (<?=h(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION)?>), so a file for another version is ignored.</span>
        <?php endif; ?>
      </td></tr>
      <tr><th>Image extensions</th><td>
        gd <?= extension_loaded('gd') ? '<span class="status-verified">yes</span>' : '<span class="status-failed">missing</span>' ?> ·
        exif <?= extension_loaded('exif') ? '<span class="status-verified">yes</span>' : '<span class="status-pending">no (photos may come in sideways)</span>' ?> ·
        zip <?= extension_loaded('zip') ? '<span class="status-verified">yes</span>' : '<span class="status-failed">missing (picture import needs it)</span>' ?> ·
        curl <?= extension_loaded('curl') ? '<span class="status-verified">yes</span>' : '<span class="status-failed">missing (R2 needs it)</span>' ?>
      </td></tr>
    </tbody>
  </table>
  <p class="small">If the phprc exists but the values above do not match it, PHP is still running with the old settings: kill the PHP processes (<code>killall -9 php<?=h(PHP_MAJOR_VERSION . PHP_MINOR_VERSION)?>.cgi</code>) and reload this page.</p>
</div>
<?php footer_html(); ?>
