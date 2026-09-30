<?php
// New subcategory form. Evaluates to subcategory_add_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/ContentAccess.php';
require_once __DIR__ . '/../lib/CategoryManagement.php';
Application::init();
require_login();

$categoryId = (int)($_GET['category_id'] ?? 0);
$cat = $categoryId > 0 ? CategoryManagement::findById($categoryId) : null;
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
$stash = ManageUI::takeForm('subcategory_add_' . $categoryId);
$form = $stash['data'] + ['name' => '', 'slug' => '', 'description' => ''];
$err = $stash['err'];

ApplicationUI::useSiteTheme(SiteManagement::findByUserId($userId));
header_html('New deck');
?>
<div class="crumbs"><a href="<?=h(ManageUI::dashboardUrl($userId))?>">My Decks</a> › <a href="/manage/category_edit.php?id=<?= $categoryId ?>"><?=h($cat['name'])?></a> › New deck</div>
<h2>New deck in <?=h($cat['name'])?></h2>
<p class="small">A deck (subcategory) holds the cards, like <em>Presidents</em> inside <em>US History</em>. You can study one deck or the whole category.</p>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/manage/subcategory_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="category_id" value="<?= $categoryId ?>">
    <?= ManageUI::nextInputHtml() ?>
    <div class="grid form-grid">
      <label>Name
        <input type="text" name="name" value="<?=h($form['name'])?>" required maxlength="150" autofocus>
      </label>
      <label>URL name <span class="hint">optional; made from the name if blank</span>
        <input type="text" name="slug" value="<?=h($form['slug'])?>" maxlength="80" placeholder="presidents">
      </label>
    </div>
    <label>Description <span class="hint">optional, shown under the name</span>
      <input type="text" name="description" value="<?=h($form['description'])?>" maxlength="500">
    </label>
    <div class="actions">
      <button type="submit" class="button primary">Create deck</button>
      <a class="button" href="<?=h(ManageUI::nextOr(ManageUI::dashboardUrl($userId)))?>">Cancel</a>
    </div>
  </form>
</div>
<?= ApplicationUI::jsScript('/manage/manage.js') ?>
<?php footer_html(); ?>
