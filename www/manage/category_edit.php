<?php
// Edit category form. Evaluates to category_edit_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/ContentAccess.php';
require_once __DIR__ . '/../lib/CategoryManagement.php';
require_once __DIR__ . '/../lib/SubcategoryManagement.php';
Application::init();
require_login();

$id = (int)($_GET['id'] ?? 0);
$cat = $id > 0 ? CategoryManagement::findById($id) : null;
if (!$cat) {
    header('Location: /manage/?err=' . urlencode('Category not found.'));
    exit;
}
$ctx = UserContext::getLoggedInUserContext();
if (!ContentAccess::canEdit($ctx, (int)$cat['user_id'])) {
    http_response_code(403);
    die('You can only change your own content.');
}
$userId = (int)$cat['user_id'];
$stash = ManageUI::takeForm('category_edit_' . $id);
$form = $stash['data'] + $cat;
$err = $stash['err'];
$msg = $_GET['msg'] ?? null;
$subcategoryCount = count(SubcategoryManagement::listForCategory($id));
$cardCount = CategoryManagement::cardCount($id);

ApplicationUI::useSiteTheme(SiteManagement::findByUserId($userId));
header_html('Edit ' . $cat['name']);
?>
<div class="crumbs"><a href="<?=h(ManageUI::dashboardUrl($userId))?>">My Decks</a> › <?=h($cat['name'])?></div>
<div class="page-head">
  <h2>Edit category</h2>
  <a class="button" href="/manage/subcategory_add.php?category_id=<?= $id ?>">+ Add deck</a>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/manage/category_edit_eval.php" class="stack">
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
    <label class="inline">
      <input type="checkbox" name="is_public" value="1" <?= !empty($form['is_public']) ? 'checked' : '' ?>>
      Show on my public page <span class="hint">(unchecked: only you and admins can see this category and its decks)</span>
    </label>
    <div class="actions">
      <button type="submit" class="button primary">Save</button>
      <a class="button" href="<?=h(ManageUI::nextOr(ManageUI::dashboardUrl($userId)))?>">Cancel</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>Delete</h3>
  <?php if ($subcategoryCount > 0): ?>
    <p class="small">This category has <?= $subcategoryCount ?> deck<?= $subcategoryCount === 1 ? '' : 's' ?> with <?= $cardCount ?> card<?= $cardCount === 1 ? '' : 's' ?> in total. Deleting it removes all of them, their images and everyone's progress on them.</p>
  <?php endif; ?>
  <form method="post" action="/manage/category_delete_eval.php" class="actions">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="next" value="<?=h(ManageUI::dashboardUrl($userId))?>">
    <button type="submit" class="button danger" data-confirm="Delete the category &quot;<?=h($cat['name'])?>&quot;<?= $cardCount > 0 ? ' and its ' . $cardCount . ' card' . ($cardCount === 1 ? '' : 's') : '' ?>? This cannot be undone.">Delete category</button>
  </form>
</div>
<?= ApplicationUI::jsScript('/manage/manage.js') ?>
<?php footer_html(); ?>
