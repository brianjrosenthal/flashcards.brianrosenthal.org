<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SiteManagement.php';
require_once __DIR__ . '/Slugger.php';

/**
 * Decides which user's public site a request is for, and builds that site's
 * public URLs. Three ways in:
 *
 *   - path form  : flashcards.brianrosenthal.org/{slug}/...  (any host)
 *   - subdomain  : {slug}.MAIN_HOST/...                      (derived from sites.slug, once a dedicated domain exists)
 *   - custom host: flashcards.charlierosenthal.org/...       (sites.domain)
 *
 * The result is a "resolved site" array used by every public page:
 *   ['site' => row|null, 'base_path' => '' | '/{slug}', 'is_custom_domain' => bool]
 * where is_custom_domain means "this site is served at / on the request's
 * hostname" (its subdomain or its custom domain).
 *
 * The request's hostname comes from request_host() (config.php), which honours
 * the X-Forwarded-Host set by the subdomain Worker when its secret matches.
 *
 * resolve() takes the lookups as callables so the routing rules are unit
 * testable without a database.
 */
final class SiteResolver {

    public static function mainHost(): string {
        return defined('MAIN_HOST') ? strtolower(trim((string)MAIN_HOST)) : '';
    }

    /** True when subdomain routing applies: a real main host is configured. */
    private static function mainHostSupportsSubdomains(string $mainHost): bool {
        return $mainHost !== '' && $mainHost !== 'localhost' && $mainHost !== '127.0.0.1';
    }

    /** "Flashcards.CharlieRosenthal.org:8080" -> "flashcards.charlierosenthal.org" */
    public static function normalizeHost(string $host): string {
        $h = strtolower(trim($host));
        return explode(':', $h, 2)[0];
    }

    /**
     * The slug a hostname of the form {slug}.{mainHost} names, or null when the
     * host is anything else (the main host itself, www., a deeper label, an
     * unrelated domain, or a label that is not a valid slug).
     */
    public static function subdomainSlug(string $host, string $mainHost): ?string {
        $h = self::normalizeHost($host);
        $main = strtolower(trim($mainHost));
        if (!self::mainHostSupportsSubdomains($main)) {
            return null;
        }
        $suffix = '.' . $main;
        if (strlen($h) <= strlen($suffix) || substr($h, -strlen($suffix)) !== $suffix) {
            return null;
        }
        $label = substr($h, 0, -strlen($suffix));
        if ($label === 'www' || strpos($label, '.') !== false || !Slugger::isValid($label)) {
            return null;
        }
        return $label;
    }

    /** "{slug}.{mainHost}" for a site row, or '' when subdomains do not apply. */
    public static function subdomainHostFor(array $site, ?string $mainHost = null): string {
        $main = $mainHost ?? self::mainHost();
        if (!self::mainHostSupportsSubdomains($main)) {
            return '';
        }
        return strtolower((string)$site['slug']) . '.' . $main;
    }

    /**
     * @param callable(string):?array $findBySlug
     * @param callable(string):?array $findByDomain
     * @param ?string $mainHost defaults to MAIN_HOST; tests pass it explicitly
     * @return array{site:?array,base_path:string,is_custom_domain:bool}
     */
    public static function resolve(string $host, string $slugFromPath, callable $findBySlug, callable $findByDomain, ?string $mainHost = null): array {
        $none = ['site' => null, 'base_path' => '', 'is_custom_domain' => false];
        $main = strtolower(trim($mainHost ?? self::mainHost()));

        $h = self::normalizeHost($host);
        $onMainHost = ($h === '' || $h === $main || $h === 'www.' . $main || $h === 'localhost' || $h === '127.0.0.1');

        // On the main host the first path segment names the site (the rewrite
        // passes it as ?site=). On a site's own hostname that segment is part
        // of the page path instead, so the hostname is checked first.
        $slug = strtolower(trim($slugFromPath));
        if ($onMainHost) {
            if ($slug === '') {
                return $none;
            }
            $site = $findBySlug($slug);
            if ($site === null) {
                return $none;
            }
            return ['site' => $site, 'base_path' => '/' . $site['slug'], 'is_custom_domain' => false];
        }

        // {slug}.MAIN_HOST: derived from the slug, nothing to configure.
        $subSlug = self::subdomainSlug($h, $main);
        if ($subSlug !== null) {
            $site = $findBySlug($subSlug);
            return $site === null ? $none : ['site' => $site, 'base_path' => '', 'is_custom_domain' => true];
        }

        // DreamHost's "Add WWW" option can serve the site as www.<domain>;
        // treat that as the same site.
        $site = $findByDomain($h);
        if ($site === null && strpos($h, 'www.') === 0) {
            $site = $findByDomain(substr($h, 4));
        }
        if ($site !== null) {
            return ['site' => $site, 'base_path' => '', 'is_custom_domain' => true];
        }

        // An unknown hostname pointed at this directory: fall back to the
        // main-host path form so /{slug}/ still works there.
        if ($slug !== '') {
            $site = $findBySlug($slug);
            if ($site !== null) {
                return ['site' => $site, 'base_path' => '/' . $site['slug'], 'is_custom_domain' => false];
            }
        }
        return $none;
    }

    /**
     * The page path within the resolved site for the live request. On the
     * main host the rewrite already split /{slug}/{path}; on a site's own
     * hostname the ?site= segment was really the first path segment.
     */
    public static function pathFromRequest(array $resolved): string {
        $path = (string)($_GET['path'] ?? '');
        $first = (string)($_GET['site'] ?? '');
        if ($resolved['is_custom_domain'] && $first !== '') {
            $path = $first . '/' . $path;
        }
        return $path;
    }

    /** Resolve from the live request: ?site= (set by the rewrite) or the hostname. */
    public static function resolveFromRequest(): array {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $cached = self::resolve(
            request_host(),
            (string)($_GET['site'] ?? ''),
            [SiteManagement::class, 'findBySlug'],
            [SiteManagement::class, 'findByDomain']
        );
        return $cached;
    }

    /** True when the current request's host is the site's own subdomain or custom domain. */
    public static function requestIsOnDomainOf(array $site): bool {
        $h = request_host();
        $sub = self::subdomainHostFor($site);
        if ($sub !== '' && $h === $sub) {
            return true;
        }
        $domain = (string)($site['domain'] ?? '');
        return $domain !== '' && ($h === $domain || $h === 'www.' . $domain);
    }

    /** True when the request's hostname is the main (admin) site or unknown. */
    public static function requestIsOnMainHost(): bool {
        $h = request_host();
        $main = self::mainHost();
        return $main === '' || $h === $main || $h === 'www.' . $main || $h === 'localhost' || $h === '127.0.0.1';
    }

    /**
     * Base path for a site as seen from the current request: '' when we are on
     * its own hostname, else the /{slug} path form (which works on every host,
     * including localhost).
     */
    public static function basePathFor(array $site): string {
        return self::requestIsOnDomainOf($site) ? '' : '/' . $site['slug'];
    }

    /** The site's homepage as a root-relative URL usable from the current host. */
    public static function publicHomeUrl(array $site): string {
        return self::basePathFor($site) . '/';
    }

    /**
     * The site's canonical absolute homepage: its custom domain when set, else
     * its subdomain, else (no usable main host, e.g. local development) the
     * path form on the main site.
     */
    public static function canonicalHomeUrl(array $site): string {
        $domain = (string)($site['domain'] ?? '');
        if ($domain !== '') {
            return 'https://' . $domain . '/';
        }
        $sub = self::subdomainHostFor($site);
        if ($sub !== '') {
            return 'https://' . $sub . '/';
        }
        require_once __DIR__ . '/../settings.php';
        return Settings::siteBaseUrl() . '/' . $site['slug'] . '/';
    }

    /**
     * Absolute URL for a node of a site's tree, for emails and other places
     * with no request context. Built on canonicalHomeUrl(); $preferSubdomain
     * picks {slug}.MAIN_HOST over a custom domain when both exist, because the
     * subdomain shares the main host's login cookie while a custom domain has
     * its own, so a signed-in owner following the link stays signed in.
     */
    public static function absoluteUrlFor(array $site, ?string $categorySlug = null, ?string $subcategorySlug = null, bool $preferSubdomain = false): string {
        $home = self::canonicalHomeUrl($site);
        if ($preferSubdomain) {
            $sub = self::subdomainHostFor($site);
            if ($sub !== '') {
                $home = 'https://' . $sub . '/';
            }
        }
        return rtrim($home, '/') . self::urlFor('', $categorySlug, $subcategorySlug);
    }

    /**
     * Where a request on a former main hostname (LEGACY_HOSTS) should be sent:
     * the same path and query on the current main host. Null when the host is
     * not a legacy one (or nothing is configured), so the request proceeds.
     *
     * @param string[] $legacyHosts
     */
    public static function legacyRedirectTarget(string $host, string $uri, array $legacyHosts, string $mainHost): ?string {
        $h = self::normalizeHost($host);
        $main = strtolower(trim($mainHost));
        if ($h === '' || $main === '' || $h === $main) {
            return null;
        }
        foreach ($legacyHosts as $legacy) {
            $l = self::normalizeHost((string)$legacy);
            if ($l !== '' && ($h === $l || $h === 'www.' . $l)) {
                $path = $uri === '' || $uri[0] !== '/' ? '/' . $uri : $uri;
                return 'https://' . $main . $path;
            }
        }
        return null;
    }

    /**
     * Public URL for a node of the tree, relative to the site's base path.
     * Pass slugs, not rows: urlFor($base, 'us-history', 'presidents').
     */
    public static function urlFor(string $basePath, ?string $categorySlug = null, ?string $subcategorySlug = null): string {
        $url = $basePath . '/';
        if ($categorySlug !== null && $categorySlug !== '') {
            $url .= rawurlencode($categorySlug) . '/';
            if ($subcategorySlug !== null && $subcategorySlug !== '') {
                $url .= rawurlencode($subcategorySlug) . '/';
            }
        }
        return $url;
    }

    /**
     * Split the rewritten ?path= ("us-history/presidents/") into up to two
     * slugs. Returns null when there are more segments than the tree has levels
     * or a segment is not a valid slug, so the router can 404.
     *
     * @return ?array{0:?string,1:?string}
     */
    public static function splitPath(string $path): ?array {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn($s) => $s !== ''));
        if (count($segments) > 2) {
            return null;
        }
        foreach ($segments as $s) {
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $s) !== 1) {
                return null;
            }
        }
        return [$segments[0] ?? null, $segments[1] ?? null];
    }
}
