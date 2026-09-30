<?php
// AJAX (GET): how many questions a deck can produce for the signed-in user,
// per card pool — {ok, all, misses}. The launcher's deck picker refetches
// these when the deck changes. Reads only.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/QuizManagement.php';
require_once __DIR__ . '/../lib/Deck.php';
Application::init();

header('Content-Type: application/json');
$me = current_user();
if (!$me) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Please sign in.']);
    exit;
}

try {
    $deck = Deck::fromRequest($_GET);
    if ($deck === null) {
        throw new InvalidArgumentException('That deck could not be found.');
    }
    $deck->assertCanView(UserContext::getLoggedInUserContext());

    $userId = (int)$me['id'];
    echo json_encode([
        'ok' => true,
        'all' => QuizManagement::countAvailableQuestions($userId, $deck, QuizManagement::SOURCE_ALL),
        'misses' => QuizManagement::countAvailableQuestions($userId, $deck, QuizManagement::SOURCE_MISSES),
    ]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
