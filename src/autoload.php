<?php

/**
 * PSR-4 autoloader for the `BasIp\` namespace.
 *
 * The application has **zero runtime Composer dependencies**, so it also runs
 * on shared hosting where `composer install` is not an option. When a Composer
 * autoloader *is* present it is used in addition (dev tools, optional packages).
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix  = 'BasIp\\';
    $baseDir = __DIR__ . '/BasIp/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$composer = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composer)) {
    require_once $composer;
}
