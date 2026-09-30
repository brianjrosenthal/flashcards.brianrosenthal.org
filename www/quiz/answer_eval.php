<?php
// AJAX: judge and record one typed quiz answer. The back never lives in the
// page, so this is where correctness is decided. Returns the verdict, the
// card's front and back, and the user's refreshed totals as JSON.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/QuizManagement.php';
Application::init();

header('Content-Type: application/json');
if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Please sign in to take a quiz.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}
require_csrf();

try {
    $cardId = (int)($_POST['card_id'] ?? 0);
    $answer = (string)($_POST['answer'] ?? '');

    $outcome = QuizManagement::recordAnswer(UserContext::getLoggedInUserContext(), $cardId, $answer);
    echo json_encode(['ok' => true] + $outcome, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
