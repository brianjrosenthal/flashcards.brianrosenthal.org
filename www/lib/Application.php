<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SiteResolver.php';

class Application {
    private static bool $initialized = false;

    public static function init(): void {
        if (self::$initialized) {
            return;
        }

        // A former main hostname (LEGACY_HOSTS)
        // sends the visitor to the same page on the current main host.
        if (PHP_SAPI !== 'cli') {
            $legacy = defined('LEGACY_HOSTS') && is_array(LEGACY_HOSTS) ? LEGACY_HOSTS : [];
            $target = SiteResolver::legacyRedirectTarget(request_host(), (string)($_SERVER['REQUEST_URI'] ?? '/'), $legacy, SiteResolver::mainHost());
            if ($target !== null) {
                header('Location: ' . $target, true, 301);
                exit;
            }
        }

        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // All "today" logic (due pills, email schedules) runs in the app's
        // timezone, not the server's (the CLI runner does the same).
        try {
            require_once __DIR__ . '/../settings.php';
            date_default_timezone_set(Settings::timezone());
        } catch (\Throwable $e) {
            // Settings table unavailable (e.g. mid-install): keep server default.
        }

        self::$initialized = true;
    }
}
