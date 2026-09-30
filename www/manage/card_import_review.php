<?php
// Step 2 of the picture import: what will be created, what will be skipped
// and why, with a preview of each picture. Evaluates to
// card_import_start_eval.php (import) or card_import_discard_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/SubcategoryManagement.php';
require_once __DIR__ . '/../lib/ImageStorage.php';
require_once __DIR__ . '/../lib/CardImageImport.php';
Application::init();
require_login();

$token = (string)($_GET['token'] ?? '');
$ctx = UserContext::getLoggedInUserContext();
try {
    $manifest = CardImageImport::load($token, $ctx);
} catch (Throwable $e) {
    header('Location: /manage/?err=' . urlencode($e->getMessage()));
    exit;
}
$subcategoryId = (int)$manifest['subcategory_id'];
if (!empty($manifest['done'])) {
    header('Location: /manage/card_import_progress.php?token=' . urlencode($token));
    exit;
}
$sub = SubcategoryManagement::findById($subcategoryId);
$userId = (int)$manifest['owner_user_id'];
$cat = $sub ? CategoryManagement::findById((int)$sub['category_id']) : null;
$listUrl = '/manage/cards.php?subcategory_id=' . $subcategoryId;
$summary = CardImageImport::summarize($manifest);
$labels = [
    CardImageImport::STATUS_OK => ['Will import', 'status-verified'],
    CardImageImport::STATUS_EXISTS => ['Already in deck', 'status-pending'],
    CardImageImport::STATUS_DUPLICATE => ['Duplicate name', 'status-pending'],
    CardImageImport::STATUS_SKIPPED => ['Skipped', 'status-failed'],
];

ApplicationUI::useSiteTheme(SiteManagement::findByUserId($userId));
header_html('Review import');
?>
<div class="crumbs"><a href="<?=h(ManageUI::dashboardUrl($userId))?>">My Decks</a> › <?php if ($cat): ?><a href="/manage/category_edit.php?id=<?= (int)$cat['id'] ?>"><?=h($cat['name'])?></a> › <?php endif; ?><a href="<?=h($listUrl)?>"><?=h($sub['name'] ?? 'Deck')?></a> › Import pictures</div>
<div class="page-head">
  <h2>Review: <?= (int)$summary['will_import'] ?> card<?= (int)$summary['will_import'] === 1 ? '' : 's' ?> to import</h2>
</div>
<p class="small">
  <?= (int)$summary['total'] ?> file<?= (int)$summary['total'] === 1 ? '' : 's' ?> in the upload:
  <strong><?= (int)$summary[CardImageImport::STATUS_OK] ?></strong> new,
  <strong><?= (int)$summary[CardImageImport::STATUS_EXISTS] ?></strong> already in this deck,
  <strong><?= (int)$summary[CardImageImport::STATUS_DUPLICATE] ?></strong> duplicate name<?= (int)$summary[CardImageImport::STATUS_DUPLICATE] === 1 ? '' : 's' ?>,
  <strong><?= (int)$summary[CardImageImport::STATUS_SKIPPED] ?></strong> skipped.
</p>
<?php foreach ((array)($manifest['upload_errors'] ?? []) as $ue): ?><p class="error"><?=h($ue)?></p><?php endforeach; ?>

<div class="card">
  <form method="post" action="/manage/card_import_start_eval.php" class="stack" id="import-review-form">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="token" value="<?=h($token)?>">
    <?php if ((int)$summary[CardImageImport::STATUS_EXISTS] > 0): ?>
      <label class="inline">
        <input type="checkbox" name="import_existing" value="1" data-adds="<?= (int)$summary[CardImageImport::STATUS_EXISTS] ?>">
        Also import the <?= (int)$summary[CardImageImport::STATUS_EXISTS] ?> picture<?= (int)$summary[CardImageImport::STATUS_EXISTS] === 1 ? '' : 's' ?> whose name is already a card in this deck <span class="hint">(makes a second card with the same back)</span>
      </label>
    <?php endif; ?>
    <div class="actions">
      <button type="submit" class="button primary" id="import-start-btn" <?= (int)$summary['will_import'] === 0 && (int)$summary[CardImageImport::STATUS_EXISTS] === 0 ? 'disabled' : '' ?>>Import <span id="import-count"><?= (int)$summary['will_import'] ?></span> card<?= (int)$summary['will_import'] === 1 ? '' : 's' ?></button>
      <button type="submit" class="button" formaction="/manage/card_import_discard_eval.php">Cancel import</button>
    </div>
  </form>
</div>

<div class="card"><div class="table-scroll">
  <table class="list import-list">
    <thead><tr><th></th><th>File</th><th>Back of the card</th><th>Size</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($manifest['entries'] as $i => $e): [$label, $cls] = $labels[$e['status']] ?? [$e['status'], '']; ?>
        <tr class="import-row status-<?=h($e['status'])?>">
          <td class="thumb"><?php if (!empty($e['file'])): ?><img src="/manage/card_import_preview.php?token=<?=h($token)?>&n=<?= (int)$i ?>" loading="lazy" alt=""><?php endif; ?></td>
          <td class="small"><?=h($e['source'])?></td>
          <td class="text"><strong><?=h($e['back'])?></strong></td>
          <td class="small"><?=h(ImageStorage::humanBytes((int)$e['size']))?><?php if (!empty($e['width'])): ?><br><?= (int)$e['width'] ?>×<?= (int)$e['height'] ?><?php endif; ?></td>
          <td><span class="<?=h($cls)?>"><?=h($label)?></span><?php if ($e['reason'] !== ''): ?><br><span class="small"><?=h($e['reason'])?></span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?= ApplicationUI::jsScript('/manage/manage.js') ?>
<?php footer_html(); ?>
