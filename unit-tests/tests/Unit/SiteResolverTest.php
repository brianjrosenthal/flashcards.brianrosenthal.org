<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SiteResolverTest extends TestCase
{
    private const MAIN = 'flashcards.brianrosenthal.org';

    private array $charlie = ['id' => 7, 'user_id' => 3, 'slug' => 'charlie', 'domain' => 'flashcards.charlierosenthal.org', 'title' => 'Charlie'];
    private array $milton = ['id' => 8, 'user_id' => 4, 'slug' => 'milton', 'domain' => null, 'title' => 'Milton'];

    private function bySlug(string $slug): ?array
    {
        return ['charlie' => $this->charlie, 'milton' => $this->milton][$slug] ?? null;
    }

    private function byDomain(string $domain): ?array
    {
        return $domain === 'flashcards.charlierosenthal.org' ? $this->charlie : null;
    }

    private function resolve(string $host, string $slug, string $main = self::MAIN): array
    {
        return SiteResolver::resolve($host, $slug, fn(string $s) => $this->bySlug($s), fn(string $d) => $this->byDomain($d), $main);
    }

    protected function tearDown(): void
    {
        unset($_GET['site'], $_GET['path']);
    }

    public function testMainHostUsesTheFirstPathSegment(): void
    {
        $r = $this->resolve(self::MAIN, 'charlie');
        $this->assertSame(7, $r['site']['id']);
        $this->assertSame('/charlie', $r['base_path']);
        $this->assertFalse($r['is_custom_domain']);

        $r = $this->resolve('localhost:8080', 'Charlie');
        $this->assertSame(7, $r['site']['id'], 'slug lookup is case-insensitive');
        $this->assertSame('/charlie', $r['base_path'], 'the base path uses the stored slug');

        $this->assertNull($this->resolve(self::MAIN, 'nobody')['site']);
        $this->assertNull($this->resolve(self::MAIN, '')['site']);
        $this->assertNull($this->resolve('www.' . self::MAIN, '')['site']);
    }

    public function testHostnameIsCheckedBeforeThePathSegment(): void
    {
        // On a page's own hostname the first segment is a category, not a slug.
        $r = $this->resolve('flashcards.charlierosenthal.org', 'us-history');
        $this->assertSame(7, $r['site']['id']);
        $this->assertSame('', $r['base_path']);
        $this->assertTrue($r['is_custom_domain']);

        $r = $this->resolve('milton.' . self::MAIN, 'charlie');
        $this->assertSame(8, $r['site']['id'], 'the subdomain wins over a segment that happens to be a slug');
        $this->assertTrue($r['is_custom_domain']);
    }

    public function testPathFromRequestPrependsTheSegmentOnAPagesOwnHostname(): void
    {
        $_GET['site'] = 'us-history';
        $_GET['path'] = 'presidents/';
        $this->assertSame('us-history/presidents/', SiteResolver::pathFromRequest(['site' => $this->charlie, 'base_path' => '', 'is_custom_domain' => true]));
        $this->assertSame('presidents/', SiteResolver::pathFromRequest(['site' => $this->charlie, 'base_path' => '/charlie', 'is_custom_domain' => false]));

        $_GET['site'] = '';
        $_GET['path'] = '';
        $this->assertSame('', SiteResolver::pathFromRequest(['site' => $this->charlie, 'base_path' => '', 'is_custom_domain' => true]));
    }

    public function testSubdomainResolvesFromTheSlugWithEmptyBasePath(): void
    {
        $r = $this->resolve('Milton.Flashcards.BrianRosenthal.org:443', '');
        $this->assertSame(8, $r['site']['id']);
        $this->assertSame('', $r['base_path']);
        $this->assertTrue($r['is_custom_domain']);

        $this->assertNull($this->resolve('nobody.' . self::MAIN, '')['site']);
        $this->assertNull($this->resolve('a.milton.' . self::MAIN, '')['site'], 'only one label deep');
        $this->assertNull($this->resolve('milton.' . self::MAIN, '', 'localhost')['site'], 'no subdomains without a real main host');
    }

    public function testSubdomainSlugParsing(): void
    {
        $this->assertSame('milton', SiteResolver::subdomainSlug('milton.' . self::MAIN, self::MAIN));
        $this->assertSame('milton-2', SiteResolver::subdomainSlug('MILTON-2.' . self::MAIN . ':8443', self::MAIN));
        $this->assertNull(SiteResolver::subdomainSlug(self::MAIN, self::MAIN));
        $this->assertNull(SiteResolver::subdomainSlug('www.' . self::MAIN, self::MAIN));
        $this->assertNull(SiteResolver::subdomainSlug('milton.example.org', self::MAIN));
        $this->assertNull(SiteResolver::subdomainSlug('evilflashcards.brianrosenthal.org', self::MAIN), 'suffix must be a whole label');
        $this->assertNull(SiteResolver::subdomainSlug('bad_slug.' . self::MAIN, self::MAIN));
        $this->assertNull(SiteResolver::subdomainSlug('milton.localhost', 'localhost'));
        $this->assertSame('milton.' . self::MAIN, SiteResolver::subdomainHostFor($this->milton, self::MAIN));
        $this->assertSame('', SiteResolver::subdomainHostFor($this->milton, 'localhost'));
    }

    public function testCustomDomainResolvesWithEmptyBasePath(): void
    {
        $r = $this->resolve('Flashcards.CharlieRosenthal.org:443', '');
        $this->assertSame(7, $r['site']['id']);
        $this->assertSame('', $r['base_path']);
        $this->assertTrue($r['is_custom_domain']);

        $r = $this->resolve('www.flashcards.charlierosenthal.org', '');
        $this->assertSame(7, $r['site']['id'], 'DreamHost may serve www. too');
        $this->assertTrue($r['is_custom_domain']);
    }

    public function testUnknownHostFallsBackToThePathForm(): void
    {
        $r = $this->resolve('example.org', 'charlie');
        $this->assertSame(7, $r['site']['id']);
        $this->assertSame('/charlie', $r['base_path']);
        $this->assertFalse($r['is_custom_domain']);

        $this->assertNull($this->resolve('example.org', '')['site']);
        $this->assertNull($this->resolve('example.org', 'nobody')['site']);
        $this->assertNull($this->resolve('', '')['site']);
    }

    public function testAbsoluteUrlForBuildsOnTheCanonicalHomeOrTheSubdomain(): void
    {
        $custom = ['slug' => 'charlie', 'domain' => 'flashcards.charlierosenthal.org'];
        $this->assertSame('https://flashcards.charlierosenthal.org/us-history/presidents/', SiteResolver::absoluteUrlFor($custom, 'us-history', 'presidents'));
        $this->assertSame('https://flashcards.charlierosenthal.org/', SiteResolver::absoluteUrlFor($custom));
        $sub = SiteResolver::subdomainHostFor($custom);
        if ($sub !== '') {
            $this->assertSame('https://' . $sub . '/us-history/', SiteResolver::absoluteUrlFor($custom, 'us-history', null, true), 'the subdomain shares the login cookie');
        }
        $pathOnly = ['slug' => 'lilly', 'domain' => null];
        $this->assertStringEndsWith('/us-history/presidents/', SiteResolver::absoluteUrlFor($pathOnly, 'us-history', 'presidents'));
        $this->assertStringStartsWith('https://', SiteResolver::absoluteUrlFor($pathOnly, 'us-history', 'presidents'));
    }

    public function testLegacyHostsRedirectToTheSamePathOnTheMainHost(): void
    {
        $legacy = ['old-flashcards.example.org'];
        $this->assertSame('https://' . self::MAIN . '/manage/?user_id=3', SiteResolver::legacyRedirectTarget('old-flashcards.example.org', '/manage/?user_id=3', $legacy, self::MAIN));
        $this->assertSame('https://' . self::MAIN . '/', SiteResolver::legacyRedirectTarget('WWW.Old-Flashcards.Example.org:443', '', $legacy, self::MAIN));
        $this->assertNull(SiteResolver::legacyRedirectTarget(self::MAIN, '/', $legacy, self::MAIN));
        $this->assertNull(SiteResolver::legacyRedirectTarget('flashcards.charlierosenthal.org', '/', $legacy, self::MAIN));
        $this->assertNull(SiteResolver::legacyRedirectTarget('old-flashcards.example.org', '/', [], self::MAIN));
        $this->assertNull(SiteResolver::legacyRedirectTarget('old-flashcards.example.org', '/', $legacy, ''), 'no main host, nowhere to go');
    }

    public function testUrlForBuildsTwoLevelPaths(): void
    {
        $this->assertSame('/charlie/', SiteResolver::urlFor('/charlie'));
        $this->assertSame('/us-history/', SiteResolver::urlFor('', 'us-history'));
        $this->assertSame('/charlie/us-history/presidents/', SiteResolver::urlFor('/charlie', 'us-history', 'presidents'));
        $this->assertSame('/', SiteResolver::urlFor('', null, 'ignored-without-category'));
        $this->assertSame('/charlie/', SiteResolver::urlFor('/charlie', ''));
    }

    public function testSplitPathAcceptsUpToTwoValidSlugs(): void
    {
        $this->assertSame([null, null], SiteResolver::splitPath(''));
        $this->assertSame(['a', null], SiteResolver::splitPath('a/'));
        $this->assertSame(['us-history', 'presidents'], SiteResolver::splitPath('/us-history/presidents/'));
        $this->assertNull(SiteResolver::splitPath('a/b/c'), 'the tree has two levels');
        $this->assertNull(SiteResolver::splitPath('Bad Slug'));
        $this->assertNull(SiteResolver::splitPath('../etc'));
        $this->assertNull(SiteResolver::splitPath('index.php'));
    }
}
