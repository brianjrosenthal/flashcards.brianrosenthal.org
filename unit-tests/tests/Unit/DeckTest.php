<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DeckTest extends TestCase
{
    private UserContext $admin;
    private UserContext $charlie;
    private UserContext $lilly;
    private array $ids;
    private int $warsId;

    protected function setUp(): void
    {
        test_reset_all();
        $this->admin = test_seed_admin();
        $this->charlie = test_seed_user('charlie@example.com', 'Charlie');
        $this->lilly = test_seed_user('lilly@example.com', 'Lilly');
        $this->ids = test_seed_tree($this->charlie, 'charlie', 3);
        $this->warsId = SubcategoryManagement::create($this->charlie, $this->ids['category_id'], ['name' => 'Wars']);
        CardManagement::create($this->charlie, $this->warsId, ['front_text' => 'Gettysburg', 'back_text' => '1863']);
        CardManagement::create($this->charlie, $this->warsId, ['front_text' => 'Yorktown', 'back_text' => '1781']);
    }

    public function testOfCategory(): void
    {
        $deck = Deck::of(Deck::TYPE_CATEGORY, $this->ids['category_id']);
        $this->assertNotNull($deck);
        $this->assertTrue($deck->isCategory());
        $this->assertSame($this->ids['category_id'], $deck->id);
        $this->assertSame($this->ids['category_id'], $deck->categoryId);
        $this->assertSame($this->charlie->id, $deck->ownerUserId);
        $this->assertSame('US History', $deck->name);
        $this->assertNull($deck->parentName);
        $this->assertNull($deck->subcategory);
        $this->assertSame('us-history', $deck->category['slug']);
    }

    public function testOfSubcategory(): void
    {
        $deck = Deck::of(Deck::TYPE_SUBCATEGORY, $this->ids['subcategory_id']);
        $this->assertNotNull($deck);
        $this->assertFalse($deck->isCategory());
        $this->assertSame($this->ids['subcategory_id'], $deck->id);
        $this->assertSame($this->ids['category_id'], $deck->categoryId);
        $this->assertSame($this->charlie->id, $deck->ownerUserId);
        $this->assertSame('Presidents', $deck->name);
        $this->assertSame('US History', $deck->parentName);
        $this->assertSame('presidents', $deck->subcategory['slug']);
    }

    public function testOfUnknownTypeOrRowIsNull(): void
    {
        $this->assertNull(Deck::of('tag', $this->ids['category_id']));
        $this->assertNull(Deck::of(Deck::TYPE_CATEGORY, 9999));
        $this->assertNull(Deck::of(Deck::TYPE_SUBCATEGORY, 9999));
        $this->assertNull(Deck::of(Deck::TYPE_SUBCATEGORY, 0));
    }

    public function testFromRequestPrefersTheSubcategoryParameter(): void
    {
        $cat = $this->ids['category_id'];
        $sub = $this->ids['subcategory_id'];
        $this->assertSame($sub, Deck::fromRequest(['subcategory' => (string)$sub, 'category' => (string)$cat])->id);
        $this->assertSame(Deck::TYPE_SUBCATEGORY, Deck::fromRequest(['subcategory' => (string)$sub])->type);
        $this->assertSame(Deck::TYPE_CATEGORY, Deck::fromRequest(['category' => (string)$cat])->type);
        $this->assertSame($cat, Deck::fromRequest(['deck_type' => 'category', 'deck_id' => (string)$cat])->id);
        $this->assertNull(Deck::fromRequest([]));
        $this->assertNull(Deck::fromRequest(['subcategory' => '0']));
        $this->assertNull(Deck::fromRequest(['category' => '9999']));
        $this->assertNull(Deck::fromRequest(['deck_type' => 'tag', 'deck_id' => '1']));
    }

    public function testOfCard(): void
    {
        $deck = Deck::ofCard($this->ids['card_ids'][0]);
        $this->assertSame(Deck::TYPE_SUBCATEGORY, $deck->type);
        $this->assertSame($this->ids['subcategory_id'], $deck->id);
        $this->assertNull(Deck::ofCard(9999));
    }

    public function testScopeSqlQueryStringLabelAndUrls(): void
    {
        $sub = Deck::of(Deck::TYPE_SUBCATEGORY, $this->ids['subcategory_id']);
        $cat = Deck::of(Deck::TYPE_CATEGORY, $this->ids['category_id']);

        $this->assertSame(['k.subcategory_id = ?', [$sub->id]], $sub->scopeSql('k'));
        [$where, $params] = $cat->scopeSql('c');
        $this->assertStringStartsWith('c.subcategory_id IN (SELECT', $where);
        $this->assertSame([$cat->id], $params);

        $this->assertSame('subcategory=' . $sub->id, $sub->queryString());
        $this->assertSame('category=' . $cat->id, $cat->queryString());
        $this->assertSame('US History › Presidents', $sub->label());
        $this->assertSame('US History (all)', $cat->label());
        $this->assertSame('/review/study.php?subcategory=' . $sub->id, $sub->studyUrl());
        $this->assertSame('/review/study.php?category=' . $cat->id . '&filter=flagged', $cat->studyUrl('flagged'));
        $this->assertSame('/quiz/?category=' . $cat->id, $cat->quizUrl());
    }

    public function testCardCountSpansEveryDeckOfACategory(): void
    {
        $this->assertSame(3, Deck::of(Deck::TYPE_SUBCATEGORY, $this->ids['subcategory_id'])->cardCount());
        $this->assertSame(2, Deck::of(Deck::TYPE_SUBCATEGORY, $this->warsId)->cardCount());
        $this->assertSame(5, Deck::of(Deck::TYPE_CATEGORY, $this->ids['category_id'])->cardCount());
    }

    public function testSiteIsTheOwnersPage(): void
    {
        $deck = Deck::of(Deck::TYPE_CATEGORY, $this->ids['category_id']);
        $this->assertSame($this->ids['site_id'], (int)$deck->site()['id']);
    }

    public function testOwnerAndAdminMayViewAndEditVisitorsMayOnlyView(): void
    {
        $deck = Deck::of(Deck::TYPE_SUBCATEGORY, $this->ids['subcategory_id']);
        foreach ([$this->charlie, $this->admin, $this->lilly, null] as $ctx) {
            $this->assertTrue($deck->canView($ctx), 'public page, public category');
        }
        $this->assertTrue($deck->canEdit($this->charlie));
        $this->assertTrue($deck->canEdit($this->admin));
        $this->assertFalse($deck->canEdit($this->lilly));
        $this->assertFalse($deck->canEdit(null));
    }

    public function testPrivateCategoryIsOnlyForTheOwnerAndAdmins(): void
    {
        CategoryManagement::update($this->charlie, $this->ids['category_id'], ['is_public' => false]);
        $sub = Deck::of(Deck::TYPE_SUBCATEGORY, $this->ids['subcategory_id']);
        $cat = Deck::of(Deck::TYPE_CATEGORY, $this->ids['category_id']);
        foreach ([$sub, $cat] as $deck) {
            $this->assertTrue($deck->canView($this->charlie));
            $this->assertTrue($deck->canView($this->admin));
            $this->assertFalse($deck->canView($this->lilly));
            $this->assertFalse($deck->canView(null));
        }
        $cat->assertCanView($this->charlie);
        $this->expectException(RuntimeException::class);
        $sub->assertCanView($this->lilly);
    }

    public function testPrivatePageHidesEveryDeckFromVisitors(): void
    {
        // Deck::site() caches the page row per owner for the request, so this
        // scenario uses an owner no earlier test has looked up: a fresh user
        // whose page is made private before any Deck is built.
        $dana = test_seed_user('dana@example.com', 'Dana');
        $tree = test_seed_tree($dana, 'dana', 1);
        SiteManagement::updateSiteContent($dana, $tree['site_id'], ['is_public' => false]);

        $deck = Deck::of(Deck::TYPE_SUBCATEGORY, $tree['subcategory_id']);
        $this->assertSame(0, (int)$deck->site()['is_public']);
        $this->assertTrue($deck->canView($dana));
        $this->assertTrue($deck->canView($this->admin));
        $this->assertFalse($deck->canView($this->charlie));
        $this->assertFalse($deck->canView(null));
    }
}
