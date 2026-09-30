<?php
// Deck picker for studying: every category the signed-in user owns (and any
// other people's decks they have progress on), each with its subcategories,
// learned/total counts and Study / Quiz links. study.php runs the deck itself.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CardProgress.php';
Application::init();
require_login();

$me = current_user();
$userId = (int)$me['id'];

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

$decks = CardProgress::listDecksForUser($userId);

header_html('Study');
?>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="page-head">
  <h2>Pick a deck to study</h2>
</div>

<?php if ($decks === []): ?>
  <div class="card empty-deck">
    <h2>No decks yet</h2>
    <p>Start with a subject, like "US History" or "Spanish", then add a deck of cards inside it.</p>
    <a class="button primary" href="/manage/category_add.php">Add your first category</a>
  </div>
<?php else: ?>
  <div class="deck-grid">
    <?php foreach ($decks as $entry): ?>
      <?php
        $cat = $entry['category'];
        $catId = (int)$cat['id'];
        $catCards = (int)$cat['card_count'];
        $catLearned = (int)$cat['learned'];
      ?>
      <div class="deck-card">
        <h3>
          <?=h($cat['name'])?>
          <?php if (!$entry['is_mine']): ?><span class="small">(<?=h($entry['owner_first_name'])?>'s)</span><?php endif; ?>
          <?php if (empty($cat['is_public'])): ?><span class="badge draft">Private</span><?php endif; ?>
        </h3>
        <div class="deck-meta">
          <?= $catCards ?> card<?= $catCards === 1 ? '' : 's' ?><?= $catCards > 0 ? ' · ' . $catLearned . '/' . $catCards . ' got' : '' ?>
        </div>
        <?php if ($catCards > 0): ?>
          <div class="actions">
            <a class="button small primary" href="/review/study.php?category=<?= $catId ?>">Study all</a>
            <a class="button small" href="/quiz/?category=<?= $catId ?>">Quiz all</a>
          </div>
        <?php endif; ?>
        <?php if ($entry['subcategories'] === []): ?>
          <p class="small">No decks inside yet.<?= $entry['is_mine'] ? ' <a href="/manage/subcategory_add.php?category_id=' . $catId . '">Add one</a>' : '' ?></p>
        <?php else: ?>
          <ul class="tree">
            <?php foreach ($entry['subcategories'] as $sub): ?>
              <?php
                $subId = (int)$sub['id'];
                $subCards = (int)$sub['card_count'];
                $subLearned = (int)$sub['learned'];
              ?>
              <li>
                <div class="tree-node level-2">
                  <span class="title"><?=h($sub['name'])?></span>
                  <span class="small"><?= $subCards > 0 ? $subLearned . '/' . $subCards . ' got' : 'no cards' ?></span>
                  <span class="tools">
                    <?php if ($subCards > 0): ?>
                      <a class="study" href="/review/study.php?subcategory=<?= $subId ?>">Study</a>
                      <a href="/quiz/?subcategory=<?= $subId ?>">Quiz</a>
                    <?php endif; ?>
                    <?php if ($entry['is_mine']): ?>
                      <a href="/manage/cards.php?subcategory_id=<?= $subId ?>">Cards</a>
                    <?php endif; ?>
                  </span>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="small" style="margin-top:14px;"><a href="/manage/">Manage my decks</a> · <a href="/progress/">My stats</a></p>
<?php endif; ?>

<?php footer_html(); ?>
