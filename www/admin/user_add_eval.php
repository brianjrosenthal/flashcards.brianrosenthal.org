<?php
// Evaluates the add-user form (POST from admin/user_add.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/SiteManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/users.php');
    exit;
}

require_csrf();

$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$is_admin = !empty($_POST['is_admin']) ? 1 : 0;

$formParams = [
    'first_name' => $first_name,
    'last_name' => $last_name,
    'email' => $email,
    'is_admin' => $is_admin,
];

// Validation
$errors = [];
if ($first_name === '') {
    $errors[] = 'First name is required.';
}
if ($last_name === '') {
    $errors[] = 'Last name is required.';
}
if ($email === '') {
    $errors[] = 'Email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email is invalid.';
}
if (empty($errors) && UserManagement::emailExists($email)) {
    $errors[] = 'Email already exists.';
}

if (!empty($errors)) {
    // Redirect back to form with errors and form data
    $query = http_build_query(['err' => implode(' ', $errors)] + $formParams);
    header('Location: /admin/user_add.php?' . $query);
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    $userId = UserManagement::createUser($ctx, [
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'is_admin' => $is_admin,
        'require_password_setup' => true,
    ]);

    // Every user gets a public page right away (/{slug}/), so there is
    // nothing else to set up before they can start making decks.
    $siteNote = '';
    try {
        SiteManagement::createForUser($ctx, $userId, $first_name, $first_name . "'s Flashcards");
    } catch (Throwable $e) {
        $siteNote = ' (Their page could not be created: ' . $e->getMessage() . ' — they can create it from My Decks.)';
    }

    header('Location: /admin/user_edit.php?id=' . $userId . '&msg=' . urlencode('User created. An activation email has been sent.' . $siteNote));
    exit;

} catch (Throwable $e) {
    $query = http_build_query(['err' => 'Error creating user: ' . $e->getMessage()] + $formParams);
    header('Location: /admin/user_add.php?' . $query);
    exit;
}
