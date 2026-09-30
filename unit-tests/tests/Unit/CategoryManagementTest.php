<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CategoryManagementTest extends TestCase
{
    private UserContext $admin;
    private UserContext $charlie;

    protected function setUp(): void
    {
        test_reset_all();
        $this->admin = test_seed_admin();
        $this->charlie = test_seed_user('charlie@example.com', 'Charlie');
    }

    public function testCreateGeneratesSlugSortOrderAndDefaults(): void
    {
        $a = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'US History', 'description' => 'From 1776']);
        $b = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'World Capitals']);
        $rowA = CategoryManagement::findById($a);
        $rowB = CategoryManagement::findById($b);
        $this->assertSame('us-history', $rowA['slug']);
        $this->assertSame('world-capitals', $rowB['slug']);
        $this->assertSame(1, (int)$rowA['sort_order']);
        $this->assertSame(2, (int)$rowB['sort_order']);
        $this->assertSame('From 1776', $rowA['description']);
        $this->assertSame('', $rowB['description']);
        $this->assertSame(1, (int)$rowA['is_public'], 'categories are public by default');
        $this->assertSame($a, (int)CategoryManagement::findBySlug($this->charlie->id, 'us-history')['id']);
    }

    public function testCreatePrivateCategory(): void
    {
        $id = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Secret', 'is_public' => false]);
        $this->assertSame(0, (int)CategoryManagement::findById($id)['is_public']);
    }

    public function testDuplicateNamesGetDistinctSlugsAndExplicitDuplicateSlugIsRefused(): void
    {
        CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Algebra']);
        $b = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Algebra']);
        $this->assertSame('algebra-2', CategoryManagement::findById($b)['slug']);

        $this->expectException(InvalidArgumentException::class);
        CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Other', 'slug' => 'algebra']);
    }

    public function testSlugsAreScopedPerUser(): void
    {
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Algebra']);
        $id = CategoryManagement::create($lilly, $lilly->id, ['name' => 'Algebra']);
        $this->assertSame('algebra', CategoryManagement::findById($id)['slug']);
    }

    public function testReservedSlugIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');
        CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'X', 'slug' => 'quiz']);
    }

    public function testReservedNameGetsSafeGeneratedSlug(): void
    {
        $id = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Review']);
        $slug = CategoryManagement::findById($id)['slug'];
        $this->assertFalse(Slugger::isReserved($slug));
        $this->assertTrue(Slugger::isValid($slug));
    }

    public function testNameIsRequiredAndDescriptionIsCapped(): void
    {
        try {
            CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => '  ']);
            $this->fail('blank name must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Name', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Long', 'description' => str_repeat('x', 501)]);
    }

    public function testOnlyOwnerOrAdminMayWrite(): void
    {
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        $id = CategoryManagement::create($this->admin, $this->charlie->id, ['name' => 'By admin']);
        CategoryManagement::update($this->admin, $id, ['name' => 'Admin edit']);
        CategoryManagement::update($this->charlie, $id, ['name' => 'Owner edit']);
        $this->assertSame('Owner edit', CategoryManagement::findById($id)['name']);

        try {
            CategoryManagement::update($lilly, $id, ['name' => 'Hijack']);
            $this->fail('another user must not edit');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('own content', $e->getMessage());
        }
        try {
            CategoryManagement::create($lilly, $this->charlie->id, ['name' => 'Planted']);
            $this->fail('another user must not create in someone else\'s tree');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->expectException(RuntimeException::class);
        CategoryManagement::delete(null, $id);
    }

    public function testUpdateChangesFieldsAndKeepsSlugUniqueness(): void
    {
        CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'A']);
        $b = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'B']);
        CategoryManagement::update($this->charlie, $b, ['name' => 'B renamed', 'slug' => 'b-new', 'sort_order' => 0, 'description' => 'x', 'is_public' => false]);
        $row = CategoryManagement::findById($b);
        $this->assertSame('B renamed', $row['name']);
        $this->assertSame('b-new', $row['slug']);
        $this->assertSame(0, (int)$row['sort_order']);
        $this->assertSame('x', $row['description']);
        $this->assertSame(0, (int)$row['is_public']);

        // Fields left out keep their values.
        CategoryManagement::update($this->charlie, $b, ['name' => 'B again']);
        $row = CategoryManagement::findById($b);
        $this->assertSame('b-new', $row['slug']);
        $this->assertSame(0, (int)$row['is_public']);

        // Updating with its own slug is fine; taking another's is not.
        CategoryManagement::update($this->charlie, $b, ['slug' => 'b-new']);
        $this->expectException(InvalidArgumentException::class);
        CategoryManagement::update($this->charlie, $b, ['slug' => 'a']);
    }

    public function testDeleteRemovesDecksCardsAndResumePoints(): void
    {
        $ids = test_seed_tree($this->charlie, 'charlie', 3);
        $catId = $ids['category_id'];
        $sub2 = SubcategoryManagement::create($this->charlie, $catId, ['name' => 'Wars']);
        CardManagement::create($this->charlie, $sub2, ['front_text' => 'Gettysburg', 'back_text' => '1863']);
        $keep = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Keep me']);

        // Resume points on the category, on one of its decks, and on an unrelated category.
        $st = pdo()->prepare("INSERT INTO user_deck_positions (user_id, deck_type, deck_id, position) VALUES (?, ?, ?, ?)");
        $st->execute([$this->charlie->id, 'category', $catId, 2]);
        $st->execute([$this->charlie->id, 'subcategory', $ids['subcategory_id'], 1]);
        $st->execute([$this->charlie->id, 'category', $keep, 5]);

        $this->assertSame(4, CategoryManagement::cardCount($catId));
        CategoryManagement::delete($this->charlie, $catId);

        $this->assertNull(CategoryManagement::findById($catId));
        $this->assertNull(SubcategoryManagement::findById($ids['subcategory_id']));
        $this->assertNull(SubcategoryManagement::findById($sub2));
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM cards')->fetchColumn(), 'cards go with their decks');
        $positions = pdo()->query('SELECT deck_type, deck_id FROM user_deck_positions')->fetchAll();
        $this->assertSame([['deck_type' => 'category', 'deck_id' => $keep]], array_map(static fn($r) => ['deck_type' => $r['deck_type'], 'deck_id' => (int)$r['deck_id']], $positions), 'only the deleted tree\'s resume points are forgotten');
        $this->assertNotNull(CategoryManagement::findById($keep));
    }

    public function testListForUserCountsPublicFilterAndTree(): void
    {
        $ids = test_seed_tree($this->charlie, 'charlie', 3);
        SubcategoryManagement::create($this->charlie, $ids['category_id'], ['name' => 'Empty deck']);
        $private = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Private', 'is_public' => false]);

        $list = CategoryManagement::listForUser($this->charlie->id);
        $this->assertSame(['US History', 'Private'], array_column($list, 'name'));
        $this->assertSame(2, (int)$list[0]['subcategory_count']);
        $this->assertSame(3, (int)$list[0]['card_count']);
        $this->assertSame(0, (int)$list[1]['card_count']);

        $public = CategoryManagement::listForUser($this->charlie->id, true);
        $this->assertSame(['US History'], array_column($public, 'name'), 'visitors only see public categories');

        $tree = CategoryManagement::treeForUser($this->charlie->id);
        $this->assertCount(2, $tree);
        $this->assertCount(2, $tree[0]['subcategories']);
        $this->assertSame(3, (int)$tree[0]['subcategories'][0]['card_count']);
        $this->assertSame(0, (int)$tree[0]['subcategories'][1]['card_count']);
        $this->assertCount(1, CategoryManagement::treeForUser($this->charlie->id, true));
        $this->assertSame($this->charlie->id, CategoryManagement::ownerUserIdOf($ids['category_id']));
        $this->assertSame($this->charlie->id, CategoryManagement::ownerUserIdOf($private));
        $this->assertNull(CategoryManagement::ownerUserIdOf(9999));
    }

    public function testWritesAreActivityLogged(): void
    {
        $id = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Logged']);
        CategoryManagement::update($this->charlie, $id, ['name' => 'Logged 2']);
        CategoryManagement::delete($this->charlie, $id);
        $types = array_column(ActivityLog::list([], 10), 'action_type');
        $this->assertContains('category.create', $types);
        $this->assertContains('category.update', $types);
        $this->assertContains('category.delete', $types);
    }
}
