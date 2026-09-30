<?php
// Card list for one deck (subcategory): thumbnails, front/back text, reorder,
// edit and delete, plus the bulk "front | back" add form at the bottom.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/ContentAccess.php';
require_once __DIR__ . '/../lib/SubcategoryManagement.php';
require_once __DIR__ . '/../lib/CardManagement.php';
require_once __DIR__ . '/../lib/ImageStorage.php';
Application::init();
require_login();

$subcategoryId = (int)($_GET['subcategory_id'] ?? 0);
$sub = $subcategoryId > 0 ? SubcategoryManagement::findById($subcategoryId) : null;
if (!$sub) {
    header('Location: /manage/?err=' . urlencode('Deck not found.'));
    exit;
}
$ctx = UserContext::getLoggedInUserContext();
$userId = (int)SubcategoryManagement::ownerUserIdOf($subcategoryId);
if (!ContentAccess::canEdit($ctx, $userId)) {
    http_response_code(403);
    die('You can only change your own content.');
}
$cat = CategoryManagement::findById((int)$sub['category_id']);
$cards = CardManagement::listForSubcategory($subcategoryId);
$stash = ManageUI::takeForm('card_bulk_' . $subcategoryId);
$bulkForm = $stash['data'] + ['lines' => ''];
$bulkErr = $stash['err'];
$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;
$site = SiteManagement::findByUserId($userId);
$listUrl = '/manage/cards.php?subcategory_id=' . $subcategoryId;
$cardCount = count($cards);

ApplicationUI::useSiteTheme($site);
header_html($sub['name'] . ' cards');
?>
<div class="crumbs"><a href="<?=h(ManageUI::dashboardUrl($userId))?>">My Decks</a> › <a href="/manage/category_edit.php?id=<?= (int)$cat['id'] ?>"><?=h($cat['name'])?></a> › <?=h($sub['name'])?></div>
<div class="page-head">
  <h2><?=h($sub['name'])?> <span class="small"><?= $cardCount ?> card<?= $cardCount === 1 ? '' : 's' ?></span></h2>
  <div class="actions">
    <?php if ($cardCount > 0): ?>
      <a class="button" href="/review/study.php?subcategory=<?= $subcategoryId ?>">Study</a>
      <a class="button" href="/quiz/?subcategory=<?= $subcategoryId ?>">Quiz</a>
    <?php endif; ?>
    <a class="button primary" href="/manage/card_add.php?subcategory_id=<?= $subcategoryId ?>">+ Add card</a>
    <a class="button" href="/manage/subcategory_edit.php?id=<?= $subcategoryId ?>">Edit deck</a>
  </div>
</div>
<?php if (!empty($sub['description'])): ?><p class="small"><?=h($sub['description'])?></p><?php endif; ?>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <?php if ($cards === []): ?>
    <p class="muted">No cards yet. Add one at a time with <a href="/manage/card_add.php?subcategory_id=<?= $subcategoryId ?>">+ Add card</a> (with a photo or map if you like), or paste a whole list below.</p>
  <?php else: ?>
    <table class="list cards-list">
      <thead>
        <tr>
          <th></th>
          <th>Front</th>
          <th>Back</th>
          <th>Order</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cards as $i => $card): $cardId = (int)$card['id']; $thumb = ImageStorage::thumbUrlForCard($card); ?>
          <tr id="card-<?= $cardId ?>">
            <td class="thumb">
              <?php if ($thumb !== null): ?>
                <a href="/manage/card_edit.php?id=<?= $cardId ?>"><img src="<?=h($thumb)?>" width="64" height="64" loading="lazy" alt=""></a>
              <?php else: ?>
                <span class="badge novideo">text</span>
              <?php endif; ?>
            </td>
            <td class="text"><?=h($card['front_text'])?></td>
            <td class="text"><?=h($card['back_text'])?></td>
            <td class="small">
              <form method="post" action="/manage/card_move_eval.php" class="move-form">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="id" value="<?= $cardId ?>">
                <input type="hidden" name="direction" value="up">
                <button type="submit" title="Move up" aria-label="Move up"<?= $i === 0 ? ' disabled' : '' ?>>&#9650;</button>
              </form>
              <form method="post" action="/manage/card_move_eval.php" class="move-form">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="id" value="<?= $cardId ?>">
                <input type="hidden" name="direction" value="down">
                <button type="submit" title="Move down" aria-label="Move down"<?= $i === $cardCount - 1 ? ' disabled' : '' ?>>&#9660;</button>
              </form>
            </td>
            <td class="small">
              <a class="button small" href="/manage/card_edit.php?id=<?= $cardId ?>">Edit</a>
              <?= ManageUI::postButtonHtml('/manage/card_delete_eval.php', ['id' => $cardId], 'Delete', 'button small danger',
                    'Delete this card? Everyone\'s progress on it is removed too. This cannot be undone.') ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card" id="bulk">
  <h3>Add many cards at once</h3>
  <p class="small">One card per line, front and back separated by <code>|</code> (a tab works too). Text cards only; add images one card at a time. Up to <?= CardManagement::BULK_MAX_LINES ?> lines.</p>
  <?php if ($bulkErr): ?><p class="error"><?=h($bulkErr)?></p><?php endif; ?>
  <form method="post" action="/manage/card_bulk_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="subcategory_id" value="<?= $subcategoryId ?>">
    <label>Cards <span class="hint">front | back</span>
      <textarea name="lines" rows="8" placeholder="George Washington | 1st president&#10;John Adams | 2nd president"><?=h($bulkForm['lines'])?></textarea>
    </label>
    <div class="actions">
      <button type="submit" class="button primary">Add these cards</button>
    </div>
  </form>
</div>
<?= ApplicationUI::jsScript('/manage/manage.js') ?>
<?php footer_html(); ?>
