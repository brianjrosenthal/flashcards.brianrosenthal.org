<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/Slugger.php';

/**
 * A user's public flashcards page: branding, the path slug (/{slug}/) and the
 * optional custom domain. One row per user. All SQL for the sites table lives
 * here.
 */
final class SiteManagement {

    /**
     * Colour schemes a site owner can pick from. Keys are stored in
     * sites.accent_color ('violet' is shown as Purple); ApplicationUI turns them
     * into CSS variables that colour the page.
     * @var array<string,array{label:string,color:string,dark:string,light:string,soft:string}>
     */
    public const ACCENTS = [
        'blue'   => ['label' => 'Blue',   'color' => '#2563EB', 'dark' => '#1D4ED8', 'light' => '#4F8DF9', 'soft' => '#DBEAFE'],
        'violet' => ['label' => 'Purple', 'color' => '#7C3AED', 'dark' => '#6528C9', 'light' => '#9D5CFF', 'soft' => '#EDE4FF'],
        'coral'  => ['label' => 'Coral',  'color' => '#E85454', 'dark' => '#C43E3E', 'light' => '#FF7A7A', 'soft' => '#FFE4E4'],
        'mint'   => ['label' => 'Mint',   'color' => '#05B888', 'dark' => '#04795A', 'light' => '#2ED3A6', 'soft' => '#D9F8EE'],
        'sunny'  => ['label' => 'Sunny',  'color' => '#D99A00', 'dark' => '#A9760A', 'light' => '#F2B824', 'soft' => '#FFF1CC'],
        'sky'    => ['label' => 'Sky',    'color' => '#0EA5E9', 'dark' => '#0369A1', 'light' => '#38BDF8', 'soft' => '#DDF3FD'],
        'slate'  => ['label' => 'Slate',  'color' => '#475569', 'dark' => '#334155', 'light' => '#64748B', 'soft' => '#E2E8F0'],
    ];

    private static function pdo(): PDO {
        return pdo();
    }

    private static function log(string $action, array $meta): void {
        try {
            ActivityLog::log(UserContext::getLoggedInUserContext(), $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }

    private static function assertAdmin(?UserContext $ctx): void {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
    }

    private static function assertOwnerOrAdmin(?UserContext $ctx, int $ownerUserId): void {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        if (!$ctx->admin && $ctx->id !== $ownerUserId) {
            throw new RuntimeException('You can only change your own site.');
        }
    }

    // ---- lookups ----------------------------------------------------------

    public static function findById(int $id): ?array {
        $st = self::pdo()->prepare('SELECT * FROM sites WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function findByUserId(int $userId): ?array {
        $st = self::pdo()->prepare('SELECT * FROM sites WHERE user_id = ? LIMIT 1');
        $st->execute([$userId]);
        return $st->fetch() ?: null;
    }

    public static function findBySlug(string $slug): ?array {
        $st = self::pdo()->prepare('SELECT * FROM sites WHERE slug = ? LIMIT 1');
        $st->execute([strtolower(trim($slug))]);
        return $st->fetch() ?: null;
    }

    public static function findByDomain(string $domain): ?array {
        $domain = self::normalizeDomain($domain);
        if ($domain === null) {
            return null;
        }
        $st = self::pdo()->prepare('SELECT * FROM sites WHERE domain = ? LIMIT 1');
        $st->execute([$domain]);
        return $st->fetch() ?: null;
    }

    /** Every site with its owner's name, for the admin list and the site switcher. */
    public static function listSites(): array {
        return self::pdo()->query(
            'SELECT s.*, u.first_name, u.last_name, u.email
             FROM sites s JOIN users u ON u.id = s.user_id
             ORDER BY u.first_name, u.last_name'
        )->fetchAll();
    }

    public static function slugExists(string $slug): bool {
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM sites WHERE slug = ?');
        $st->execute([$slug]);
        return (int)$st->fetchColumn() > 0;
    }

    // ---- writes -----------------------------------------------------------

    /**
     * Create the site for a user who has none. Admins can do this for anyone;
     * a user can do it for themselves. The slug is derived from $slugHint
     * (normally the first name) and de-duplicated.
     */
    public static function createForUser(?UserContext $ctx, int $userId, string $slugHint, string $title): int {
        self::assertOwnerOrAdmin($ctx, $userId);
        if (self::findByUserId($userId) !== null) {
            throw new RuntimeException('That user already has a site.');
        }
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('Site title is required.');
        }

        $base = Slugger::fromText($slugHint);
        if ($base === '' || Slugger::isReserved($base)) {
            $base = 'site-' . $userId;
        }
        $slug = Slugger::makeUnique($base, [self::class, 'slugExists']);

        $st = self::pdo()->prepare(
            'INSERT INTO sites (user_id, slug, title, tagline, accent_color)
             VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$userId, $slug, $title, '', 'violet']);
        $id = (int)self::pdo()->lastInsertId();
        self::log('site.create', ['site_id' => $id, 'user_id' => $userId, 'slug' => $slug]);
        return $id;
    }

    /**
     * Owner-editable fields: title, tagline, accent_color, is_public. Unknown
     * keys are ignored; validation errors throw InvalidArgumentException with
     * a message fit for the form.
     */
    public static function updateSiteContent(?UserContext $ctx, int $siteId, array $fields): void {
        $site = self::findById($siteId);
        if (!$site) {
            throw new RuntimeException('Site not found.');
        }
        self::assertOwnerOrAdmin($ctx, (int)$site['user_id']);

        $title = trim((string)($fields['title'] ?? $site['title']));
        if ($title === '') {
            throw new InvalidArgumentException('Site title is required.');
        }
        if (mb_strlen($title) > 150) {
            throw new InvalidArgumentException('Site title must be 150 characters or fewer.');
        }
        $tagline = trim((string)($fields['tagline'] ?? $site['tagline']));
        if (mb_strlen($tagline) > 255) {
            throw new InvalidArgumentException('Tagline must be 255 characters or fewer.');
        }
        $accent = (string)($fields['accent_color'] ?? $site['accent_color']);
        if (!isset(self::ACCENTS[$accent])) {
            throw new InvalidArgumentException('Unknown accent color.');
        }
        $isPublic = array_key_exists('is_public', $fields) ? ((int)(bool)$fields['is_public']) : (int)$site['is_public'];

        $st = self::pdo()->prepare(
            'UPDATE sites SET title = ?, tagline = ?, accent_color = ?, is_public = ? WHERE id = ?'
        );
        $st->execute([$title, $tagline, $accent, $isPublic, $siteId]);
        self::log('site.update', ['site_id' => $siteId, 'fields' => array_keys($fields)]);
    }

    /**
     * Admin-only routing fields: the path slug and the custom domain. The
     * domain is stored as a bare lowercase hostname; '' clears it.
     */
    public static function updateSiteRouting(?UserContext $ctx, int $siteId, string $slug, string $domain): void {
        self::assertAdmin($ctx);
        $site = self::findById($siteId);
        if (!$site) {
            throw new RuntimeException('Site not found.');
        }

        $slug = strtolower(trim($slug));
        if (($problem = Slugger::problemWith($slug)) !== null) {
            throw new InvalidArgumentException($problem);
        }
        if (strlen($slug) > 50) {
            throw new InvalidArgumentException('Site URL name must be 50 characters or fewer.');
        }
        $other = self::findBySlug($slug);
        if ($other && (int)$other['id'] !== $siteId) {
            throw new InvalidArgumentException('Another site already uses the URL name "' . $slug . '".');
        }

        $domainNorm = null;
        if (trim($domain) !== '') {
            $domainNorm = self::normalizeDomain($domain);
            if ($domainNorm === null) {
                throw new InvalidArgumentException('Domain must be a bare hostname like flashcards.NAME.org.');
            }
            $mainHost = defined('MAIN_HOST') ? strtolower(trim((string)MAIN_HOST)) : '';
            if ($domainNorm === $mainHost) {
                throw new InvalidArgumentException('That is the main site\'s hostname; a user site needs its own.');
            }
            if ($mainHost !== '' && substr($domainNorm, -strlen('.' . $mainHost)) === '.' . $mainHost) {
                throw new InvalidArgumentException('Subdomains of ' . $mainHost . ' are automatic: the site is already at ' . $slug . '.' . $mainHost . '. Use a custom domain only for a different domain name.');
            }
            $other = self::findByDomain($domainNorm);
            if ($other && (int)$other['id'] !== $siteId) {
                throw new InvalidArgumentException('Another site already uses the domain ' . $domainNorm . '.');
            }
        }

        $st = self::pdo()->prepare('UPDATE sites SET slug = ?, domain = ? WHERE id = ?');
        $st->execute([$slug, $domainNorm, $siteId]);
        self::log('site.update_routing', ['site_id' => $siteId, 'slug' => $slug, 'domain' => $domainNorm]);
    }

    /**
     * "https://Flashcards.CharlieRosenthal.org:443/" -> "flashcards.charlierosenthal.org".
     * Returns null when the input is not a plausible hostname.
     */
    public static function normalizeDomain(string $domain): ?string {
        $d = strtolower(trim($domain));
        if ($d === '') {
            return null;
        }
        $d = preg_replace('#^[a-z]+://#', '', $d) ?? $d;
        $d = explode('/', $d, 2)[0];
        $d = explode(':', $d, 2)[0];
        if ($d === '' || strlen($d) > 253) {
            return null;
        }
        if (preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $d) !== 1
            && $d !== 'localhost') {
            return null;
        }
        return $d;
    }
}
