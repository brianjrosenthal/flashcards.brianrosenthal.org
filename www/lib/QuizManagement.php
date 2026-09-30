<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/Deck.php';
require_once __DIR__ . '/ImageStorage.php';

/**
 * The typed quiz: the front of a card (its text and/or image) is shown and
 * the person types what is on the back.
 *
 * Answers are judged on the server (the browser only ever receives the
 * fronts), scored into points, and appended to quiz_attempts. Because a back
 * can be phrased more than one way, a wrong answer can be claimed as right
 * anyway — markAttemptCorrectAnyway() — which awards partial credit while
 * keeping the honest record of what was typed.
 *
 * All SQL touching quiz_attempts lives here. The judging helpers are pure so
 * the unit tests can exercise them directly.
 */
final class QuizManagement {
    public const RESULT_CORRECT = 'correct';     // matches an accepted answer exactly
    public const RESULT_CLOSE = 'close';         // a typo or two away from one
    public const RESULT_INCORRECT = 'incorrect';

    // Which cards a round draws from. SOURCE_MISSES is every weak spot: cards
    // whose latest flashcard mark is Need More Review, flagged cards, and
    // cards missed in a quiz and not got right since.
    public const SOURCE_ALL = 'all';
    public const SOURCE_MISSES = 'misses';

    public const POINTS_CORRECT = 10;
    public const POINTS_CLOSE = 8;
    public const POINTS_OVERRIDE = 5;            // "my answer was right anyway"

    private static function pdo(): PDO {
        return pdo();
    }

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }

    public static function isValidSource(string $source): bool {
        return in_array($source, [self::SOURCE_ALL, self::SOURCE_MISSES], true);
    }

    private static function assertValidSource(string $source): void {
        if (!self::isValidSource($source)) {
            throw new InvalidArgumentException('Unknown quiz card pool: ' . $source);
        }
    }

    // ===== Building a round =====

    /**
     * A round of questions from one deck and one pool of cards (see the
     * SOURCE_* constants). $limit caps the round; null takes everything.
     *
     * Cards are dealt least-recently-quizzed first — never-quizzed cards lead,
     * then the longest-ago ones, ties at random — so a second round moves on
     * to cards the first one did not ask instead of sampling the deck afresh.
     * The chosen slice is then shuffled so the round is not asked in a
     * predictable sequence.
     *
     * Each question is [card_id, front_text, image_url, words, letters,
     * first_letter]. The back is deliberately absent: the client posts what
     * was typed to recordAnswer() and the server decides.
     */
    public static function buildQuizRound(int $viewerId, Deck $deck, string $source = self::SOURCE_ALL, ?int $limit = 20): array {
        $cards = self::fetchCandidateCards($viewerId, $deck, $source);
        if ($limit !== null && $limit > 0 && count($cards) > $limit) {
            $cards = array_slice($cards, 0, $limit);
        }
        $questions = array_map([self::class, 'buildQuestionForCard'], $cards);
        shuffle($questions);
        return $questions;
    }

    /** How many questions this deck and pool can produce for the viewer. */
    public static function countAvailableQuestions(int $viewerId, Deck $deck, string $source): int {
        self::assertValidSource($source);
        $params = [$viewerId, $viewerId];
        $sql = 'SELECT COUNT(*) ' . self::candidateFromSql($deck, $source, $params);
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    /**
     * The primary accepted answer of every card in the deck — unique, sorted,
     * normalized — for the "answers starting with that letter" chips. Nothing
     * marks which one belongs to the question being asked.
     */
    public static function listAnswerTexts(Deck $deck): array {
        [$where, $params] = $deck->scopeSql('k');
        $st = self::pdo()->prepare('SELECT k.back_text FROM cards k WHERE ' . $where);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $accepted = self::acceptedAnswers((string)$row['back_text']);
            if ($accepted) {
                $out[$accepted[0]] = true;
            }
        }
        $list = array_keys($out);
        sort($list, SORT_STRING);
        return $list;
    }

    /**
     * Candidate cards, oldest-quizzed first, with everything a question or a
     * verdict needs. The quiz_attempts summary joined here does double duty:
     * it supplies the ordering and the "cards I miss" test.
     */
    private static function fetchCandidateCards(int $viewerId, Deck $deck, string $source): array {
        self::assertValidSource($source);
        $params = [$viewerId, $viewerId];
        $sql = 'SELECT k.* ' . self::candidateFromSql($deck, $source, $params)
             . ' ORDER BY (qa.last_quizzed IS NULL) DESC, qa.last_quizzed ASC, RAND()';
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /**
     * FROM ... WHERE ... for the cards of $deck in $source, for the viewer.
     * $params must already hold the viewer id twice (state join, quiz join);
     * the deck's parameters are appended.
     */
    private static function candidateFromSql(Deck $deck, string $source, array &$params): string {
        [$scopeWhere, $scopeParams] = $deck->scopeSql('k');
        foreach ($scopeParams as $p) {
            $params[] = $p;
        }
        $sql = "FROM cards k
                LEFT JOIN user_card_state s ON s.card_id = k.id AND s.user_id = ?
                LEFT JOIN (
                    SELECT card_id,
                           MAX(created_at) AS last_quizzed,
                           MAX(CASE WHEN result <> 'incorrect' OR was_overridden = 1 THEN created_at END) AS last_right,
                           MAX(CASE WHEN result = 'incorrect' AND was_overridden = 0 THEN created_at END) AS last_wrong
                    FROM quiz_attempts
                    WHERE user_id = ?
                    GROUP BY card_id
                ) qa ON qa.card_id = k.id
                WHERE " . $scopeWhere;
        if ($source === self::SOURCE_MISSES) {
            $sql .= " AND (s.is_flagged = 1
                           OR s.last_mark = 'needs_review'
                           OR (qa.last_wrong IS NOT NULL
                               AND (qa.last_right IS NULL OR qa.last_wrong > qa.last_right)))";
        }
        return $sql;
    }

    /** One question from a cards row: the front, plus the hint numbers about the back. */
    private static function buildQuestionForCard(array $card): array {
        $accepted = self::acceptedAnswers((string)$card['back_text']);
        $primary = $accepted[0] ?? '';
        return [
            'card_id' => (int)$card['id'],
            'front_text' => (string)$card['front_text'],
            'image_url' => ImageStorage::displayUrlForCard($card),
            'words' => $primary === '' ? 0 : count(explode(' ', $primary)),
            'letters' => (int)preg_match_all('/[\p{L}\p{N}]/u', $primary),
            'first_letter' => mb_strtoupper(mb_substr($primary, 0, 1)),
        ];
    }

    private static function fetchCard(int $cardId): ?array {
        $st = self::pdo()->prepare('SELECT * FROM cards WHERE id = ? LIMIT 1');
        $st->execute([$cardId]);
        return $st->fetch() ?: null;
    }

    // ===== Judging an answer =====

    /**
     * Every answer a back accepts, normalized, the primary one first. A back
     * lists alternatives separated by "/", ";" or a line break, and a note in
     * parentheses is optional: "George Washington (1st president)" accepts
     * both "george washington" and the note included.
     */
    public static function acceptedAnswers(string $backText): array {
        $out = [];
        foreach (preg_split('/[\/;]|\r?\n/u', $backText) ?: [] as $piece) {
            $withoutNotes = (string)preg_replace('/\([^)]*\)/u', ' ', $piece);
            foreach ([$withoutNotes, $piece] as $variant) {
                $normalized = self::normalizeAnswer($variant);
                if ($normalized !== '' && !in_array($normalized, $out, true)) {
                    $out[] = $normalized;
                }
            }
        }
        return $out;
    }

    /**
     * Compare on letters alone: case, punctuation, stray spacing and a leading
     * article ("the", "a", "an", "to") should not decide whether someone knows
     * what is on the back.
     */
    public static function normalizeAnswer(string $s): string {
        $s = mb_strtolower(trim($s));
        $s = (string)preg_replace('/[^\p{L}\p{N}\s\'-]+/u', ' ', $s);
        $s = trim((string)preg_replace('/\s+/u', ' ', $s));
        $s = (string)preg_replace('/^(?:the|a|an|to) /u', '', $s);
        return trim($s);
    }

    /**
     * How many typos an answer may carry and still count as close: none in a
     * short answer (at four letters a "typo" is usually a different word),
     * one in a medium one, two in a long one.
     */
    public static function allowedEdits(string $target): int {
        $len = mb_strlen($target);
        if ($len <= 4) return 0;
        if ($len <= 8) return 1;
        return 2;
    }

    /**
     * Optimal string alignment distance, multibyte-safe: a letter added,
     * dropped or changed costs 1, and so does swapping two neighbours
     * ("diegn" for "deign"). PHP's levenshtein() counts bytes and calls a
     * swap two edits, so accented and swapped answers need this instead.
     */
    public static function editDistance(string $a, string $b): int {
        $x = $a === '' ? [] : mb_str_split($a);
        $y = $b === '' ? [] : mb_str_split($b);
        $m = count($x);
        $n = count($y);
        if ($m === 0) return $n;
        if ($n === 0) return $m;

        $d = [];
        for ($i = 0; $i <= $m; $i++) {
            $d[$i] = [$i];
        }
        for ($j = 0; $j <= $n; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
                $d[$i][$j] = min(
                    $d[$i - 1][$j] + 1,
                    $d[$i][$j - 1] + 1,
                    $d[$i - 1][$j - 1] + $cost
                );
                if ($i > 1 && $j > 1 && $x[$i - 1] === $y[$j - 2] && $x[$i - 2] === $y[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }
        return $d[$m][$n];
    }

    /**
     * What the typed answer is worth against a card's back: RESULT_CORRECT
     * when it equals any accepted answer, RESULT_CLOSE when it is within that
     * answer's typo allowance, else RESULT_INCORRECT.
     */
    public static function judgeAnswer(string $typed, string $backText): string {
        $typed = self::normalizeAnswer($typed);
        if ($typed === '') return self::RESULT_INCORRECT;

        $accepted = self::acceptedAnswers($backText);
        if (in_array($typed, $accepted, true)) {
            return self::RESULT_CORRECT;
        }
        foreach ($accepted as $candidate) {
            $allowed = self::allowedEdits($candidate);
            if ($allowed > 0 && self::editDistance($typed, $candidate) <= $allowed) {
                return self::RESULT_CLOSE;
            }
        }
        return self::RESULT_INCORRECT;
    }

    public static function pointsForResult(string $result): int {
        if ($result === self::RESULT_CORRECT) return self::POINTS_CORRECT;
        if ($result === self::RESULT_CLOSE) return self::POINTS_CLOSE;
        return 0;
    }

    // ===== Recording answers =====

    /**
     * Judge and record one typed answer. Anyone who may view the card's deck
     * may answer (the owner, an admin, or a signed-in visitor of a public
     * deck). Returns everything the quiz page needs to show the outcome: the
     * verdict, the points, the card's front and back, the attempt id (so the
     * answer can be claimed as right anyway), and the user's refreshed totals.
     */
    public static function recordAnswer(UserContext $ctx, int $cardId, string $typed): array {
        $deck = Deck::ofCard($cardId);
        if (!$deck) {
            throw new InvalidArgumentException('That card no longer exists.');
        }
        $deck->assertCanView($ctx);
        $card = self::fetchCard($cardId);
        if (!$card) {
            throw new InvalidArgumentException('That card no longer exists.');
        }

        $typed = trim($typed);
        if (mb_strlen($typed) > 255) {
            $typed = mb_substr($typed, 0, 255);
        }

        $result = self::judgeAnswer($typed, (string)$card['back_text']);
        $points = self::pointsForResult($result);

        $st = self::pdo()->prepare(
            'INSERT INTO quiz_attempts (user_id, card_id, answer_text, result, points_awarded) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$ctx->id, $cardId, $typed, $result, $points]);
        $attemptId = (int)self::pdo()->lastInsertId();

        self::log($ctx, 'quiz.answered', [
            'attempt_id' => $attemptId,
            'card_id' => $cardId,
            'deck_type' => $deck->type,
            'deck_id' => $deck->id,
            'result' => $result,
            'points' => $points,
        ]);

        return [
            'attempt_id' => $attemptId,
            'result' => $result,
            'points' => $points,
            'can_claim_correct' => $result === self::RESULT_INCORRECT,
            'front_text' => (string)$card['front_text'],
            'image_url' => ImageStorage::displayUrlForCard($card),
            'back_text' => (string)$card['back_text'],
            'answer_text' => $typed,
            'totals' => self::getQuizStatsForUser($ctx->id),
        ];
    }

    /**
     * "I was right anyway": partial credit on an attempt judged incorrect,
     * without rewriting what was typed or how it was judged. Only the
     * attempt's own user may claim it; claiming twice is harmless.
     */
    public static function markAttemptCorrectAnyway(UserContext $ctx, int $attemptId): array {
        $st = self::pdo()->prepare('SELECT * FROM quiz_attempts WHERE id = ? AND user_id = ? LIMIT 1');
        $st->execute([$attemptId, $ctx->id]);
        $attempt = $st->fetch();
        if (!$attempt) {
            throw new RuntimeException('That answer could not be found.');
        }
        if (!empty($attempt['was_overridden'])) {
            return ['ok' => true, 'points' => (int)$attempt['points_awarded'], 'totals' => self::getQuizStatsForUser($ctx->id)];
        }
        if ((string)$attempt['result'] !== self::RESULT_INCORRECT) {
            throw new RuntimeException('That answer already counted as correct.');
        }

        $st = self::pdo()->prepare('UPDATE quiz_attempts SET was_overridden = 1, points_awarded = ? WHERE id = ?');
        $st->execute([self::POINTS_OVERRIDE, $attemptId]);

        self::log($ctx, 'quiz.claimed_correct', [
            'attempt_id' => $attemptId,
            'card_id' => (int)$attempt['card_id'],
        ]);

        return ['ok' => true, 'points' => self::POINTS_OVERRIDE, 'totals' => self::getQuizStatsForUser($ctx->id)];
    }

    // ===== Stats =====

    /**
     * The quiz half of a user's progress: lifetime points, questions
     * answered, the verdict breakdown, how many were claimed right anyway,
     * the accuracy (correct + close + claimed, as a percentage of answered)
     * and today's count. A $deck scopes everything to that deck's cards.
     */
    public static function getQuizStatsForUser(int $userId, ?Deck $deck = null): array {
        $params = [$userId];
        $where = 'a.user_id = ?';
        if ($deck !== null) {
            [$scopeWhere, $scopeParams] = $deck->scopeSql('k');
            $where .= ' AND a.card_id IN (SELECT k.id FROM cards k WHERE ' . $scopeWhere . ')';
            foreach ($scopeParams as $p) {
                $params[] = $p;
            }
        }

        $st = self::pdo()->prepare(
            "SELECT COUNT(*) AS answered,
                    COALESCE(SUM(a.points_awarded), 0) AS points,
                    COALESCE(SUM(a.result = 'correct'), 0) AS correct,
                    COALESCE(SUM(a.result = 'close'), 0) AS close,
                    COALESCE(SUM(a.result = 'incorrect'), 0) AS incorrect,
                    COALESCE(SUM(a.was_overridden = 1), 0) AS overridden,
                    COALESCE(SUM(a.created_at >= CURDATE()), 0) AS answered_today
             FROM quiz_attempts a WHERE " . $where
        );
        $st->execute($params);
        $row = $st->fetch() ?: [];

        $answered = (int)($row['answered'] ?? 0);
        $landed = (int)($row['correct'] ?? 0) + (int)($row['close'] ?? 0) + (int)($row['overridden'] ?? 0);

        return [
            'points' => (int)($row['points'] ?? 0),
            'answered' => $answered,
            'correct' => (int)($row['correct'] ?? 0),
            'close' => (int)($row['close'] ?? 0),
            'incorrect' => (int)($row['incorrect'] ?? 0),
            'overridden' => (int)($row['overridden'] ?? 0),
            'accuracy_pct' => $answered > 0 ? (int)round(($landed / $answered) * 100) : 0,
            'answered_today' => (int)($row['answered_today'] ?? 0),
        ];
    }

    /**
     * The cards this user has missed most in quizzes — a miss is an answer
     * judged incorrect and not claimed right anyway. Rows are [card_id,
     * front_text, back_text, subcategory_name, quiz_misses], most-missed
     * first; cards never missed do not appear. A null $limit returns every
     * missed card; a $deck scopes to its cards.
     */
    public static function getMostMissedCardsForUser(int $userId, ?int $limit = 20, ?Deck $deck = null): array {
        $params = [$userId];
        $sql = "SELECT k.id AS card_id, k.front_text, k.back_text, s.name AS subcategory_name,
                       COUNT(*) AS quiz_misses
                FROM quiz_attempts a
                JOIN cards k ON k.id = a.card_id
                JOIN subcategories s ON s.id = k.subcategory_id
                WHERE a.user_id = ? AND a.result = 'incorrect' AND a.was_overridden = 0";
        if ($deck !== null) {
            [$scopeWhere, $scopeParams] = $deck->scopeSql('k');
            $sql .= ' AND ' . $scopeWhere;
            foreach ($scopeParams as $p) {
                $params[] = $p;
            }
        }
        $sql .= ' GROUP BY k.id, k.front_text, k.back_text, s.name
                  ORDER BY quiz_misses DESC, k.id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }

        $st = self::pdo()->prepare($sql);
        $st->execute($params);

        return array_map(fn($row) => [
            'card_id' => (int)$row['card_id'],
            'front_text' => (string)$row['front_text'],
            'back_text' => (string)$row['back_text'],
            'subcategory_name' => (string)$row['subcategory_name'],
            'quiz_misses' => (int)$row['quiz_misses'],
        ], $st->fetchAll());
    }
}
