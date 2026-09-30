<?php
// Restore a deck's original order for the signed-in viewer (back to the
// first card). POST from the study toolbar / deck-complete panel; PRG back
// to the deck.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/Deck.php';
require_once __DIR__ . '/../lib/CardProgress.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /review/');
    exit;
}
require_csrf();

$deck = Deck::fromRequest($_POST);
if ($deck === null) {
    header('Location: /review/?err=' . urlencode('Unknown deck.'));
    exit;
}

try {
    CardProgress::restoreOriginalOrder(UserContext::getLoggedInUserContext(), $deck);
    header('Location: ' . $deck->studyUrl());
} catch (\Throwable $e) {
    header('Location: /review/?err=' . urlencode($e->getMessage()));
}
exit;
