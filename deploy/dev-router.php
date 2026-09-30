<?php
// Router for PHP's built-in server, mirroring www/.htaccess so pretty URLs
// work locally:
//   php -S localhost:8080 -t www deploy/dev-router.php
// Real files/directories are served as-is; everything else goes to
// public_site.php with the same query parameters the rewrite rules set
// (/{slug}/{category}/{subcategory}/ -> ?site={slug}&path=...).

$root = __DIR__ . '/../www';
$uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = ltrim($uri, '/');

if (preg_match('#^(logs|db_migrations|lib)(/|$)#', $path) === 1) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

$file = realpath($root . '/' . $path);
if ($path !== '' && $file !== false && strpos($file, realpath($root)) === 0) {
    if (is_file($file)) {
        return false;                       // let the built-in server serve it
    }
    if (is_dir($file) && is_file($file . '/index.php')) {
        if (substr($uri, -1) !== '/') {
            header('Location: ' . $uri . '/');
            return true;
        }
        $_SERVER['SCRIPT_NAME'] = '/' . rtrim($path, '/') . '/index.php';
        require $file . '/index.php';
        return true;
    }
}

if ($path === '') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    require $root . '/index.php';
    return true;
}

if (preg_match('#^([a-z0-9-]+)/?(.*)$#', $path, $m) === 1) {
    $_GET['site'] = $m[1];
    $_GET['path'] = $m[2];
} else {
    $_GET['path'] = $path;
}
$_SERVER['SCRIPT_NAME'] = '/public_site.php';
require $root . '/public_site.php';
return true;
