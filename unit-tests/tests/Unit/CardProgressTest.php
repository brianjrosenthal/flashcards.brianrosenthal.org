<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CardProgressTest extends TestCase
{
    private UserContext $admin;
    private UserContext $charlie;
    /** @var array{site_id:int,category_id:int,subcategory_id:int,card_ids:int[]} */
    private array $tree;
    private Deck $subDeck;
    private Deck $catDeck;

    protected function setUp(): void
    {
        test_reset_all();
        $this->admin = test_seed_admin();
        $this->charlie = test_seed_user('charlie@example.com', 'Charlie');
        $this->tree = test_seed_tree($this->charlie, 'charlie', 3);
        $this->subDeck = Deck::of(Deck::TYPE_SUBCATEGORY, $this->tree['subcategory_id']);
        $this->catDeck = Deck::of(Deck::TYPE_CATEGORY, $this->tree['category_id']);
    }

    private function stateRow(int $userId, int $cardId): array
    {
        $st = pdo()->prepare('SELECT * FROM user_card_state WHERE user_id = ? AND card_id = ?');
        $st->execute([$userId, $cardId]);
        return $st->fetch() ?: [];
    }

    private function rowCount(string $table): int
    {
        return (int)pdo()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    // --- deck cards ---

    public function testDeckCardsFollowTreeOrderAndCarryState(): void
    {
        $cards = CardProgress::getDeckCards($this->charlie->id, $this->subDeck);
        $this->assertSame(['Front 1', 'Front 2', 'Front 3'], array_column($cards, 'front_text'));
        $this->assertSame(['Back 1', 'Back 2', 'Back 3'], array_column($cards, 'back_text'));
        $this->assertSame('Presidents', $cards[0]['subcategory_name']);
        $this->assertNull($cards[0]['image_url']);
        $this->assertFalse($cards[0]['flagged']);
        $this->assertFalse($cards[0]['marked']);
        $this->assertNull($cards[0]['last_mark']);

        // A category deck deals every subcategory, in subcategory then card order.
        $sub2 = SubcategoryManagement::create($this->charlie, $this->tree['category_id'], ['name' => 'Wars']);
        $extra = CardManagement::create($this->charlie, $sub2, ['front_text' => 'Front W', 'back_text' => 'Back W']);
        $catCards = CardProgress::getDeckCards($this->charlie->id, $this->catDeck);
        $this->assertSame(array_merge($this->tree['card_ids'], [$extra]), array_column($catCards, 'id'));
        $this->assertSame('Wars', end($catCards)['subcategory_name']);

        // An anonymous visitor (viewer 0) gets the same cards with no state.
        $anon = CardProgress::getDeckCards(0, $this->subDeck);
        $this->assertCount(3, $anon);
    }

    public function testDeckFilters(): void
    {
        [$a, $b, $c] = $this->tree['card_ids'];
        CardProgress::markCard($this->charlie, $a, CardProgress::MARK_NEEDS_REVIEW);
        CardProgress::markCard($this->charlie, $b, CardProgress::MARK_GOT_IT);
        CardProgress::setCardFlag($this->charlie, $c, true);

        $misses = CardProgress::getDeckCards($this->charlie->id, $this->subDeck, CardProgress::FILTER_NEEDS_REVIEW);
        $this->assertSame([$a], array_column($misses, 'id'));
        $this->assertTrue($misses[0]['marked']);
        $this->assertSame('needs_review', $misses[0]['last_mark']);

        $flagged = CardProgress::getDeckCards($this->charlie->id, $this->subDeck, CardProgress::FILTER_FLAGGED);
        $this->assertSame([$c], array_column($flagged, 'id'));
        $this->assertTrue($flagged[0]['flagged']);

        // Another viewer's state does not leak.
        $this->assertCount(0, CardProgress::getDeckCards($this->admin->id, $this->subDeck, CardProgress::FILTER_FLAGGED));

        $this->expectException(InvalidArgumentException::class);
        CardProgress::getDeckCards($this->charlie->id, $this->subDeck, 'bogus');
    }

    // --- marks ---

    public function testMarkCardUpsertsStateAppendsEventsAndReturnsScore(): void
    {
        $cardId = $this->tree['card_ids'][0];
        $score = CardProgress::markCard($this->charlie, $cardId, CardProgress::MARK_GOT_IT);

        $state = $this->stateRow($this->charlie->id, $cardId);
        $this->assertSame('got_it', $state['last_mark']);
        $this->assertSame(1, (int)$state['got_it_count']);
        $this->assertSame(0, (int)$state['needs_review_count']);
        $this->assertNotNull($state['last_reviewed_at']);
        $this->assertSame(1, $this->rowCount('card_review_events'));
        $this->assertSame(['mastered' => 1, 'total_cards' => 3, 'reviewed_today' => 1], $score);

        CardProgress::markCard($this->charlie, $cardId, CardProgress::MARK_NEEDS_REVIEW);
        $score = CardProgress::markCard($this->charlie, $cardId, CardProgress::MARK_NEEDS_REVIEW);
        $state = $this->stateRow($this->charlie->id, $cardId);
        $this->assertSame('needs_review', $state['last_mark']);
        $this->assertSame(1, (int)$state['got_it_count']);
        $this->assertSame(2, (int)$state['needs_review_count']);
        $this->assertSame(1, $this->rowCount('user_card_state'));
        $this->assertSame(3, $this->rowCount('card_review_events'));
        $this->assertSame(0, $score['mastered']);
        $this->assertSame(3, $score['reviewed_today']);

        $types = array_column(ActivityLog::list([], 10), 'action_type');
        $this->assertContains('card.marked', $types);
    }

    public function testMarkCardRejectsUnknownMarkAndUnknownCard(): void
    {
        try {
            CardProgress::markCard($this->charlie, $this->tree['card_ids'][0], 'maybe');
            $this->fail('unknown mark must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('maybe', $e->getMessage());
        }
        $this->expectException(RuntimeException::class);
        CardProgress::markCard($this->charlie, 999999, CardProgress::MARK_GOT_IT);
    }

    public function testMarkCardSavesPositionInTheGivenDeck(): void
    {
        $cardId = $this->tree['card_ids'][1];
        CardProgress::markCard($this->charlie, $cardId, CardProgress::MARK_GOT_IT, 2, $this->catDeck);
        $this->assertSame(2, CardProgress::deckPositionFor($this->charlie->id, $this->catDeck));
        $this->assertSame(0, CardProgress::deckPositionFor($this->charlie->id, $this->subDeck));

        // Without a deck the card's own subcategory deck remembers the place.
        CardProgress::markCard($this->charlie, $cardId, CardProgress::MARK_GOT_IT, 1);
        $this->assertSame(1, CardProgress::deckPositionFor($this->charlie->id, $this->subDeck));
        $this->assertSame(2, CardProgress::deckPositionFor($this->charlie->id, $this->catDeck));
    }

    public function testVisitorOfAPublicDeckMayMarkAndFlag(): void
    {
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        $cardId = $this->tree['card_ids'][0];

        $score = CardProgress::markCard($lilly, $cardId, CardProgress::MARK_GOT_IT);
        $this->assertSame('got_it', $this->stateRow($lilly->id, $cardId)['last_mark']);
        $this->assertTrue(CardProgress::setCardFlag($lilly, $cardId, true));
        // Lilly owns nothing, so her total is just the cards she has touched.
        $this->assertSame(['mastered' => 1, 'total_cards' => 1, 'reviewed_today' => 1], $score);
        // The owner's own progress is untouched.
        $this->assertSame([], $this->stateRow($this->charlie->id, $cardId));
    }

    public function testVisitorOfAPrivateDeckCannotMarkOrFlag(): void
    {
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        $cardId = $this->tree['card_ids'][0];
        CategoryManagement::update($this->charlie, $this->tree['category_id'], ['is_public' => 0]);

        try {
            CardProgress::markCard($lilly, $cardId, CardProgress::MARK_GOT_IT);
            $this->fail('a private deck must not accept marks from a visitor');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('private', $e->getMessage());
        }
        try {
            CardProgress::setCardFlag($lilly, $cardId, true);
            $this->fail('a private deck must not accept flags from a visitor');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('private', $e->getMessage());
        }
        $this->assertSame(0, $this->rowCount('user_card_state'));
        $this->assertSame(0, $this->rowCount('card_review_events'));

        // The owner and an admin still can.
        CardProgress::markCard($this->charlie, $cardId, CardProgress::MARK_GOT_IT);
        CardProgress::markCard($this->admin, $cardId, CardProgress::MARK_GOT_IT);
        $this->assertSame(2, $this->rowCount('user_card_state'));
    }

    // --- flags ---

    public function testSetCardFlagUpsertsAndToggles(): void
    {
        $cardId = $this->tree['card_ids'][1];
        $this->assertTrue(CardProgress::setCardFlag($this->charlie, $cardId, true));
        $this->assertSame(1, (int)$this->stateRow($this->charlie->id, $cardId)['is_flagged']);
        $this->assertNull($this->stateRow($this->charlie->id, $cardId)['last_mark']);

        $this->assertFalse(CardProgress::setCardFlag($this->charlie, $cardId, false));
        $this->assertSame(0, (int)$this->stateRow($this->charlie->id, $cardId)['is_flagged']);
        $this->assertSame(1, $this->rowCount('user_card_state'));

        // Flagging never disturbs an existing mark.
        CardProgress::markCard($this->charlie, $cardId, CardProgress::MARK_GOT_IT);
        CardProgress::setCardFlag($this->charlie, $cardId, true);
        $state = $this->stateRow($this->charlie->id, $cardId);
        $this->assertSame('got_it', $state['last_mark']);
        $this->assertSame(1, (int)$state['is_flagged']);

        $types = array_column(ActivityLog::list([], 10), 'action_type');
        $this->assertContains('card.flag_toggled', $types);
    }

    // --- order ---

    public function testShuffleIsDeterministicAndRestoreReturnsTreeOrder(): void
    {
        // With only 3 cards two seeds can collide; use enough cards to make a
        // collision across every attempt effectively impossible.
        for ($i = 4; $i <= 15; $i++) {
            CardManagement::create($this->charlie, $this->tree['subcategory_id'], ['front_text' => 'Front ' . $i, 'back_text' => 'Back ' . $i]);
        }
        $original = array_column(CardProgress::getDeckCards($this->charlie->id, $this->subDeck), 'id');
        $this->assertFalse(CardProgress::isDeckShuffled($this->charlie->id, $this->subDeck));

        $seed = CardProgress::shuffleDeck($this->charlie, $this->subDeck);
        $this->assertGreaterThan(0, $seed);
        $this->assertTrue(CardProgress::isDeckShuffled($this->charlie->id, $this->subDeck));

        $first = array_column(CardProgress::getDeckCards($this->charlie->id, $this->subDeck), 'id');
        $second = array_column(CardProgress::getDeckCards($this->charlie->id, $this->subDeck), 'id');
        $this->assertSame($first, $second, 'the same seed must deal the same order');
        $this->assertEqualsCanonicalizing($original, $first);

        $differs = $first !== $original;
        for ($attempt = 0; $attempt < 5 && !$differs; $attempt++) {
            CardProgress::shuffleDeck($this->charlie, $this->subDeck);
            $differs = array_column(CardProgress::getDeckCards($this->charlie->id, $this->subDeck), 'id') !== $original;
        }
        $this->assertTrue($differs, 'Shuffling never changed the order');

        // The shuffle is per viewer and per deck.
        $this->assertSame($original, array_column(CardProgress::getDeckCards($this->admin->id, $this->subDeck), 'id'));
        $this->assertFalse(CardProgress::isDeckShuffled($this->charlie->id, $this->catDeck));

        CardProgress::restoreOriginalOrder($this->charlie, $this->subDeck);
        $this->assertFalse(CardProgress::isDeckShuffled($this->charlie->id, $this->subDeck));
        $this->assertSame($original, array_column(CardProgress::getDeckCards($this->charlie->id, $this->subDeck), 'id'));

        $types = array_column(ActivityLog::list([], 20), 'action_type');
        $this->assertContains('deck.shuffled', $types);
        $this->assertContains('deck.order_restored', $types);
    }

    public function testPositionsAreKeptPerDeckAndResetByShuffleOrRestore(): void
    {
        CardProgress::saveDeckPosition($this->charlie, $this->subDeck, 3);
        CardProgress::saveDeckPosition($this->charlie, $this->subDeck, 9);
        CardProgress::saveDeckPosition($this->charlie, $this->catDeck, 4);
        $this->assertSame(9, CardProgress::deckPositionFor($this->charlie->id, $this->subDeck));
        $this->assertSame(4, CardProgress::deckPositionFor($this->charlie->id, $this->catDeck));
        $this->assertSame(0, CardProgress::deckPositionFor($this->admin->id, $this->subDeck));

        CardProgress::shuffleDeck($this->charlie, $this->subDeck);
        $this->assertSame(0, CardProgress::deckPositionFor($this->charlie->id, $this->subDeck));
        $this->assertSame(4, CardProgress::deckPositionFor($this->charlie->id, $this->catDeck), 'other decks keep their place');

        // Saving a position keeps the shuffle; restoring order resets the place.
        CardProgress::saveDeckPosition($this->charlie, $this->subDeck, 5);
        $this->assertTrue(CardProgress::isDeckShuffled($this->charlie->id, $this->subDeck));
        CardProgress::restoreOriginalOrder($this->charlie, $this->subDeck);
        $this->assertSame(0, CardProgress::deckPositionFor($this->charlie->id, $this->subDeck));

        // Negative positions clamp to the start.
        CardProgress::saveDeckPosition($this->charlie, $this->subDeck, -2);
        $this->assertSame(0, CardProgress::deckPositionFor($this->charlie->id, $this->subDeck));
    }

    // --- aggregates ---

    public function testScoreSummaryCountsOwnedAndStudiedCards(): void
    {
        [$a, $b] = $this->tree['card_ids'];
        $this->assertSame(['mastered' => 0, 'total_cards' => 3, 'reviewed_today' => 0], CardProgress::getScoreSummary($this->charlie->id));

        CardProgress::markCard($this->charlie, $a, CardProgress::MARK_GOT_IT);
        CardProgress::markCard($this->charlie, $b, CardProgress::MARK_NEEDS_REVIEW);
        $this->assertSame(['mastered' => 1, 'total_cards' => 3, 'reviewed_today' => 2], CardProgress::getScoreSummary($this->charlie->id));

        // Scoped to a deck: the deck's card count, and only its marks/events.
        $sub2 = SubcategoryManagement::create($this->charlie, $this->tree['category_id'], ['name' => 'Wars']);
        $extra = CardManagement::create($this->charlie, $sub2, ['front_text' => 'Front W', 'back_text' => 'Back W']);
        CardProgress::markCard($this->charlie, $extra, CardProgress::MARK_GOT_IT);
        $this->assertSame(['mastered' => 1, 'total_cards' => 3, 'reviewed_today' => 2], CardProgress::getScoreSummary($this->charlie->id, $this->subDeck));
        $this->assertSame(['mastered' => 2, 'total_cards' => 4, 'reviewed_today' => 3], CardProgress::getScoreSummary($this->charlie->id, $this->catDeck));
        $this->assertSame(['mastered' => 2, 'total_cards' => 4, 'reviewed_today' => 3], CardProgress::getScoreSummary($this->charlie->id));

        // A visitor who studied one of Charlie's cards plus their own deck.
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        $lillyTree = test_seed_tree($lilly, 'lilly', 2);
        CardProgress::markCard($lilly, $a, CardProgress::MARK_GOT_IT);
        $this->assertSame(['mastered' => 1, 'total_cards' => 3, 'reviewed_today' => 1], CardProgress::getScoreSummary($lilly->id));
        CardProgress::markCard($lilly, $lillyTree['card_ids'][0], CardProgress::MARK_GOT_IT);
        $this->assertSame(['mastered' => 2, 'total_cards' => 3, 'reviewed_today' => 2], CardProgress::getScoreSummary($lilly->id), 'owned cards are not double counted');
    }

    public function testDeckSummariesForUser(): void
    {
        [$a, $b] = $this->tree['card_ids'];
        $emptyCat = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Empty']);
        $sub2 = SubcategoryManagement::create($this->charlie, $this->tree['category_id'], ['name' => 'Wars']);
        CardProgress::markCard($this->charlie, $a, CardProgress::MARK_GOT_IT);
        CardProgress::markCard($this->charlie, $b, CardProgress::MARK_NEEDS_REVIEW);

        $s = CardProgress::deckSummariesForUser($this->charlie->id, $this->charlie->id);
        $this->assertSame(['total' => 3, 'learned' => 1], $s['subcategory'][$this->tree['subcategory_id']]);
        $this->assertSame(['total' => 0, 'learned' => 0], $s['subcategory'][$sub2]);
        $this->assertSame(['total' => 3, 'learned' => 1], $s['category'][$this->tree['category_id']]);
        $this->assertSame(['total' => 0, 'learned' => 0], $s['category'][$emptyCat]);

        // Another viewer of the same tree sees their own (empty) progress.
        $other = CardProgress::deckSummariesForUser($this->admin->id, $this->charlie->id);
        $this->assertSame(['total' => 3, 'learned' => 0], $other['category'][$this->tree['category_id']]);
    }

    public function testListDecksForUserIncludesOwnAndStudiedDecks(): void
    {
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        $lillyTree = test_seed_tree($lilly, 'lilly', 2);
        CardProgress::markCard($lilly, $lillyTree['card_ids'][0], CardProgress::MARK_GOT_IT);

        // Her own deck first, then Charlie's public one (never studied yet).
        $decks = CardProgress::listDecksForUser($lilly->id);
        $this->assertCount(2, $decks);
        $this->assertTrue($decks[0]['is_mine']);
        $this->assertSame('Lilly', $decks[0]['owner_first_name']);
        $this->assertSame(2, $decks[0]['category']['card_count']);
        $this->assertSame(1, $decks[0]['category']['learned']);
        $this->assertSame(1, $decks[0]['subcategories'][0]['learned']);
        $this->assertFalse($decks[1]['is_mine']);

        // Studying one of Charlie's cards keeps his category listed, labelled with his name.
        CardProgress::markCard($lilly, $this->tree['card_ids'][0], CardProgress::MARK_NEEDS_REVIEW);
        $decks = CardProgress::listDecksForUser($lilly->id);
        $this->assertCount(2, $decks);
        $this->assertTrue($decks[0]['is_mine']);
        $this->assertFalse($decks[1]['is_mine']);
        $this->assertSame('Charlie', $decks[1]['owner_first_name']);
        $this->assertSame('US History', $decks[1]['category']['name']);
        $this->assertSame(3, $decks[1]['category']['card_count']);
        $this->assertSame(0, $decks[1]['category']['learned']);
        $this->assertSame('Presidents', $decks[1]['subcategories'][0]['name']);

        // Charlie sees his own deck and Lilly's public one; her studying
        // changes nothing of his.
        $charlies = CardProgress::listDecksForUser($this->charlie->id);
        $this->assertCount(2, $charlies);
        $this->assertTrue($charlies[0]['is_mine']);
        $this->assertSame(0, $charlies[0]['category']['learned']);
    }

    public function testForgetDeckDropsEveryViewersPosition(): void
    {
        CardProgress::shuffleDeck($this->charlie, $this->subDeck);
        CardProgress::saveDeckPosition($this->admin, $this->subDeck, 2);
        CardProgress::saveDeckPosition($this->charlie, $this->catDeck, 1);
        $this->assertSame(3, $this->rowCount('user_deck_positions'));

        CardProgress::forgetDeck(Deck::TYPE_SUBCATEGORY, $this->tree['subcategory_id']);
        $this->assertSame(1, $this->rowCount('user_deck_positions'));
        $this->assertFalse(CardProgress::isDeckShuffled($this->charlie->id, $this->subDeck));
        $this->assertSame(1, CardProgress::deckPositionFor($this->charlie->id, $this->catDeck));
    }

    public function testGetStatsForUser(): void
    {
        [$a, $b, $c] = $this->tree['card_ids'];
        CardProgress::markCard($this->charlie, $a, CardProgress::MARK_GOT_IT);
        CardProgress::markCard($this->charlie, $b, CardProgress::MARK_NEEDS_REVIEW);
        CardProgress::setCardFlag($this->charlie, $c, true);

        $stats = CardProgress::getStatsForUser($this->charlie->id, null, 14);
        $this->assertSame(1, $stats['got_it']);
        $this->assertSame(3, $stats['total_cards']);
        $this->assertSame(1, $stats['needs_review']);
        $this->assertSame(1, $stats['flagged']);
        $this->assertSame(2, $stats['total_reviews']);
        $this->assertSame(2, $stats['reviewed_today']);
        $this->assertCount(14, $stats['daily']);
        $this->assertSame(2, end($stats['daily'])['count'], 'today is the last bucket');
        $this->assertSame(date('Y-m-d'), end($stats['daily'])['date']);
        $this->assertCount(7, CardProgress::getStatsForUser($this->charlie->id, null, 7)['daily']);

        // Scoped to another deck of the same owner: nothing there yet.
        $sub2 = SubcategoryManagement::create($this->charlie, $this->tree['category_id'], ['name' => 'Wars']);
        $scoped = CardProgress::getStatsForUser($this->charlie->id, Deck::of(Deck::TYPE_SUBCATEGORY, $sub2));
        $this->assertSame(0, $scoped['got_it']);
        $this->assertSame(0, $scoped['total_cards']);
        $this->assertSame(0, $scoped['total_reviews']);
        $this->assertSame(0, end($scoped['daily'])['count']);
        $this->assertSame(1, CardProgress::getStatsForUser($this->charlie->id, $this->subDeck)['flagged']);
    }

    public function testMostMissedCards(): void
    {
        [$a, $b, $c] = $this->tree['card_ids'];
        CardProgress::markCard($this->charlie, $a, CardProgress::MARK_NEEDS_REVIEW);
        CardProgress::markCard($this->charlie, $a, CardProgress::MARK_NEEDS_REVIEW);
        CardProgress::markCard($this->charlie, $a, CardProgress::MARK_GOT_IT);   // misses stay counted
        CardProgress::markCard($this->charlie, $b, CardProgress::MARK_NEEDS_REVIEW);
        CardProgress::markCard($this->charlie, $c, CardProgress::MARK_GOT_IT);

        $rows = CardProgress::getMostMissedCards($this->charlie->id);
        $this->assertSame([$a, $b], array_column($rows, 'card_id'));
        $this->assertSame([2, 1], array_column($rows, 'misses'));
        $this->assertSame('Front 1', $rows[0]['front_text']);
        $this->assertSame('Back 1', $rows[0]['back_text']);
        $this->assertSame('Presidents', $rows[0]['subcategory_name']);

        $this->assertCount(1, CardProgress::getMostMissedCards($this->charlie->id, 1));
        $this->assertCount(2, CardProgress::getMostMissedCards($this->charlie->id, null, $this->catDeck));
        $this->assertCount(0, CardProgress::getMostMissedCards($this->admin->id));
    }

    public function testIsCardFlaggedReadsTheViewersOwnFlag(): void {
        $cardId = $this->tree['card_ids'][0];
        $this->assertFalse(CardProgress::isCardFlagged($this->charlie->id, $cardId));
        CardProgress::setCardFlag($this->charlie, $cardId, true);
        $this->assertTrue(CardProgress::isCardFlagged($this->charlie->id, $cardId));
        $this->assertFalse(CardProgress::isCardFlagged($this->charlie->id + 1000, $cardId), 'flags are per viewer');
        CardProgress::setCardFlag($this->charlie, $cardId, false);
        $this->assertFalse(CardProgress::isCardFlagged($this->charlie->id, $cardId));
    }

    public function testDeckPickerListsEveryonesPublicDecks(): void
    {
        // Charlie's deck exists from setUp. Lilly has a public page with one
        // public and one private category; Dad has studied nothing of hers.
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        $lillyTree = test_seed_tree($lilly, 'lilly', 1);
        $private = CategoryManagement::create($lilly, $lilly->id, ['name' => 'Secret', 'is_public' => 0]);
        SubcategoryManagement::create($lilly, $private, ['name' => 'Hidden deck']);
        $dad = test_seed_user('dad@example.com', 'Dad');

        $names = static fn(array $decks): array => array_map(
            static fn(array $d): string => $d['owner_first_name'] . ':' . $d['category']['name'] . ($d['is_mine'] ? '*' : ''),
            $decks
        );
        $this->assertSame(['Charlie:US History', 'Lilly:US History'], $names(CardProgress::listDecksForUser($dad->id)), 'public decks of everyone, private ones hidden');
        $this->assertSame(['US History*', 'Lilly:US History'], array_map(static fn(string $n): string => str_replace('Charlie:', '', $n), $names(CardProgress::listDecksForUser($this->charlie->id))));

        // A private page hides its decks ...
        SiteManagement::updateSiteContent($lilly, $lillyTree['site_id'], ['is_public' => false]);
        $this->assertSame(['Charlie:US History'], $names(CardProgress::listDecksForUser($dad->id)));

        // ... unless the viewer already has progress on one of them (studied
        // while it was public).
        SiteManagement::updateSiteContent($lilly, $lillyTree['site_id'], ['is_public' => true]);
        CardProgress::markCard($dad, $lillyTree['card_ids'][0], CardProgress::MARK_GOT_IT);
        SiteManagement::updateSiteContent($lilly, $lillyTree['site_id'], ['is_public' => false]);
        $this->assertSame(['Charlie:US History', 'Lilly:US History'], $names(CardProgress::listDecksForUser($dad->id)));
    }
}
