<?php
// Dashboard: all of one user's decks (categories → subcategories) with card
// counts and links to study, quiz, the card lists and the editors. Admins can
// switch between users.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/CategoryManagement.php';
require_once __DIR__ . '/../lib/CardProgress.php';
Application::init();
require_login();

$target = ManageUI::targetUser($_GET['user_id'] ?? null);
$userId = (int)$target['id'];
$site = SiteManagement::findByUserId($userId);
$me = current_user();
$isMe = ((int)$me['id'] === $userId);

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

ApplicationUI::useSiteTheme($site);
header_html($isMe ? 'My Decks' : 'Decks of ' . $target['first_name']);
?>

<?= ManageUI::siteSwitcherHtml($userId) ?>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<?php if (!$site): ?>
  <div class="card">
    <h2><?= $isMe ? 'Welcome!' : h($target['first_name']) . ' has no page yet' ?></h2>
    <p><?= $isMe ? 'You don\'t have a public page yet. Create one to start making decks.' : 'Create their page so they can start making decks.' ?></p>
    <form method="post" action="/manage/site_create_eval.php" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="user_id" value="<?= $userId ?>">
      <label>Page title
        <input type="text" name="title" value="<?=h($target['first_name'] . "'s Flashcards")?>" required maxlength="150">
      </label>
      <div class="actions"><button type="submit" class="button primary">Create page</button></div>
    </form>
  </div>
<?php else: ?>
  <div class="page-head">
    <h2><?=h($site['title'])?></h2>
    <div class="actions">
      <a class="button" href="<?=h(SiteResolver::publicHomeUrl($site))?>">View my page</a>
      <a class="button" href="/manage/site_settings.php?site_id=<?= (int)$site['id'] ?>">Page settings</a>
    </div>
  </div>
  <p class="small">
    <?php $canonical = SiteResolver::canonicalHomeUrl($site); ?>
    Public at <a href="<?=h($canonical)?>"><?=h($canonical)?></a>
    <?php if (empty($site['is_public'])): ?> · <span class="status-pending">Not public</span><?php endif; ?>
  </p>

  <div class="card">
    <h3>Decks</h3>
    <?= ManageUI::treeHtml(CategoryManagement::treeForUser($userId), $site, $userId, CardProgress::deckSummariesForUser((int)$me['id'], $userId)) ?>
  </div>
<?php endif; ?>
<?= ApplicationUI::jsScript('/manage/manage.js') ?>
<?php footer_html(); ?>
