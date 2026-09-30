<?php
// Step 3 of the picture import: a progress bar while manage.js calls
// card_import_batch_eval.php until every picture is imported, then the
// summary (also shown when revisiting a finished import).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/SubcategoryManagement.php';
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
$sub = SubcategoryManagement::findById($subcategoryId);
$userId = (int)$manifest['owner_user_id'];
$listUrl = '/manage/cards.php?subcategory_id=' . $subcategoryId;
$summary = CardImageImport::summarize($manifest);
$failed = array_values(array_filter($manifest['entries'], static fn(array $e): bool => $e['status'] === CardImageImport::STATUS_FAILED));

ApplicationUI::useSiteTheme(SiteManagement::findByUserId($userId));
header_html('Importing pictures');
?>
<div class="crumbs"><a href="<?=h(ManageUI::dashboardUrl($userId))?>">My Decks</a> › <a href="<?=h($listUrl)?>"><?=h($sub['name'] ?? 'Deck')?></a> › Import pictures</div>
<div class="page-head"><h2><?= !empty($manifest['done']) ? 'Import finished' : 'Importing pictures…' ?></h2></div>

<div class="card" id="import-progress"
     data-token="<?=h($token)?>"
     data-csrf="<?=h(csrf_token())?>"
     data-total="<?= (int)$summary['will_import'] + (int)$summary[CardImageImport::STATUS_DONE] + (int)$summary[CardImageImport::STATUS_FAILED] ?>"
     data-done="<?= (int)$manifest['created'] + (int)$manifest['failed'] ?>"
     data-finished="<?= !empty($manifest['done']) ? '1' : '0' ?>"
     data-list-url="<?=h($listUrl)?>">
  <div class="progress-track"><div class="progress-fill" id="import-progress-fill" style="width:<?= $summary['will_import'] + $manifest['created'] + $manifest['failed'] > 0 ? (int)round(100 * ($manifest['created'] + $manifest['failed']) / max(1, $summary['will_import'] + $manifest['created'] + $manifest['failed'])) : 100 ?>%"></div></div>
  <p id="import-progress-text" class="small"><?php if (!empty($manifest['done'])): ?>Done.<?php else: ?>Resizing and uploading each picture; please keep this page open.<?php endif; ?></p>
  <p id="import-progress-error" class="error" hidden></p>
  <p id="import-summary" <?= empty($manifest['done']) ? 'hidden' : '' ?>>
    <strong><span id="import-created"><?= (int)$manifest['created'] ?></span> card<?= (int)$manifest['created'] === 1 ? '' : 's' ?> imported</strong>
    <span id="import-failed-wrap" <?= (int)$manifest['failed'] === 0 ? 'hidden' : '' ?>>· <span id="import-failed"><?= (int)$manifest['failed'] ?></span> failed</span>
  </p>
  <div class="actions" id="import-done-actions" <?= empty($manifest['done']) ? 'hidden' : '' ?>>
    <a class="button primary" href="<?=h($listUrl)?>">See the cards</a>
    <a class="button" href="/review/study.php?subcategory=<?= $subcategoryId ?>">Study them</a>
    <a class="button" href="/manage/card_import.php?subcategory_id=<?= $subcategoryId ?>">Import more</a>
  </div>
</div>

<div class="card" id="import-failures" <?= $failed === [] ? 'hidden' : '' ?>>
  <h3>Could not import</h3>
  <ul id="import-failure-list">
    <?php foreach ($failed as $e): ?><li><strong><?=h($e['source'])?></strong> — <?=h($e['reason'])?></li><?php endforeach; ?>
  </ul>
</div>
<?= ApplicationUI::jsScript('/manage/manage.js') ?>
<?php footer_html(); ?>
