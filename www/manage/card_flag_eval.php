<?php
// Flags or unflags a card for the signed-in user (POST from the card editor).
// Flags are per viewer, so this touches the viewer's own state, not the card.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CardProgress.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /manage/');
    exit;
}
require_csrf();

$id = (int)($_POST['id'] ?? 0);
$flagged = !empty($_POST['flagged']);
$return = validate_relative_next_path($_POST['return'] ?? '');
if ($return === '') {
    $return = '/manage/card_edit.php?id=' . $id;
}
$sep = strpos($return, '?') === false ? '?' : '&';

try {
    CardProgress::setCardFlag(UserContext::getLoggedInUserContext(), $id, $flagged);
    header('Location: ' . $return . $sep . 'msg=' . urlencode($flagged ? 'Card flagged.' : 'Flag removed.'));
} catch (Throwable $e) {
    header('Location: ' . $return . $sep . 'err=' . urlencode($e->getMessage()));
}
exit;
