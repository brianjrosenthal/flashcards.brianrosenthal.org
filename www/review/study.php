<?php
// The flashcard study engine — the heart of the app. One deck (a subcategory
// or a whole category) is embedded as JSON in the viewer's order; reads are
// server-rendered, and only marks / flags / positions POST to the dedicated
// eval endpoints in this directory. Anyone may study a public deck; only a
// signed-in viewer's progress is saved.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/Deck.php';
require_once __DIR__ . '/../lib/CardProgress.php';
require_once __DIR__ . '/../lib/SiteResolver.php';
Application::init();

$me = current_user();                          // also restores a remember-me login
$ctx = UserContext::getLoggedInUserContext();  // null for an anonymous visitor
$canSave = $ctx !== null;

$deck = Deck::fromRequest($_GET);
if ($deck === null) {
    http_response_code(404);
    header_html('Deck not found');
    echo '<div class="card empty-deck"><h2>Deck not found</h2><p>That deck does not exist, or it has been removed.</p>'
       . '<a class="button primary" href="' . ($canSave ? '/review/' : '/') . '">' . ($canSave ? 'Back to decks' : 'Home') . '</a></div>';
    footer_html();
    exit;
}
if (!$deck->canView($ctx)) {
    if ($ctx === null) {
        require_login();
    }
    http_response_code(403);
    header_html('Private deck');
    echo '<div class="card empty-deck"><h2>This deck is private</h2><p>Only its owner can study it.</p>'
       . '<a class="button primary" href="/review/">Back to decks</a></div>';
    footer_html();
    exit;
}

$filter = (string)($_GET['filter'] ?? CardProgress::FILTER_ALL);
if (!in_array($filter, [CardProgress::FILTER_ALL, CardProgress::FILTER_FLAGGED, CardProgress::FILTER_NEEDS_REVIEW], true) || !$canSave) {
    $filter = CardProgress::FILTER_ALL;
}

$viewerId = $ctx ? $ctx->id : 0;
$cards = CardProgress::getDeckCards($viewerId, $deck, $filter);
$isShuffled = $canSave && CardProgress::isDeckShuffled($viewerId, $deck);

// The full pass keeps a resume point per viewer and deck; the flagged/misses
// passes shrink as you clear them, so they start at the top.
$persistPosition = $canSave && $filter === CardProgress::FILTER_ALL;
$startAt = $persistPosition ? min(CardProgress::deckPositionFor($viewerId, $deck), count($cards)) : 0;

// ?card=N (the edit page sends you back here) lands on that card whatever
// the saved position says.
$returnToCard = (int)($_GET['card'] ?? 0);
if ($returnToCard > 0) {
    foreach ($cards as $i => $row) {
        if ((int)$row['id'] === $returnToCard) {
            $startAt = $i;
            break;
        }
    }
}
$canEdit = $deck->canEdit($ctx);

$deckJson = array_map(fn($row) => [
    'id' => $row['id'],
    'front_text' => $row['front_text'],
    'back_text' => $row['back_text'],
    'image_url' => $row['image_url'],
    'image_width' => $row['image_width'],
    'image_height' => $row['image_height'],
    'subcategory_name' => $row['subcategory_name'],
    'flagged' => $row['flagged'],
    'marked' => $row['marked'],
], $cards);
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

$deckQuery = $deck->queryString();
$filterLabels = [
    CardProgress::FILTER_ALL => 'All cards',
    CardProgress::FILTER_FLAGGED => 'Flagged',
    CardProgress::FILTER_NEEDS_REVIEW => 'Misses',
];
$studyUrl = fn(string $f) => $deck->studyUrl($f === CardProgress::FILTER_ALL ? '' : $f);

// Where "Back to decks" goes: my own picker for my decks, else the owner's
// public page for this category / deck.
$site = $deck->site();
$isMine = $ctx !== null && $ctx->id === $deck->ownerUserId;
if ($isMine || $site === null) {
    $backUrl = $canSave ? '/review/' : '/';
} else {
    $backUrl = SiteResolver::urlFor(
        SiteResolver::basePathFor($site),
        (string)$deck->category['slug'],
        $deck->subcategory ? (string)$deck->subcategory['slug'] : null
    );
}
$thisUrl = '/review/study.php?' . $deckQuery . ($filter !== CardProgress::FILTER_ALL ? '&filter=' . $filter : '');

ApplicationUI::useSiteTheme($site);
header_html($deck->label());
?>

<div class="crumbs">
  <a href="<?=h($backUrl)?>">&larr; Back to decks</a>
  <span>&rsaquo;</span>
  <strong><?=h($deck->label())?></strong>
  <?php if (!$isMine): ?><span class="small">(<?=h($site['title'] ?? 'shared deck')?>)</span><?php endif; ?>
</div>

<div class="review-toolbar">
  <?php if ($canSave): ?>
    <nav class="deck-tabs" aria-label="Which cards">
      <?php foreach ($filterLabels as $key => $label): ?>
        <a href="<?=h($studyUrl($key))?>" class="deck-tab<?= $filter === $key ? ' active' : '' ?>"><?=h($label)?></a>
      <?php endforeach; ?>
    </nav>
    <?php if ($filter === CardProgress::FILTER_ALL): ?>
      <div class="deck-order">
        <span class="small"><?= $isShuffled ? 'Shuffled' : 'In order' ?></span>
        <form method="post" action="/review/shuffle_eval.php">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="deck_type" value="<?=h($deck->type)?>">
          <input type="hidden" name="deck_id" value="<?= $deck->id ?>">
          <button type="submit" class="button small">&#128256; Shuffle</button>
        </form>
        <?php if ($isShuffled): ?>
          <form method="post" action="/review/order_eval.php">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="deck_type" value="<?=h($deck->type)?>">
            <input type="hidden" name="deck_id" value="<?= $deck->id ?>">
            <button type="submit" class="button small">Original order</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <p class="notice small" style="margin:0;">Flipping and marking work, but nothing is saved.
      <a href="/login.php?next=<?=h(urlencode($thisUrl))?>">Sign in to save your progress</a></p>
  <?php endif; ?>
</div>

<?php if ($cards === []): ?>
  <div class="card empty-deck">
    <?php if ($filter === CardProgress::FILTER_FLAGGED): ?>
      <h2>No flagged cards in <?=h($deck->name)?></h2>
      <p>Tap the flag on any card to collect cards here for a focused pass.</p>
      <a class="button primary" href="<?=h($studyUrl(CardProgress::FILTER_ALL))?>">Back to all cards</a>
    <?php elseif ($filter === CardProgress::FILTER_NEEDS_REVIEW): ?>
      <h2>No misses in <?=h($deck->name)?> &#127881;</h2>
      <p>Nothing is marked "Need More Review" right now. Keep it up!</p>
      <a class="button primary" href="<?=h($studyUrl(CardProgress::FILTER_ALL))?>">Back to all cards</a>
    <?php else: ?>
      <h2>This deck is empty</h2>
      <?php if ($deck->canEdit($ctx)): ?>
        <p>Add some cards and come back to study them.</p>
        <div class="actions" style="justify-content:center;">
          <?php if ($deck->isCategory()): ?>
            <a class="button primary" href="/manage/subcategory_add.php?category_id=<?= $deck->id ?>">Add a deck</a>
          <?php else: ?>
            <a class="button primary" href="/manage/card_add.php?subcategory_id=<?= $deck->id ?>">Add a card</a>
          <?php endif; ?>
          <a class="button" href="/manage/">My decks</a>
        </div>
      <?php else: ?>
        <p>No cards have been added here yet — check back soon!</p>
        <a class="button primary" href="<?=h($backUrl)?>">Back</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php else: ?>

  <div class="progress-wrap">
    <div class="progress-track"><div class="progress-fill" id="progress-fill"></div></div>
    <div class="progress-text small" id="progress-text"></div>
  </div>

  <div class="flashcard-stage" id="flashcard-stage">
    <div class="flashcard-row">
      <button type="button" class="nav-btn" id="btn-prev" aria-label="Previous card" title="Previous card (&#8592;)">&lt;</button>
      <div class="flashcard" id="flashcard" tabindex="0" role="button" aria-label="Flip card">
        <?php if ($canSave): ?>
          <button type="button" class="flag-btn" id="flag-btn" aria-pressed="false" title="Flag this card (f)">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
              <path class="flag-outline" d="M5 3v18M5 4h11l-2.5 4L16 12H5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              <path class="flag-fill" d="M5 4h11l-2.5 4L16 12H5z" fill="currentColor"/>
            </svg>
          </button>
        <?php endif; ?>
        <div class="flashcard-face flashcard-front">
          <img id="card-image" class="flashcard-image" alt="" hidden>
          <div id="card-front-text" class="flashcard-word flashcard-front-text"></div>
          <div class="flashcard-hint small">tap to see the answer</div>
        </div>
        <div class="flashcard-face flashcard-back">
          <div id="card-back" class="flashcard-definition flashcard-back-text"></div>
          <?php if ($deck->isCategory()): ?>
            <div id="card-source" class="card-source"></div>
          <?php endif; ?>
          <div class="flashcard-hint small">tap to see the front</div>
        </div>
      </div>
      <button type="button" class="nav-btn" id="btn-next" aria-label="Next card" title="Next card (&#8594;)">&gt;</button>
    </div>

    <div class="mark-buttons">
      <button type="button" class="button mark-btn mark-miss" id="btn-miss" title="Press 2">Need More Review</button>
      <button type="button" class="button mark-btn mark-got" id="btn-got" title="Press 1">Got it! &#10024;</button>
    </div>
    <?php if ($canEdit): ?>
      <p class="card-tools small"><a id="edit-card-link" href="#" title="Edit this card (e)">&#9998; Edit this card</a></p>
    <?php endif; ?>
    <p class="keyboard-hint small">space = flip &nbsp;·&nbsp; &#8592; &#8594; = back / forward &nbsp;·&nbsp; 1 = got it &nbsp;·&nbsp; 2 = need more review<?= $canSave ? ' &nbsp;·&nbsp; f = flag' : '' ?><?= $canEdit ? ' &nbsp;·&nbsp; e = edit' : '' ?></p>
  </div>

  <div class="card deck-done hidden" id="deck-done">
    <div class="deck-done-burst" aria-hidden="true">&#127881;</div>
    <h2>Deck complete!</h2>
    <p id="deck-done-tally" class="deck-done-tally"></p>
    <p><button type="button" class="button small" id="btn-done-back">&lt; Back to the last card</button></p>
    <div class="actions" style="justify-content:center;flex-wrap:wrap;">
      <?php if ($canSave && $filter === CardProgress::FILTER_ALL): ?>
        <form method="post" action="/review/shuffle_eval.php">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="deck_type" value="<?=h($deck->type)?>">
          <input type="hidden" name="deck_id" value="<?= $deck->id ?>">
          <button type="submit" class="button primary">&#128256; Shuffle &amp; go again</button>
        </form>
        <form method="post" action="/review/order_eval.php">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="deck_type" value="<?=h($deck->type)?>">
          <input type="hidden" name="deck_id" value="<?= $deck->id ?>">
          <button type="submit" class="button">Start over in order</button>
        </form>
        <a class="button" href="<?=h($studyUrl(CardProgress::FILTER_NEEDS_REVIEW))?>">Review misses</a>
        <a class="button" href="<?=h($studyUrl(CardProgress::FILTER_FLAGGED))?>">Review flagged</a>
        <a class="button" href="<?=h($deck->quizUrl())?>">&#9997;&#65039; Take a quiz</a>
      <?php elseif ($canSave): ?>
        <a class="button primary" href="<?=h($studyUrl($filter))?>">Go again</a>
        <a class="button" href="<?=h($studyUrl(CardProgress::FILTER_ALL))?>">Back to all cards</a>
      <?php else: ?>
        <a class="button primary" href="<?=h($thisUrl)?>">Go again</a>
        <a class="button" href="/login.php?next=<?=h(urlencode($thisUrl))?>">Sign in to save your progress</a>
      <?php endif; ?>
    </div>
  </div>

  <div id="toast" class="toast hidden" role="alert"></div>

  <script>
    const DECK = <?= json_encode($deckJson, $jsonFlags) ?>;
    const START_AT = <?= (int)$startAt ?>;
    const PERSIST_POSITION = <?= $persistPosition ? 'true' : 'false' ?>;
    const DECK_TYPE = <?= json_encode($deck->type, $jsonFlags) ?>;
    const DECK_ID = <?= $deck->id ?>;
    const CSRF = <?= json_encode($canSave ? csrf_token() : '', $jsonFlags) ?>;
    const CAN_SAVE = <?= $canSave ? 'true' : 'false' ?>;
    const CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
    const THIS_URL = <?= json_encode($thisUrl, $jsonFlags) ?>;
  </script>
  <?= ApplicationUI::jsScript('/review/review.js') ?>
<?php endif; ?>

<?php footer_html(); ?>
