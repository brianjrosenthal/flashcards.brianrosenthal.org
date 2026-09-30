<?php
// Step 1 of the picture import: choose a ZIP of images (and/or image files).
// Each picture becomes a card with the image on the front and the file name
// on the back. Evaluates to card_import_upload_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/ContentAccess.php';
require_once __DIR__ . '/../lib/SubcategoryManagement.php';
require_once __DIR__ . '/../lib/CardManagement.php';
require_once __DIR__ . '/../lib/ImageStorage.php';
require_once __DIR__ . '/../lib/CardImageImport.php';
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
$err = $_GET['err'] ?? null;
$listUrl = '/manage/cards.php?subcategory_id=' . $subcategoryId;
$imagesEnabled = ImageStorage::isConfigured();
$postMax = (string)ini_get('post_max_size');

ApplicationUI::useSiteTheme(SiteManagement::findByUserId($userId));
header_html('Import pictures');
?>
<div class="crumbs"><a href="<?=h(ManageUI::dashboardUrl($userId))?>">My Decks</a> › <a href="/manage/category_edit.php?id=<?= (int)$cat['id'] ?>"><?=h($cat['name'])?></a> › <a href="<?=h($listUrl)?>"><?=h($sub['name'])?></a> › Import pictures</div>
<div class="page-head">
  <h2>Import pictures into <?=h($sub['name'])?></h2>
  <div class="actions"><a class="button" href="<?=h($listUrl)?>">All cards (<?= CardManagement::countForSubcategory($subcategoryId) ?>)</a></div>
</div>
<p class="small">Every picture becomes one card: the picture on the front, the file name on the back. Underscores turn into spaces, so <code>Ada_Lovelace.jpg</code> becomes a card whose back reads <strong>Ada Lovelace</strong>. Name the files, zip them up, and upload the ZIP here. You'll see exactly what will be created before anything is imported.</p>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<?php if (!$imagesEnabled): ?>
  <p class="notice">Image uploads are disabled until an admin configures Image Storage, so pictures cannot be imported yet.</p>
<?php else: ?>
<div class="card">
  <form method="post" action="/manage/card_import_upload_eval.php?subcategory_id=<?= $subcategoryId ?>" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="subcategory_id" value="<?= $subcategoryId ?>">
    <label>ZIP file, or pictures <span class="hint">a .zip of images, several image files, or both</span>
      <input type="file" name="files[]" multiple accept=".zip,application/zip,image/jpeg,image/png,image/webp,image/gif" required>
    </label>
    <p class="small">JPEG, PNG, WebP or GIF, up to <?=h(ImageStorage::humanBytes(ImageStorage::maxBytes()))?> each; pictures are resized to <?= ImageStorage::MAX_LONG_EDGE ?>px. One upload can be at most <strong><?=h($postMax)?></strong> in total (the server's limit), and up to <?= CardImageImport::MAX_ENTRIES ?> pictures. Folders inside the ZIP are fine; only the file name is used.</p>
    <div class="actions">
      <button type="submit" class="button primary">Upload and review</button>
      <a class="button" href="<?=h($listUrl)?>">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>
<?php footer_html(); ?>
