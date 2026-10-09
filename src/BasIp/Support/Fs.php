<?php

declare(strict_types=1);

namespace BasIp\Support;

use RuntimeException;

/**
 * Filesystem helpers with sane permissions and atomic writes.
 */
final class Fs
{
    public static function ensureDir(string $path, int $mode = 0770): string
    {
        if (!is_dir($path)) {
            if (!@mkdir($path, $mode, true) && !is_dir($path)) {
                throw new RuntimeException(sprintf('Unable to create directory "%s"', $path));
            }
            @chmod($path, $mode);
        }

        return $path;
    }

    /** Atomic write: temp file in the same directory + rename. */
    public static function put(string $path, string $contents, int $mode = 0640): int
    {
        $dir = dirname($path);
        self::ensureDir($dir);

        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $bytes = @file_put_contents($tmp, $contents, LOCK_EX);
        if ($bytes === false) {
            @unlink($tmp);

            throw new RuntimeException(sprintf('Unable to write "%s"', $path));
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException(sprintf('Unable to move temp file into place for "%s"', $path));
        }

        return $bytes;
    }

    public static function get(string $path, string $default = ''): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return $default;
        }

        return (string) file_get_contents($path);
    }

    public static function exists(string $path): bool
    {
        return file_exists($path);
    }

    public static function delete(string $path): bool
    {
        if (is_dir($path) && !is_link($path)) {
            return self::deleteDir($path);
        }
        if (file_exists($path) || is_link($path)) {
            return @unlink($path);
        }

        return true;
    }

    public static function deleteDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        return @rmdir($dir);
    }

    public static function copy(string $from, string $to): bool
    {
        self::ensureDir(dirname($to));

        return @copy($from, $to);
    }

    public static function copyDir(string $from, string $to, array $skip = []): int
    {
        if (!is_dir($from)) {
            throw new RuntimeException("Source directory not found: $from");
        }
        self::ensureDir($to);
        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($from) + 1);
            foreach ($skip as $needle) {
                if ($needle !== '' && (str_starts_with($relative, $needle) || $relative === trim($needle, '/'))) {
                    continue 2;
                }
            }
            $target = $to . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                self::ensureDir($target);
                continue;
            }
            if (@copy($item->getPathname(), $target)) {
                @chmod($target, 0640);
                $count++;
            }
        }

        return $count;
    }

    public static function size(string $path): int
    {
        if (is_file($path)) {
            return (int) @filesize($path);
        }
        if (!is_dir($path)) {
            return 0;
        }
        $total = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $item) {
            if ($item->isFile()) {
                $total += (int) $item->getSize();
            }
        }

        return $total;
    }

    public static function isWritable(string $path): bool
    {
        if (file_exists($path)) {
            return is_writable($path);
        }

        return is_writable(dirname($path));
    }

    /**
     * Recursive glob.
     *
     * @return array<int, string>
     */
    public static function glob(string $dir, string $pattern = '*'): array
    {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        foreach ((array) glob(rtrim($dir, '/') . '/' . $pattern) ?: [] as $file) {
            $out[] = $file;
        }

        return $out;
    }

    /** Rotate files matching a prefix, keeping the newest `$keep`. */
    public static function rotate(string $dir, string $prefix, int $keep, string $suffix = ''): int
    {
        $files = self::glob($dir, $prefix . '*' . $suffix);
        usort($files, static fn ($a, $b) => (int) filemtime($b) <=> (int) filemtime($a));
        $removed = 0;
        foreach (array_slice($files, max(0, $keep)) as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /** Human friendly relative path from the project root. */
    public static function relative(string $path, string $root): string
    {
        $root = rtrim($root, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
