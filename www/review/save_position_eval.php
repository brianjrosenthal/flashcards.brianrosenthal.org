<?php
// AJAX: persist the viewer's resume point in a deck when they browse with
// the back/forward buttons (marks save it themselves). Returns {ok}.
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
    $deck = Deck::fromRequest($_POST);
    if ($deck === null) {
        throw new RuntimeException('Unknown deck.');
    }
    $position = (int)($_POST['position'] ?? 0);

    CardProgress::saveDeckPosition(UserContext::getLoggedInUserContext(), $deck, $position);
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
