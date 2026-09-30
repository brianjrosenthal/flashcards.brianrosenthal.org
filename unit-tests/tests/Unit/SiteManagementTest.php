<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SiteManagementTest extends TestCase
{
    private UserContext $admin;
    private UserContext $charlie;

    protected function setUp(): void
    {
        test_reset_all();
        $this->admin = test_seed_admin();
        $this->charlie = test_seed_user('charlie@example.com', 'Charlie');
    }

    public function testCreateForUserDerivesSlugAndDefaults(): void
    {
        $id = SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', "Charlie's Flashcards");
        $site = SiteManagement::findById($id);
        $this->assertSame('charlie', $site['slug']);
        $this->assertSame("Charlie's Flashcards", $site['title']);
        $this->assertSame('', $site['tagline']);
        $this->assertNull($site['domain']);
        $this->assertSame('violet', $site['accent_color']);
        $this->assertSame(1, (int)$site['is_public'], 'pages are public by default');
        $this->assertSame($id, (int)SiteManagement::findByUserId($this->charlie->id)['id']);
        $this->assertSame($id, (int)SiteManagement::findBySlug('CHARLIE')['id']);
        $this->assertNull(SiteManagement::findBySlug('nobody'));
    }

    public function testUserMayCreateTheirOwnPageButNotAnothers(): void
    {
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        SiteManagement::createForUser($this->charlie, $this->charlie->id, 'Charlie', 'Mine');
        $this->expectException(RuntimeException::class);
        SiteManagement::createForUser($this->charlie, $lilly->id, 'Lilly', 'Not mine');
    }

    public function testSecondPageForSameUserIsRefused(): void
    {
        SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'One');
        $this->expectException(RuntimeException::class);
        SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'Two');
    }

    public function testTitleIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', '  ');
    }

    public function testSlugCollisionsGetASuffixAndReservedHintsFallBack(): void
    {
        $other = test_seed_user('other@example.com', 'Charlie');
        SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'A');
        $id2 = SiteManagement::createForUser($this->admin, $other->id, 'Charlie', 'B');
        $this->assertSame('charlie-2', SiteManagement::findById($id2)['slug']);

        $third = test_seed_user('admin2@example.com', 'Admin');
        $id3 = SiteManagement::createForUser($this->admin, $third->id, 'admin', 'C');
        $this->assertSame('site-' . $third->id, SiteManagement::findById($id3)['slug']);

        $fourth = test_seed_user('quiz@example.com', 'Quiz');
        $id4 = SiteManagement::createForUser($this->admin, $fourth->id, 'Quiz', 'D');
        $this->assertSame('site-' . $fourth->id, SiteManagement::findById($id4)['slug'], 'a slug must not shadow /quiz/');
    }

    public function testOwnerCanUpdateContentAndAdminCanUpdateRouting(): void
    {
        $id = SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'A');

        SiteManagement::updateSiteContent($this->charlie, $id, [
            'title' => 'Charlie Studies', 'tagline' => 'Cards for every test',
            'accent_color' => 'mint', 'is_public' => false,
        ]);
        $site = SiteManagement::findById($id);
        $this->assertSame('Charlie Studies', $site['title']);
        $this->assertSame('Cards for every test', $site['tagline']);
        $this->assertSame('mint', $site['accent_color']);
        $this->assertSame(0, (int)$site['is_public']);

        SiteManagement::updateSiteContent($this->charlie, $id, ['is_public' => true]);
        $site = SiteManagement::findById($id);
        $this->assertSame(1, (int)$site['is_public']);
        $this->assertSame('Charlie Studies', $site['title'], 'other fields keep their values');
        $this->assertSame('mint', $site['accent_color']);

        SiteManagement::updateSiteRouting($this->admin, $id, 'charlie-r', 'https://Flashcards.CharlieRosenthal.org/');
        $site = SiteManagement::findById($id);
        $this->assertSame('charlie-r', $site['slug']);
        $this->assertSame('flashcards.charlierosenthal.org', $site['domain']);
        $this->assertSame($id, (int)SiteManagement::findByDomain('FLASHCARDS.charlierosenthal.org:443')['id']);
        $this->assertNull(SiteManagement::findByDomain('nobody.example.org'));

        SiteManagement::updateSiteRouting($this->admin, $id, 'charlie-r', '');
        $this->assertNull(SiteManagement::findById($id)['domain']);
    }

    public function testNonOwnerCannotUpdateContent(): void
    {
        $id = SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'A');
        $stranger = test_seed_user('stranger@example.com');
        $this->expectException(RuntimeException::class);
        SiteManagement::updateSiteContent($stranger, $id, ['title' => 'Hijacked']);
    }

    public function testOwnerCannotChangeRouting(): void
    {
        $id = SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'A');
        $this->expectException(RuntimeException::class);
        SiteManagement::updateSiteRouting($this->charlie, $id, 'new-slug', '');
    }

    public function testRoutingValidation(): void
    {
        $id = SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'A');
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        $id2 = SiteManagement::createForUser($this->admin, $lilly->id, 'Lilly', 'B');
        SiteManagement::updateSiteRouting($this->admin, $id2, 'lilly', 'flashcards.lillyrosenthal.org');

        foreach ([
            ['admin', ''],                                   // reserved slug
            ['review', ''],                                  // would shadow /review/
            ['www', ''],                                     // would shadow www.MAIN_HOST
            ['lilly', ''],                                   // taken slug
            ['charlie', 'flashcards.lillyrosenthal.org'],    // taken domain
            ['charlie', MAIN_HOST],                          // the main host
            ['charlie', 'charlie.' . MAIN_HOST],             // subdomains are automatic, not custom domains
            ['charlie', 'not a host'],                       // malformed
        ] as [$slug, $domain]) {
            try {
                SiteManagement::updateSiteRouting($this->admin, $id, $slug, $domain);
                $this->fail("expected rejection of slug '$slug' / domain '$domain'");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testListSitesCarriesOwnerNames(): void
    {
        SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'A');
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        SiteManagement::createForUser($this->admin, $lilly->id, 'Lilly', 'B');
        $list = SiteManagement::listSites();
        $this->assertSame(['Charlie', 'Lilly'], array_column($list, 'first_name'));
        $this->assertSame(['charlie', 'lilly'], array_column($list, 'slug'));
        $this->assertSame('charlie@example.com', $list[0]['email']);
    }

    public function testContentValidation(): void
    {
        $id = SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'A');
        try {
            SiteManagement::updateSiteContent($this->charlie, $id, ['title' => '  ']);
            $this->fail('empty title should be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('title', $e->getMessage());
        }
        try {
            SiteManagement::updateSiteContent($this->charlie, $id, ['tagline' => str_repeat('x', 256)]);
            $this->fail('over-long tagline should be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Tagline', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        SiteManagement::updateSiteContent($this->charlie, $id, ['accent_color' => 'plaid']);
    }

    public function testNormalizeDomain(): void
    {
        $this->assertSame('flashcards.charlierosenthal.org', SiteManagement::normalizeDomain(' HTTPS://Flashcards.CharlieRosenthal.org:8443/x '));
        $this->assertSame('localhost', SiteManagement::normalizeDomain('localhost'));
        $this->assertNull(SiteManagement::normalizeDomain(''));
        $this->assertNull(SiteManagement::normalizeDomain('no spaces allowed'));
        $this->assertNull(SiteManagement::normalizeDomain('nodots'));
    }

    public function testWritesAreActivityLogged(): void
    {
        $id = SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'A');
        SiteManagement::updateSiteContent($this->charlie, $id, ['title' => 'B']);
        SiteManagement::updateSiteRouting($this->admin, $id, 'charlie', '');
        $types = array_column(ActivityLog::list([], 10), 'action_type');
        $this->assertContains('site.create', $types);
        $this->assertContains('site.update', $types);
        $this->assertContains('site.update_routing', $types);
    }
}
