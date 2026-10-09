<?php

/**
 * Router for PHP's built-in development server:
 *   php -S 127.0.0.1:8080 -t public public/router.php
 *   (или `bin/bas-ip serve`)
 *
 * Отдаёт существующие статические файлы напрямую, всё остальное — во
 * фронт-контроллер. Только для разработки: в production используйте nginx/Apache.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    // Никогда не отдаём PHP-исходники как статику.
    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php' && basename($file) !== 'index.php') {
        return false;
    }
    if (str_ends_with($path, '.php') && $path !== '/index.php') {
        return false;
    }

    return false;   // пусть встроенный сервер отдаст файл сам
}

require __DIR__ . '/index.php';
