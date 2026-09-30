<?php
declare(strict_types=1);

/**
 * URL slugs for sites, categories and subcategories. Pure functions,
 * no database: uniqueness within a parent is the caller's job (see
 * makeUnique()).
 */
final class Slugger {

    /**
     * Slugs that would collide with real paths on the main host (a category
     * called "admin" would shadow /admin/) or read as system paths on a custom
     * domain, plus hostnames that must not become a site's subdomain (a site
     * slug may also be served at {slug}.MAIN_HOST, so "www" or "mail"
     * would shadow the domain's own records). Checked case-insensitively;
     * isReserved() also refuses anything that exists as a file or directory
     * in the web root.
     */
    public const RESERVED = [
        'admin', 'manage', 'profile', 'site', 'logs', 'db_migrations', 'lib',
        'assets', 'login', 'logout', 'index', 'public_site', 'api', 'static',
        'cgi-bin', 'stats', 'failed_auth',
        'review', 'quiz', 'progress', 'cards', 'images', 'flashcards', 'study',
        'www', 'mail', 'smtp', 'imap', 'pop', 'pop3', 'ftp', 'webmail', 'ns1', 'ns2',
        'mysql', 'autoconfig', 'autodiscover', 'cdn', 'app', 'dev', 'test',
    ];

    public const MAX_LENGTH = 80;

    /** "Derivation of e^x!" -> "derivation-of-e-x". Empty input yields ''. */
    public static function fromText(string $text): string {
        $s = mb_strtolower(trim($text), 'UTF-8');
        // Strip accents: decompose (é -> e + combining acute) and drop the
        // combining marks. Falls back to iconv when intl is not installed.
        if (class_exists('Normalizer')) {
            $s = preg_replace('/\p{Mn}+/u', '', (string)Normalizer::normalize($s, Normalizer::FORM_D)) ?? $s;
        } else {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($ascii) && $ascii !== '') {
                $s = strtolower(str_replace(["'", '`', '^', '"', '~'], '', $ascii));
            }
        }
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        if (strlen($s) > self::MAX_LENGTH) {
            $s = rtrim(substr($s, 0, self::MAX_LENGTH), '-');
        }
        return $s;
    }

    /** Lowercase letters, digits and single hyphens between them; 1-80 chars. */
    public static function isValid(string $slug): bool {
        if ($slug === '' || strlen($slug) > self::MAX_LENGTH) {
            return false;
        }
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
    }

    /**
     * Whether a slug must not be used as a top-level URL segment. Only the
     * first path segment can collide with real files, but the same rule is
     * applied at every level so the tree stays predictable.
     */
    public static function isReserved(string $slug, ?string $webRoot = null): bool {
        $lower = strtolower($slug);
        if (in_array($lower, self::RESERVED, true)) {
            return true;
        }
        $root = $webRoot ?? dirname(__DIR__);
        return file_exists($root . '/' . $lower) || file_exists($root . '/' . $lower . '.php');
    }

    /**
     * Human-readable reason a slug is unusable, or null when it is fine.
     * Callers surface this text directly in form errors.
     */
    public static function problemWith(string $slug): ?string {
        if (!self::isValid($slug)) {
            return 'URL names may only contain lowercase letters, numbers and hyphens (e.g. "sequences-and-series").';
        }
        if (self::isReserved($slug)) {
            return 'The URL name "' . $slug . '" is reserved. Please choose another.';
        }
        return null;
    }

    /**
     * Append -2, -3, ... to $base until $exists(candidate) returns false.
     * $base must already be a valid slug.
     */
    public static function makeUnique(string $base, callable $exists): string {
        if (!$exists($base)) {
            return $base;
        }
        for ($n = 2; $n < 1000; $n++) {
            $suffix = '-' . $n;
            $candidate = rtrim(substr($base, 0, self::MAX_LENGTH - strlen($suffix)), '-') . $suffix;
            if (!$exists($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('Could not find an unused URL name for "' . $base . '".');
    }
}
