<?php
// Settings management for the Flashcards application
require_once __DIR__ . '/config.php';

class Settings {
    private static function pdo(): PDO {
        return pdo();
    }

    public static function get(string $key, string $default = ''): string {
        $st = self::pdo()->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
        $st->execute([$key]);
        $row = $st->fetch();
        return $row ? (string)$row['value'] : $default;
    }

    public static function set(string $key, string $value): bool {
        $st = self::pdo()->prepare(
            'INSERT INTO settings (key_name, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        return $st->execute([$key, $value]);
    }

    public static function siteTitle(): string {
        return self::get('site_title', APP_NAME);
    }

    public static function timezone(): string {
        return self::get('timezone', date_default_timezone_get());
    }

    /**
     * Absolute URL of the main (admin) site, without a trailing slash. Used for
     * links in emails so activation/reset links always point at the main host
     * no matter which domain (or CLI) triggered the email. Falls back to the
     * current request's host when the setting is empty.
     */
    public static function siteBaseUrl(): string {
        $configured = rtrim(self::get('site_base_url', ''), '/');
        if ($configured !== '') {
            return $configured;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }
}
