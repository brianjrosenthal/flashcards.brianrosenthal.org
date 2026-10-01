<?php
// A round of the typed quiz on one deck. The round is built server-side and
// embedded as JSON — fronts only, never the backs: each typed answer POSTs to
// answer_eval.php, which judges and records it.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/QuizManagement.php';
require_once __DIR__ . '/../lib/Deck.php';
Application::init();
require_login();

$me = current_user();
$ctx = UserContext::getLoggedInUserContext();

// The launcher's <select name="deck"> submits "category:N" / "subcategory:N"
// when JavaScript is off; with it on, quiz_setup.js rewrites that into the
// ?subcategory=N / ?category=N every other page uses.
$params = $_GET;
if (empty($params['subcategory']) && empty($params['category']) && !empty($params['deck'])) {
    $parts = explode(':', (string)$params['deck'], 2);
    if (count($parts) === 2 && in_array($parts[0], [Deck::TYPE_CATEGORY, Deck::TYPE_SUBCATEGORY], true)) {
        $params[$parts[0]] = (int)$parts[1];
    }
}
$deck = Deck::fromRequest($params);

if ($deck === null || !$deck->canView($ctx)) {
    http_response_code($deck === null ? 404 : 403);
    header_html('Quiz');
    echo '<div class="card empty-deck"><h2>' . ($deck === null ? 'That deck could not be found' : 'This deck is private') . '</h2>'
       . '<p>Pick a deck to quiz on from the launcher.</p>'
       . '<div class="actions" style="justify-content:center;"><a class="button primary" href="/quiz/">Choose a deck</a></div></div>';
    footer_html();
    exit;
}

$source = (string)($_GET['source'] ?? QuizManagement::SOURCE_ALL);
if (!QuizManagement::isValidSource($source)) {
    $source = QuizManagement::SOURCE_ALL;
}
$count = (int)($_GET['count'] ?? 20);
if (!in_array($count, [0, 10, 20, 40], true)) {
    $count = 20;
}
$direction = (string)($_GET['direction'] ?? QuizManagement::DIRECTION_FRONT_TO_BACK);
if (!QuizManagement::isValidDirection($direction)) {
    $direction = QuizManagement::DIRECTION_FRONT_TO_BACK;
}
$backToFront = $direction === QuizManagement::DIRECTION_BACK_TO_FRONT;

$questions = QuizManagement::buildQuizRound((int)$me['id'], $deck, $source, $count > 0 ? $count : null, $direction);

// Carries the round's settings back to the launcher (pre-ticked) and into
// the "play again" links.
$settingsQuery = $deck->queryString() . '&' . http_build_query(['source' => $source, 'count' => $count, 'direction' => $direction]);
$roundSettings = ['deck_type' => $deck->type, 'deck_id' => $deck->id, 'source' => $source, 'count' => $count, 'direction' => $direction];

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

ApplicationUI::useSiteTheme($deck->site());
header_html('Quiz: ' . $deck->label());
?>

<div class="quiz-toolbar">
  <div class="quiz-toolbar-titles">
    <h2 class="quiz-title">Quiz</h2>
    <span class="quiz-deck-chip"><?=h($deck->label())?></span>
    <?php if ($source === QuizManagement::SOURCE_MISSES): ?>
      <span class="quiz-deck-chip quiz-source-chip">Cards I miss or flagged</span>
    <?php endif; ?>
    <?php if ($backToFront): ?>
      <span class="quiz-deck-chip quiz-source-chip">Back &rarr; front</span>
    <?php endif; ?>
  </div>
  <a class="button small" href="/quiz/?<?=h($settingsQuery)?>">Change settings</a>
</div>

<?php if (empty($questions)): ?>
  <div class="card empty-deck">
    <?php if ($source === QuizManagement::SOURCE_MISSES): ?>
      <h2>Nothing missed in this deck &#127881;</h2>
      <p>There's nothing here you've marked Need More Review, flagged, or missed in a quiz. That's the good kind of empty.</p>
    <?php else: ?>
      <h2>Nothing to ask here yet</h2>
      <p>This deck has no cards yet.</p>
    <?php endif; ?>
    <div class="actions" style="justify-content:center;flex-wrap:wrap;">
      <a class="button primary" href="/quiz/?<?=h($settingsQuery)?>">Change what you're quizzing on</a>
      <?php if ($source !== QuizManagement::SOURCE_ALL): ?>
        <a class="button" href="/quiz/play.php?<?=h($deck->queryString() . '&' . http_build_query(['source' => QuizManagement::SOURCE_ALL, 'count' => $count]))?>">Quiz on all cards instead</a>
      <?php endif; ?>
      <a class="button" href="<?=h($deck->studyUrl())?>">Study this deck</a>
    </div>
  </div>
<?php else: ?>

  <div class="progress-wrap">
    <div class="progress-track"><div class="progress-fill" id="quiz-progress-fill"></div></div>
    <div class="progress-text small" id="quiz-progress-text"></div>
  </div>

  <div class="quiz-stage" id="quiz-stage">
    <div class="quiz-nav">
      <button type="button" class="quiz-nav-btn hidden" id="quiz-prev-btn" aria-label="Back to the previous question">&#8592; Look back</button>
      <button type="button" class="quiz-nav-btn hidden" id="quiz-fwd-btn" aria-label="Forward to the next question">Forward &#8594;</button>
    </div>
    <div class="quiz-card">
      <div class="quiz-points-chip" id="quiz-points-chip"><span id="quiz-points">0</span> pts</div>
      <div class="quiz-streak hidden" id="quiz-streak"></div>

      <p class="quiz-ask small"><?= $backToFront ? "What's on the front?" : "What's on the back?" ?></p>
      <div class="quiz-prompt" id="quiz-prompt"></div>

      <button type="button" class="button small quiz-hint-btn" id="quiz-hint-btn">Need a hint?</button>
      <div class="quiz-hint hidden" id="quiz-hint"></div>
      <button type="button" class="button small quiz-choices-btn hidden" id="quiz-choices-btn"></button>
      <div class="quiz-choices hidden" id="quiz-choices"></div>

      <form class="quiz-answer-form" id="quiz-form" autocomplete="off">
        <input type="text" id="quiz-input" class="quiz-input" placeholder="type the answer&hellip;"
               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
               aria-label="Your answer">
        <button type="submit" class="button primary quiz-check" id="quiz-check">Check</button>
      </form>
    </div>

    <div class="quiz-feedback hidden" id="quiz-feedback">
      <div class="quiz-feedback-burst" id="quiz-feedback-burst" aria-hidden="true"></div>
      <div class="quiz-feedback-title" id="quiz-feedback-title"></div>
      <div class="quiz-feedback-front small hidden" id="quiz-feedback-front"></div>
      <div class="quiz-feedback-word" id="quiz-feedback-back"></div>
      <div class="quiz-feedback-yours small hidden" id="quiz-feedback-yours"></div>
      <div class="quiz-feedback-points" id="quiz-feedback-points"></div>
      <div class="actions quiz-feedback-actions">
        <button type="button" class="button quiz-claim hidden" id="quiz-claim">&#10003; I was right anyway</button>
        <button type="button" class="button primary" id="quiz-next">Next &#8594;</button>
      </div>
    </div>

    <p class="keyboard-hint small">enter = check your answer, then enter again for the next card</p>
  </div>

  <div class="card quiz-done hidden" id="quiz-done">
    <div class="quiz-done-burst" id="quiz-done-burst" aria-hidden="true">&#127881;</div>
    <h2 id="quiz-done-title">Round complete!</h2>
    <p class="quiz-done-score" id="quiz-done-score"></p>
    <p class="quiz-done-tally" id="quiz-done-tally"></p>
    <div class="actions" style="justify-content:center;flex-wrap:wrap;">
      <a class="button primary" href="/quiz/play.php?<?=h($settingsQuery)?>">Play again</a>
      <a class="button" href="/quiz/?<?=h($settingsQuery)?>">Change settings</a>
      <a class="button" href="<?=h($deck->studyUrl())?>">Study this deck</a>
    </div>
  </div>

  <div id="toast" class="toast hidden" role="alert"></div>

  <script>
    // Fronts only — nothing here says what is on the back.
    const QUESTIONS = <?= json_encode($questions, $jsonFlags) ?>;
    // Every answer in the deck (texts only, nothing marking which is which),
    // for the "show answers starting with…" second hint.
    const ANSWER_LIST = <?= json_encode(QuizManagement::listAnswerTexts($deck, $direction), $jsonFlags) ?>;
    // Identifies this round's settings, so a saved in-progress round is only
    // resumed into the round it belongs to.
    const ROUND_SETTINGS = <?= json_encode($roundSettings, $jsonFlags) ?>;
    const CSRF = <?= json_encode(csrf_token(), $jsonFlags) ?>;
  </script>
  <?= ApplicationUI::jsScript('/quiz/celebrations.js') ?>
  <?= ApplicationUI::jsScript('/quiz/quiz.js') ?>
<?php endif; ?>

<?php footer_html(); ?>
