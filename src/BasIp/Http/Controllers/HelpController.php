<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers;

use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Http\Controller;
use BasIp\Support\Markdown;
use BasIp\Support\Str;

/**
 * The built-in reference manual («Справка»).
 *
 * Content lives in `resources/help/help.php` as structured Markdown, so the
 * same source powers the in-app help, the printable version and (via the wiki
 * build) the GitHub Wiki. Search is a simple ranked substring index built once
 * per request — fast enough for a few hundred KB of text and dependency-free.
 */
final class HelpController extends Controller
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $sections = null;

    public function index(Request $request): Response
    {
        $this->requireUser();
        $sections = $this->sections();
        $q = trim($request->str('q'));

        $results = $q !== '' ? $this->searchSections($q) : [];

        return $this->view('help/index', [
            'title'    => 'Справка',
            'sections' => $sections,
            'q'        => $q,
            'results'  => $results,
            'quick'    => $this->quickGuides(),
            'shortcuts' => $this->shortcuts(),
            'version'  => \BasIp\Core\App::VERSION,
        ]);
    }

    public function section(Request $request, string $section = ''): Response
    {
        $this->requireUser();
        $sections = $this->sections();

        if ($section === '' || $section === 'index') {
            return $this->index($request);
        }

        $found = null;
        foreach ($sections as $item) {
            if (($item['slug'] ?? '') === $section) {
                $found = $item;
                break;
            }
        }
        if ($found === null) {
            $this->notFound('Раздел справки не найден: ' . $section);
        }

        $index = array_search($found, $sections, true);
        $prev = $index > 0 ? $sections[$index - 1] : null;
        $next = $index !== false && isset($sections[$index + 1]) ? $sections[$index + 1] : null;

        return $this->view('help/section', [
            'title'    => (string) ($found['title'] ?? 'Справка'),
            'section'  => $found,
            'sections' => $sections,
            'html'     => Markdown::toHtml((string) ($found['body'] ?? '')),
            'toc'      => Markdown::headings((string) ($found['body'] ?? '')),
            'prev'     => $prev,
            'next'     => $next,
            'printable' => $request->bool('print'),
        ]);
    }

    public function search(Request $request): Response
    {
        $this->requireUser();
        $q = trim($request->str('q'));
        if ($q === '' || mb_strlen($q) < 2) {
            return $this->json(['ok' => true, 'query' => $q, 'results' => []]);
        }

        $results = $this->searchSections($q, (int) $request->int('limit', 20));

        if ($request->expectsJson()) {
            return $this->json(['ok' => true, 'query' => $q, 'results' => $results]);
        }

        return $this->view('help/search', [
            'title' => 'Поиск: ' . $q,
            'q' => $q,
            'results' => $results,
            'sections' => $this->sections(),
        ]);
    }

    /* ─────────────────────────── internals ─────────────────────────── */

    /** @return array<int, array<string, mixed>> */
    private function sections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        $file = $this->config()->getPath('resources') . '/help/help.php';
        /** @var array<int, array<string, mixed>>|null $data */
        $data = is_file($file) ? require $file : null;
        if (!is_array($data)) {
            $data = [];
        }

        // Append live, installation-specific values so the help is never stale.
        foreach ($data as $index => $section) {
            $data[$index]['body'] = $this->interpolate((string) ($section['body'] ?? ''));
        }

        return $this->sections = $data;
    }

    /** Replace `{placeholders}` with real installation values. */
    private function interpolate(string $text): string
    {
        $user = $this->user();

        return strtr($text, [
            '{version}'      => \BasIp\Core\App::VERSION,
            '{app_name}'     => $this->config()->string('app.name', 'BAS-IP'),
            '{app_url}'      => $this->config()->string('app.url', ''),
            '{timezone}'     => $this->config()->string('app.timezone', 'UTC'),
            '{currency}'     => $this->config()->string('billing.currency_symbol', '₽'),
            '{db_driver}'    => $this->config()->string('db.default', 'mysql'),
            '{php_version}'  => PHP_VERSION,
            '{repo}'         => $this->config()->string('update.repo', 'paulmann/BAS-IP'),
            '{base_path}'    => $this->config()->root(),
            '{device_url}'   => $this->config()->string('device.base_url', ''),
            '{guest_ttl}'    => (string) $this->settingsService()->int('guest.default_ttl_hours', $this->config()->int('guest.default_ttl_hours', 12)),
            '{due_days}'     => (string) $this->config()->int('billing.due_days', 14),
            '{role}'         => (string) ($user?->roleLabel() ?? 'гость'),
            '{active_count}' => (string) $this->residentRepo()->countActive(),
        ]);
    }

    /**
     * Ranked full-text-ish search over the help content.
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchSections(string $query, int $limit = 20): array
    {
        $needle = Str::lower(Str::squash($query));
        $terms = array_values(array_filter(preg_split('/\s+/u', $needle) ?: []));
        $results = [];

        foreach ($this->sections() as $section) {
            $title = Str::lower((string) ($section['title'] ?? ''));
            $summary = Str::lower((string) ($section['summary'] ?? ''));
            $body = Str::lower(strip_tags((string) ($section['body'] ?? '')));
            $keywords = Str::lower(implode(' ', (array) ($section['keywords'] ?? [])));

            $score = 0;
            $matched = 0;
            foreach ($terms as $term) {
                $hit = false;
                $score += substr_count($title, $term) * 12;
                $score += substr_count($keywords, $term) * 8;
                $score += substr_count($summary, $term) * 4;
                $count = substr_count($body, $term);
                if ($count > 0) {
                    $hit = true;
                    $score += min(20, $count);
                }
                if (str_contains($title, $term) || str_contains($keywords, $term) || str_contains($summary, $term)) {
                    $hit = true;
                }
                if ($hit) {
                    $matched++;
                }
            }

            if ($matched < count($terms)) {
                continue;      // every word must appear somewhere
            }

            $results[] = [
                'slug'    => (string) ($section['slug'] ?? ''),
                'title'   => (string) ($section['title'] ?? ''),
                'icon'    => (string) ($section['icon'] ?? 'help-circle'),
                'group'   => (string) ($section['group'] ?? 'Прочее'),
                'summary' => (string) ($section['summary'] ?? ''),
                'excerpt' => $this->excerpt((string) ($section['body'] ?? ''), $terms),
                'score'   => $score,
            ];
        }

        usort($results, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($results, 0, max(1, $limit));
    }

    /** @param array<int, string> $terms */
    private function excerpt(string $body, array $terms, int $radius = 120): string
    {
        $plain = Str::squash(strip_tags(preg_replace('/```.*?```/s', ' ', $body) ?? $body));
        $lower = Str::lower($plain);
        $pos = false;
        foreach ($terms as $term) {
            $found = mb_strpos($lower, $term);
            if ($found !== false && ($pos === false || $found < $pos)) {
                $pos = $found;
            }
        }
        if ($pos === false) {
            return Str::limit($plain, $radius * 2);
        }

        $start = max(0, $pos - intdiv($radius, 2));
        $excerpt = mb_substr($plain, $start, $radius * 2);
        foreach ($terms as $term) {
            $excerpt = Str::highlight(Str::e($excerpt), $term);
        }

        return ($start > 0 ? '…' : '') . $excerpt . '…';
    }

    /** @return array<int, array<string, mixed>> */
    private function quickGuides(): array
    {
        return [
            ['title' => 'Первый запуск за 5 минут', 'slug' => 'quick-start', 'icon' => 'rocket', 'text' => 'Установка, создание администратора, первое открытие.'],
            ['title' => 'Как выставить оплату за месяц', 'slug' => 'billing-charges', 'icon' => 'calculator', 'text' => 'Общая сумма → деление на активных жителей → рассылка.'],
            ['title' => 'Гостевой доступ на 12 часов', 'slug' => 'guest-access', 'icon' => 'user-plus', 'text' => 'Ссылка, код, PIN, журнал и отзыв.'],
            ['title' => 'Перерасчёт при смене числа жителей', 'slug' => 'billing-recalc', 'icon' => 'refresh-cw', 'text' => 'Дополнительные счета и сторно без правки выставленных.'],
            ['title' => 'Импорт базы из Excel', 'slug' => 'data-import', 'icon' => 'upload', 'text' => 'CSV с русскими заголовками, проверка перед загрузкой.'],
            ['title' => 'Журнал открываний', 'slug' => 'gate-log', 'icon' => 'history', 'text' => 'Кто, когда и по какому доступу открыл шлагбаум.'],
            ['title' => 'Безопасность и 2FA', 'slug' => 'security', 'icon' => 'shield', 'text' => 'Пароли, двухфакторка, IP-allowlist, аудит.'],
            ['title' => 'API и интеграции', 'slug' => 'api', 'icon' => 'plug', 'text' => 'Токены, области доступа, примеры curl, Telegram-бот.'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function shortcuts(): array
    {
        return [
            ['keys' => ['Ctrl', 'K'], 'action' => 'Командная палитра (быстрый переход)'],
            ['keys' => ['/'], 'action' => 'Поиск по текущему разделу'],
            ['keys' => ['G', 'then', 'D'], 'action' => 'Дашборд'],
            ['keys' => ['G', 'then', 'R'], 'action' => 'Жители'],
            ['keys' => ['G', 'then', 'G'], 'action' => 'Журнал шлагбаума'],
            ['keys' => ['G', 'then', 'B'], 'action' => 'Оплаты'],
            ['keys' => ['Shift', 'O'], 'action' => 'Открыть шлагбаум (с подтверждением)'],
            ['keys' => ['Shift', 'G'], 'action' => 'Создать гостевой доступ'],
            ['keys' => ['Shift', 'T'], 'action' => 'Переключить светлую/тёмную тему'],
            ['keys' => ['Shift', '?'], 'action' => 'Эта справка'],
            ['keys' => ['Esc'], 'action' => 'Закрыть диалог / палитру'],
        ];
    }
}
