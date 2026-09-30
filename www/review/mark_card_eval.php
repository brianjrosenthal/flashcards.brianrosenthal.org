<?php
// AJAX: record a Got it / Need More Review click on a card. Returns
// {ok, score} so the client can repaint the score chip, or {ok:false, error}.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/Deck.php';
require_once __DIR__ . '/../lib/CardProgress.php';
Application::init();

header('Content-Type: application/json');
if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Please sign in']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Your session expired — reload the page.']);
    exit;
}

try {
    $cardId = (int)($_POST['card_id'] ?? 0);
    $mark = (string)($_POST['mark'] ?? '');
    $position = isset($_POST['position']) && $_POST['position'] !== '' ? (int)$_POST['position'] : null;

    // Which deck the position belongs to (deck_type + deck_id); null when the
    // caller sent none, in which case the card's own deck remembers it.
    $deck = Deck::fromRequest($_POST);

    $score = CardProgress::markCard(UserContext::getLoggedInUserContext(), $cardId, $mark, $position, $deck);
    echo json_encode(['ok' => true, 'score' => $score]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
