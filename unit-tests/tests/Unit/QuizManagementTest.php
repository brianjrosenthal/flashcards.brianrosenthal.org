<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class QuizManagementTest extends TestCase
{
    private UserContext $charlie;
    private UserContext $lilly;
    private array $tree;
    private Deck $deck;

    protected function setUp(): void
    {
        test_reset_all();
        test_seed_admin();
        $this->charlie = test_seed_user('charlie@example.com', 'Charlie');
        $this->lilly = test_seed_user('lilly@example.com', 'Lilly');
        UserContext::set($this->charlie);
        $this->tree = test_seed_tree($this->charlie, 'charlie', 3);
        $this->deck = Deck::of(Deck::TYPE_SUBCATEGORY, $this->tree['subcategory_id']);
    }

    private function addCard(string $front, string $back): int
    {
        return CardManagement::create($this->charlie, $this->tree['subcategory_id'], ['front_text' => $front, 'back_text' => $back]);
    }

    /** Push a card's quiz history into the past so ordering is testable
     *  (DATETIME only resolves to the second, and a test answers far faster). */
    private function backdateAttemptsFor(int $cardId, int $daysAgo): void
    {
        $st = pdo()->prepare('UPDATE quiz_attempts SET created_at = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE card_id = ?');
        $st->execute([$daysAgo, $cardId]);
    }

    /** A flashcard mark / flag, written straight to the state table so this
     *  test does not depend on CardProgress' API. */
    private function setCardState(int $userId, int $cardId, ?string $lastMark, bool $flagged = false): void
    {
        $st = pdo()->prepare(
            'INSERT INTO user_card_state (user_id, card_id, is_flagged, last_mark) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_flagged = VALUES(is_flagged), last_mark = VALUES(last_mark)'
        );
        $st->execute([$userId, $cardId, $flagged ? 1 : 0, $lastMark]);
    }

    // --- normalizing and accepted answers ---

    public function testNormalizeAnswerStripsCasePunctuationAndArticles(): void
    {
        $this->assertSame('george washington', QuizManagement::normalizeAnswer('  George   Washington. '));
        $this->assertSame('mitochondria', QuizManagement::normalizeAnswer('The Mitochondria'));
        $this->assertSame('apple', QuizManagement::normalizeAnswer('an apple'));
        $this->assertSame('run', QuizManagement::normalizeAnswer('to run'));
        $this->assertSame("o'brien-smith", QuizManagement::normalizeAnswer("O'Brien-Smith!"));
        // A bare article is an answer in its own right, not something to strip.
        $this->assertSame('a', QuizManagement::normalizeAnswer('A'));
        $this->assertSame('', QuizManagement::normalizeAnswer(' ... '));
    }

    public function testAcceptedAnswersSplitAlternativesAndDropNotes(): void
    {
        $accepted = QuizManagement::acceptedAnswers('the mitochondria / powerhouse of the cell (informal)');
        $this->assertSame('mitochondria', $accepted[0], 'The first piece is the primary answer');
        $this->assertContains('powerhouse of the cell', $accepted);
        $this->assertContains('powerhouse of the cell informal', $accepted);

        $this->assertSame(['paris', 'lyon', 'nice'], QuizManagement::acceptedAnswers("Paris; Lyon\nNice"));
        $this->assertSame(['george washington', 'george washington 1st president'],
            QuizManagement::acceptedAnswers('George Washington (1st president)'));
        $this->assertSame([], QuizManagement::acceptedAnswers(''));
    }

    // --- judging answers ---

    public function testExactAnswerIsCorrectRegardlessOfCaseArticlesAndPunctuation(): void
    {
        foreach (['Washington', 'WASHINGTON', '  washington. ', 'the Washington'] as $typed) {
            $this->assertSame(QuizManagement::RESULT_CORRECT, QuizManagement::judgeAnswer($typed, 'Washington'), "Rejected '$typed'");
        }
    }

    public function testAnyAlternativeOnTheBackIsCorrect(): void
    {
        $back = 'the mitochondria / powerhouse of the cell (informal)';
        $this->assertSame(QuizManagement::RESULT_CORRECT, QuizManagement::judgeAnswer('Mitochondria', $back));
        $this->assertSame(QuizManagement::RESULT_CORRECT, QuizManagement::judgeAnswer('powerhouse of the cell', $back));
        $this->assertSame(QuizManagement::RESULT_CORRECT, QuizManagement::judgeAnswer('Powerhouse of the cell (informal)', $back));
        $this->assertSame(QuizManagement::RESULT_INCORRECT, QuizManagement::judgeAnswer('nucleus', $back));
    }

    public function testEditToleranceGrowsWithLength(): void
    {
        $this->assertSame(0, QuizManagement::allowedEdits('rome'));
        $this->assertSame(1, QuizManagement::allowedEdits('paris'));
        $this->assertSame(1, QuizManagement::allowedEdits('budapest'));
        $this->assertSame(2, QuizManagement::allowedEdits('amsterdam'));

        // Four letters: must be exact.
        $this->assertSame(QuizManagement::RESULT_INCORRECT, QuizManagement::judgeAnswer('roma', 'Rome'));
        // Five letters: one slip is close, two are not.
        $this->assertSame(QuizManagement::RESULT_CLOSE, QuizManagement::judgeAnswer('parid', 'Paris'));
        $this->assertSame(QuizManagement::RESULT_INCORRECT, QuizManagement::judgeAnswer('pared', 'Paris'));
        // Nine letters: two slips are still close.
        $this->assertSame(QuizManagement::RESULT_CLOSE, QuizManagement::judgeAnswer('amsterdum', 'Amsterdam'));
        $this->assertSame(QuizManagement::RESULT_CLOSE, QuizManagement::judgeAnswer('amstirdum', 'Amsterdam'));
        $this->assertSame(QuizManagement::RESULT_INCORRECT, QuizManagement::judgeAnswer('amstirdun', 'Amsterdam'));
    }

    public function testSwappedLettersCountAsOneEdit(): void
    {
        $this->assertSame(1, QuizManagement::editDistance('diegn', 'deign'));
        $this->assertSame(QuizManagement::RESULT_CLOSE, QuizManagement::judgeAnswer('Lincon', 'Lincoln'));
        $this->assertSame(QuizManagement::RESULT_CLOSE, QuizManagement::judgeAnswer('Linclon', 'Lincoln'));
    }

    public function testAccentsAreOneEditNotEqual(): void
    {
        $this->assertSame(1, QuizManagement::editDistance('café', 'cafe'));
        $this->assertSame(0, QuizManagement::editDistance('café', 'café'));
        $this->assertSame(2, QuizManagement::editDistance('', 'ab'));
        // Four letters leave no room for the missing accent...
        $this->assertSame(QuizManagement::RESULT_INCORRECT, QuizManagement::judgeAnswer('cafe', 'café'));
        // ...but a longer answer is close.
        $this->assertSame(QuizManagement::RESULT_CLOSE, QuizManagement::judgeAnswer('souffle', 'soufflé'));
        $this->assertSame(QuizManagement::RESULT_CORRECT, QuizManagement::judgeAnswer('Soufflé', 'soufflé'));
    }

    public function testEmptyOrUnrelatedAnswerIsIncorrect(): void
    {
        $this->assertSame(QuizManagement::RESULT_INCORRECT, QuizManagement::judgeAnswer('   ', 'Washington'));
        $this->assertSame(QuizManagement::RESULT_INCORRECT, QuizManagement::judgeAnswer('elephant', 'Washington'));
    }

    public function testPointsForResult(): void
    {
        $this->assertSame(10, QuizManagement::pointsForResult(QuizManagement::RESULT_CORRECT));
        $this->assertSame(8, QuizManagement::pointsForResult(QuizManagement::RESULT_CLOSE));
        $this->assertSame(0, QuizManagement::pointsForResult(QuizManagement::RESULT_INCORRECT));
    }

    // --- building rounds ---

    public function testRoundUsesEveryCardAndNeverLeaksTheBack(): void
    {
        $round = QuizManagement::buildQuizRound($this->charlie->id, $this->deck, QuizManagement::SOURCE_ALL, null);
        $this->assertCount(3, $round);
        $this->assertSame(3, QuizManagement::countAvailableQuestions($this->charlie->id, $this->deck, QuizManagement::SOURCE_ALL));
        $this->assertEqualsCanonicalizing($this->tree['card_ids'], array_column($round, 'card_id'));

        foreach ($round as $q) {
            $this->assertArrayNotHasKey('back_text', $q);
            $this->assertSame(['card_id', 'prompt_text', 'prompt_image_url', 'words', 'letters', 'first_letter'], array_keys($q));
            $this->assertStringStartsWith('Front ', $q['prompt_text']);
            $this->assertNull($q['prompt_image_url']);
            $this->assertSame(2, $q['words']);       // "back N"
            $this->assertSame(5, $q['letters']);     // b a c k + the digit
            $this->assertSame('B', $q['first_letter']);
        }
    }

    public function testRoundIsLimitedToTheRequestedCount(): void
    {
        $this->assertCount(2, QuizManagement::buildQuizRound($this->charlie->id, $this->deck, QuizManagement::SOURCE_ALL, 2));
        $this->assertCount(3, QuizManagement::buildQuizRound($this->charlie->id, $this->deck, QuizManagement::SOURCE_ALL, 50));
        $this->assertCount(3, QuizManagement::buildQuizRound($this->charlie->id, $this->deck));
    }

    public function testCategoryDeckSpansEverySubcategory(): void
    {
        $other = SubcategoryManagement::create($this->charlie, $this->tree['category_id'], ['name' => 'Wars']);
        CardManagement::create($this->charlie, $other, ['front_text' => 'Started 1861', 'back_text' => 'Civil War']);
        $category = Deck::of(Deck::TYPE_CATEGORY, $this->tree['category_id']);
        $this->assertSame(4, QuizManagement::countAvailableQuestions($this->charlie->id, $category, QuizManagement::SOURCE_ALL));
        $this->assertSame(3, QuizManagement::countAvailableQuestions($this->charlie->id, $this->deck, QuizManagement::SOURCE_ALL));
    }

    public function testQuestionHintsDescribeThePrimaryAnswer(): void
    {
        $id = $this->addCard('First president', 'George Washington (1st) / Washington');
        $round = QuizManagement::buildQuizRound($this->charlie->id, $this->deck, QuizManagement::SOURCE_ALL, null);
        $q = array_column($round, null, 'card_id')[$id];
        $this->assertSame(2, $q['words']);
        $this->assertSame(16, $q['letters']);
        $this->assertSame('G', $q['first_letter']);
    }

    public function testBuildRoundRejectsUnknownSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        QuizManagement::buildQuizRound($this->charlie->id, $this->deck, 'random');
    }

    public function testListAnswerTextsIsUniqueSortedPrimaryAnswers(): void
    {
        $this->addCard('Dup', 'back 1');
        $this->addCard('Notes', 'Zebra (striped) / horse');
        $this->assertSame(['back 1', 'back 2', 'back 3', 'zebra'], QuizManagement::listAnswerTexts($this->deck));
    }

    // --- least-recently-quizzed ordering ---

    public function testASecondRoundMovesOnToCardsTheFirstDidNotAsk(): void
    {
        [$a, $b, $c] = $this->tree['card_ids'];
        QuizManagement::recordAnswer($this->charlie, $a, 'Back 1');
        QuizManagement::recordAnswer($this->charlie, $b, 'Back 2');
        // Only the third card has never been quizzed, so it leads regardless of shuffling.
        $round = QuizManagement::buildQuizRound($this->charlie->id, $this->deck, QuizManagement::SOURCE_ALL, 1);
        $this->assertSame([$c], array_column($round, 'card_id'));
    }

    public function testOncePractisedEverythingComesBackRoundOldestFirst(): void
    {
        [$a, $b, $c] = $this->tree['card_ids'];
        foreach ([$a => 'Back 1', $b => 'Back 2', $c => 'Back 3'] as $id => $back) {
            QuizManagement::recordAnswer($this->charlie, $id, $back);
        }
        $this->backdateAttemptsFor($b, 9);
        $this->backdateAttemptsFor($a, 3);
        $round = QuizManagement::buildQuizRound($this->charlie->id, $this->deck, QuizManagement::SOURCE_ALL, 2);
        $this->assertEqualsCanonicalizing([$b, $a], array_column($round, 'card_id'));
    }

    public function testAnotherUsersPracticeDoesNotReorderMyRound(): void
    {
        [$a, $b, $c] = $this->tree['card_ids'];
        QuizManagement::recordAnswer($this->lilly, $a, 'Back 1');
        QuizManagement::recordAnswer($this->charlie, $b, 'Back 2');
        $round = QuizManagement::buildQuizRound($this->charlie->id, $this->deck, QuizManagement::SOURCE_ALL, 2);
        $this->assertEqualsCanonicalizing([$a, $c], array_column($round, 'card_id'));
    }

    // --- card pool: cards I miss ---

    public function testMissesPoolSpansMarksFlagsAndQuizMisses(): void
    {
        [$a, $b, $c] = $this->tree['card_ids'];
        $this->setCardState($this->charlie->id, $a, 'needs_review');
        QuizManagement::recordAnswer($this->charlie, $b, 'nope');
        QuizManagement::recordAnswer($this->charlie, $c, 'Back 3');

        $round = QuizManagement::buildQuizRound($this->charlie->id, $this->deck, QuizManagement::SOURCE_MISSES, null);
        $this->assertEqualsCanonicalizing([$a, $b], array_column($round, 'card_id'));
        $this->assertSame(2, QuizManagement::countAvailableQuestions($this->charlie->id, $this->deck, QuizManagement::SOURCE_MISSES));

        // Flagging pulls a card in even when it was never missed.
        $this->setCardState($this->charlie->id, $c, 'got_it', true);
        $this->assertSame(3, QuizManagement::countAvailableQuestions($this->charlie->id, $this->deck, QuizManagement::SOURCE_MISSES));

        // Getting a missed card right afterwards clears it from the pool...
        $this->setCardState($this->charlie->id, $c, 'got_it', false);
        QuizManagement::recordAnswer($this->charlie, $b, 'Back 2');
        $this->backdateAttemptsFor($b, 0);
        $st = pdo()->prepare("UPDATE quiz_attempts SET created_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE card_id = ? AND result = 'incorrect'");
        $st->execute([$b]);
        $this->assertSame([$a], array_column(QuizManagement::buildQuizRound($this->charlie->id, $this->deck, QuizManagement::SOURCE_MISSES, null), 'card_id'));

        // ...and so does claiming a miss as right anyway. (The earlier hit on
        // this card is pushed back a day: a miss only counts once it is more
        // recent than the last hit, and DATETIME resolves to the second.)
        $this->backdateAttemptsFor($c, 1);
        $miss = QuizManagement::recordAnswer($this->charlie, $c, 'wrong');
        $this->assertSame(2, QuizManagement::countAvailableQuestions($this->charlie->id, $this->deck, QuizManagement::SOURCE_MISSES));
        QuizManagement::markAttemptCorrectAnyway($this->charlie, $miss['attempt_id']);
        $this->assertSame(1, QuizManagement::countAvailableQuestions($this->charlie->id, $this->deck, QuizManagement::SOURCE_MISSES));
    }

    // --- recording answers ---

    public function testRecordAnswerJudgesScoresAndReturnsTheCard(): void
    {
        $id = $this->tree['card_ids'][0];
        $res = QuizManagement::recordAnswer($this->charlie, $id, ' back 1 ');
        $this->assertSame(QuizManagement::RESULT_CORRECT, $res['result']);
        $this->assertSame(10, $res['points']);
        $this->assertFalse($res['can_claim_correct']);
        $this->assertSame('Front 1', $res['front_text']);
        $this->assertSame('Back 1', $res['back_text']);
        $this->assertNull($res['image_url']);
        $this->assertSame('back 1', $res['answer_text']);
        $this->assertSame(10, $res['totals']['points']);
        $this->assertSame(1, $res['totals']['answered']);
        $this->assertSame(1, $res['totals']['correct']);

        $row = pdo()->query('SELECT * FROM quiz_attempts WHERE id = ' . (int)$res['attempt_id'])->fetch();
        $this->assertSame($this->charlie->id, (int)$row['user_id']);
        $this->assertSame('back 1', $row['answer_text']);
        $this->assertSame('correct', $row['result']);
        $this->assertSame(0, (int)$row['was_overridden']);

        $close = QuizManagement::recordAnswer($this->charlie, $id, 'back 1x');
        $this->assertSame(QuizManagement::RESULT_CLOSE, $close['result']);
        $this->assertSame(8, $close['points']);

        $miss = QuizManagement::recordAnswer($this->charlie, $id, 'nope');
        $this->assertSame(QuizManagement::RESULT_INCORRECT, $miss['result']);
        $this->assertSame(0, $miss['points']);
        $this->assertTrue($miss['can_claim_correct']);
        $this->assertSame(18, $miss['totals']['points']);

        $this->assertCount(3, ActivityLog::list(['action_type' => 'quiz.answered']));
    }

    public function testRecordAnswerRejectsUnknownCard(): void
    {
        $this->expectException(InvalidArgumentException::class);
        QuizManagement::recordAnswer($this->charlie, 9999, 'x');
    }

    public function testClaimCorrectAnywayAwardsPartialCreditOnce(): void
    {
        $id = $this->tree['card_ids'][0];
        $miss = QuizManagement::recordAnswer($this->charlie, $id, 'nope');

        $claimed = QuizManagement::markAttemptCorrectAnyway($this->charlie, $miss['attempt_id']);
        $this->assertTrue($claimed['ok']);
        $this->assertSame(5, $claimed['points']);
        $this->assertSame(5, $claimed['totals']['points']);
        $this->assertSame(1, $claimed['totals']['overridden']);

        $row = pdo()->query('SELECT * FROM quiz_attempts WHERE id = ' . (int)$miss['attempt_id'])->fetch();
        $this->assertSame('incorrect', $row['result'], 'the honest verdict stays');
        $this->assertSame('nope', $row['answer_text']);
        $this->assertSame(1, (int)$row['was_overridden']);
        $this->assertSame(5, (int)$row['points_awarded']);

        // Claiming again changes nothing.
        $again = QuizManagement::markAttemptCorrectAnyway($this->charlie, $miss['attempt_id']);
        $this->assertSame(5, $again['points']);
        $this->assertSame(5, $again['totals']['points']);
        $this->assertCount(1, ActivityLog::list(['action_type' => 'quiz.claimed_correct']));
    }

    public function testACorrectAnswerCannotBeClaimed(): void
    {
        $res = QuizManagement::recordAnswer($this->charlie, $this->tree['card_ids'][0], 'Back 1');
        $this->expectException(RuntimeException::class);
        QuizManagement::markAttemptCorrectAnyway($this->charlie, $res['attempt_id']);
    }

    public function testOnlyTheAttemptsOwnerMayClaimIt(): void
    {
        $miss = QuizManagement::recordAnswer($this->charlie, $this->tree['card_ids'][0], 'nope');
        $this->expectException(RuntimeException::class);
        QuizManagement::markAttemptCorrectAnyway($this->lilly, $miss['attempt_id']);
    }

    // --- who may answer ---

    public function testVisitorMayAnswerOnAPublicDeckButNotAPrivateOne(): void
    {
        $id = $this->tree['card_ids'][0];
        $res = QuizManagement::recordAnswer($this->lilly, $id, 'Back 1');
        $this->assertSame(QuizManagement::RESULT_CORRECT, $res['result']);
        $this->assertSame(1, QuizManagement::getQuizStatsForUser($this->lilly->id)['answered']);
        $this->assertSame(0, QuizManagement::getQuizStatsForUser($this->charlie->id)['answered']);

        CategoryManagement::update($this->charlie, $this->tree['category_id'], ['is_public' => 0]);
        // The owner still can...
        QuizManagement::recordAnswer($this->charlie, $id, 'Back 1');
        // ...the visitor cannot.
        $this->expectException(RuntimeException::class);
        QuizManagement::recordAnswer($this->lilly, $id, 'Back 1');
    }

    // --- stats ---

    public function testQuizStatsCountEveryKindOfAnswer(): void
    {
        [$a, $b, $c] = $this->tree['card_ids'];
        QuizManagement::recordAnswer($this->charlie, $a, 'Back 1');            // correct, 10
        QuizManagement::recordAnswer($this->charlie, $b, 'back 2x');           // close, 8
        $miss = QuizManagement::recordAnswer($this->charlie, $c, 'nope');      // incorrect, 0
        QuizManagement::recordAnswer($this->charlie, $c, 'still wrong');       // incorrect, 0
        QuizManagement::markAttemptCorrectAnyway($this->charlie, $miss['attempt_id']);  // +5
        QuizManagement::recordAnswer($this->lilly, $a, 'Back 1');              // somebody else's

        $stats = QuizManagement::getQuizStatsForUser($this->charlie->id);
        $this->assertSame([
            'points' => 23,
            'answered' => 4,
            'correct' => 1,
            'close' => 1,
            'incorrect' => 2,
            'overridden' => 1,
            'accuracy_pct' => 75,
            'answered_today' => 4,
        ], $stats);

        $this->backdateAttemptsFor($a, 2);
        $this->assertSame(3, QuizManagement::getQuizStatsForUser($this->charlie->id)['answered_today']);

        $empty = QuizManagement::getQuizStatsForUser(9999);
        $this->assertSame(0, $empty['answered']);
        $this->assertSame(0, $empty['accuracy_pct']);
    }

    public function testQuizStatsCanBeScopedToADeck(): void
    {
        $other = SubcategoryManagement::create($this->charlie, $this->tree['category_id'], ['name' => 'Wars']);
        $warId = CardManagement::create($this->charlie, $other, ['front_text' => 'Started 1861', 'back_text' => 'Civil War']);
        QuizManagement::recordAnswer($this->charlie, $this->tree['card_ids'][0], 'Back 1');
        QuizManagement::recordAnswer($this->charlie, $warId, 'Civil War');

        $this->assertSame(1, QuizManagement::getQuizStatsForUser($this->charlie->id, $this->deck)['answered']);
        $this->assertSame(1, QuizManagement::getQuizStatsForUser($this->charlie->id, Deck::of(Deck::TYPE_SUBCATEGORY, $other))['answered']);
        $this->assertSame(2, QuizManagement::getQuizStatsForUser($this->charlie->id, Deck::of(Deck::TYPE_CATEGORY, $this->tree['category_id']))['answered']);
    }

    public function testMostMissedCardsRankUnclaimedMisses(): void
    {
        [$a, $b, $c] = $this->tree['card_ids'];
        QuizManagement::recordAnswer($this->charlie, $a, 'wrong');
        QuizManagement::recordAnswer($this->charlie, $a, 'wrong again');
        QuizManagement::recordAnswer($this->charlie, $a, 'Back 1');            // a later hit keeps the misses counted
        $claimed = QuizManagement::recordAnswer($this->charlie, $b, 'wrong');
        QuizManagement::markAttemptCorrectAnyway($this->charlie, $claimed['attempt_id']);
        QuizManagement::recordAnswer($this->charlie, $b, 'wrong twice');
        QuizManagement::recordAnswer($this->charlie, $c, 'Back 3');
        QuizManagement::recordAnswer($this->lilly, $c, 'wrong');               // somebody else's miss

        $rows = QuizManagement::getMostMissedCardsForUser($this->charlie->id);
        $this->assertSame([
            ['card_id' => $a, 'front_text' => 'Front 1', 'back_text' => 'Back 1', 'subcategory_name' => 'Presidents', 'quiz_misses' => 2],
            ['card_id' => $b, 'front_text' => 'Front 2', 'back_text' => 'Back 2', 'subcategory_name' => 'Presidents', 'quiz_misses' => 1],
        ], $rows);

        $this->assertCount(1, QuizManagement::getMostMissedCardsForUser($this->charlie->id, 1));
        $this->assertCount(2, QuizManagement::getMostMissedCardsForUser($this->charlie->id, null));
        $this->assertCount(2, QuizManagement::getMostMissedCardsForUser($this->charlie->id, 20, $this->deck));

        $other = SubcategoryManagement::create($this->charlie, $this->tree['category_id'], ['name' => 'Wars']);
        $this->assertSame([], QuizManagement::getMostMissedCardsForUser($this->charlie->id, 20, Deck::of(Deck::TYPE_SUBCATEGORY, $other)));
        $this->assertSame([], QuizManagement::getMostMissedCardsForUser(9999));
    }

    public function testListItemsSplitOnCommasAndAnd(): void
    {
        $this->assertSame(['lennon', 'mccartney', 'harrison'], QuizManagement::listItems('Lennon, McCartney and Harrison'));
        $this->assertSame(['lennon', 'mccartney', 'harrison'], QuizManagement::listItems('Lennon, McCartney, and Harrison'));
        $this->assertSame(['lennon', 'mccartney'], QuizManagement::listItems('Lennon & McCartney'));
        $this->assertSame(['beatles', 'stones'], QuizManagement::listItems('the Beatles and the Stones'), 'articles go per item');
        $this->assertSame(['sandwich'], QuizManagement::listItems('sandwich'), '"and" inside a word is not a separator');
        $this->assertSame(['paris'], QuizManagement::listItems('Paris'));
    }

    public function testListAnswersMatchInAnyOrderWithCommasOrAnd(): void
    {
        $back = 'Lennon, McCartney, Harrison and Starr';
        $this->assertSame('correct', QuizManagement::judgeAnswer('Lennon, McCartney, Harrison and Starr', $back));
        $this->assertSame('correct', QuizManagement::judgeAnswer('Starr and Harrison and McCartney and Lennon', $back));
        $this->assertSame('correct', QuizManagement::judgeAnswer('harrison, starr, lennon, mccartney', $back));
        $this->assertSame('correct', QuizManagement::judgeAnswer('McCartney & Starr & Lennon & Harrison', $back));
        $this->assertSame('close', QuizManagement::judgeAnswer('Starr, Harrison, McCartny, Lennon', $back), 'one typo in one item');
        $this->assertSame('incorrect', QuizManagement::judgeAnswer('Lennon, McCartney, Harrison', $back), 'a missing item');
        $this->assertSame('incorrect', QuizManagement::judgeAnswer('Lennon, McCartney, Harrison, Starr, Best', $back), 'an extra item');
        $this->assertSame('incorrect', QuizManagement::judgeAnswer('Lennon, Lennon, Harrison, Starr', $back), 'each item pairs off once');

        // Works with the other accepted-answer separators too.
        $this->assertSame('correct', QuizManagement::judgeAnswer('white and red and blue', 'red, white and blue / the tricolour'));
        $this->assertSame('correct', QuizManagement::judgeAnswer('tricolour', 'red, white and blue / the tricolour'));
        // Non-list backs are unchanged: "Trinidad and Tobago" is still one answer.
        $this->assertSame('correct', QuizManagement::judgeAnswer('Trinidad and Tobago', 'Trinidad and Tobago'));
        $this->assertSame('incorrect', QuizManagement::judgeAnswer('Paris, Rome', 'Paris'));
    }

    public function testBackToFrontAsksTheBackAndJudgesTheFront(): void
    {
        [$a] = $this->tree['card_ids'];
        $imageOnly = CardManagement::create($this->charlie, $this->tree['subcategory_id'], ['front_text' => '', 'back_text' => 'Only a picture'], [
            'body' => 'x', 'thumb_body' => 'y', 'content_type' => 'image/png', 'ext' => 'png', 'width' => 10, 'height' => 10, 'size' => 1,
        ]);

        $back = QuizManagement::DIRECTION_BACK_TO_FRONT;
        $this->assertSame(4, QuizManagement::countAvailableQuestions($this->charlie->id, $this->deck, QuizManagement::SOURCE_ALL));
        $this->assertSame(3, QuizManagement::countAvailableQuestions($this->charlie->id, $this->deck, QuizManagement::SOURCE_ALL, $back), 'image-only fronts cannot be typed');

        $round = QuizManagement::buildQuizRound($this->charlie->id, $this->deck, QuizManagement::SOURCE_ALL, null, $back);
        $this->assertCount(3, $round);
        foreach ($round as $q) {
            $this->assertStringStartsWith('Back ', $q['prompt_text'], 'the back is the prompt');
            $this->assertNull($q['prompt_image_url'], 'the front image is the answer side, never shown with the prompt');
            $this->assertArrayNotHasKey('front_text', $q);
            $this->assertSame('F', $q['first_letter'], 'hints describe the front');
        }
        $this->assertNotContains($imageOnly, array_column($round, 'card_id'));
        $this->assertSame(['front 1', 'front 2', 'front 3'], QuizManagement::listAnswerTexts($this->deck, $back));

        $res = QuizManagement::recordAnswer($this->charlie, $a, 'front 1', $back);
        $this->assertSame('correct', $res['result']);
        $this->assertSame('back', $res['direction']);
        $this->assertSame('Front 1', $res['front_text']);
        $this->assertSame('Back 1', $res['back_text']);
        $st = pdo()->prepare('SELECT direction FROM quiz_attempts WHERE id = ?');
        $st->execute([$res['attempt_id']]);
        $this->assertSame('back', $st->fetchColumn());

        $this->assertSame('incorrect', QuizManagement::recordAnswer($this->charlie, $a, 'Back 1', $back)['result'], 'the back is not the answer that way round');
        $this->assertSame('correct', QuizManagement::recordAnswer($this->charlie, $a, 'Back 1')['result'], 'front-to-back is unchanged');

        $this->expectException(InvalidArgumentException::class);
        QuizManagement::recordAnswer($this->charlie, $imageOnly, 'anything', $back);
    }
}
