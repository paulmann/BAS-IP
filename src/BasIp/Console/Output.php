<?php

declare(strict_types=1);

namespace BasIp\Console;

use BasIp\Support\Str;

/**
 * Terminal output with ANSI colours, tables, progress and prompts.
 *
 * Colours are disabled automatically when stdout is not a TTY or when
 * `NO_COLOR` is set, so piping to a file gives clean text.
 */
final class Output
{
    private bool $colors;
    private bool $quiet = false;
    private bool $verbose = false;

    /** @var array<int, string> */
    private array $buffer = [];

    private bool $capturing = false;

    public function __construct(
        private $stdout = STDOUT,
        private $stderr = STDERR,
    ) {
        $this->colors = $this->detectColors();
    }

    private function detectColors(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }
        if (getenv('FORCE_COLOR') !== false) {
            return true;
        }
        if (PHP_SAPI !== 'cli') {
            return false;
        }
        if (function_exists('stream_isatty')) {
            return @stream_isatty($this->stdout);
        }

        return DIRECTORY_SEPARATOR === '\\' ? (getenv('ANSICON') !== false || getenv('ConEmuANSI') === 'ON') : true;
    }

    public function setQuiet(bool $quiet): void   { $this->quiet = $quiet; }
    public function setVerbose(bool $verbose): void { $this->verbose = $verbose; }
    public function isVerbose(): bool { return $this->verbose; }
    public function capture(bool $on): void { $this->capturing = $on; }

    /** @return array<int, string> */
    public function captured(): array { return $this->buffer; }

    public function write(string $text): void
    {
        if ($this->quiet) {
            return;
        }
        if ($this->capturing) {
            $this->buffer[] = $text;
        }
        fwrite($this->stdout, $this->colorize($text));
    }

    public function line(string $text = ''): void
    {
        $this->write($text . PHP_EOL);
    }

    public function error(string $text): void
    {
        fwrite($this->stderr, $this->colorize('<red>✖ ' . $text . '</red>') . PHP_EOL);
    }

    public function warn(string $text): void
    {
        $this->line('<yellow>⚠ ' . $text . '</yellow>');
    }

    public function success(string $text): void
    {
        $this->line('<green>✔ ' . $text . '</green>');
    }

    public function info(string $text): void
    {
        $this->line('<cyan>ℹ ' . $text . '</cyan>');
    }

    public function debug(string $text): void
    {
        if ($this->verbose) {
            $this->line('<dim>' . $text . '</dim>');
        }
    }

    public function title(string $text): void
    {
        $this->line('');
        $this->line('<bold><cyan>' . $text . '</cyan></bold>');
        $this->line('<dim>' . str_repeat('─', max(20, min(78, mb_strlen($text) + 4))) . '</dim>');
    }

    public function section(string $text): void
    {
        $this->line('');
        $this->line('<bold>' . $text . '</bold>');
    }

    /**
     * @param array<int, array<int|string, mixed>> $rows
     * @param array<int, string> $headers
     */
    public function table(array $headers, array $rows): void
    {
        if ($rows === []) {
            $this->line('<dim>  (нет данных)</dim>');

            return;
        }
        $widths = [];
        foreach ($headers as $i => $header) {
            $widths[$i] = mb_strlen((string) $header);
        }
        foreach ($rows as $row) {
            foreach (array_values((array) $row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($this->plain((string) $cell)));
            }
        }
        foreach ($widths as $i => $width) {
            $widths[$i] = min(60, max(4, $width));
        }

        $line = '+';
        foreach ($widths as $width) {
            $line .= str_repeat('-', $width + 2) . '+';
        }

        $render = function (array $cells) use ($widths): string {
            $out = '|';
            foreach ($widths as $i => $width) {
                $value = $this->plain((string) ($cells[$i] ?? ''));
                $pad = $width - mb_strlen($value);
                $out .= ' ' . (mb_strlen($value) > $width ? mb_substr($value, 0, $width - 1) . '…' : $value . str_repeat(' ', max(0, $pad))) . ' |';
            }

            return $out;
        };

        $this->line('<dim>' . $line . '</dim>');
        $this->line('<bold>' . $render(array_values($headers)) . '</bold>');
        $this->line('<dim>' . $line . '</dim>');
        foreach ($rows as $row) {
            $this->line($render(array_values((array) $row)));
        }
        $this->line('<dim>' . $line . '</dim>');
    }

    /** Key/value listing. @param array<string, mixed> $values */
    public function definitions(array $values, int $pad = 28): void
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if (is_bool($value)) {
                $value = $value ? 'да' : 'нет';
            }
            $text = (string) ($value ?? '');
            if (mb_strlen($text) > 100) {
                $text = mb_substr($text, 0, 97) . '…';
            }
            $this->line(sprintf('  <cyan>%s</cyan>%s %s', (string) $key, str_repeat(' ', max(1, $pad - mb_strlen((string) $key))), $text === '' ? '<dim>—</dim>' : $text));
        }
    }

    public function progress(int $current, int $total, string $label = '', int $width = 30): void
    {
        $total = max(1, $total);
        $ratio = min(1.0, $current / $total);
        $filled = (int) round($ratio * $width);
        $bar = str_repeat('█', $filled) . str_repeat('░', max(0, $width - $filled));
        $this->write(sprintf("\r  <%s> %s</%s> %3d%% (%d/%d) %s", $ratio >= 1 ? 'green' : 'cyan', $bar, $ratio >= 1 ? 'green' : 'cyan', (int) round($ratio * 100), $current, $total, Str::limit($label, 30)));
        if ($current >= $total) {
            $this->line('');
        }
    }

    public function ask(string $question, ?string $default = null): string
    {
        $suffix = $default !== null ? ' <dim>[' . $default . ']</dim>' : '';
        $this->write($question . $suffix . ': ');
        $line = fgets(STDIN);

        return $line === false ? (string) $default : (trim($line) !== '' ? trim($line) : (string) $default);
    }

    public function askHidden(string $question): string
    {
        $this->write($question . ': ');
        if (DIRECTORY_SEPARATOR === '\\') {
            return trim((string) fgets(STDIN));
        }
        $tty = null;
        $value = '';
        try {
            $tty = popen('stty -echo 2>/dev/null; true', 'r');
            if (is_resource($tty)) {
                pclose($tty);
            }
            $line = fgets(STDIN);
            $value = $line === false ? '' : trim($line);
        } finally {
            $restore = popen('stty echo 2>/dev/null; true', 'r');
            if (is_resource($restore)) {
                pclose($restore);
            }
        }
        $this->line('');

        return $value;
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $suffix = $default ? '[Y/n]' : '[y/N]';
        $answer = strtolower($this->ask($question . ' <dim>' . $suffix . '</dim>', $default ? 'y' : 'n'));

        return in_array($answer, ['y', 'yes', 'да', '1', 'true'], true);
    }

    public function choice(string $question, array $options, ?string $default = null): string
    {
        $this->line($question);
        $index = 1;
        $map = [];
        foreach ($options as $key => $label) {
            $map[(string) $index] = (string) $key;
            $marker = ((string) $key === (string) $default) ? ' <green>*</green>' : '';
            $this->line(sprintf('  <cyan>%2d)</cyan> %s%s', $index, is_string($label) ? $label : (string) $key, $marker));
            $index++;
        }
        $answer = $this->ask('Выбор', $default !== null ? (string) array_search($default, array_map('strval', array_keys($options)), true) : '1');

        return $map[$answer] ?? (string) $default ?? (string) array_key_first($options);
    }

    /** Show a "working…" line while a callback runs, then clear it. */
    public function spinner(string $label, callable $callback): mixed
    {
        $this->write('  <cyan>⠋</cyan> ' . $label);
        try {
            return $callback();
        } finally {
            $this->write('\r' . str_repeat(' ', mb_strlen($label) + 6) . '\r');
        }
    }

    public function bullet(string $text, string $marker = '•'): void
    {
        $this->line('  <dim>' . $marker . '</dim> ' . $text);
    }

    private function colorize(string $text): string
    {
        if (!$this->colors) {
            return $this->plain($text);
        }
        $map = [
            'red' => '0;31', 'green' => '0;32', 'yellow' => '0;33', 'blue' => '0;34',
            'magenta' => '0;35', 'cyan' => '0;36', 'white' => '0;37', 'dim' => '2',
            'bold' => '1', 'underline' => '4',
        ];

        return (string) preg_replace_callback('/<(\/)?(' . implode('|', array_keys($map)) . ')>/', static function (array $m) use ($map): string {
            return $m[1] === '/' ? "\033[0m" : "\033[" . $map[$m[2]] . 'm';
        }, $text);
    }

    private function plain(string $text): string
    {
        return (string) preg_replace('/<\/?(red|green|yellow|blue|magenta|cyan|white|dim|bold|underline)>/', '', $text);
    }
}
