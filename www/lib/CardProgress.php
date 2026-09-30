<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/Deck.php';
require_once __DIR__ . '/SubcategoryManagement.php';
require_once __DIR__ . '/ImageStorage.php';

/**
 * Per-viewer flashcard progress: the ordered cards of a deck (with the
 * viewer's shuffle), Got it / Need More Review marks, flags, resume points,
 * and the score/stat aggregates. All SQL touching user_card_state,
 * card_review_events and user_deck_positions lives here.
 *
 * The "viewer" is whoever studies: the deck's owner, or any signed-in
 * visitor of a public deck — progress is per viewer, never per owner.
 * Writes therefore check that the viewer may VIEW the card's deck (not own it).
 */
final class CardProgress {
    public const MARK_GOT_IT = 'got_it';
    public const MARK_NEEDS_REVIEW = 'needs_review';

    public const FILTER_ALL = 'all';
    public const FILTER_FLAGGED = 'flagged';
    public const FILTER_NEEDS_REVIEW = 'needs_review';

    private static function pdo(): PDO {
        return pdo();
    }

    private static function assertValidMark(string $mark): void {
        if (!in_array($mark, [self::MARK_GOT_IT, self::MARK_NEEDS_REVIEW], true)) {
            throw new InvalidArgumentException('Unknown mark: ' . $mark);
        }
    }

    private static function assertValidFilter(string $filter): void {
        if (!in_array($filter, [self::FILTER_ALL, self::FILTER_FLAGGED, self::FILTER_NEEDS_REVIEW], true)) {
            throw new InvalidArgumentException('Unknown deck filter: ' . $filter);
        }
    }

    /** The deck a card belongs to, after checking the viewer may study it. */
    private static function viewableDeckOfCard(?UserContext $ctx, int $cardId): Deck {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        $deck = Deck::ofCard($cardId);
        if (!$deck) {
            throw new RuntimeException('Card not found.');
        }
        $deck->assertCanView($ctx);
        return $deck;
    }

    /**
     * The JOIN + WHERE fragment (and params) restricting a per-card table
     * aliased x to one deck's cards; no-ops when $deck is null.
     * @return array{0:string,1:string,2:array}
     */
    private static function cardScope(?Deck $deck): array {
        if ($deck === null) {
            return ['', '', []];
        }
        [$where, $params] = $deck->scopeSql('k');
        return [' INNER JOIN cards k ON k.id = x.card_id', ' AND ' . $where, $params];
    }

    /** The viewer's user_deck_positions row for a deck, or null. */
    private static function deckRow(int $viewerId, Deck $deck): ?array {
        $st = self::pdo()->prepare(
            'SELECT position, shuffle_seed FROM user_deck_positions WHERE user_id = ? AND deck_type = ? AND deck_id = ? LIMIT 1'
        );
        $st->execute([$viewerId, $deck->type, $deck->id]);
        return $st->fetch() ?: null;
    }

    // ---- the deck ---------------------------------------------------------

    /**
     * The deck's cards in the viewer's current order. Without a shuffle seed
     * that is the tree order (subcategory, then card sort_order); with one it
     * is SHA2(seed:card_id) — a stable per-viewer permutation that places
     * later-added cards without any backfill.
     *
     * $filter: 'all', 'flagged' (the viewer's flagged cards) or 'needs_review'
     * (cards whose latest mark was Need More Review). $viewerId may be 0 for
     * an anonymous visitor (no state, no shuffle).
     *
     * Rows: id, front_text, back_text, image_url, image_width, image_height,
     * subcategory_name, flagged (bool), marked (bool), last_mark.
     */
    public static function getDeckCards(int $viewerId, Deck $deck, string $filter = self::FILTER_ALL): array {
        self::assertValidFilter($filter);
        [$scopeWhere, $scopeParams] = $deck->scopeSql('k');
        $seed = $viewerId > 0 ? self::shuffleSeedFor($viewerId, $deck) : null;

        $sql = 'SELECT k.id, k.front_text, k.back_text, k.image_object_key, k.image_width, k.image_height,
                       s.name AS subcategory_name,
                       COALESCE(x.is_flagged, 0) AS is_flagged,
                       x.last_mark
                FROM cards k
                INNER JOIN subcategories s ON s.id = k.subcategory_id
                LEFT JOIN user_card_state x ON x.card_id = k.id AND x.user_id = ?
                WHERE ' . $scopeWhere;
        $params = array_merge([$viewerId], $scopeParams);

        if ($filter === self::FILTER_FLAGGED) {
            $sql .= ' AND x.is_flagged = 1';
        } elseif ($filter === self::FILTER_NEEDS_REVIEW) {
            $sql .= " AND x.last_mark = 'needs_review'";
        }

        if ($seed === null) {
            $sql .= ' ORDER BY s.sort_order, s.id, k.sort_order, k.id';
        } else {
            $sql .= " ORDER BY SHA2(CONCAT(?, ':', k.id), 256)";
            $params[] = $seed;
        }

        $st = self::pdo()->prepare($sql);
        $st->execute($params);

        $cards = [];
        foreach ($st->fetchAll() as $row) {
            $cards[] = [
                'id' => (int)$row['id'],
                'front_text' => (string)$row['front_text'],
                'back_text' => (string)$row['back_text'],
                'image_url' => ImageStorage::displayUrlForCard($row),
                'image_width' => $row['image_width'] === null ? null : (int)$row['image_width'],
                'image_height' => $row['image_height'] === null ? null : (int)$row['image_height'],
                'subcategory_name' => (string)$row['subcategory_name'],
                'flagged' => !empty($row['is_flagged']),
                'marked' => $row['last_mark'] !== null,
                'last_mark' => $row['last_mark'] === null ? null : (string)$row['last_mark'],
            ];
        }
        return $cards;
    }

    // ---- marks and flags --------------------------------------------------

    /**
     * Record a Got it / Need More Review click: upsert the card's state row,
     * bump its counter, append a review event, and (when given) persist the
     * viewer's position in the deck being studied — $deck, or the card's own
     * subcategory deck when the caller did not say. Returns the fresh score
     * summary so the caller can repaint the score chip.
     */
    public static function markCard(UserContext $ctx, int $cardId, string $mark, ?int $position = null, ?Deck $deck = null): array {
        self::assertValidMark($mark);
        $cardDeck = self::viewableDeckOfCard($ctx, $cardId);

        $counterColumn = $mark === self::MARK_GOT_IT ? 'got_it_count' : 'needs_review_count';
        $pdo = self::pdo();
        $st = $pdo->prepare(
            "INSERT INTO user_card_state (user_id, card_id, last_mark, {$counterColumn}, last_reviewed_at)
             VALUES (?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE
               last_mark = VALUES(last_mark),
               {$counterColumn} = {$counterColumn} + 1,
               last_reviewed_at = NOW()"
        );
        $st->execute([$ctx->id, $cardId, $mark]);

        $st = $pdo->prepare('INSERT INTO card_review_events (user_id, card_id, mark) VALUES (?, ?, ?)');
        $st->execute([$ctx->id, $cardId, $mark]);

        if ($position !== null) {
            self::saveDeckPosition($ctx, $deck ?? $cardDeck, $position);
        }

        ActivityLog::log($ctx, 'card.marked', ['card_id' => $cardId, 'mark' => $mark]);

        return self::getScoreSummary($ctx->id);
    }

    /** Save the viewer's flag on a card. Returns the stored value. */
    public static function setCardFlag(UserContext $ctx, int $cardId, bool $flagged): bool {
        self::viewableDeckOfCard($ctx, $cardId);

        $st = self::pdo()->prepare(
            'INSERT INTO user_card_state (user_id, card_id, is_flagged) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE is_flagged = VALUES(is_flagged)'
        );
        $st->execute([$ctx->id, $cardId, $flagged ? 1 : 0]);

        ActivityLog::log($ctx, 'card.flag_toggled', ['card_id' => $cardId, 'is_flagged' => $flagged]);
        return $flagged;
    }

    // ---- order and resume point (per viewer, per deck) --------------------

    /** Re-deal the deck for this viewer: a new random seed, back to the first card. */
    public static function shuffleDeck(UserContext $ctx, Deck $deck): int {
        $deck->assertCanView($ctx);
        $seed = random_int(1, 2147483647);
        $st = self::pdo()->prepare(
            'INSERT INTO user_deck_positions (user_id, deck_type, deck_id, position, shuffle_seed) VALUES (?, ?, ?, 0, ?)
             ON DUPLICATE KEY UPDATE position = 0, shuffle_seed = VALUES(shuffle_seed)'
        );
        $st->execute([$ctx->id, $deck->type, $deck->id, $seed]);

        ActivityLog::log($ctx, 'deck.shuffled', ['deck_type' => $deck->type, 'deck_id' => $deck->id, 'seed' => $seed]);
        return $seed;
    }

    /** Back to the tree order, starting from the first card. */
    public static function restoreOriginalOrder(UserContext $ctx, Deck $deck): void {
        $deck->assertCanView($ctx);
        $st = self::pdo()->prepare(
            'INSERT INTO user_deck_positions (user_id, deck_type, deck_id, position, shuffle_seed) VALUES (?, ?, ?, 0, NULL)
             ON DUPLICATE KEY UPDATE position = 0, shuffle_seed = NULL'
        );
        $st->execute([$ctx->id, $deck->type, $deck->id]);

        ActivityLog::log($ctx, 'deck.order_restored', ['deck_type' => $deck->type, 'deck_id' => $deck->id]);
    }

    /** Persist the resume point (piggybacks on mark saves; not logged). */
    public static function saveDeckPosition(UserContext $ctx, Deck $deck, int $position): void {
        $deck->assertCanView($ctx);
        $position = max(0, $position);
        $st = self::pdo()->prepare(
            'INSERT INTO user_deck_positions (user_id, deck_type, deck_id, position) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE position = VALUES(position)'
        );
        $st->execute([$ctx->id, $deck->type, $deck->id, $position]);
    }

    public static function deckPositionFor(int $viewerId, Deck $deck): int {
        $row = self::deckRow($viewerId, $deck);
        return (int)($row['position'] ?? 0);
    }

    private static function shuffleSeedFor(int $viewerId, Deck $deck): ?int {
        $row = self::deckRow($viewerId, $deck);
        if (!$row || $row['shuffle_seed'] === null) {
            return null;
        }
        return (int)$row['shuffle_seed'];
    }

    public static function isDeckShuffled(int $viewerId, Deck $deck): bool {
        return self::shuffleSeedFor($viewerId, $deck) !== null;
    }

    /** Drop every viewer's position/shuffle for a deck that is being deleted. */
    public static function forgetDeck(string $deckType, int $deckId): void {
        $st = self::pdo()->prepare('DELETE FROM user_deck_positions WHERE deck_type = ? AND deck_id = ?');
        $st->execute([$deckType, $deckId]);
    }

    // ---- aggregates -------------------------------------------------------

    /**
     * The header score chip: mastered = cards whose latest mark is Got it.
     * Returns ['mastered' => int, 'total_cards' => int, 'reviewed_today' => int].
     * A $deck scopes everything to that deck's cards; without one, total_cards
     * counts the cards the viewer owns plus any other cards they have studied.
     */
    public static function getScoreSummary(int $viewerId, ?Deck $deck = null): array {
        $pdo = self::pdo();
        [$scopeJoin, $scopeWhere, $scopeParams] = self::cardScope($deck);

        $st = $pdo->prepare("SELECT COUNT(*) FROM user_card_state x{$scopeJoin} WHERE x.user_id = ? AND x.last_mark = 'got_it'{$scopeWhere}");
        $st->execute(array_merge([$viewerId], $scopeParams));
        $mastered = (int)$st->fetchColumn();

        if ($deck !== null) {
            $total = $deck->cardCount();
        } else {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM (
                    SELECT k.id FROM cards k
                    INNER JOIN subcategories s ON s.id = k.subcategory_id
                    INNER JOIN categories c ON c.id = s.category_id
                    WHERE c.user_id = ?
                    UNION
                    SELECT x.card_id FROM user_card_state x WHERE x.user_id = ?
                 ) t'
            );
            $st->execute([$viewerId, $viewerId]);
            $total = (int)$st->fetchColumn();
        }

        $st = $pdo->prepare("SELECT COUNT(*) FROM card_review_events x{$scopeJoin} WHERE x.user_id = ? AND x.created_at >= CURDATE(){$scopeWhere}");
        $st->execute(array_merge([$viewerId], $scopeParams));
        $reviewedToday = (int)$st->fetchColumn();

        return [
            'mastered' => $mastered,
            'total_cards' => $total,
            'reviewed_today' => $reviewedToday,
        ];
    }

    /**
     * Everything the stats page shows: got_it (= mastered), total_cards,
     * needs_review, flagged, reviewed_today, total_reviews, and daily review
     * counts for the last $days days (['date' => 'Y-m-d', 'count' => int],
     * oldest first, zero days included). A $deck scopes it all to one deck.
     */
    public static function getStatsForUser(int $viewerId, ?Deck $deck = null, int $days = 14): array {
        $pdo = self::pdo();
        $summary = self::getScoreSummary($viewerId, $deck);
        [$scopeJoin, $scopeWhere, $scopeParams] = self::cardScope($deck);

        $st = $pdo->prepare("SELECT COUNT(*) FROM user_card_state x{$scopeJoin} WHERE x.user_id = ? AND x.last_mark = 'needs_review'{$scopeWhere}");
        $st->execute(array_merge([$viewerId], $scopeParams));
        $needsReview = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM user_card_state x{$scopeJoin} WHERE x.user_id = ? AND x.is_flagged = 1{$scopeWhere}");
        $st->execute(array_merge([$viewerId], $scopeParams));
        $flagged = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM card_review_events x{$scopeJoin} WHERE x.user_id = ?{$scopeWhere}");
        $st->execute(array_merge([$viewerId], $scopeParams));
        $totalReviews = (int)$st->fetchColumn();

        $days = max(1, $days);
        $st = $pdo->prepare(
            "SELECT DATE(x.created_at) AS day, COUNT(*) AS c
             FROM card_review_events x{$scopeJoin}
             WHERE x.user_id = ? AND x.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY){$scopeWhere}
             GROUP BY DATE(x.created_at)"
        );
        $st->execute(array_merge([$viewerId, $days - 1], $scopeParams));
        $byDay = [];
        foreach ($st->fetchAll() as $row) {
            $byDay[(string)$row['day']] = (int)$row['c'];
        }
        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $daily[] = ['date' => $date, 'count' => $byDay[$date] ?? 0];
        }

        return [
            'got_it' => $summary['mastered'],
            'mastered' => $summary['mastered'],
            'total_cards' => $summary['total_cards'],
            'needs_review' => $needsReview,
            'flagged' => $flagged,
            'reviewed_today' => $summary['reviewed_today'],
            'total_reviews' => $totalReviews,
            'daily' => $daily,
        ];
    }

    /**
     * Learned/total per deck of one owner's tree, as the viewer sees it
     * (learned = cards whose latest mark is Got it for the viewer):
     * ['category' => [id => ['total' => n, 'learned' => n]], 'subcategory' => [id => [...]]].
     * Every category and subcategory of the owner is present, empty ones as 0/0.
     */
    public static function deckSummariesForUser(int $viewerId, int $ownerUserId): array {
        $pdo = self::pdo();
        $out = ['category' => [], 'subcategory' => []];

        $st = $pdo->prepare('SELECT id FROM categories WHERE user_id = ?');
        $st->execute([$ownerUserId]);
        foreach ($st->fetchAll() as $row) {
            $out['category'][(int)$row['id']] = ['total' => 0, 'learned' => 0];
        }

        $st = $pdo->prepare(
            "SELECT s.id AS subcategory_id, s.category_id,
                    COUNT(k.id) AS total,
                    SUM(CASE WHEN x.last_mark = 'got_it' THEN 1 ELSE 0 END) AS learned
             FROM subcategories s
             INNER JOIN categories c ON c.id = s.category_id
             LEFT JOIN cards k ON k.subcategory_id = s.id
             LEFT JOIN user_card_state x ON x.card_id = k.id AND x.user_id = ?
             WHERE c.user_id = ?
             GROUP BY s.id, s.category_id"
        );
        $st->execute([$viewerId, $ownerUserId]);
        foreach ($st->fetchAll() as $row) {
            $total = (int)$row['total'];
            $learned = (int)$row['learned'];
            $out['subcategory'][(int)$row['subcategory_id']] = ['total' => $total, 'learned' => $learned];
            $catId = (int)$row['category_id'];
            if (!isset($out['category'][$catId])) {
                $out['category'][$catId] = ['total' => 0, 'learned' => 0];
            }
            $out['category'][$catId]['total'] += $total;
            $out['category'][$catId]['learned'] += $learned;
        }
        return $out;
    }

    /**
     * Every deck the viewer can pick from: the categories they own (in tree
     * order) followed by categories of other owners they have progress on.
     * Each entry: ['category' => row (+card_count, learned), 'subcategories' =>
     * [rows (+card_count, learned)], 'owner_first_name' => string, 'is_mine' => bool].
     */
    public static function listDecksForUser(int $viewerId): array {
        $pdo = self::pdo();

        $st = $pdo->prepare(
            'SELECT c.*, u.first_name AS owner_first_name
             FROM categories c INNER JOIN users u ON u.id = c.user_id
             WHERE c.user_id = ?
             ORDER BY c.sort_order, c.name'
        );
        $st->execute([$viewerId]);
        $mine = $st->fetchAll();

        $st = $pdo->prepare(
            'SELECT DISTINCT c.*, u.first_name AS owner_first_name
             FROM categories c
             INNER JOIN users u ON u.id = c.user_id
             INNER JOIN subcategories s ON s.category_id = c.id
             INNER JOIN cards k ON k.subcategory_id = s.id
             INNER JOIN user_card_state x ON x.card_id = k.id AND x.user_id = ?
             WHERE c.user_id <> ?
             ORDER BY u.first_name, c.sort_order, c.name'
        );
        $st->execute([$viewerId, $viewerId]);
        $others = $st->fetchAll();

        $summariesByOwner = [];
        $decks = [];
        foreach (array_merge($mine, $others) as $cat) {
            $ownerId = (int)$cat['user_id'];
            if (!isset($summariesByOwner[$ownerId])) {
                $summariesByOwner[$ownerId] = self::deckSummariesForUser($viewerId, $ownerId);
            }
            $summaries = $summariesByOwner[$ownerId];
            $catId = (int)$cat['id'];
            $catSummary = $summaries['category'][$catId] ?? ['total' => 0, 'learned' => 0];
            $cat['card_count'] = $catSummary['total'];
            $cat['learned'] = $catSummary['learned'];

            $subs = SubcategoryManagement::listForCategory($catId);
            foreach ($subs as &$sub) {
                $subSummary = $summaries['subcategory'][(int)$sub['id']] ?? ['total' => 0, 'learned' => 0];
                $sub['card_count'] = (int)$sub['card_count'];
                $sub['learned'] = $subSummary['learned'];
            }
            unset($sub);

            $decks[] = [
                'category' => $cat,
                'subcategories' => $subs,
                'owner_first_name' => (string)$cat['owner_first_name'],
                'is_mine' => $ownerId === $viewerId,
            ];
        }
        return $decks;
    }

    /**
     * Cards the viewer marks Need More Review most often (every such mark
     * ever, so a card stays counted after it is later gotten right). Rows:
     * card_id, front_text, back_text, subcategory_name, misses — most missed
     * first. $limit null = all.
     */
    public static function getMostMissedCards(int $viewerId, ?int $limit = 20, ?Deck $deck = null): array {
        [$scopeWhere, $scopeParams] = $deck !== null ? $deck->scopeSql('k') : ['1=1', []];
        $sql = "SELECT k.id AS card_id, k.front_text, k.back_text, s.name AS subcategory_name, COUNT(*) AS misses
                FROM card_review_events x
                INNER JOIN cards k ON k.id = x.card_id
                INNER JOIN subcategories s ON s.id = k.subcategory_id
                WHERE x.user_id = ? AND x.mark = 'needs_review' AND {$scopeWhere}
                GROUP BY k.id, k.front_text, k.back_text, s.name
                ORDER BY misses DESC, k.id";
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }
        $st = self::pdo()->prepare($sql);
        $st->execute(array_merge([$viewerId], $scopeParams));
        $rows = [];
        foreach ($st->fetchAll() as $row) {
            $rows[] = [
                'card_id' => (int)$row['card_id'],
                'front_text' => (string)$row['front_text'],
                'back_text' => (string)$row['back_text'],
                'subcategory_name' => (string)$row['subcategory_name'],
                'misses' => (int)$row['misses'],
            ];
        }
        return $rows;
    }
}
