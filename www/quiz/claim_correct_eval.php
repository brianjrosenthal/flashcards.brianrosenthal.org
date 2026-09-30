<?php
// AJAX: "I was right anyway" — award partial credit on an answer judged
// incorrect, for the cases where the back honestly fits what was typed.
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
    $attemptId = (int)($_POST['attempt_id'] ?? 0);

    $outcome = QuizManagement::markAttemptCorrectAnyway(UserContext::getLoggedInUserContext(), $attemptId);
    echo json_encode(['ok' => true] + $outcome, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
