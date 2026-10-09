<?php

/**
 * BAS-IP Gate Manager — single front controller.
 *
 * Point the web server's document root at this `public/` directory. Everything
 * else (config, storage, src, resources) stays outside the web root, which is
 * the single most important hardening step for this kind of app.
 *
 * @see deploy/nginx/bas-ip.conf
 * @see deploy/apache/bas-ip.conf
 */

declare(strict_types=1);

// Fail loudly during development, never leak in production.
define('BASIP_START', microtime(true));

$root = dirname(__DIR__);

/** @var BasIp\Core\App $app */
$app = require $root . '/src/bootstrap.php';

$config = $app->config();

if ($config->bool('app.debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

// A friendly message beats a white screen when the app has never been installed.
if (!$config->hasLocalFile() && !is_file(__DIR__ . '/install.lock')) {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $allowed = ['/install', '/health', '/assets', '/favicon.svg', '/robots.txt'];
    $isAllowed = false;
    foreach ($allowed as $prefix) {
        if (str_starts_with($path, $prefix)) {
            $isAllowed = true;
            break;
        }
    }
    if (!$isAllowed) {
        header('Location: ' . rtrim(\BasIp\Core\Request::detectBasePath($_SERVER), '/') . '/install', true, 302);
        exit;
    }
}

$app->run();
