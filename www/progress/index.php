<?php
// Personal stats page: big-number tiles plus a 14-day activity chart, quiz
// tiles and the cards missed most often — optionally scoped to one deck.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/Deck.php';
require_once __DIR__ . '/../lib/CardProgress.php';
require_once __DIR__ . '/../lib/QuizManagement.php';
Application::init();
require_login();

$me = current_user();
$userId = (int)$me['id'];
$ctx = UserContext::getLoggedInUserContext();

// Optional deck filter (?deck=category:N | subcategory:N) — every number on
// the page follows it. Unknown or private decks fall back to "all".
$decks = CardProgress::listDecksForUser($userId);
$deckParam = (string)($_GET['deck'] ?? '');
$deck = null;
if (preg_match('/^(category|subcategory):(\d+)$/', $deckParam, $m)) {
    $candidate = Deck::of($m[1], (int)$m[2]);
    if ($candidate !== null && $candidate->canView($ctx)) {
        $deck = $candidate;
    }
}
$deckValue = $deck ? $deck->type . ':' . $deck->id : '';
$deckQuery = $deck ? '&deck=' . urlencode($deckValue) : '';

// The study / quiz links the page hands out follow the chosen deck; without
// one they go to the pickers.
$studyHref = fn(string $filter) => $deck ? $deck->studyUrl($filter) : '/review/';
$quizHref = $deck ? $deck->quizUrl() : '/quiz/';
$missesQuizHref = $quizHref . ($deck ? '&' : '?') . 'source=misses';

$stats = CardProgress::getStatsForUser($userId, $deck);
$quiz = QuizManagement::getQuizStatsForUser($userId, $deck);

// How many most-missed cards to show: top 20 (default), top 40, or everything.
$missedChoices = ['20' => 'Top 20', '40' => 'Top 40', 'all' => 'All time'];
$missedShown = (string)($_GET['missed'] ?? '20');
if (!isset($missedChoices[$missedShown])) {
    $missedShown = '20';
}

// One table across both halves of the app: flashcard misses (every Need More
// Review) and quiz misses (every answer that earned nothing), merged per card
// and ranked by the total. Both sources are fetched whole so the ranking is
// over the combined count, then cut to the chosen size.
$missed = [];
foreach (CardProgress::getMostMissedCards($userId, null, $deck) as $row) {
    $missed[$row['card_id']] = [
        'card_id' => $row['card_id'],
        'front_text' => $row['front_text'],
        'back_text' => $row['back_text'],
        'subcategory_name' => $row['subcategory_name'],
        'flashcard_misses' => $row['misses'],
        'quiz_misses' => 0,
    ];
}
foreach (QuizManagement::getMostMissedCardsForUser($userId, null, $deck) as $row) {
    $id = (int)$row['card_id'];
    if (!isset($missed[$id])) {
        $missed[$id] = [
            'card_id' => $id,
            'front_text' => (string)$row['front_text'],
            'back_text' => (string)$row['back_text'],
            'subcategory_name' => (string)($row['subcategory_name'] ?? ''),
            'flashcard_misses' => 0,
            'quiz_misses' => 0,
        ];
    }
    $missed[$id]['quiz_misses'] = (int)$row['quiz_misses'];
}
foreach ($missed as &$row) {
    $row['total_misses'] = $row['flashcard_misses'] + $row['quiz_misses'];
}
unset($row);
usort($missed, fn($a, $b) => [$b['total_misses'], $a['card_id']] <=> [$a['total_misses'], $b['card_id']]);
if ($missedShown !== 'all') {
    $missed = array_slice($missed, 0, (int)$missedShown);
}

$maxDaily = 0;
foreach ($stats['daily'] as $day) {
    $maxDaily = max($maxDaily, $day['count']);
}

$quizAnswered = (int)($quiz['answered'] ?? 0);
$quizAccuracy = isset($quiz['accuracy_pct']) ? (int)$quiz['accuracy_pct'] : ($quizAnswered > 0 ? (int)round(100 * (int)($quiz['correct'] ?? 0) / $quizAnswered) : 0);

header_html('My Stats');
?>

<div class="progress-toolbar">
  <h2>Hi <?=h($me['first_name'])?>! Here's your progress &#127775;</h2>
  <?php if ($decks !== []): ?>
    <form method="get" action="/progress/" class="deck-picker">
      <input type="hidden" name="missed" value="<?=h($missedShown)?>">
      <label class="small">Deck
        <select name="deck" onchange="this.form.submit()">
          <option value="">All decks</option>
          <?php foreach ($decks as $entry): ?>
            <?php $cat = $entry['category']; $catValue = 'category:' . (int)$cat['id']; ?>
            <optgroup label="<?=h($cat['name'] . ($entry['is_mine'] ? '' : ' (' . $entry['owner_first_name'] . "'s)"))?>">
              <option value="<?=h($catValue)?>" <?= $deckValue === $catValue ? 'selected' : '' ?>>All of <?=h($cat['name'])?> (<?= (int)$cat['card_count'] ?>)</option>
              <?php foreach ($entry['subcategories'] as $sub): ?>
                <?php $subValue = 'subcategory:' . (int)$sub['id']; ?>
                <option value="<?=h($subValue)?>" <?= $deckValue === $subValue ? 'selected' : '' ?>><?=h($sub['name'])?> (<?= (int)$sub['card_count'] ?>)</option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
  <?php endif; ?>
</div>
<?php if ($deck): ?>
  <p class="small progress-deck-note">Showing just <strong><?=h($deck->label())?></strong>.
    <a href="/progress/?missed=<?=h($missedShown)?>">See all decks</a></p>
<?php endif; ?>

<div class="stat-tiles">
  <div class="stat-tile stat-mastered">
    <div class="stat-number"><?= number_format($stats['got_it']) ?></div>
    <div class="stat-label">Got it</div>
    <div class="stat-sub small">of <?= number_format($stats['total_cards']) ?> cards</div>
  </div>
  <div class="stat-tile stat-misses">
    <div class="stat-number"><?= number_format($stats['needs_review']) ?></div>
    <div class="stat-label">Need more review</div>
    <div class="stat-sub small"><a href="<?=h($studyHref(CardProgress::FILTER_NEEDS_REVIEW))?>">review them now</a></div>
  </div>
  <div class="stat-tile stat-flagged">
    <div class="stat-number"><?= number_format($stats['flagged']) ?></div>
    <div class="stat-label">Flagged</div>
    <div class="stat-sub small"><a href="<?=h($studyHref(CardProgress::FILTER_FLAGGED))?>">review them now</a></div>
  </div>
  <div class="stat-tile stat-today">
    <div class="stat-number"><?= number_format($stats['reviewed_today']) ?></div>
    <div class="stat-label">Reviewed today</div>
    <div class="stat-sub small"><?= number_format($stats['total_reviews']) ?> all-time</div>
  </div>
</div>

<div class="card">
  <h3>Last 14 days</h3>
  <?php if ($maxDaily === 0): ?>
    <p class="small">No reviews yet<?= $deck ? ' in this deck' : '' ?> — <a href="<?=h($studyHref(''))?>">flip your first card</a> and the chart fills in!</p>
  <?php else: ?>
    <div class="daily-chart">
      <?php foreach ($stats['daily'] as $day): ?>
        <?php $pct = (int)round(($day['count'] / $maxDaily) * 100); ?>
        <div class="daily-col" title="<?= h(date('M j', strtotime($day['date']))) ?>: <?= (int)$day['count'] ?> review<?= $day['count'] === 1 ? '' : 's' ?>">
          <div class="daily-count small"><?= $day['count'] > 0 ? (int)$day['count'] : '' ?></div>
          <div class="daily-bar-track"><div class="daily-bar" style="height:<?= max($pct, $day['count'] > 0 ? 6 : 0) ?>%;"></div></div>
          <div class="daily-label small"><?= h(date('D', strtotime($day['date']))[0]) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<h3 class="progress-section-head">Quiz &#9997;&#65039;</h3>
<?php if ($quizAnswered === 0): ?>
  <div class="card">
    <p class="small">No quiz answers yet<?= $deck ? ' on this deck' : '' ?> — <a href="<?=h($quizHref)?>">type your first answer</a> and your points start stacking up.</p>
  </div>
<?php else: ?>
  <div class="stat-tiles">
    <div class="stat-tile stat-points">
      <div class="stat-number"><?= number_format((int)($quiz['points'] ?? 0)) ?></div>
      <div class="stat-label">Quiz points</div>
      <div class="stat-sub small"><?= number_format((int)($quiz['overridden'] ?? 0)) ?> claimed "right anyway"</div>
    </div>
    <div class="stat-tile stat-accuracy">
      <div class="stat-number"><?= $quizAccuracy ?>%</div>
      <div class="stat-label">Answered right</div>
      <div class="stat-sub small"><?= number_format((int)($quiz['correct'] ?? 0)) ?> of <?= number_format($quizAnswered) ?><?= !empty($quiz['close']) ? ' · ' . number_format((int)$quiz['close']) . ' close' : '' ?></div>
    </div>
    <div class="stat-tile stat-today">
      <div class="stat-number"><?= number_format((int)($quiz['answered_today'] ?? 0)) ?></div>
      <div class="stat-label">Quizzed today</div>
      <div class="stat-sub small"><?= isset($quiz['best_streak']) ? 'best streak ' . number_format((int)$quiz['best_streak']) . ' · ' : '' ?><a href="<?=h($quizHref)?>">play a round</a></div>
    </div>
  </div>
<?php endif; ?>

<h3 class="progress-section-head">Cards you miss the most &#128269;</h3>
<div class="card">
  <?php if ($missed === []): ?>
    <p class="small">Nothing missed yet — every flashcard you mark Need More Review and every quiz answer that doesn't land shows up here.</p>
  <?php else: ?>
    <div class="missed-toolbar">
      <p class="small">Counting every Need More Review on a flashcard and every quiz answer that didn't earn points.</p>
      <div class="missed-limit-picks">
        <?php foreach ($missedChoices as $value => $label): ?>
          <?php if ($value === $missedShown): ?>
            <span class="missed-limit-pick active"><?= h($label) ?></span>
          <?php else: ?>
            <a class="missed-limit-pick" href="/progress/?missed=<?= h($value) ?><?=h($deckQuery)?>"><?= h($label) ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="table-scroll">
      <table class="list">
        <thead>
          <tr>
            <th>Front</th>
            <th>Back</th>
            <th>Deck</th>
            <th>Misses</th>
            <th>Flashcards</th>
            <th>Quizzes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($missed as $row): ?>
            <tr>
              <td><strong><?= h($row['front_text'] !== '' ? $row['front_text'] : '(image)') ?></strong></td>
              <td><?= h($row['back_text']) ?></td>
              <td class="small"><?= h($row['subcategory_name']) ?></td>
              <td><strong><?= number_format($row['total_misses']) ?></strong></td>
              <td><?= number_format($row['flashcard_misses']) ?></td>
              <td><?= number_format($row['quiz_misses']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="small"><a href="<?=h($missesQuizHref)?>">Quiz me on my misses</a> &middot; <a href="<?=h($studyHref(CardProgress::FILTER_NEEDS_REVIEW))?>">flip through them</a></p>
  <?php endif; ?>
</div>

<div class="actions">
  <a class="button primary" href="<?=h($studyHref(''))?>">Keep studying &#8594;</a>
  <a class="button" href="<?=h($quizHref)?>">Take a quiz</a>
</div>

<?php footer_html(); ?>
