<?php
// Evaluates the edit card form (multipart POST from manage/card_edit.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/CardManagement.php';
require_once __DIR__ . '/../lib/ImageStorage.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /manage/');
    exit;
}

// A body over post_max_size arrives with $_POST and $_FILES empty; the card
// id on the action URL lets us send the user back with a real message.
$id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
if ($_POST === [] && $_FILES === [] && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    header('Location: /manage/card_edit.php?id=' . $id . '&err='
        . urlencode('That upload is larger than the server accepts (' . ini_get('post_max_size') . '). Please choose a smaller image.'));
    exit;
}
require_csrf();

$data = [
    'front_text' => (string)($_POST['front_text'] ?? ''),
    'back_text' => (string)($_POST['back_text'] ?? ''),
    'sort_order' => (int)($_POST['sort_order'] ?? 0),
];
$removeImage = !empty($_POST['remove_image']);
$next = validate_relative_next_path($_POST['next'] ?? '');
$nextParam = $next !== '' ? '&next=' . urlencode($next) : '';
$imageChosen = isset($_FILES['image']) && is_array($_FILES['image'])
    && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

try {
    $ctx = UserContext::getLoggedInUserContext();
    $card = CardManagement::findById($id);
    if (!$card) {
        throw new RuntimeException('Card not found.');
    }
    $image = $imageChosen ? ImageStorage::prepareUpload($_FILES['image']) : null;
    CardManagement::update($ctx, $id, $data, $image, $removeImage);
    if ($next !== '') {
        header('Location: ' . $next);
    } else {
        header('Location: /manage/cards.php?subcategory_id=' . (int)$card['subcategory_id'] . '&msg=' . urlencode('Card saved.') . '#card-' . $id);
    }
    exit;
} catch (Throwable $e) {
    $message = $e->getMessage() . ($imageChosen ? ' (Please choose the image again.)' : '');
    ManageUI::stashForm('card_edit_' . $id, $data + ['remove_image' => $removeImage ? 1 : 0], $message);
    header('Location: /manage/card_edit.php?id=' . $id . $nextParam);
    exit;
}
