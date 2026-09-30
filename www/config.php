<?php
// Main configuration for the Flashcards application (flashcards.brianrosenthal.org)
require_once __DIR__ . '/config.local.php';
require_once __DIR__ . '/lib/UserContext.php';

// The hostname this request is really for. Once a dedicated domain exists,
// subdomain pages ({slug}.MAIN_HOST) may reach this server through a proxy
// (e.g. a Cloudflare Worker) that fetches the main host and passes the
// visitor's hostname in X-Forwarded-Host together with a shared secret
// (SUBDOMAIN_PROXY_KEY); the header is trusted only when the secret matches.
// Lowercase, no port.
function request_host(): string {
    static $host = null;
    if ($host !== null) {
        return $host;
    }
    $raw = (string)($_SERVER['HTTP_HOST'] ?? '');
    $key = defined('SUBDOMAIN_PROXY_KEY') ? (string)SUBDOMAIN_PROXY_KEY : '';
    $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');
    if ($key !== '' && $forwarded !== '' && hash_equals($key, (string)($_SERVER['HTTP_X_SITE_PROXY_KEY'] ?? ''))) {
        $raw = $forwarded;
    }
    $host = explode(':', strtolower(trim($raw)), 2)[0];
    return $host;
}

// Cookie Domain attribute for this request: the main host (so a login on
// MAIN_HOST is shared with every {slug}.MAIN_HOST) when the request is on it
// or one of its subdomains, else '' (host-only cookie — a custom domain must
// not be given a Domain the browser would reject).
function cookie_domain(): string {
    $main = defined('MAIN_HOST') ? strtolower(trim((string)MAIN_HOST)) : '';
    if ($main === '' || $main === 'localhost' || $main === '127.0.0.1') {
        return '';
    }
    $h = request_host();
    $suffix = '.' . $main;
    $isSub = strlen($h) > strlen($suffix) && substr($h, -strlen($suffix)) === $suffix;
    return ($h === $main || $isSub) ? $main : '';
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    if (PHP_SAPI !== 'cli') {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => cookie_domain(),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

// Bootstrap UserContext from session
UserContext::bootstrapFromSession();

// Database connection. Unit tests inject a connection to a test database via
// set_pdo_for_testing(); everything (including the lib classes) flows through here.
function set_pdo_for_testing(?PDO $override): void {
    $GLOBALS['__pdo_override_for_testing'] = $override;
}

function pdo(): PDO {
    if (!empty($GLOBALS['__pdo_override_for_testing'])) {
        return $GLOBALS['__pdo_override_for_testing'];
    }
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}

// CSRF token functions
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void {
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('CSRF token mismatch');
    }
}

// Remember me token functions
function create_remember_token(int $userId, string $passwordHash): ?string {
    if (!defined('REMEMBER_TOKEN_KEY') || REMEMBER_TOKEN_KEY === '') {
        return null;
    }

    $payload = $userId . ':' . substr($passwordHash, 0, 20);
    $signature = hash_hmac('sha256', $payload, REMEMBER_TOKEN_KEY);
    return base64_encode($payload . ':' . $signature);
}

function verify_remember_token(string $token): ?int {
    if (!defined('REMEMBER_TOKEN_KEY') || REMEMBER_TOKEN_KEY === '') {
        return null;
    }

    $decoded = base64_decode($token, true);
    if ($decoded === false) return null;

    $parts = explode(':', $decoded);
    if (count($parts) !== 3) return null;

    [$userId, $hashPrefix, $signature] = $parts;
    $payload = $userId . ':' . $hashPrefix;
    $expectedSignature = hash_hmac('sha256', $payload, REMEMBER_TOKEN_KEY);

    if (!hash_equals($expectedSignature, $signature)) {
        return null;
    }

    // Verify user exists and hash prefix matches
    require_once __DIR__ . '/lib/UserManagement.php';
    $row = UserManagement::findById((int)$userId);

    if (!$row || substr((string)$row['password_hash'], 0, 20) !== $hashPrefix) {
        return null;
    }

    return (int)$userId;
}

// Validate a user-supplied post-login redirect target: must be a relative path
// (leading single slash) and not point back at the login page. Returns '' if invalid.
function validate_relative_next_path($raw): string {
    if (!is_string($raw)) return '';
    $n = trim($raw);
    if ($n === '' || $n[0] !== '/' || strpos($n, '//') === 0) return '';
    if (strpos($n, '/login.php') === 0) return '';
    return $n;
}

// Establish the session for a freshly authenticated user (login, or auto-login
// after initial password setup). Also sets the remember-me cookie unless the
// user marked this as a public computer.
function establish_login_session(array $user, bool $isSuper = false, bool $publicComputer = false): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    $_SESSION['is_admin'] = !empty($user['is_admin']) ? 1 : 0;
    $_SESSION['is_super'] = $isSuper ? 1 : 0;
    $_SESSION['last_activity'] = time();
    $_SESSION['public_computer'] = $publicComputer ? 1 : 0;
    UserContext::set(new UserContext((int)$user['id'], !empty($user['is_admin']), $isSuper));

    if (!$publicComputer) {
        $rememberToken = create_remember_token((int)$user['id'], (string)$user['password_hash']);
        if ($rememberToken) {
            $expireTime = time() + (10 * 365 * 24 * 60 * 60); // 10 years
            setcookie('remember_token', $rememberToken, $expireTime, '/', cookie_domain(), true, true);
        }
    }
}

// Current user functions
function current_user(): ?array {
    require_once __DIR__ . '/lib/UserManagement.php';

    if (!empty($_SESSION['uid'])) {
        static $user = null;
        if ($user === null) {
            $user = UserManagement::findById((int)$_SESSION['uid']) ?: false;
        }
        return $user ?: null;
    }

    // Check remember me token
    if (!empty($_COOKIE['remember_token'])) {
        $userId = verify_remember_token($_COOKIE['remember_token']);
        if ($userId) {
            $user = UserManagement::findById($userId);
            if ($user && !empty($user['email_verified_at'])) {
                // Auto-login from remember token
                session_regenerate_id(true);
                $_SESSION['uid'] = $user['id'];
                $_SESSION['is_admin'] = !empty($user['is_admin']) ? 1 : 0;
                $_SESSION['last_activity'] = time();
                UserContext::set(new UserContext((int)$user['id'], !empty($user['is_admin'])));
                return $user;
            }
        }
        // Invalid token, clear it
        setcookie('remember_token', '', time() - 3600, '/', cookie_domain(), true, true);
    }

    return null;
}

function require_login(): void {
    if (!current_user()) {
        $next = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: /login.php' . ($next ? '?next=' . urlencode($next) : ''));
        exit;
    }
}

function require_admin(): void {
    $user = current_user();
    if (!$user || empty($user['is_admin'])) {
        http_response_code(403);
        die('Admin access required');
    }
}
