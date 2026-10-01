<?php
// AJAX (GET): how many questions a deck can produce for the signed-in user,
// per card pool — {ok, all, misses} — for a direction (?direction=front|back).
// The launcher refetches these when the deck or direction changes. Reads only.
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
    $direction = (string)($_GET['direction'] ?? QuizManagement::DIRECTION_FRONT_TO_BACK);
    if (!QuizManagement::isValidDirection($direction)) {
        $direction = QuizManagement::DIRECTION_FRONT_TO_BACK;
    }
    echo json_encode([
        'ok' => true,
        'all' => QuizManagement::countAvailableQuestions($userId, $deck, QuizManagement::SOURCE_ALL, $direction),
        'misses' => QuizManagement::countAvailableQuestions($userId, $deck, QuizManagement::SOURCE_MISSES, $direction),
    ]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
