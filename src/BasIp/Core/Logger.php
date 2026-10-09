<?php

declare(strict_types=1);

namespace BasIp\Core;

use BasIp\Support\Arr;
use BasIp\Support\Fs;
use Throwable;

/**
 * Structured, channel-based logger.
 *
 * Channels: app, audit, gate, device, notify, error, access, update.
 * Each channel writes to its own daily-rotated file in `storage/logs`.
 * Records are JSON by default so they can be shipped to Loki/ELK untouched.
 *
 * Sensitive keys are redacted recursively before anything touches the disk.
 */
final class Logger
{
    public const DEBUG     = 'debug';
    public const INFO      = 'info';
    public const NOTICE    = 'notice';
    public const WARNING   = 'warning';
    public const ERROR     = 'error';
    public const CRITICAL  = 'critical';
    public const ALERT     = 'alert';
    public const EMERGENCY = 'emergency';

    private const LEVELS = [
        self::DEBUG => 0, self::INFO => 1, self::NOTICE => 2, self::WARNING => 3,
        self::ERROR => 4, self::CRITICAL => 5, self::ALERT => 6, self::EMERGENCY => 7,
    ];

    /** @var array<string, resource>|array<string, null> */
    private array $handles = [];

    /** @var array<string, mixed> */
    private array $context = [];

    /** @var array<int, callable> */
    private array $processors = [];

    private ?string $requestId = null;

    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private string $defaultChannel = 'app',
    ) {
    }

    public function channel(string $channel): self
    {
        $clone = clone $this;
        $clone->defaultChannel = $channel;

        return $clone;
    }

    /** @param array<string, mixed> $context */
    public function withContext(array $context): self
    {
        $clone = clone $this;
        $clone->context = array_merge($this->context, $context);

        return $clone;
    }

    public function withRequestId(string $id): self
    {
        $clone = clone $this;
        $clone->requestId = $id;

        return $clone;
    }

    public function pushProcessor(callable $processor): void
    {
        $this->processors[] = $processor;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);
        if (!isset(self::LEVELS[$level])) {
            $level = self::INFO;
        }

        $channel = $this->resolveChannel($context);
        unset($context['channel']);
        $minLevel = $this->minLevel($channel);
        if (self::LEVELS[$level] < $minLevel) {
            return;
        }

        $record = [
            'ts'         => $this->clock->format('Y-m-d\TH:i:s.vP'),
            'level'      => $level,
            'channel'    => $channel,
            'message'    => $this->interpolate($message, $context),
            'request_id' => $this->requestId ?? ($context['request_id'] ?? null),
            'context'    => $this->redact(Arr::mergeDeep($this->context, $context)),
        ];

        foreach ($this->processors as $processor) {
            $record = $processor($record) ?? $record;
        }

        $this->write($channel, $record, $level);
    }

    public function debug(string $m, array $c = []): void     { $this->log(self::DEBUG, $m, $c); }
    public function info(string $m, array $c = []): void      { $this->log(self::INFO, $m, $c); }
    public function notice(string $m, array $c = []): void    { $this->log(self::NOTICE, $m, $c); }
    public function warning(string $m, array $c = []): void   { $this->log(self::WARNING, $m, $c); }
    public function error(string $m, array $c = []): void     { $this->log(self::ERROR, $m, $c); }
    public function critical(string $m, array $c = []): void  { $this->log(self::CRITICAL, $m, $c); }
    public function alert(string $m, array $c = []): void     { $this->log(self::ALERT, $m, $c); }
    public function emergency(string $m, array $c = []): void { $this->log(self::EMERGENCY, $m, $c); }

    public function exception(Throwable $e, array $context = []): void
    {
        $this->log(self::ERROR, $e->getMessage(), $context + [
            'exception' => $e::class,
            'file'      => Fs::relative($e->getFile(), $this->config->root()),
            'line'      => $e->getLine(),
            'trace'     => $this->config->bool('app.debug')
                ? array_slice(explode("\n", $e->getTraceAsString()), 0, 25)
                : null,
            'previous'  => $e->getPrevious()?->getMessage(),
        ]);
    }

    /** @return array<int, array<string, mixed>> Newest first. */
    public function tail(string $channel, int $lines = 200, ?string $levelFilter = null, ?string $search = null): array
    {
        $files = $this->filesFor($channel);
        $out = [];
        foreach ($files as $file) {
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            $buffer = [];
            while (($line = fgets($handle)) !== false) {
                $buffer[] = $line;
                if (count($buffer) > $lines * 3) {
                    array_shift($buffer);
                }
            }
            fclose($handle);
            foreach (array_reverse($buffer) as $raw) {
                $record = json_decode(trim($raw), true);
                if (!is_array($record)) {
                    $record = ['message' => trim($raw), 'level' => 'info', 'ts' => null, 'channel' => $channel, 'context' => []];
                }
                if ($levelFilter !== null && ($record['level'] ?? '') !== $levelFilter) {
                    continue;
                }
                if ($search !== null && stripos(json_encode($record, JSON_UNESCAPED_UNICODE) ?: '', $search) === false) {
                    continue;
                }
                $out[] = $record;
                if (count($out) >= $lines) {
                    break 2;
                }
            }
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function availableFiles(): array
    {
        $dir  = $this->config->getPath('logs');
        $out  = [];
        foreach (Fs::glob($dir, '*.log') as $file) {
            $out[] = [
                'name'  => basename($file),
                'path'  => $file,
                'size'  => (int) @filesize($file),
                'mtime' => (int) @filemtime($file),
                'lines' => $this->countLines($file),
            ];
        }
        usort($out, static fn ($a, $b) => $b['mtime'] <=> $a['mtime']);

        return $out;
    }

    public function truncate(string $channel): int
    {
        $count = 0;
        foreach ($this->filesFor($channel) as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }
        unset($this->handles[$channel]);

        return $count;
    }

    public function close(): void
    {
        foreach ($this->handles as $handle) {
            if (is_resource($handle)) {
                @fclose($handle);
            }
        }
        $this->handles = [];
    }

    /* ───────────────────────────── internals ───────────────────────────── */

    /** @param array<string, mixed> $record */
    private function write(string $channel, array $record, string $level): void
    {
        $format = $this->config->string('logging.format', 'json');
        $line = $format === 'json'
            ? json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            : $this->formatLine($record);
        if ($line === false) {
            $line = json_encode(['level' => $level, 'message' => $record['message'] ?? '']);
        }

        $handle = $this->handle($channel);
        if ($handle !== null) {
            @fwrite($handle, $line . PHP_EOL);
        }

        // Fatal problems always land in error.log too, and on stderr in CLI/dev.
        if ($channel !== 'error' && self::LEVELS[$level] >= self::LEVELS[self::ERROR]) {
            $errHandle = $this->handle('error');
            if ($errHandle !== null) {
                @fwrite($errHandle, $line . PHP_EOL);
            }
        }
        // Mirror to stderr when explicitly enabled, or by default in a CLI
        // debug session. An explicit `false` always wins — the test-suite and
        // cron jobs rely on clean output.
        $stderr = $this->config->get('logging.stderr');
        $mirror = $stderr === true || ($stderr === null && PHP_SAPI === 'cli' && $this->config->bool('app.debug'));
        if ($mirror) {
            fwrite(STDERR, $line . PHP_EOL);
        }
    }

    private function formatLine(array $record): string
    {
        $ctx = $record['context'] ?? [];
        unset($record['context']);
        $suffix = $ctx === [] ? '' : ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return sprintf('[%s] %s.%s: %s%s', $record['ts'] ?? '-', strtoupper((string) ($record['level'] ?? 'INFO')), $record['channel'] ?? '-', $record['message'] ?? '', (string) $suffix);
    }

    /** @return resource|null */
    private function handle(string $channel)
    {
        if (array_key_exists($channel, $this->handles) && is_resource($this->handles[$channel])) {
            return $this->handles[$channel];
        }

        $dir  = $this->config->getPath('logs');
        try {
            Fs::ensureDir($dir);
        } catch (Throwable) {
            return $this->handles[$channel] = null;
        }

        $file = $this->fileFor($channel);
        $handle = @fopen($file, 'ab');
        if ($handle === false) {
            return $this->handles[$channel] = null;
        }
        @chmod($file, 0640);
        $this->handles[$channel] = $handle;
        $this->rotateIfNeeded($channel, $file);

        return $handle;
    }

    private function fileFor(string $channel): string
    {
        $base = $this->config->string('logging.channels.' . $channel . '.file', $channel . '.log');
        $base = preg_replace('/\.log$/', '', $base) ?? $base;
        $date = $this->config->bool('logging.daily', true) ? '-' . $this->clock->format('Y-m-d') : '';

        return $this->config->getPath('logs') . '/' . $base . $date . '.log';
    }

    /** @return array<int, string> */
    private function filesFor(string $channel): array
    {
        $base = $this->config->string('logging.channels.' . $channel . '.file', $channel . '.log');
        $base = preg_replace('/\.log$/', '', $base) ?? $base;
        $files = Fs::glob($this->config->getPath('logs'), $base . '*.log');
        usort($files, static fn ($a, $b) => strcmp($b, $a));

        return $files;
    }

    private function rotateIfNeeded(string $channel, string $file): void
    {
        $maxMb   = $this->config->int('logging.max_size_mb', 20);
        $maxFiles = $this->config->int('logging.max_files', 30);
        if ($maxMb > 0 && is_file($file) && (int) @filesize($file) > $maxMb * 1024 * 1024) {
            $rotated = preg_replace('/\.log$/', '-' . $this->clock->format('His') . '.log', $file) ?? $file . '.1';
            @rename($file, $rotated);
            $handle = @fopen($file, 'ab');
            if (is_resource($handle)) {
                if (is_resource($this->handles[$channel] ?? null)) {
                    @fclose($this->handles[$channel]);
                }
                $this->handles[$channel] = $handle;
            }
        }
        if ($maxFiles > 0) {
            $base = $this->config->string('logging.channels.' . $channel . '.file', $channel . '.log');
            $base = preg_replace('/\.log$/', '', $base) ?? $base;
            Fs::rotate($this->config->getPath('logs'), $base, $maxFiles, '.log');
        }
    }

    private function minLevel(string $channel): int
    {
        $level = $this->config->get('logging.channels.' . $channel . '.level')
            ?? $this->config->string('logging.level', 'info');
        $level = strtolower((string) $level);

        return self::LEVELS[$level] ?? 1;
    }

    /** @param array<string, mixed> $context */
    private function resolveChannel(array $context): string
    {
        if (isset($context['channel']) && is_string($context['channel'])) {
            return $context['channel'];
        }

        return $this->defaultChannel;
    }

    /** @param array<string, mixed> $array @return array<string, mixed> */
    private function redact(array $array): array
    {
        $needles = (array) $this->config->get('logging.redact', []);

        return $needles === [] ? $array : Arr::redact($array, $needles);
    }

    /** @param array<string, mixed> $context */
    private function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        return (string) preg_replace_callback('/\{([a-zA-Z0-9_.]+)\}/', static function (array $m) use ($context): string {
            $value = Arr::get($context, $m[1]);

            return is_scalar($value) || $value === null ? (string) $value : $m[0];
        }, $message);
    }

    private function countLines(string $file): int
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return 0;
        }
        $lines = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false) {
                break;
            }
            $lines += substr_count($chunk, "\n");
        }
        fclose($handle);

        return $lines;
    }
}
