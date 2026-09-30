<?php
// Edit card form: text, order, and the image (shown, replaceable, removable).
// Evaluates to card_edit_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/ContentAccess.php';
require_once __DIR__ . '/../lib/CardManagement.php';
require_once __DIR__ . '/../lib/ImageStorage.php';
require_once __DIR__ . '/../lib/CardProgress.php';
Application::init();
require_login();

$id = (int)($_GET['id'] ?? 0);
$card = $id > 0 ? CardManagement::findWithAncestors($id) : null;
if (!$card) {
    header('Location: /manage/?err=' . urlencode('Card not found.'));
    exit;
}
$ctx = UserContext::getLoggedInUserContext();
$userId = (int)$card['user_id'];
if (!ContentAccess::canEdit($ctx, $userId)) {
    http_response_code(403);
    die('You can only change your own content.');
}
$subcategoryId = (int)$card['subcategory_id'];
$stash = ManageUI::takeForm('card_edit_' . $id);
$form = $stash['data'] + $card;
$err = $stash['err'] ?? ($_GET['err'] ?? null);
$msg = $_GET['msg'] ?? null;
$imagesEnabled = ImageStorage::isConfigured();
$currentImage = ImageStorage::displayUrlForCard($card);
$listUrl = '/manage/cards.php?subcategory_id=' . $subcategoryId;
$nav = CardManagement::neighbors($id);
$next = validate_relative_next_path($_GET['next'] ?? '');
$editUrl = static function (array $other) use ($next): string {
    $n = ManageUI::nextForCard($next, (int)$other['id']);
    return '/manage/card_edit.php?id=' . (int)$other['id'] . ($n !== '' ? '&next=' . urlencode($n) : '');
};
$isFlagged = CardProgress::isCardFlagged($ctx->id, $id);
$selfUrl = '/manage/card_edit.php?id=' . $id . ($next !== '' ? '&next=' . urlencode($next) : '');
$cardLabel = static fn(array $c): string => mb_strimwidth(trim((string)$c['front_text']) !== '' ? (string)$c['front_text'] : (string)$c['back_text'], 0, 28, '…');

ApplicationUI::useSiteTheme(SiteManagement::findByUserId($userId));
header_html('Edit card');
?>
<div class="crumbs"><a href="<?=h(ManageUI::dashboardUrl($userId))?>">My Decks</a> › <a href="/manage/category_edit.php?id=<?= (int)$card['category_id'] ?>"><?=h($card['category_name'])?></a> › <a href="<?=h($listUrl)?>"><?=h($card['subcategory_name'])?></a> › Edit card</div>
<div class="page-head">
  <h2>Edit card <span class="small">card <?= (int)$nav['index'] ?> of <?= (int)$nav['total'] ?></span></h2>
  <div class="actions">
    <a class="button" href="<?=h($listUrl . '#card-' . $id)?>">All cards</a>
    <a class="button" href="/manage/card_add.php?subcategory_id=<?= $subcategoryId ?>">+ Add card</a>
  </div>
</div>
<nav class="card-nav" aria-label="Other cards in this deck">
  <?php if ($nav['prev']): ?>
    <a class="button small" href="<?=h($editUrl($nav['prev']))?>" title="<?=h($cardLabel($nav['prev']))?>">&larr; Previous card</a>
  <?php else: ?>
    <span class="button small disabled" aria-disabled="true">&larr; Previous card</span>
  <?php endif; ?>
  <?php if ($nav['next']): ?>
    <a class="button small" href="<?=h($editUrl($nav['next']))?>" title="<?=h($cardLabel($nav['next']))?>">Next card &rarr;</a>
  <?php else: ?>
    <span class="button small disabled" aria-disabled="true">Next card &rarr;</span>
  <?php endif; ?>
  <form method="post" action="/manage/card_flag_eval.php" class="card-nav-flag">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="flagged" value="<?= $isFlagged ? '0' : '1' ?>">
    <input type="hidden" name="return" value="<?=h($selfUrl)?>">
    <button type="submit" class="button small flag-toggle<?= $isFlagged ? ' is-flagged' : '' ?>" aria-pressed="<?= $isFlagged ? 'true' : 'false' ?>" title="<?= $isFlagged ? 'Remove your flag from this card' : 'Flag this card for yourself (shows in your Flagged tab)' ?>">
      <?= $isFlagged ? '&#9873; Flagged' : '&#9872; Flag this card' ?>
    </button>
  </form>
</nav>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/manage/card_edit_eval.php?id=<?= $id ?>" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <?= ManageUI::nextInputHtml() ?>
    <?php if ($currentImage !== null): ?>
      <div>
        <span class="hint">Current image (<?= (int)$card['image_width'] ?>×<?= (int)$card['image_height'] ?>, <?=h(ImageStorage::humanBytes((int)$card['image_size_bytes']))?>)</span>
        <img src="<?=h($currentImage)?>" class="image-preview" alt="">
      </div>
      <label class="inline">
        <input type="checkbox" name="remove_image" value="1" <?= !empty($form['remove_image']) ? 'checked' : '' ?>>
        Remove image <span class="hint">(the card must then have front text)</span>
      </label>
    <?php endif; ?>
    <?php if ($imagesEnabled): ?>
      <label><?= $currentImage !== null ? 'Replace image' : 'Front (image)' ?> <span class="hint">optional — a photo, a map, a diagram</span>
        <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" data-preview-target="image-preview" data-paste-note="paste-note">
      </label>
      <img id="image-preview" class="image-preview" hidden alt="">
      <p id="paste-note" class="small paste-note" hidden></p>
      <p class="small">JPEG, PNG, WebP or GIF up to <?=h(ImageStorage::humanBytes(ImageStorage::maxBytes()))?>; larger pictures are resized to <?= ImageStorage::MAX_LONG_EDGE ?>px. Tip: copy a picture and press <kbd>&#8984;V</kbd> (or drag it onto this form) to use it.</p>
    <?php elseif ($currentImage === null): ?>
      <p class="notice">Image uploads are disabled until an admin configures Image Storage. Text cards still work.</p>
    <?php endif; ?>
    <label>Front (text) <span class="hint"><?= ($imagesEnabled || $currentImage !== null) ? 'optional when there is an image' : 'the question or prompt' ?></span>
      <textarea name="front_text" rows="3" maxlength="<?= CardManagement::MAX_TEXT ?>"><?=h($form['front_text'])?></textarea>
    </label>
    <label>Back <span class="hint">the answer; separate several accepted answers with "/"</span>
      <textarea name="back_text" rows="3" maxlength="<?= CardManagement::MAX_TEXT ?>" required><?=h($form['back_text'])?></textarea>
    </label>
    <div class="grid form-grid">
      <label>Order <span class="hint">lower numbers first</span>
        <input type="number" name="sort_order" value="<?= (int)$form['sort_order'] ?>" min="0" max="99999">
      </label>
    </div>
    <div class="actions">
      <button type="submit" class="button primary">Save</button>
      <?php if ($nav['next']): ?>
        <button type="submit" class="button" name="save_next" value="1" title="Save this card and open the next one">Save &amp; next &rarr;</button>
      <?php endif; ?>
      <a class="button" href="<?=h(ManageUI::nextOr($listUrl . '#card-' . $id))?>">Cancel</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>Delete</h3>
  <p class="small">Deleting the card removes its image and everyone's progress on it.</p>
  <form method="post" action="/manage/card_delete_eval.php" class="actions">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <button type="submit" class="button danger" data-confirm="Delete this card? This cannot be undone.">Delete card</button>
  </form>
</div>
<?= ApplicationUI::jsScript('/manage/manage.js') ?>
<?php footer_html(); ?>
