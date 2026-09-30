<?php
declare(strict_types=1);

// Test bootstrap: load the app, then point every pdo() call at a dedicated
// test database (recreated from schema.sql on every run) so tests never touch
// the real database. Image storage is replaced by an in-memory fake.

require_once __DIR__ . '/../../www/config.php';
require_once __DIR__ . '/../../www/lib/UserManagement.php';
require_once __DIR__ . '/../../www/lib/ActivityLog.php';
require_once __DIR__ . '/../../www/lib/Slugger.php';
require_once __DIR__ . '/../../www/lib/ContentAccess.php';
require_once __DIR__ . '/../../www/lib/SiteManagement.php';
require_once __DIR__ . '/../../www/lib/SiteResolver.php';
require_once __DIR__ . '/../../www/lib/CategoryManagement.php';
require_once __DIR__ . '/../../www/lib/SubcategoryManagement.php';
require_once __DIR__ . '/../../www/lib/Deck.php';
require_once __DIR__ . '/../../www/lib/S3Client.php';
require_once __DIR__ . '/../../www/lib/ImageStorage.php';
require_once __DIR__ . '/../../www/lib/CardManagement.php';
require_once __DIR__ . '/../../www/lib/CardProgress.php';
require_once __DIR__ . '/../../www/lib/QuizManagement.php';
require_once __DIR__ . '/../../www/lib/MigrationRunner.php';
require_once __DIR__ . '/Support/FakeS3Client.php';

// FLASHCARDS_TEST_DB lets several test runs share one MySQL server without
// dropping each other's database.
define('TEST_DB_NAME', getenv('FLASHCARDS_TEST_DB') ?: 'flashcards_brianrosenthal_test');

$server = new PDO(
    'mysql:host=' . DB_HOST . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$server->exec('DROP DATABASE IF EXISTS `' . TEST_DB_NAME . '`');
$server->exec('CREATE DATABASE `' . TEST_DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$testPdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . TEST_DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$testPdo->exec((string)file_get_contents(__DIR__ . '/../../www/schema.sql'));

set_pdo_for_testing($testPdo);

// Storage: never touch the network from tests.
ImageStorage::storage(new FakeS3Client('https://r2-test.example', 'auto'));

// Helper for tests: wipe all domain tables back to a clean slate.
function test_reset_all(): void {
    $pdo = pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ([
        'activity_log', 'emails_sent',
        'quiz_attempts', 'user_deck_positions', 'card_review_events', 'user_card_state',
        'cards', 'subcategories', 'categories', 'sites',
        'users',
    ] as $table) {
        $pdo->exec('TRUNCATE TABLE ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    ImageStorage::storage()->reset();
}

// Helper for tests: seed a verified admin and return their UserContext.
function test_seed_admin(string $email = 'admin@example.com'): UserContext {
    $st = pdo()->prepare(
        "INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at)
         VALUES ('Admin', 'User', ?, 'hash', 1, NOW())"
    );
    $st->execute([$email]);
    $ctx = new UserContext((int)pdo()->lastInsertId(), true);
    UserContext::set($ctx);
    return $ctx;
}

// Helper for tests: seed a verified non-admin and return their UserContext.
function test_seed_user(string $email = 'user@example.com', string $firstName = 'Regular'): UserContext {
    $st = pdo()->prepare(
        "INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at)
         VALUES (?, 'User', ?, 'hash', 0, NOW())"
    );
    $st->execute([$firstName, $email]);
    return new UserContext((int)pdo()->lastInsertId(), false);
}

// Helper for tests: a page (site) plus one category, one deck (subcategory)
// and $cardCount text cards for a user. Cards are "Front N" / "Back N".
// Returns ['site_id', 'category_id', 'subcategory_id', 'card_ids' => [...]].
function test_seed_tree(UserContext $owner, string $slugHint = 'charlie', int $cardCount = 3): array {
    $siteId = SiteManagement::createForUser($owner, $owner->id, $slugHint, ucfirst($slugHint) . "'s Flashcards");
    $categoryId = CategoryManagement::create($owner, $owner->id, ['name' => 'US History']);
    $subcategoryId = SubcategoryManagement::create($owner, $categoryId, ['name' => 'Presidents']);
    $cardIds = [];
    for ($i = 1; $i <= $cardCount; $i++) {
        $cardIds[] = CardManagement::create($owner, $subcategoryId, ['front_text' => 'Front ' . $i, 'back_text' => 'Back ' . $i]);
    }
    return ['site_id' => $siteId, 'category_id' => $categoryId, 'subcategory_id' => $subcategoryId, 'card_ids' => $cardIds];
}
