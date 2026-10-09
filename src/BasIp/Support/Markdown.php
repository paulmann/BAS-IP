<?php

declare(strict_types=1);

namespace BasIp\Support;

/**
 * A small, safe Markdown → HTML converter.
 *
 * Supports the subset the help pages and release notes actually use:
 * headings, paragraphs, bold/italic/inline-code, fenced code blocks, lists
 * (ordered/unordered/nested by indentation), blockquotes, tables, links,
 * images, horizontal rules and line breaks.
 *
 * Everything is escaped before parsing, so untrusted Markdown (release notes
 * fetched from GitHub) can never inject markup. No external library, no CDN.
 */
final class Markdown
{
    /**
     * Sentinel wrapped around protected segments (code blocks, inline code).
     * U+E000 is a Private Use Area code point: it never appears in real text
     * and — unlike NUL — survives `htmlspecialchars()`.
     */
    private const GUARD = "\u{E000}";

    /** @var array<int, array{level: int, text: string, id: string}> */
    private array $headings = [];

    public static function toHtml(string $markdown, bool $collectHeadings = false): string
    {
        $instance = new self();

        return $instance->convert($markdown, $collectHeadings);
    }

    /** @return array<int, array{level: int, text: string, id: string}> */
    public static function headings(string $markdown): array
    {
        $instance = new self();
        $instance->convert($markdown, true);

        return $instance->headings;
    }

    private function convert(string $markdown, bool $collectHeadings): string
    {
        $this->headings = [];
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

        // Protect fenced code blocks from any further processing.
        $blocks = [];
        $markdown = (string) preg_replace_callback('/```([a-zA-Z0-9_+-]*)\n(.*?)```/s', static function (array $m) use (&$blocks): string {
            $lang = $m[1] !== '' ? ' class="language-' . Str::e($m[1]) . '"' : '';
            $blocks[] = '<pre class="code-block"><code' . $lang . '>' . Str::e($m[2]) . '</code></pre>';

            return self::GUARD . 'BLOCK' . (count($blocks) - 1) . self::GUARD;
        }, $markdown);

        // Inline code (also protected).
        $inline = [];
        $markdown = (string) preg_replace_callback('/`([^`\n]+)`/', static function (array $m) use (&$inline): string {
            $inline[] = '<code class="inline-code">' . Str::e($m[1]) . '</code>';

            return self::GUARD . 'INLINE' . (count($inline) - 1) . self::GUARD;
        }, $markdown);

        $markdown = Str::e($markdown);

        $lines = explode("\n", $markdown);
        $html = [];
        $listStack = [];       // ['type' => 'ul'|'ol', 'indent' => int]
        $inTable = false;
        $tableHeaderDone = false;
        $paragraph = [];

        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if ($paragraph !== []) {
                $html[] = '<p>' . self::inline(implode(' ', $paragraph)) . '</p>';
                $paragraph = [];
            }
        };
        $closeLists = function (int $toLevel = 0) use (&$listStack, &$html): void {
            while (count($listStack) > $toLevel) {
                $item = array_pop($listStack);
                $html[] = '</' . $item['type'] . '>';
            }
        };
        $closeTable = static function () use (&$inTable, &$tableHeaderDone, &$html): void {
            if ($inTable) {
                $html[] = '</tbody></table></div>';
                $inTable = false;
                $tableHeaderDone = false;
            }
        };

        foreach ($lines as $rawLine) {
            $line = rtrim($rawLine);
            $trimmed = ltrim($line);

            // Table detection: a line containing | followed by a separator row.
            if (str_contains($trimmed, '|') && preg_match('/^\|?[\s:|-]+\|[\s:|-]*$/', $trimmed)) {
                continue;   // separator row — the header was already emitted
            }
            if (preg_match('/^\|(.+)\|$/', $trimmed) || (str_contains($trimmed, '|') && preg_match('/^[^|]+\|[^|]+/', $trimmed) && !$inTable && str_contains($trimmed, '|'))) {
                if (str_contains($trimmed, '|')) {
                    $cells = array_map('trim', explode('|', trim($trimmed, '|')));
                    if (!$inTable) {
                        $flushParagraph();
                        $closeLists();
                        $html[] = '<div class="table-wrap"><table class="md-table"><thead><tr>';
                        foreach ($cells as $cell) {
                            $html[] = '<th>' . self::inline($cell) . '</th>';
                        }
                        $html[] = '</tr></thead><tbody>';
                        $inTable = true;
                        $tableHeaderDone = true;
                        continue;
                    }
                    if ($tableHeaderDone) {
                        $html[] = '<tr>';
                        foreach ($cells as $cell) {
                            $html[] = '<td>' . self::inline($cell) . '</td>';
                        }
                        $html[] = '</tr>';
                        continue;
                    }
                }
            }
            $closeTable();

            if ($trimmed === '') {
                $flushParagraph();
                $closeLists();
                continue;
            }

            // Horizontal rule
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed)) {
                $flushParagraph();
                $closeLists();
                $html[] = '<hr class="md-hr">';
                continue;
            }

            // Heading
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m)) {
                $flushParagraph();
                $closeLists();
                $level = strlen($m[1]);
                $text = self::inline($m[2]);
                $id = Str::slug(preg_replace('/<[^>]+>/', '', $text) ?? $m[2]);
                if ($collectHeadings && $level <= 3) {
                    $this->headings[] = ['level' => $level, 'text' => trim(preg_replace('/<[^>]+>/', '', $text) ?? ''), 'id' => $id];
                }
                $html[] = sprintf('<h%d class="md-h%d" id="%s">%s</h%d>', $level, $level, Str::e($id), $text, $level);
                continue;
            }

            // Blockquote
            if (str_starts_with($trimmed, '&gt;')) {
                $flushParagraph();
                $closeLists();
                $html[] = '<blockquote class="md-quote">' . self::inline(trim(substr($trimmed, 4))) . '</blockquote>';
                continue;
            }

            // Lists
            $indent = strlen($line) - strlen(ltrim($line, " \t"));
            if (preg_match('/^([-*+])\s+(.*)$/', $trimmed, $m) || preg_match('/^(\d+)[.)]\s+(.*)$/', $trimmed, $m)) {
                $flushParagraph();
                $type = isset($m[1]) && preg_match('/^\d+$/', (string) $m[1]) ? 'ol' : 'ul';
                $level = intdiv($indent, 2) + 1;

                if ($listStack === [] || $level > count($listStack)) {
                    while (count($listStack) < $level) {
                        $html[] = '<' . $type . ' class="md-list">';
                        $listStack[] = ['type' => $type, 'indent' => count($listStack) + 1];
                    }
                } else {
                    $closeLists($level);
                }

                // Task list support: `- [ ]` / `- [x]`
                $content = $m[2];
                if (preg_match('/^\[( |x|X)\]\s*(.*)$/', $content, $task)) {
                    $checked = strtolower($task[1]) === 'x' ? ' checked' : '';
                    $content = '<span class="task"><input type="checkbox" disabled' . $checked . '> ' . self::inline($task[2]) . '</span>';
                } else {
                    $content = self::inline($content);
                }
                $html[] = '<li>' . $content . '</li>';
                continue;
            }
            $closeLists();

            $paragraph[] = $trimmed;
        }

        $flushParagraph();
        $closeLists();
        $closeTable();

        $html = implode("\n", $html);

        // Restore protected segments.
        $guard = preg_quote(self::GUARD, '/');
        $html = (string) preg_replace_callback('/' . $guard . 'INLINE(\d+)' . $guard . '/', static fn (array $m): string => $inline[(int) $m[1]] ?? '', $html);
        $html = (string) preg_replace_callback('/' . $guard . 'BLOCK(\d+)' . $guard . '/', static fn (array $m): string => $blocks[(int) $m[1]] ?? '', $html);

        return $html;
    }

    /** Inline formatting: bold, italic, strike, links, images, line breaks. */
    private static function inline(string $text): string
    {
        // Images ![alt](src)
        $text = (string) preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/', static function (array $m): string {
            $src = self::safeUrl($m[2]);

            return $src === null ? Str::e($m[0]) : sprintf('<img src="%s" alt="%s" loading="lazy">', Str::e($src), Str::e($m[1]));
        }, $text);

        // Links [text](url)
        $text = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
            $href = self::safeUrl($m[2]);

            return $href === null
                ? $m[1]
                : sprintf('<a href="%s"%s>%s</a>', Str::e($href), self::isExternal($href) ? ' target="_blank" rel="noopener noreferrer"' : '', $m[1]);
        }, $text);

        // Autolinks
        $text = (string) preg_replace_callback('~(?<!["\'>=/])(https?://[^\s<&]+[^\s<&.,;:!?)\]])~', static function (array $m): string {
            $href = self::safeUrl($m[1]);

            return $href === null ? $m[0] : sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', Str::e($href), Str::e($m[1]));
        }, $text);

        // Bold / italic / strike / mark
        $text = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $text);
        $text = (string) preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $text);
        $text = (string) preg_replace('/==([^=]+)==/', '<mark>$1</mark>', $text);

        return str_replace("\n", '<br>', $text);
    }

    /** Reject javascript:/data: URLs (except data:image/*) — XSS defence. */
    private static function safeUrl(string $url): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '') {
            return null;
        }
        $lower = strtolower($url);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'vbscript:')) {
            return null;
        }
        if (str_starts_with($lower, 'data:') && !str_starts_with($lower, 'data:image/')) {
            return null;
        }
        if (str_starts_with($lower, '//')) {
            return null;
        }

        return $url;
    }

    private static function isExternal(string $url): bool
    {
        return (bool) preg_match('~^https?://~i', $url);
    }
}
