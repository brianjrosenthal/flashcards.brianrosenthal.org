<?php
// AJAX: save the viewer's flag toggle on a card. Returns {ok, is_flagged}.
require_once __DIR__ . '/../partials.php';
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
    $flagged = !empty($_POST['flagged']);

    $isFlagged = CardProgress::setCardFlag(UserContext::getLoggedInUserContext(), $cardId, $flagged);
    echo json_encode(['ok' => true, 'is_flagged' => $isFlagged]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
