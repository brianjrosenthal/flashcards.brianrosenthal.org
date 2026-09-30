<?php
// New card form (optional image + front text, back text). Evaluates to
// card_add_eval.php. The subcategory id rides on the action URL as well as in
// the body, so an over-size upload (which empties $_POST) can still be
// reported against the right deck.
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
$stash = ManageUI::takeForm('card_add_' . $subcategoryId);
$form = $stash['data'] + ['front_text' => '', 'back_text' => '', 'add_another' => 1];
$err = $stash['err'] ?? ($_GET['err'] ?? null);
$msg = $_GET['msg'] ?? null;
$imagesEnabled = ImageStorage::isConfigured();
$listUrl = '/manage/cards.php?subcategory_id=' . $subcategoryId;

ApplicationUI::useSiteTheme(SiteManagement::findByUserId($userId));
header_html('New card');
?>
<div class="crumbs"><a href="<?=h(ManageUI::dashboardUrl($userId))?>">My Decks</a> › <a href="/manage/category_edit.php?id=<?= (int)$cat['id'] ?>"><?=h($cat['name'])?></a> › <a href="<?=h($listUrl)?>"><?=h($sub['name'])?></a> › New card</div>
<div class="page-head">
  <h2>New card</h2>
  <div class="actions">
    <a class="button" href="<?=h($listUrl)?>">All cards (<?= CardManagement::countForSubcategory($subcategoryId) ?>)</a>
  </div>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/manage/card_add_eval.php?subcategory_id=<?= $subcategoryId ?>" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="subcategory_id" value="<?= $subcategoryId ?>">
    <?= ManageUI::nextInputHtml() ?>
    <?php if ($imagesEnabled): ?>
      <label>Front (image) <span class="hint">optional — a photo, a map, a diagram</span>
        <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" data-preview-target="image-preview">
      </label>
      <img id="image-preview" class="image-preview" hidden alt="">
      <p class="small">JPEG, PNG, WebP or GIF up to <?=h(ImageStorage::humanBytes(ImageStorage::maxBytes()))?>; larger pictures are resized to <?= ImageStorage::MAX_LONG_EDGE ?>px.</p>
    <?php else: ?>
      <p class="notice">Image uploads are disabled until an admin configures Image Storage. Text cards still work.</p>
    <?php endif; ?>
    <label>Front (text) <span class="hint"><?= $imagesEnabled ? 'optional when there is an image' : 'the question or prompt' ?></span>
      <textarea name="front_text" rows="3" maxlength="<?= CardManagement::MAX_TEXT ?>" autofocus><?=h($form['front_text'])?></textarea>
    </label>
    <label>Back <span class="hint">the answer; separate several accepted answers with "/"</span>
      <textarea name="back_text" rows="3" maxlength="<?= CardManagement::MAX_TEXT ?>" required><?=h($form['back_text'])?></textarea>
    </label>
    <label class="inline">
      <input type="checkbox" name="add_another" value="1" <?= !empty($form['add_another']) ? 'checked' : '' ?>>
      Add another card after this one
    </label>
    <div class="actions">
      <button type="submit" class="button primary">Add card</button>
      <a class="button" href="<?=h(ManageUI::nextOr($listUrl))?>">Cancel</a>
    </div>
  </form>
</div>
<?= ApplicationUI::jsScript('/manage/manage.js') ?>
<?php footer_html(); ?>
