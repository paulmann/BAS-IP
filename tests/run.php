#!/usr/bin/env php
<?php

/**
 * Test runner — no PHPUnit required.
 *
 *   php tests/run.php                       # SQLite (fast, default)
 *   BASIP_TEST_DB=mysql php tests/run.php   # MySQL / MariaDB
 *   BASIP_TEST_DB=pgsql php tests/run.php   # PostgreSQL
 *   php tests/run.php --filter=Billing      # only matching classes
 *   php tests/run.php --verbose             # list every test method
 */

declare(strict_types=1);

use BasIp\Tests\Support\TestApp;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/Support/TestCase.php';
require_once __DIR__ . '/Support/TestApp.php';

// PSR-4 for the test namespace.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'BasIp\\Tests\\')) {
        return;
    }
    $relative = substr($class, strlen('BasIp\\Tests\\'));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$options = array_slice($argv, 1);
$filter = null;
$verbose = false;
foreach ($options as $option) {
    if (str_starts_with($option, '--filter=')) {
        $filter = substr($option, 9);
    } elseif ($option === '--verbose' || $option === '-v') {
        $verbose = true;
    }
}

$colors = getenv('NO_COLOR') === false && function_exists('stream_isatty') && @stream_isatty(STDOUT);
$paint = static function (string $text, string $color) use ($colors): string {
    if (!$colors) {
        return $text;
    }
    $map = ['red' => '0;31', 'green' => '0;32', 'yellow' => '0;33', 'cyan' => '0;36', 'dim' => '2', 'bold' => '1'];

    return "\033[" . ($map[$color] ?? '0') . 'm' . $text . "\033[0m";
};

$driver = getenv('BASIP_TEST_DB') ?: 'sqlite';
echo $paint("\n  BAS-IP Gate Manager · test suite\n", 'bold');
echo $paint('  driver: ' . $driver . ' · PHP ' . PHP_VERSION . "\n\n", 'dim');

// Boot once — every test shares the migrated database.
TestApp::boot();

$directories = [__DIR__ . '/Unit', __DIR__ . '/Feature'];
$files = [];
foreach ($directories as $directory) {
    foreach (glob($directory . '/*Test.php') ?: [] as $file) {
        $files[] = $file;
    }
}
sort($files);

$totalTests = 0;
$totalAssertions = 0;
$failed = [];
$started = microtime(true);

foreach ($files as $file) {
    require_once $file;
    $class = 'BasIp\\Tests\\' . str_replace('/', '\\', substr($file, strlen(__DIR__) + 1, -4));
    if (!class_exists($class)) {
        continue;
    }
    if ($filter !== null && stripos($class, $filter) === false) {
        continue;
    }

    /** @var BasIp\Tests\Support\TestCase $instance */
    $instance = new $class();
    $shortName = substr($class, strrpos($class, '\\') + 1);
    echo '  ' . str_pad($shortName, 34);

    $before = $instance->assertions;
    $ran = $instance->runTests();
    $totalTests += count($ran);
    $totalAssertions += $instance->assertions - $before;

    if ($instance->failures === []) {
        echo $paint(' ✓ ' . count($ran) . ' tests', 'green') . $paint(' (' . $instance->assertions . ' assertions)', 'dim') . "\n";
    } else {
        echo $paint(' ✗ ' . count($instance->failures) . ' failed', 'red') . "\n";
        foreach ($instance->failures as $failure) {
            $failed[] = $failure;
            echo '      ' . $paint('→ ' . $failure, 'red') . "\n";
        }
    }

    if ($verbose) {
        foreach ($ran as $method) {
            echo $paint('        · ' . $method, 'dim') . "\n";
        }
    }
}

$elapsed = (microtime(true) - $started) * 1000;
TestApp::shutdown();

echo "\n" . str_repeat('─', 60) . "\n";
if ($failed === []) {
    echo $paint(sprintf('  OK · %d tests · %d assertions · %.0f ms', $totalTests, $totalAssertions, $elapsed), 'green') . "\n\n";
    exit(0);
}

echo $paint(sprintf('  FAILED · %d of %d tests · %d assertions · %.0f ms', count($failed), $totalTests, $totalAssertions, $elapsed), 'red') . "\n";
foreach ($failed as $index => $failure) {
    echo $paint(sprintf('   %d) %s', $index + 1, $failure), 'red') . "\n";
}
echo "\n";
exit(1);
