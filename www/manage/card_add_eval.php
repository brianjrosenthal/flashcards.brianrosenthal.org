<?php
// Evaluates the new card form (multipart POST from manage/card_add.php).
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

// A body over post_max_size arrives with $_POST and $_FILES empty; the deck
// id on the action URL lets us send the user back with a real message.
$subcategoryId = (int)($_POST['subcategory_id'] ?? ($_GET['subcategory_id'] ?? 0));
if ($_POST === [] && $_FILES === [] && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    header('Location: /manage/card_add.php?subcategory_id=' . $subcategoryId . '&err='
        . urlencode('That upload is larger than the server accepts (' . ini_get('post_max_size') . '). Please choose a smaller image.'));
    exit;
}
require_csrf();

$data = [
    'front_text' => (string)($_POST['front_text'] ?? ''),
    'back_text' => (string)($_POST['back_text'] ?? ''),
];
$addAnother = !empty($_POST['add_another']);
$next = validate_relative_next_path($_POST['next'] ?? '');
$nextParam = $next !== '' ? '&next=' . urlencode($next) : '';
$imageChosen = isset($_FILES['image']) && is_array($_FILES['image'])
    && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

try {
    $ctx = UserContext::getLoggedInUserContext();
    $image = $imageChosen ? ImageStorage::prepareUpload($_FILES['image']) : null;
    $id = CardManagement::create($ctx, $subcategoryId, $data, $image);
    if ($addAnother) {
        header('Location: /manage/card_add.php?subcategory_id=' . $subcategoryId . '&msg=' . urlencode('Card added.') . $nextParam);
    } elseif ($next !== '') {
        header('Location: ' . $next);
    } else {
        header('Location: /manage/cards.php?subcategory_id=' . $subcategoryId . '&msg=' . urlencode('Card added.') . '#card-' . $id);
    }
    exit;
} catch (Throwable $e) {
    $message = $e->getMessage() . ($imageChosen ? ' (Please choose the image again.)' : '');
    ManageUI::stashForm('card_add_' . $subcategoryId, $data + ['add_another' => $addAnother ? 1 : 0], $message);
    header('Location: /manage/card_add.php?subcategory_id=' . $subcategoryId . $nextParam);
    exit;
}
