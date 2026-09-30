<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/ContentAccess.php';
require_once __DIR__ . '/SubcategoryManagement.php';
require_once __DIR__ . '/ImageStorage.php';

/**
 * Cards: the flashcards inside a subcategory (deck). Front = optional text
 * and/or an image in storage (at least one), back = text. All SQL for the
 * cards table lives here.
 *
 * Write ordering with storage, so a failure never leaves a row pointing at
 * a missing object or an object nobody references for long:
 *   create   = INSERT, put the image, UPDATE the image columns; a failed put
 *              deletes the row again.
 *   replace  = put the new image, UPDATE the row, then delete the old object
 *              (best effort, logged as card.image_delete_failed).
 *   remove / delete card = delete from storage first, then the row.
 */
final class CardManagement {

    /** Longest front or back text. */
    public const MAX_TEXT = 2000;

    /** Most cards one bulk add may create. */
    public const BULK_MAX_LINES = 500;

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
        $st = self::pdo()->prepare('SELECT * FROM cards WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * A card with its ancestors' ids, names and slugs (subcategory_*,
     * category_*, user_id) so pages can build breadcrumbs and URLs in one
     * query.
     */
    public static function findWithAncestors(int $id): ?array {
        $st = self::pdo()->prepare(
            'SELECT k.*,
                    s.name AS subcategory_name, s.slug AS subcategory_slug,
                    c.id AS category_id, c.name AS category_name, c.slug AS category_slug,
                    c.user_id
             FROM cards k
             JOIN subcategories s ON s.id = k.subcategory_id
             JOIN categories c ON c.id = s.category_id
             WHERE k.id = ? LIMIT 1'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function ownerUserIdOf(int $cardId): ?int {
        $st = self::pdo()->prepare(
            'SELECT c.user_id FROM cards k
             JOIN subcategories s ON s.id = k.subcategory_id
             JOIN categories c ON c.id = s.category_id
             WHERE k.id = ?'
        );
        $st->execute([$cardId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    /** A deck's cards in display order. */
    public static function listForSubcategory(int $subcategoryId): array {
        $st = self::pdo()->prepare('SELECT * FROM cards WHERE subcategory_id = ? ORDER BY sort_order, id');
        $st->execute([$subcategoryId]);
        return $st->fetchAll();
    }

    /** Every card in a category, deck by deck in display order, each with subcategory_name. */
    public static function listForCategory(int $categoryId): array {
        $st = self::pdo()->prepare(
            'SELECT k.*, s.name AS subcategory_name
             FROM cards k
             JOIN subcategories s ON s.id = k.subcategory_id
             WHERE s.category_id = ?
             ORDER BY s.sort_order, s.id, k.sort_order, k.id'
        );
        $st->execute([$categoryId]);
        return $st->fetchAll();
    }

    /**
     * Where a card sits in its deck's display order: the previous and next
     * cards (null at the ends), its 1-based index and the deck's card count.
     * For "Next card" navigation on the editor.
     *
     * @return array{prev:?array,next:?array,index:int,total:int}
     */
    public static function neighbors(int $cardId): array {
        $card = self::findById($cardId);
        if (!$card) {
            return ['prev' => null, 'next' => null, 'index' => 0, 'total' => 0];
        }
        $all = self::listForSubcategory((int)$card['subcategory_id']);
        $prev = null;
        $next = null;
        $index = 0;
        foreach ($all as $i => $row) {
            if ((int)$row['id'] === $cardId) {
                $index = $i + 1;
                $prev = $all[$i - 1] ?? null;
                $next = $all[$i + 1] ?? null;
                break;
            }
        }
        return ['prev' => $prev, 'next' => $next, 'index' => $index, 'total' => count($all)];
    }

    public static function countForSubcategory(int $subcategoryId): int {
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM cards WHERE subcategory_id = ?');
        $st->execute([$subcategoryId]);
        return (int)$st->fetchColumn();
    }

    /**
     * Every image object key recorded on a card, for the storage diagnostics
     * page (thumbnails are implied: see ImageStorage::thumbKeyFor).
     * @return string[]
     */
    public static function listImageObjectKeys(): array {
        $rows = self::pdo()->query('SELECT image_object_key FROM cards WHERE image_object_key IS NOT NULL ORDER BY id')->fetchAll();
        $keys = [];
        foreach ($rows as $r) {
            $key = (string)$r['image_object_key'];
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /** Image keys of one deck's cards. @return string[] */
    private static function listImageObjectKeysInSubcategory(int $subcategoryId): array {
        $st = self::pdo()->prepare('SELECT image_object_key FROM cards WHERE subcategory_id = ? AND image_object_key IS NOT NULL');
        $st->execute([$subcategoryId]);
        $keys = [];
        foreach ($st->fetchAll() as $r) {
            $key = (string)$r['image_object_key'];
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    // ---- writes -----------------------------------------------------------

    /**
     * Create a card in a deck. $image is an ImageStorage::prepareUpload()
     * result (already validated and resized) or null for a text-only card.
     *
     * @param array{front_text?:string,back_text?:string} $data
     * @return int the new card id
     */
    public static function create(?UserContext $ctx, int $subcategoryId, array $data, ?array $image = null): int {
        $ownerId = SubcategoryManagement::ownerUserIdOf($subcategoryId);
        if ($ownerId === null) {
            throw new RuntimeException('Deck not found.');
        }
        ContentAccess::assertCanEdit($ctx, $ownerId);
        [$front, $back] = self::validate($data, $image !== null);

        $pdo = self::pdo();
        $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM cards WHERE subcategory_id = ?');
        $st->execute([$subcategoryId]);
        $sortOrder = (int)$st->fetchColumn();

        $st = $pdo->prepare('INSERT INTO cards (subcategory_id, front_text, back_text, sort_order) VALUES (?, ?, ?, ?)');
        $st->execute([$subcategoryId, $front, $back, $sortOrder]);
        $id = (int)$pdo->lastInsertId();

        $key = null;
        if ($image !== null) {
            $key = ImageStorage::newObjectKeyFor($ownerId, $id, (string)$image['ext']);
            try {
                ImageStorage::putPrepared($key, $image);
            } catch (\Throwable $e) {
                // No image means possibly no front at all: take the row back out.
                $st = $pdo->prepare('DELETE FROM cards WHERE id = ?');
                $st->execute([$id]);
                throw $e;
            }
            self::writeImageColumns($id, $key, $image);
        }
        self::log('card.create', ['card_id' => $id, 'subcategory_id' => $subcategoryId, 'has_image' => $key !== null, 'object_key' => $key]);
        return $id;
    }

    /**
     * Change a card's text and/or order, and optionally replace ($image) or
     * remove ($removeImage) its image. Validation looks at whether an image
     * will exist AFTER the save, so removing the image from a card with no
     * front text is refused.
     *
     * @param array{front_text?:string,back_text?:string,sort_order?:int} $data
     */
    public static function update(?UserContext $ctx, int $id, array $data, ?array $image = null, bool $removeImage = false): void {
        $card = self::findById($id);
        if (!$card) {
            throw new RuntimeException('Card not found.');
        }
        $ownerId = (int)self::ownerUserIdOf($id);
        ContentAccess::assertCanEdit($ctx, $ownerId);

        $oldKey = (string)($card['image_object_key'] ?? '');
        $hasImageAfter = $image !== null || (!$removeImage && $oldKey !== '');
        [$front, $back] = self::validate($data + $card, $hasImageAfter);
        $sortOrder = array_key_exists('sort_order', $data) ? (int)$data['sort_order'] : (int)$card['sort_order'];

        $pdo = self::pdo();
        if ($image !== null) {
            $newKey = ImageStorage::newObjectKeyFor($ownerId, $id, (string)$image['ext']);
            ImageStorage::putPrepared($newKey, $image);
            $st = $pdo->prepare(
                'UPDATE cards SET front_text = ?, back_text = ?, sort_order = ?,
                        image_object_key = ?, image_content_type = ?, image_width = ?, image_height = ?, image_size_bytes = ?, image_uploaded_at = NOW()
                 WHERE id = ?'
            );
            $st->execute([$front, $back, $sortOrder, $newKey, (string)$image['content_type'], (int)$image['width'], (int)$image['height'], (int)$image['size'], $id]);
            if ($oldKey !== '') {
                self::deleteOldImageBestEffort($id, $oldKey);
            }
        } elseif ($removeImage && $oldKey !== '') {
            // Storage first: if the delete fails the row still points at a real
            // object and the user can retry; the reverse would leak the object.
            ImageStorage::deleteObject($oldKey);
            $st = $pdo->prepare(
                'UPDATE cards SET front_text = ?, back_text = ?, sort_order = ?,
                        image_object_key = NULL, image_content_type = NULL, image_width = NULL, image_height = NULL, image_size_bytes = NULL, image_uploaded_at = NULL
                 WHERE id = ?'
            );
            $st->execute([$front, $back, $sortOrder, $id]);
        } else {
            $st = $pdo->prepare('UPDATE cards SET front_text = ?, back_text = ?, sort_order = ? WHERE id = ?');
            $st->execute([$front, $back, $sortOrder, $id]);
        }
        self::log('card.update', [
            'card_id' => $id, 'subcategory_id' => (int)$card['subcategory_id'],
            'image' => $image !== null ? 'replaced' : (($removeImage && $oldKey !== '') ? 'removed' : 'unchanged'),
        ]);
    }

    /** Delete a card: its image from storage first (best effort), then the row (progress rows cascade). */
    public static function delete(?UserContext $ctx, int $id): void {
        $card = self::findById($id);
        if (!$card) {
            throw new RuntimeException('Card not found.');
        }
        ContentAccess::assertCanEdit($ctx, (int)self::ownerUserIdOf($id));

        $key = (string)($card['image_object_key'] ?? '');
        if ($key !== '') {
            self::deleteOldImageBestEffort($id, $key);
        }
        $st = self::pdo()->prepare('DELETE FROM cards WHERE id = ?');
        $st->execute([$id]);
        self::log('card.delete', ['card_id' => $id, 'subcategory_id' => (int)$card['subcategory_id'], 'object_key' => $key !== '' ? $key : null]);
    }

    /** Put a new image on a card (replacing any previous one). $image is a prepareUpload() result. */
    public static function setImage(?UserContext $ctx, int $id, array $image): void {
        $card = self::findById($id);
        if (!$card) {
            throw new RuntimeException('Card not found.');
        }
        $ownerId = (int)self::ownerUserIdOf($id);
        ContentAccess::assertCanEdit($ctx, $ownerId);

        $oldKey = (string)($card['image_object_key'] ?? '');
        $newKey = ImageStorage::newObjectKeyFor($ownerId, $id, (string)$image['ext']);
        ImageStorage::putPrepared($newKey, $image);
        self::writeImageColumns($id, $newKey, $image);
        if ($oldKey !== '') {
            self::deleteOldImageBestEffort($id, $oldKey);
        }
        self::log('card.image_set', ['card_id' => $id, 'object_key' => $newKey, 'replaced' => $oldKey !== '' ? $oldKey : null]);
    }

    /** Remove a card's image (storage first, then the columns). Refused when the card would have no front left. */
    public static function removeImage(?UserContext $ctx, int $id): void {
        $card = self::findById($id);
        if (!$card) {
            throw new RuntimeException('Card not found.');
        }
        ContentAccess::assertCanEdit($ctx, (int)self::ownerUserIdOf($id));
        $key = (string)($card['image_object_key'] ?? '');
        if ($key === '') {
            return;
        }
        self::validate($card, false);

        ImageStorage::deleteObject($key);
        $st = self::pdo()->prepare(
            'UPDATE cards SET image_object_key = NULL, image_content_type = NULL, image_width = NULL, image_height = NULL, image_size_bytes = NULL, image_uploaded_at = NULL
             WHERE id = ?'
        );
        $st->execute([$id]);
        self::log('card.image_remove', ['card_id' => $id, 'object_key' => $key]);
    }

    /**
     * Move a card one step up or down within its deck by swapping sort_order
     * with its neighbour. Decks whose sort_orders are not a clean 1..n
     * sequence are renumbered first. Moving past either end is a no-op.
     */
    public static function moveInOrder(?UserContext $ctx, int $id, string $direction): void {
        if ($direction !== 'up' && $direction !== 'down') {
            throw new InvalidArgumentException('Direction must be "up" or "down".');
        }
        $card = self::findById($id);
        if (!$card) {
            throw new RuntimeException('Card not found.');
        }
        ContentAccess::assertCanEdit($ctx, (int)self::ownerUserIdOf($id));
        $subcategoryId = (int)$card['subcategory_id'];

        $pdo = self::pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $cards = self::listForSubcategory($subcategoryId);
            if (!self::sortOrdersAreClean($cards)) {
                $st = $pdo->prepare('UPDATE cards SET sort_order = ? WHERE id = ?');
                foreach ($cards as $i => $row) {
                    $st->execute([$i + 1, (int)$row['id']]);
                    $cards[$i]['sort_order'] = $i + 1;
                }
            }
            $index = null;
            foreach ($cards as $i => $row) {
                if ((int)$row['id'] === $id) {
                    $index = $i;
                    break;
                }
            }
            $neighbourIndex = $direction === 'up' ? $index - 1 : $index + 1;
            $neighbour = ($index !== null && isset($cards[$neighbourIndex])) ? $cards[$neighbourIndex] : null;
            if ($neighbour !== null) {
                $st = $pdo->prepare('UPDATE cards SET sort_order = ? WHERE id = ?');
                $st->execute([(int)$neighbour['sort_order'], $id]);
                $st->execute([(int)$cards[$index]['sort_order'], (int)$neighbour['id']]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        if ($neighbour !== null) {
            self::log('card.move', ['card_id' => $id, 'subcategory_id' => $subcategoryId, 'direction' => $direction, 'swapped_with' => (int)$neighbour['id']]);
        }
    }

    /** Whether the (already ordered) cards carry sort_orders 1..n. */
    private static function sortOrdersAreClean(array $orderedCards): bool {
        foreach ($orderedCards as $i => $row) {
            if ((int)$row['sort_order'] !== $i + 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * Parse bulk-add text: one card per non-blank line, "front | back" (the
     * FIRST "|" splits; a tab is accepted when a line has no "|"). Pure.
     * Every problem line is named in one message.
     *
     * @return array<int,array{front:string,back:string}>
     * @throws InvalidArgumentException
     */
    public static function parseBulkLines(string $lines): array {
        $cards = [];
        $problems = [];
        $lineNumber = 0;
        foreach (preg_split('/\r\n|\r|\n/', $lines) ?: [] as $raw) {
            $lineNumber++;
            if (trim($raw) === '') {
                continue;
            }
            $separator = strpos($raw, '|') !== false ? '|' : (strpos($raw, "\t") !== false ? "\t" : null);
            if ($separator === null) {
                $problems[] = 'line ' . $lineNumber . ' has no "|" between front and back';
                continue;
            }
            [$front, $back] = explode($separator, $raw, 2);
            $front = trim($front);
            $back = trim($back);
            if ($front === '') {
                $problems[] = 'line ' . $lineNumber . ' has an empty front';
            } elseif (mb_strlen($front) > self::MAX_TEXT) {
                $problems[] = 'line ' . $lineNumber . ' has a front longer than ' . self::MAX_TEXT . ' characters';
            }
            if ($back === '') {
                $problems[] = 'line ' . $lineNumber . ' has an empty back';
            } elseif (mb_strlen($back) > self::MAX_TEXT) {
                $problems[] = 'line ' . $lineNumber . ' has a back longer than ' . self::MAX_TEXT . ' characters';
            }
            $cards[] = ['front' => $front, 'back' => $back];
        }
        if ($problems !== []) {
            throw new InvalidArgumentException(ucfirst(implode('; ', $problems)) . '.');
        }
        if ($cards === []) {
            throw new InvalidArgumentException('Type at least one line in the form "front | back".');
        }
        if (count($cards) > self::BULK_MAX_LINES) {
            throw new InvalidArgumentException('You can add at most ' . self::BULK_MAX_LINES . ' cards at a time (' . count($cards) . ' lines given).');
        }
        return $cards;
    }

    /**
     * Create one text card per line of $lines (see parseBulkLines), all or
     * nothing. Returns the number created.
     */
    public static function bulkCreateFromLines(?UserContext $ctx, int $subcategoryId, string $lines): int {
        $ownerId = SubcategoryManagement::ownerUserIdOf($subcategoryId);
        if ($ownerId === null) {
            throw new RuntimeException('Deck not found.');
        }
        ContentAccess::assertCanEdit($ctx, $ownerId);
        $cards = self::parseBulkLines($lines);

        $pdo = self::pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM cards WHERE subcategory_id = ?');
            $st->execute([$subcategoryId]);
            $sortOrder = (int)$st->fetchColumn();
            $ins = $pdo->prepare('INSERT INTO cards (subcategory_id, front_text, back_text, sort_order) VALUES (?, ?, ?, ?)');
            foreach ($cards as $card) {
                $sortOrder++;
                $ins->execute([$subcategoryId, $card['front'], $card['back'], $sortOrder]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        self::log('card.bulk_create', ['subcategory_id' => $subcategoryId, 'count' => count($cards)]);
        return count($cards);
    }

    /**
     * Delete every card in a deck (SubcategoryManagement::delete calls this
     * inside its transaction). Images are removed from storage first, best
     * effort; progress rows cascade with the cards. Returns the count.
     */
    public static function deleteAllInSubcategory(?UserContext $ctx, int $subcategoryId): int {
        $ownerId = SubcategoryManagement::ownerUserIdOf($subcategoryId);
        if ($ownerId === null) {
            throw new RuntimeException('Deck not found.');
        }
        ContentAccess::assertCanEdit($ctx, $ownerId);

        $keys = self::listImageObjectKeysInSubcategory($subcategoryId);
        if ($keys !== []) {
            try {
                ImageStorage::deleteObjects($keys);
            } catch (\Throwable $e) {
                self::log('card.image_delete_failed', ['subcategory_id' => $subcategoryId, 'object_keys' => $keys, 'error' => $e->getMessage()]);
            }
        }
        $st = self::pdo()->prepare('DELETE FROM cards WHERE subcategory_id = ?');
        $st->execute([$subcategoryId]);
        $count = $st->rowCount();
        self::log('card.delete_all', ['subcategory_id' => $subcategoryId, 'count' => $count, 'images' => count($keys)]);
        return $count;
    }

    // ---- helpers ----------------------------------------------------------

    private static function writeImageColumns(int $id, string $key, array $image): void {
        $st = self::pdo()->prepare(
            'UPDATE cards SET image_object_key = ?, image_content_type = ?, image_width = ?, image_height = ?, image_size_bytes = ?, image_uploaded_at = NOW()
             WHERE id = ?'
        );
        $st->execute([$key, (string)$image['content_type'], (int)$image['width'], (int)$image['height'], (int)$image['size'], $id]);
    }

    /** Delete an object the database no longer references; a failure is logged, not raised. */
    private static function deleteOldImageBestEffort(int $cardId, string $key): void {
        try {
            ImageStorage::deleteObject($key);
        } catch (\Throwable $e) {
            self::log('card.image_delete_failed', ['card_id' => $cardId, 'object_key' => $key, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{0:string,1:string} [front_text, back_text]
     * @throws InvalidArgumentException with a form-ready message
     */
    private static function validate(array $data, bool $hasImageAfterSave): array {
        $front = trim((string)($data['front_text'] ?? ''));
        $back = trim((string)($data['back_text'] ?? ''));
        if ($back === '') {
            throw new InvalidArgumentException('The back of the card is required.');
        }
        if ($front === '' && !$hasImageAfterSave) {
            throw new InvalidArgumentException('Give the card a front: some text, an image, or both.');
        }
        if (mb_strlen($front) > self::MAX_TEXT) {
            throw new InvalidArgumentException('The front must be ' . self::MAX_TEXT . ' characters or fewer.');
        }
        if (mb_strlen($back) > self::MAX_TEXT) {
            throw new InvalidArgumentException('The back must be ' . self::MAX_TEXT . ' characters or fewer.');
        }
        return [$front, $back];
    }
}
