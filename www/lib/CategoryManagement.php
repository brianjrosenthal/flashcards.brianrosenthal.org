<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/ContentAccess.php';
require_once __DIR__ . '/Slugger.php';

/**
 * Categories: the top level of a user's decks ("US History"). All SQL for the
 * categories table lives here.
 */
final class CategoryManagement {

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
        $st = self::pdo()->prepare('SELECT * FROM categories WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function findBySlug(int $userId, string $slug): ?array {
        $st = self::pdo()->prepare('SELECT * FROM categories WHERE user_id = ? AND slug = ? LIMIT 1');
        $st->execute([$userId, $slug]);
        return $st->fetch() ?: null;
    }

    public static function ownerUserIdOf(int $categoryId): ?int {
        $st = self::pdo()->prepare('SELECT user_id FROM categories WHERE id = ?');
        $st->execute([$categoryId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    /**
     * A user's categories in display order, each with subcategory_count and
     * card_count. $publicOnly limits to categories shown on the public page.
     */
    public static function listForUser(int $userId, bool $publicOnly = false): array {
        $st = self::pdo()->prepare(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM subcategories s WHERE s.category_id = c.id) AS subcategory_count,
                    (SELECT COUNT(*) FROM cards k JOIN subcategories s ON s.id = k.subcategory_id
                      WHERE s.category_id = c.id) AS card_count
             FROM categories c
             WHERE c.user_id = ?' . ($publicOnly ? ' AND c.is_public = 1' : '') . '
             ORDER BY c.sort_order, c.name'
        );
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /**
     * The whole tree for the dashboard and the public page: categories, each
     * with 'subcategories' (each carrying card_count). Two query rounds.
     */
    public static function treeForUser(int $userId, bool $publicOnly = false): array {
        require_once __DIR__ . '/SubcategoryManagement.php';
        $tree = self::listForUser($userId, $publicOnly);
        foreach ($tree as &$cat) {
            $cat['subcategories'] = SubcategoryManagement::listForCategory((int)$cat['id']);
        }
        unset($cat);
        return $tree;
    }

    public static function slugExists(int $userId, string $slug, ?int $exceptId = null): bool {
        $st = self::pdo()->prepare(
            'SELECT COUNT(*) FROM categories WHERE user_id = ? AND slug = ? AND (? IS NULL OR id <> ?)'
        );
        $st->execute([$userId, $slug, $exceptId, $exceptId]);
        return (int)$st->fetchColumn() > 0;
    }

    /** Number of cards across every subcategory of the category. */
    public static function cardCount(int $categoryId): int {
        $st = self::pdo()->prepare(
            'SELECT COUNT(*) FROM cards k JOIN subcategories s ON s.id = k.subcategory_id WHERE s.category_id = ?'
        );
        $st->execute([$categoryId]);
        return (int)$st->fetchColumn();
    }

    // ---- writes -----------------------------------------------------------

    /**
     * @param array{name:string,slug?:string,description?:string,is_public?:mixed} $data
     */
    public static function create(?UserContext $ctx, int $userId, array $data): int {
        ContentAccess::assertCanEdit($ctx, $userId);
        [$name, $slug, $description] = self::validate($userId, $data, null);
        $isPublic = array_key_exists('is_public', $data) ? (int)(bool)$data['is_public'] : 1;

        $st = self::pdo()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM categories WHERE user_id = ?');
        $st->execute([$userId]);
        $sortOrder = (int)$st->fetchColumn();

        $st = self::pdo()->prepare(
            'INSERT INTO categories (user_id, slug, name, description, is_public, sort_order) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$userId, $slug, $name, $description, $isPublic, $sortOrder]);
        $id = (int)self::pdo()->lastInsertId();
        self::log('category.create', ['category_id' => $id, 'user_id' => $userId, 'name' => $name]);
        return $id;
    }

    public static function update(?UserContext $ctx, int $id, array $data): void {
        $cat = self::findById($id);
        if (!$cat) {
            throw new RuntimeException('Category not found.');
        }
        ContentAccess::assertCanEdit($ctx, (int)$cat['user_id']);
        [$name, $slug, $description] = self::validate((int)$cat['user_id'], $data + $cat, $id);
        $sortOrder = array_key_exists('sort_order', $data) ? (int)$data['sort_order'] : (int)$cat['sort_order'];
        $isPublic = array_key_exists('is_public', $data) ? (int)(bool)$data['is_public'] : (int)$cat['is_public'];

        $st = self::pdo()->prepare(
            'UPDATE categories SET name = ?, slug = ?, description = ?, is_public = ?, sort_order = ? WHERE id = ?'
        );
        $st->execute([$name, $slug, $description, $isPublic, $sortOrder, $id]);
        self::log('category.update', ['category_id' => $id, 'name' => $name]);
    }

    /**
     * Deletes the category with everything in it: its subcategories, their
     * cards (images in storage included) and everyone's progress on them.
     * One transaction, so a storage failure leaves the tree intact.
     */
    public static function delete(?UserContext $ctx, int $id): void {
        require_once __DIR__ . '/SubcategoryManagement.php';
        require_once __DIR__ . '/CardProgress.php';
        require_once __DIR__ . '/Deck.php';
        $cat = self::findById($id);
        if (!$cat) {
            throw new RuntimeException('Category not found.');
        }
        ContentAccess::assertCanEdit($ctx, (int)$cat['user_id']);

        $pdo = self::pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            foreach (SubcategoryManagement::listForCategory($id) as $sub) {
                SubcategoryManagement::delete($ctx, (int)$sub['id']);
            }
            CardProgress::forgetDeck(Deck::TYPE_CATEGORY, $id);
            $st = $pdo->prepare('DELETE FROM categories WHERE id = ?');
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
        self::log('category.delete', ['category_id' => $id, 'name' => $cat['name']]);
    }

    /**
     * @return array{0:string,1:string,2:string} [name, slug, description]
     * @throws InvalidArgumentException with a form-ready message
     */
    private static function validate(int $userId, array $data, ?int $exceptId): array {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        if (mb_strlen($name) > 150) {
            throw new InvalidArgumentException('Name must be 150 characters or fewer.');
        }
        $slug = strtolower(trim((string)($data['slug'] ?? '')));
        if ($slug === '') {
            $slug = Slugger::fromText($name);
            if ($slug === '' || Slugger::isReserved($slug)) {
                $slug = Slugger::makeUnique($slug === '' ? 'category' : $slug . '-1',
                    static fn(string $s): bool => self::slugExists($userId, $s, $exceptId) || Slugger::isReserved($s));
            } else {
                $slug = Slugger::makeUnique($slug, static fn(string $s): bool => self::slugExists($userId, $s, $exceptId));
            }
        }
        if (($problem = Slugger::problemWith($slug)) !== null) {
            throw new InvalidArgumentException($problem);
        }
        if (self::slugExists($userId, $slug, $exceptId)) {
            throw new InvalidArgumentException('Another category already uses the URL name "' . $slug . '".');
        }
        $description = trim((string)($data['description'] ?? ''));
        if (mb_strlen($description) > 500) {
            throw new InvalidArgumentException('Description must be 500 characters or fewer.');
        }
        return [$name, $slug, $description];
    }
}
