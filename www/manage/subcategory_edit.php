<?php
// Edit deck (subcategory) form. Evaluates to subcategory_edit_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/ContentAccess.php';
require_once __DIR__ . '/../lib/SubcategoryManagement.php';
require_once __DIR__ . '/../lib/CardManagement.php';
Application::init();
require_login();

$id = (int)($_GET['id'] ?? 0);
$sub = $id > 0 ? SubcategoryManagement::findById($id) : null;
if (!$sub) {
    header('Location: /manage/?err=' . urlencode('Deck not found.'));
    exit;
}
$ctx = UserContext::getLoggedInUserContext();
$userId = (int)SubcategoryManagement::ownerUserIdOf($id);
if (!ContentAccess::canEdit($ctx, $userId)) {
    http_response_code(403);
    die('You can only change your own content.');
}
$cat = CategoryManagement::findById((int)$sub['category_id']);
$stash = ManageUI::takeForm('subcategory_edit_' . $id);
$form = $stash['data'] + $sub;
$err = $stash['err'];
$msg = $_GET['msg'] ?? null;
$cardCount = CardManagement::countForSubcategory($id);
$site = SiteManagement::findByUserId($userId);

ApplicationUI::useSiteTheme($site);
header_html('Edit ' . $sub['name']);
?>
<div class="crumbs"><a href="<?=h(ManageUI::dashboardUrl($userId))?>">My Decks</a> › <a href="/manage/category_edit.php?id=<?= (int)$cat['id'] ?>"><?=h($cat['name'])?></a> › <?=h($sub['name'])?></div>
<div class="page-head">
  <h2>Edit deck</h2>
  <div class="actions">
    <a class="button" href="/manage/cards.php?subcategory_id=<?= $id ?>">Cards (<?= $cardCount ?>)</a>
    <a class="button" href="/manage/card_add.php?subcategory_id=<?= $id ?>">+ Add card</a>
  </div>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/manage/subcategory_edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <?= ManageUI::nextInputHtml() ?>
    <div class="grid form-grid">
      <label>Name
        <input type="text" name="name" value="<?=h($form['name'])?>" required maxlength="150">
      </label>
      <label>URL name <span class="hint">changing it changes the page's address</span>
        <input type="text" name="slug" value="<?=h($form['slug'])?>" required maxlength="80" pattern="[a-z0-9]+(-[a-z0-9]+)*">
      </label>
      <label>Order <span class="hint">lower numbers first</span>
        <input type="number" name="sort_order" value="<?= (int)$form['sort_order'] ?>" min="0" max="9999">
      </label>
    </div>
    <label>Description <span class="hint">optional, shown under the name</span>
      <input type="text" name="description" value="<?=h($form['description'])?>" maxlength="500">
    </label>
    <div class="actions">
      <button type="submit" class="button primary">Save</button>
      <a class="button" href="<?=h(ManageUI::nextOr(ManageUI::dashboardUrl($userId)))?>">Cancel</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>Delete</h3>
  <?php if ($cardCount > 0): ?>
    <p class="small">This deck has <?= $cardCount ?> card<?= $cardCount === 1 ? '' : 's' ?>. Deleting it removes them, their images and everyone's progress on them.</p>
  <?php endif; ?>
  <form method="post" action="/manage/subcategory_delete_eval.php" class="actions">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="next" value="<?=h(ManageUI::dashboardUrl($userId))?>">
    <button type="submit" class="button danger" data-confirm="Delete the deck &quot;<?=h($sub['name'])?>&quot;<?= $cardCount > 0 ? ' and its ' . $cardCount . ' card' . ($cardCount === 1 ? '' : 's') : '' ?>? This cannot be undone.">Delete deck</button>
  </form>
</div>
<?= ApplicationUI::jsScript('/manage/manage.js') ?>
<?php footer_html(); ?>
