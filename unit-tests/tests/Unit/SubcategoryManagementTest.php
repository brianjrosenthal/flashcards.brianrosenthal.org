<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SubcategoryManagementTest extends TestCase
{
    private UserContext $charlie;
    private int $categoryId;

    protected function setUp(): void
    {
        test_reset_all();
        test_seed_admin();
        $this->charlie = test_seed_user('charlie@example.com', 'Charlie');
        $this->categoryId = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'US History']);
    }

    public function testCreateAndLookups(): void
    {
        $id = SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Presidents & VPs', 'description' => 'd']);
        $row = SubcategoryManagement::findById($id);
        $this->assertSame('presidents-vps', $row['slug']);
        $this->assertSame('d', $row['description']);
        $this->assertSame(1, (int)$row['sort_order']);
        $this->assertSame($this->categoryId, (int)$row['category_id']);
        $this->assertSame($id, (int)SubcategoryManagement::findBySlug($this->categoryId, 'presidents-vps')['id']);
        $this->assertSame($this->charlie->id, SubcategoryManagement::ownerUserIdOf($id));
        $this->assertNull(SubcategoryManagement::ownerUserIdOf(9999));
    }

    public function testSlugsAreScopedPerCategory(): void
    {
        $other = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Geography']);
        SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Basics']);
        $dup = SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Basics']);
        $inOther = SubcategoryManagement::create($this->charlie, $other, ['name' => 'Basics']);
        $this->assertSame('basics-2', SubcategoryManagement::findById($dup)['slug']);
        $this->assertSame('basics', SubcategoryManagement::findById($inOther)['slug']);
    }

    public function testReservedNamesGetSafeSlugsAndExplicitReservedSlugIsRefused(): void
    {
        $id = SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Cards']);
        $this->assertFalse(Slugger::isReserved(SubcategoryManagement::findById($id)['slug']));
        $this->expectException(InvalidArgumentException::class);
        SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'X', 'slug' => 'images']);
    }

    public function testCreateInUnknownCategoryFails(): void
    {
        $this->expectException(RuntimeException::class);
        SubcategoryManagement::create($this->charlie, 9999, ['name' => 'X']);
    }

    public function testOnlyOwnerOrAdminMayWrite(): void
    {
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        $id = SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Mine']);
        $admin = new UserContext(1, true);
        SubcategoryManagement::update($admin, $id, ['name' => 'Admin touched']);
        SubcategoryManagement::update($this->charlie, $id, ['name' => 'Owner touched', 'description' => 'dd', 'sort_order' => 4]);
        $row = SubcategoryManagement::findById($id);
        $this->assertSame('Owner touched', $row['name']);
        $this->assertSame('dd', $row['description']);
        $this->assertSame(4, (int)$row['sort_order']);
        $this->expectException(RuntimeException::class);
        SubcategoryManagement::update($lilly, $id, ['name' => 'Hijack']);
    }

    public function testListForCategoryCountsCards(): void
    {
        $a = SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Presidents']);
        $b = SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Wars']);
        CardManagement::create($this->charlie, $a, ['front_text' => 'Washington', 'back_text' => '1st']);
        CardManagement::create($this->charlie, $a, ['front_text' => 'Adams', 'back_text' => '2nd']);
        $list = SubcategoryManagement::listForCategory($this->categoryId);
        $this->assertSame([$a, $b], array_map('intval', array_column($list, 'id')), 'display order');
        $this->assertSame(2, (int)$list[0]['card_count']);
        $this->assertSame(0, (int)$list[1]['card_count']);
    }

    public function testDeleteRemovesCardsProgressAndResumePoint(): void
    {
        $ids = test_seed_tree($this->charlie, 'charlie', 2);
        $subId = $ids['subcategory_id'];
        $st = pdo()->prepare("INSERT INTO user_card_state (user_id, card_id, last_mark) VALUES (?, ?, 'got_it')");
        $st->execute([$this->charlie->id, $ids['card_ids'][0]]);
        $st = pdo()->prepare("INSERT INTO user_deck_positions (user_id, deck_type, deck_id, position) VALUES (?, 'subcategory', ?, 1)");
        $st->execute([$this->charlie->id, $subId]);

        SubcategoryManagement::delete($this->charlie, $subId);

        $this->assertNull(SubcategoryManagement::findById($subId));
        $this->assertNull(CardManagement::findById($ids['card_ids'][0]));
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM cards')->fetchColumn());
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM user_card_state')->fetchColumn(), 'progress goes with the cards');
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM user_deck_positions')->fetchColumn(), 'the deck\'s resume point is forgotten');
        $this->assertNotNull(CategoryManagement::findById($ids['category_id']), 'the category stays');
        $this->assertContains('subcategory.delete', array_column(ActivityLog::list([], 10), 'action_type'));
    }

    public function testDeleteRefusedForStrangers(): void
    {
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        $id = SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Mine']);
        $this->expectException(RuntimeException::class);
        SubcategoryManagement::delete($lilly, $id);
    }
}
