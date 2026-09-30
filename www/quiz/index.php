<?php
// Quiz launcher: pick a deck, pick which cards to draw from, pick a round
// length, start. Everything here is a read; play.php runs the round itself.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/QuizManagement.php';
require_once __DIR__ . '/../lib/Deck.php';
require_once __DIR__ . '/../lib/CardProgress.php';
Application::init();
require_login();

$me = current_user();
$userId = (int)$me['id'];
$ctx = UserContext::getLoggedInUserContext();

$stats = QuizManagement::getQuizStatsForUser($userId);

// Every deck the viewer can quiz on: their own, plus public decks they have
// studied. Each entry is a category with its subcategories.
$deckGroups = CardProgress::listDecksForUser($userId);

// The deck to preselect: whatever ?subcategory= / ?category= names (a deck
// page's Quiz button, or play.php's "change settings" link), else the first
// deck in the picker.
$selectedDeck = Deck::fromRequest($_GET);
if ($selectedDeck !== null && !$selectedDeck->canView($ctx)) {
    $selectedDeck = null;
}
if ($selectedDeck === null && !empty($deckGroups)) {
    $first = $deckGroups[0];
    $selectedDeck = Deck::of(Deck::TYPE_CATEGORY, (int)$first['category']['id']);
}
$selectedValue = $selectedDeck ? $selectedDeck->type . ':' . $selectedDeck->id : '';

$selectedSource = (string)($_GET['source'] ?? QuizManagement::SOURCE_ALL);
if (!QuizManagement::isValidSource($selectedSource)) {
    $selectedSource = QuizManagement::SOURCE_ALL;
}
$selectedCount = (int)($_GET['count'] ?? 20);
if (!in_array($selectedCount, [0, 10, 20, 40], true)) {
    $selectedCount = 20;
}

// Pool sizes for the preselected deck; quiz_setup.js refetches them from
// pool_counts_eval.php when the deck changes.
$poolCounts = [QuizManagement::SOURCE_ALL => 0, QuizManagement::SOURCE_MISSES => 0];
if ($selectedDeck !== null) {
    foreach (array_keys($poolCounts) as $source) {
        $poolCounts[$source] = QuizManagement::countAvailableQuestions($userId, $selectedDeck, $source);
    }
}
if ($poolCounts[$selectedSource] === 0 && $poolCounts[QuizManagement::SOURCE_ALL] > 0) {
    $selectedSource = QuizManagement::SOURCE_ALL;
}
$canStart = $poolCounts[$selectedSource] > 0;

$sourceCards = [
    QuizManagement::SOURCE_ALL => [
        'name' => 'All cards',
        'blurb' => 'Works through the deck, starting with whatever you have practiced least recently.',
    ],
    QuizManagement::SOURCE_MISSES => [
        'name' => 'Cards I miss',
        'blurb' => 'Cards marked Need More Review or flagged, plus any you missed in a quiz and have not got right since.',
    ],
];

header_html('Quiz');
?>

<div class="quiz-hero">
  <h2 class="quiz-hero-title">Quiz time<span class="quiz-hero-spark">&#10024;</span></h2>
  <p class="quiz-hero-sub">See the front, type the back. <?= QuizManagement::POINTS_CORRECT ?> points for an exact answer,
    <?= QuizManagement::POINTS_CLOSE ?> if a typo sneaks in.</p>
  <?php if ($stats['answered'] > 0): ?>
    <p class="quiz-hero-score"><strong><?= number_format($stats['points']) ?></strong> points so far
      &nbsp;&#183;&nbsp; <?= number_format($stats['answered']) ?> answered &nbsp;&#183;&nbsp; <?= (int)$stats['accuracy_pct'] ?>% right</p>
  <?php endif; ?>
</div>

<?php if (empty($deckGroups)): ?>
  <div class="card empty-deck">
    <h2>No decks to quiz on yet</h2>
    <p>Make a deck and add a few cards, and the quiz fills itself in.</p>
    <div class="actions" style="justify-content:center;">
      <a class="button primary" href="/manage/">Go to My Decks</a>
    </div>
  </div>
<?php else: ?>

<form method="get" action="/quiz/play.php" class="quiz-setup card" id="quiz-setup">
  <fieldset class="quiz-fieldset">
    <legend>Which deck?</legend>
    <label class="quiz-count">
      <select name="deck" id="quiz-deck-select">
        <?php foreach ($deckGroups as $group):
            $cat = $group['category'];
            $subs = $group['subcategories'] ?? [];
            $catCards = (int)($cat['card_count'] ?? 0);
            $groupLabel = (string)$cat['name'] . (empty($group['is_mine']) ? ' (' . (string)($group['owner_first_name'] ?? '') . ')' : '');
            $catValue = Deck::TYPE_CATEGORY . ':' . (int)$cat['id'];
        ?>
          <optgroup label="<?=h($groupLabel)?>">
            <option value="<?=h($catValue)?>" <?= $selectedValue === $catValue ? 'selected' : '' ?>>
              All of <?=h($cat['name'])?> (<?= $catCards ?>)
            </option>
            <?php foreach ($subs as $sub): $subValue = Deck::TYPE_SUBCATEGORY . ':' . (int)$sub['id']; ?>
              <option value="<?=h($subValue)?>" <?= $selectedValue === $subValue ? 'selected' : '' ?>>
                &nbsp;&nbsp;<?=h($sub['name'])?> (<?= (int)($sub['card_count'] ?? 0) ?>)
              </option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
    </label>
  </fieldset>

  <fieldset class="quiz-fieldset">
    <legend>Which cards?</legend>
    <div class="quiz-source-picks">
      <?php foreach ($sourceCards as $source => $card): $count = $poolCounts[$source]; ?>
        <label class="quiz-source-pick<?= $count === 0 ? ' empty' : '' ?>" data-source="<?=h($source)?>">
          <input type="radio" name="source" value="<?=h($source)?>" <?= $selectedSource === $source ? 'checked' : '' ?> <?= $count === 0 ? 'disabled' : '' ?>>
          <span class="quiz-source-body">
            <span class="quiz-source-name"><?=h($card['name'])?>
              <span class="quiz-source-count"><?= number_format($count) ?></span>
            </span>
            <span class="quiz-source-blurb small"><?=h($card['blurb'])?></span>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <fieldset class="quiz-fieldset">
    <legend>How many questions?</legend>
    <label class="quiz-count">
      <select name="count">
        <?php foreach ([10 => '10 questions', 20 => '20 questions', 40 => '40 questions', 0 => 'Everything in the deck'] as $value => $label): ?>
          <option value="<?= (int)$value ?>" <?= $selectedCount === (int)$value ? 'selected' : '' ?>><?=h($label)?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </fieldset>

  <div class="actions">
    <button type="submit" class="button primary quiz-start<?= $canStart ? '' : ' disabled' ?>" id="quiz-start" <?= $canStart ? '' : 'disabled' ?>>Start the quiz &#8594;</button>
    <a class="button" href="/review/">Back to flashcards</a>
    <span class="small quiz-setup-note hidden" id="quiz-setup-note"></span>
  </div>
</form>

<?= ApplicationUI::jsScript('/quiz/quiz_setup.js') ?>

<?php endif; ?>

<?php footer_html(); ?>
