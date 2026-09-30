<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../lib/ActivityLog.php';
Application::init();
require_admin();

$msg = null;
$err = null;

// Settings definitions for the Flashcards application
$SETTINGS_DEF = [
  'site_title' => [
    'label' => 'Site Title',
    'hint'  => 'Shown in the header and page titles.',
    'type'  => 'text',
  ],
  'timezone' => [
    'label' => 'Time zone',
    'hint'  => 'Dates (e.g. "published on") are shown in this time zone.',
    'type'  => 'timezone',
  ],
  'site_base_url' => [
    'label' => 'Site URL',
    'hint'  => 'Used for links in emails, e.g. https://flashcards.brianrosenthal.org',
    'type'  => 'text',
  ],
];

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  try {
    foreach ($SETTINGS_DEF as $key => $meta) {
      $val = $_POST['s'][$key] ?? '';
      Settings::set($key, $val);
    }
    ActivityLog::log(UserContext::getLoggedInUserContext(), 'settings.update', array_keys($SETTINGS_DEF));
    $msg = 'Settings saved.';
  } catch (Throwable $e) {
    $err = 'Failed to save settings: ' . $e->getMessage();
  }
}

// Gather current values
$current = [];
foreach ($SETTINGS_DEF as $key => $_meta) {
  if ($key === 'site_title') {
    $default = defined('APP_NAME') ? APP_NAME : 'Flashcards';
  } elseif ($key === 'timezone') {
    $default = date_default_timezone_get();
  } else {
    $default = '';
  }
  $current[$key] = Settings::get($key, $default);
}

header_html('Manage Settings');
?>
<h2>Manage Settings</h2>
<?php if($msg):?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if($err):?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <?php foreach ($SETTINGS_DEF as $key => $meta): ?>
      <label>
        <?=h($meta['label'])?>
        <?php if (($meta['type'] ?? 'text') === 'timezone'): ?>
          <?php $zones = DateTimeZone::listIdentifiers(); ?>
          <select name="s[<?=h($key)?>]">
            <?php foreach ($zones as $z): ?>
              <option value="<?=h($z)?>" <?= $current[$key] === $z ? 'selected' : '' ?>><?=h($z)?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" name="s[<?=h($key)?>]" value="<?=h($current[$key])?>">
        <?php endif; ?>
        <?php if (!empty($meta['hint'])): ?>
          <small class="small"><?=h($meta['hint'])?></small>
        <?php endif; ?>
      </label>
    <?php endforeach; ?>
    <div class="actions">
      <button class="button primary" type="submit">Save</button>
      <a class="button" href="/manage/">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
