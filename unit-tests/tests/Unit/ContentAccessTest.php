<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The visibility rules are pure functions of the rows, so no database is
 * needed: owner or admin may always edit and view; anyone else may view only
 * when both the owner's page and the category are public.
 */
final class ContentAccessTest extends TestCase
{
    private UserContext $owner;
    private UserContext $admin;
    private UserContext $stranger;

    protected function setUp(): void
    {
        $this->owner = new UserContext(3, false);
        $this->admin = new UserContext(1, true);
        $this->stranger = new UserContext(4, false);
    }

    private function site(bool $public): array
    {
        return ['id' => 7, 'user_id' => 3, 'slug' => 'charlie', 'is_public' => $public ? 1 : 0];
    }

    private function category(bool $public): array
    {
        return ['id' => 11, 'user_id' => 3, 'slug' => 'us-history', 'is_public' => $public ? '1' : '0'];
    }

    public function testCanEdit(): void
    {
        $this->assertTrue(ContentAccess::canEdit($this->owner, 3));
        $this->assertTrue(ContentAccess::canEdit($this->admin, 3));
        $this->assertFalse(ContentAccess::canEdit($this->stranger, 3));
        $this->assertFalse(ContentAccess::canEdit(null, 3));
    }

    public function testAssertCanEditExplains(): void
    {
        ContentAccess::assertCanEdit($this->owner, 3);
        ContentAccess::assertCanEdit($this->admin, 3);
        try {
            ContentAccess::assertCanEdit(null, 3);
            $this->fail('anonymous must not edit');
        } catch (RuntimeException $e) {
            $this->assertSame('Login required', $e->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('own content');
        ContentAccess::assertCanEdit($this->stranger, 3);
    }

    public function testOwnerAndAdminSeeEverything(): void
    {
        foreach ([$this->owner, $this->admin] as $ctx) {
            $this->assertTrue(ContentAccess::canView($ctx, $this->site(true), $this->category(true)));
            $this->assertTrue(ContentAccess::canView($ctx, $this->site(false), $this->category(true)));
            $this->assertTrue(ContentAccess::canView($ctx, $this->site(true), $this->category(false)));
            $this->assertTrue(ContentAccess::canView($ctx, null, $this->category(false)), 'even without a page row');
        }
    }

    public function testVisitorsNeedAPublicPageAndAPublicCategory(): void
    {
        foreach ([$this->stranger, null] as $ctx) {
            $this->assertTrue(ContentAccess::canView($ctx, $this->site(true), $this->category(true)));
            $this->assertFalse(ContentAccess::canView($ctx, $this->site(false), $this->category(true)), 'private page');
            $this->assertFalse(ContentAccess::canView($ctx, $this->site(true), $this->category(false)), 'private category');
            $this->assertFalse(ContentAccess::canView($ctx, $this->site(false), $this->category(false)));
            $this->assertFalse(ContentAccess::canView($ctx, null, $this->category(true)), 'no page row counts as not public');
        }
    }

    public function testAssertCanViewThrowsForPrivateDecks(): void
    {
        ContentAccess::assertCanView(null, $this->site(true), $this->category(true));
        ContentAccess::assertCanView($this->owner, $this->site(false), $this->category(false));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('private');
        ContentAccess::assertCanView($this->stranger, $this->site(true), $this->category(false));
    }
}
