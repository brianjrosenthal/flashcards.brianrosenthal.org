<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/ContentAccess.php';
require_once __DIR__ . '/Slugger.php';
require_once __DIR__ . '/CategoryManagement.php';

/**
 * Subcategories: the decks ("Presidents" inside "US History"). Cards live in
 * a subcategory. All SQL for the subcategories table lives here.
 */
final class SubcategoryManagement {

    private static function pdo(): PDO {
        return pdo();
    }

    private static function log(string $action, array $meta): void {
        try {
            ActivityLog::log(UserContext::getLoggedInUserContext(), $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }

    // ---- lookups ----------------------------------------------------------

    public static function findById(int $id): ?array {
        $st = self::pdo()->prepare('SELECT * FROM subcategories WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function findBySlug(int $categoryId, string $slug): ?array {
        $st = self::pdo()->prepare('SELECT * FROM subcategories WHERE category_id = ? AND slug = ? LIMIT 1');
        $st->execute([$categoryId, $slug]);
        return $st->fetch() ?: null;
    }

    public static function ownerUserIdOf(int $subcategoryId): ?int {
        $st = self::pdo()->prepare(
            'SELECT c.user_id FROM subcategories s JOIN categories c ON c.id = s.category_id WHERE s.id = ?'
        );
        $st->execute([$subcategoryId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    /** A category's subcategories in display order, each with card_count. */
    public static function listForCategory(int $categoryId): array {
        $st = self::pdo()->prepare(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM cards k WHERE k.subcategory_id = s.id) AS card_count
             FROM subcategories s
             WHERE s.category_id = ?
             ORDER BY s.sort_order, s.name'
        );
        $st->execute([$categoryId]);
        return $st->fetchAll();
    }

    public static function slugExists(int $categoryId, string $slug, ?int $exceptId = null): bool {
        $st = self::pdo()->prepare(
            'SELECT COUNT(*) FROM subcategories WHERE category_id = ? AND slug = ? AND (? IS NULL OR id <> ?)'
        );
        $st->execute([$categoryId, $slug, $exceptId, $exceptId]);
        return (int)$st->fetchColumn() > 0;
    }

    // ---- writes -----------------------------------------------------------

    public static function create(?UserContext $ctx, int $categoryId, array $data): int {
        $ownerId = CategoryManagement::ownerUserIdOf($categoryId);
        if ($ownerId === null) {
            throw new RuntimeException('Category not found.');
        }
        ContentAccess::assertCanEdit($ctx, $ownerId);
        [$name, $slug, $description] = self::validate($categoryId, $data, null);

        $st = self::pdo()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM subcategories WHERE category_id = ?');
        $st->execute([$categoryId]);
        $sortOrder = (int)$st->fetchColumn();

        $st = self::pdo()->prepare(
            'INSERT INTO subcategories (category_id, slug, name, description, sort_order) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$categoryId, $slug, $name, $description, $sortOrder]);
        $id = (int)self::pdo()->lastInsertId();
        self::log('subcategory.create', ['subcategory_id' => $id, 'category_id' => $categoryId, 'name' => $name]);
        return $id;
    }

    public static function update(?UserContext $ctx, int $id, array $data): void {
        $sub = self::findById($id);
        if (!$sub) {
            throw new RuntimeException('Subcategory not found.');
        }
        ContentAccess::assertCanEdit($ctx, (int)self::ownerUserIdOf($id));
        [$name, $slug, $description] = self::validate((int)$sub['category_id'], $data + $sub, $id);
        $sortOrder = array_key_exists('sort_order', $data) ? (int)$data['sort_order'] : (int)$sub['sort_order'];

        $st = self::pdo()->prepare(
            'UPDATE subcategories SET name = ?, slug = ?, description = ?, sort_order = ? WHERE id = ?'
        );
        $st->execute([$name, $slug, $description, $sortOrder, $id]);
        self::log('subcategory.update', ['subcategory_id' => $id, 'name' => $name]);
    }

    /**
     * Deletes the deck with all its cards: images are removed from storage,
     * progress rows go with the cards (FK cascade) and the deck's resume
     * points are forgotten. One transaction.
     */
    public static function delete(?UserContext $ctx, int $id): void {
        require_once __DIR__ . '/CardManagement.php';
        require_once __DIR__ . '/CardProgress.php';
        require_once __DIR__ . '/Deck.php';
        $sub = self::findById($id);
        if (!$sub) {
            throw new RuntimeException('Subcategory not found.');
        }
        ContentAccess::assertCanEdit($ctx, (int)self::ownerUserIdOf($id));

        $pdo = self::pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $removed = CardManagement::deleteAllInSubcategory($ctx, $id);
            CardProgress::forgetDeck(Deck::TYPE_SUBCATEGORY, $id);
            $st = $pdo->prepare('DELETE FROM subcategories WHERE id = ?');
            $st->execute([$id]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        self::log('subcategory.delete', ['subcategory_id' => $id, 'name' => $sub['name'], 'cards_removed' => $removed]);
    }

    private static function validate(int $categoryId, array $data, ?int $exceptId): array {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        if (mb_strlen($name) > 150) {
            throw new InvalidArgumentException('Name must be 150 characters or fewer.');
        }
        $slug = strtolower(trim((string)($data['slug'] ?? '')));
        if ($slug === '') {
            $base = Slugger::fromText($name);
            if ($base === '' || Slugger::isReserved($base)) {
                $base = $base === '' ? 'deck' : $base . '-1';
            }
            $slug = Slugger::makeUnique($base,
                static fn(string $s): bool => self::slugExists($categoryId, $s, $exceptId) || Slugger::isReserved($s));
        }
        if (($problem = Slugger::problemWith($slug)) !== null) {
            throw new InvalidArgumentException($problem);
        }
        if (self::slugExists($categoryId, $slug, $exceptId)) {
            throw new InvalidArgumentException('Another subcategory here already uses the URL name "' . $slug . '".');
        }
        $description = trim((string)($data['description'] ?? ''));
        if (mb_strlen($description) > 500) {
            throw new InvalidArgumentException('Description must be 500 characters or fewer.');
        }
        return [$name, $slug, $description];
    }
}
