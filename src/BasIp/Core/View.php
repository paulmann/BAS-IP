<?php

declare(strict_types=1);

namespace BasIp\Core;

use BasIp\Support\Money;
use BasIp\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Plain-PHP template engine with layouts, sections, stacks and components.
 *
 * No compiled templates, no caching layer, no framework lock-in — just
 * `include` with output buffering, which keeps stack traces readable and the
 * footprint at zero. Templates live in `resources/views` and use `.php`.
 *
 * Inside a template `$this` is the View, so helpers read naturally:
 *   <?= $this->e($resident->full_name) ?>
 *   <?php $this->start('toolbar') ?> … <?php $this->end() ?>
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    /** @var array<string, string> */
    private array $sections = [];

    /** @var array<int, string> */
    private array $sectionStack = [];

    /** @var array<string, array<int, string>> */
    private array $stacks = [];

    /** @var array<string, bool> */
    private array $rendered = [];

    private ?string $layout = null;
    /** @var array<string, mixed> */
    private array $layoutData = [];
    private int $depth = 0;

    public function __construct(
        private readonly Config $config,
        private readonly Lang $lang,
        private readonly Csrf $csrf,
        private readonly Router $router,
    ) {
    }

    /** Data available to every template (auth user, config, csrf, …). */
    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string, mixed> $data */
    public function shareMany(array $data): void
    {
        $this->shared = array_merge($this->shared, $data);
    }

    /** @return array<string, mixed> */
    public function shared(): array
    {
        return $this->shared;
    }

    /**
     * Render a template (and its layout) to a string.
     *
     * The layout can be declared two ways: the template calls
     * `$this->layout('layouts/app')` itself (wins), or the controller passes
     * `'layout' => 'auth'` in the view data (data-driven). Whichever way it
     * is declared, the layout also receives the template's data, so
     * `$title` and friends are visible inside it.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $this->depth = 0;

        $declared = null;
        if (isset($data['layout']) && is_string($data['layout']) && $data['layout'] !== '') {
            $declared = 'layouts/' . ltrim($data['layout'], '/');
            unset($data['layout']);
        }

        $content = $this->renderTemplate($template, $data);

        if ($this->layout !== null && $this->layoutData === []) {
            // Template declared a layout without data — share the view data.
            $this->layoutData = $data;
        }
        if ($this->layout === null && $declared !== null && $this->resolve($declared) !== null) {
            $this->layout     = $declared;
            $this->layoutData = $data;
        }

        $guard = 0;
        while ($this->layout !== null && $guard++ < 5) {
            $layout = $this->layout;
            $layoutData = $this->layoutData;
            $this->layout = null;
            $this->layoutData = [];
            $layoutData['content'] = $content;
            $content = $this->renderTemplate($layout, $layoutData);
        }

        return $content;
    }

    public function renderToResponse(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->render($template, $data), $status);
    }

    /** Declare the layout from inside a template. @param array<string, mixed> $data */
    public function layout(string $layout, array $data = []): void
    {
        $this->layout     = $layout;
        $this->layoutData = $data;
    }

    public function start(string $section): void
    {
        $this->sectionStack[] = $section;
        ob_start();
    }

    public function end(): string
    {
        $section = array_pop($this->sectionStack);
        $content = (string) ob_get_clean();
        if ($section !== null) {
            $this->sections[$section] = $content;
        }

        return $content;
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]) && trim($this->sections[$name]) !== '';
    }

    public function push(string $stack, string $content): void
    {
        $this->stacks[$stack][] = $content;
    }

    public function stack(string $name, string $default = ''): string
    {
        $items = $this->stacks[$name] ?? [];

        return $items === [] ? $default : implode("\n", $items);
    }

    public function startPush(string $stack): void
    {
        $this->sectionStack[] = '@push:' . $stack;
        ob_start();
    }

    public function endPush(): void
    {
        $section = array_pop($this->sectionStack);
        $content = (string) ob_get_clean();
        if (is_string($section) && str_starts_with($section, '@push:')) {
            $this->push(substr($section, 6), $content);
        }
    }

    /** Render a partial without a layout. @param array<string, mixed> $data */
    public function include(string $template, array $data = []): string
    {
        return $this->renderTemplate($template, $data, false);
    }

    /** Alias kept for readability in component-heavy templates. */
    public function component(string $template, array $data = []): string
    {
        return $this->include('components/' . ltrim($template, '/'), $data);
    }

    /**
     * Render a component only once per request (handy for one-off dialogs).
     *
     * @param array<string, mixed> $data
     */
    public function once(string $template, array $data = []): string
    {
        if (isset($this->rendered[$template])) {
            return '';
        }
        $this->rendered[$template] = true;

        return $this->include($template, $data);
    }

    /* ─────────────────────────── template helpers ─────────────────────────── */

    public function e(mixed $value): string
    {
        return Str::e($value);
    }

    public function raw(string $value): string
    {
        return $value;
    }

    public function t(string $key, array $replace = []): string
    {
        return $this->lang->get($key, $replace);
    }

    public function te(string $key, array $replace = []): string
    {
        return Str::e($this->lang->get($key, $replace));
    }

    public function csrf(): string
    {
        return $this->csrf->field();
    }

    public function csrfToken(): string
    {
        return $this->csrf->token();
    }

    public function method(string $method): string
    {
        $method = strtoupper($method);
        if (in_array($method, ['GET', 'POST'], true)) {
            return '';
        }

        return '<input type="hidden" name="_method" value="' . $method . '">';
    }

    public function url(string $path = '', array $query = []): string
    {
        $base = rtrim((string) $this->shared['basePath'], '/');
        $url  = $base . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        return $url === '' ? '/' : $url;
    }

    public function route(string $name, array $params = [], array $query = []): string
    {
        return $this->router->url($name, $params, $query, rtrim((string) ($this->shared['basePath'] ?? ''), '/'));
    }

    public function asset(string $path): string
    {
        $path  = ltrim($path, '/');
        $file  = $this->config->getPath('public') . '/assets/' . $path;
        $version = is_file($file) ? substr((string) filemtime($file), -6) : $this->config->string('app.version', '1');

        return $this->url('assets/' . $path, ['v' => $version]);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config->get($key, $default);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $settings = $this->shared['settings'] ?? [];

        return is_array($settings) && array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    public function money(float|int|string|null $amount, ?string $symbol = null, ?int $precision = null): string
    {
        return Money::format(
            $amount,
            $symbol ?? $this->config->string('billing.currency_symbol', '₽'),
            $precision ?? $this->config->int('billing.precision', 2)
        );
    }

    /**
     * Format a date for display. Accepts null, '' , a `Y-m-d[ H:i:s]` string or
     * a UNIX timestamp — templates pull values straight from DB rows, so the
     * input type is not always predictable.
     */
    public function date(mixed $value, string $format = 'd.m.Y'): string
    {
        return $this->formatDate($value, $format);
    }

    public function datetime(mixed $value, string $format = 'd.m.Y H:i'): string
    {
        return $this->formatDate($value, $format);
    }

    /** Shared implementation for {@see date()} and {@see datetime()}. */
    private function formatDate(mixed $value, string $format): string
    {
        if ($value === null || $value === '' || $value === [] || $value === false) {
            return '—';
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $value = (int) $value;
            // Heuristic: seconds vs milliseconds.
            if ($value > 10_000_000_000) {
                $value = intdiv($value, 1000);
            }

            return date($format, $value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format($format);
        }

        $text = trim((string) $value);
        if ($text === '' || str_starts_with($text, '0000')) {
            return '—';
        }
        try {
            $dt = new \DateTimeImmutable($text, new \DateTimeZone($this->config->string('app.timezone', 'UTC')));
        } catch (Throwable) {
            return Str::e($text);
        }

        return $dt->format($format);
    }

    public function timeAgo(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        try {
            $dt = new \DateTimeImmutable($value);
        } catch (Throwable) {
            return '—';
        }
        $diff = time() - $dt->getTimestamp();
        if ($diff < 0) {
            return $this->datetime($value);
        }

        return match (true) {
            $diff < 5      => 'только что',
            $diff < 60     => $diff . ' ' . Str::plural($diff, 'секунду', 'секунды', 'секунд') . ' назад',
            $diff < 3600   => intdiv($diff, 60) . ' ' . Str::plural(intdiv($diff, 60), 'минуту', 'минуты', 'минут') . ' назад',
            $diff < 86400  => intdiv($diff, 3600) . ' ' . Str::plural(intdiv($diff, 3600), 'час', 'часа', 'часов') . ' назад',
            $diff < 172800 => 'вчера в ' . $dt->format('H:i'),
            $diff < 604800 => intdiv($diff, 86400) . ' ' . Str::plural(intdiv($diff, 86400), 'день', 'дня', 'дней') . ' назад',
            default        => $dt->format('d.m.Y H:i'),
        };
    }

    public function old(string $key, mixed $default = ''): mixed
    {
        $old = $this->shared['old'] ?? [];

        return is_array($old) && array_key_exists($key, $old) ? $old[$key] : $default;
    }

    /** @param array<string, string> $errors */
    public function error(string $key): ?string
    {
        $errors = $this->shared['errors'] ?? [];

        return is_array($errors) ? ($errors[$key] ?? null) : null;
    }

    public function hasError(string $key): bool
    {
        return $this->error($key) !== null;
    }

    public function checked(bool $value): string
    {
        return $value ? ' checked' : '';
    }

    public function selected(mixed $current, mixed $value): string
    {
        return (string) $current === (string) $value ? ' selected' : '';
    }

    public function active(string $path, bool $exact = false, string $classes = 'active'): string
    {
        $current = (string) ($this->shared['path'] ?? '/');
        $match = $exact ? $current === $path : ($current === $path || str_starts_with($current, rtrim($path, '/') . '/'));

        return $match ? $classes : '';
    }

    public function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }

    /** Render an inline SVG icon from the local sprite. */
    public function icon(string $name, string $classes = 'h-4 w-4', ?string $title = null): string
    {
        $classes = Str::e($classes);
        $name    = Str::e($name);
        $aria    = $title === null
            ? 'aria-hidden="true"'
            : 'role="img" aria-label="' . Str::e($title) . '"';

        return sprintf('<svg class="lucide %s" %s><use href="%s#i-%s"></use></svg>', $classes, $aria, $this->asset('icons/lucide.svg'), $name);
    }

    /**
     * Turn server-side flash messages and validation errors into the JSON
     * payload that `app.js` turns into toasts.
     *
     * @param array<string, mixed> $flash
     * @param array<string, array<int, string>> $errors
     * @return array<int, array<string, string>>
     */
    public function flashPayload(array $flash, array $errors = []): array
    {
        $out = [];
        foreach ($flash as $key => $value) {
            if (in_array($key, ['_old_input', '_errors', '_csrf', '_csrf_at'], true) || !is_string($value)) {
                continue;
            }
            $type = match ($key) {
                'success' => 'success',
                'error'   => 'destructive',
                'warning' => 'warning',
                default   => 'info',
            };
            $out[] = ['type' => $type, 'message' => $value];
        }
        foreach ($errors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $out[] = ['type' => 'destructive', 'message' => is_string($message) ? $message : (string) $field];
            }
        }

        return $out;
    }

    /** Human label for a `login_attempts.reason` code. */
    public function loginFailureLabel(string $reason): string
    {
        return match ($reason) {
            'unknown_account' => 'Нет такого аккаунта',
            'bad_password'    => 'Неверный пароль',
            'bad_2fa'         => 'Неверный код 2FA',
            'inactive'        => 'Аккаунт отключён',
            '2fa_required'    => 'Требуется код 2FA',
            'locked'          => 'Аккаунт заблокирован',
            ''                => 'Отказ',
            default           => $reason,
        };
    }

    /** Human label for a `login_attempts.method` code. */
    public function loginMethodLabel(string $method): string
    {
        return match ($method) {
            'password' => 'Пароль',
            'totp'     => 'Код 2FA',
            'recovery' => 'Резервный код',
            'token'    => 'Токен',
            ''         => '—',
            default    => $method,
        };
    }

    public function sessionHas(string $key): bool
    {
        $session = $this->shared['session'] ?? null;

        return $session instanceof Session && $session->has($key);
    }

    public function sessionGet(string $key, mixed $default = null): mixed
    {
        $session = $this->shared['session'] ?? null;

        return $session instanceof Session ? $session->get($key, $default) : $default;
    }

    public function sessionPull(string $key, mixed $default = null): mixed
    {
        $session = $this->shared['session'] ?? null;

        return $session instanceof Session ? $session->pull($key, $default) : $default;
    }

    /** Short description of an API-token scope. */
    public function scopeHint(string $scope): string
    {
        return match ($scope) {
            'gate.open'               => 'Открывать шлагбаум',
            'gate.close'              => 'Закрывать шлагбаум',
            'gate.status'             => 'Читать состояние панели',
            'gate.log'                => 'Читать журнал открываний',
            'guest.create'            => 'Создавать гостевые доступы',
            'guest.revoke'            => 'Отзывать гостевые доступы',
            'guest.view'              => 'Список гостевых доступов',
            'residents.view'          => 'Читать базу жителей',
            'billing.view'            => 'Читать счета и статистику',
            'billing.payment.record'  => 'Проводить оплаты',
            'notifications.send'      => 'Отправлять уведомления',
            'system.health'           => 'Проверка состояния',
            '*'                       => 'Полный доступ (используйте осторожно)',
            default                   => $scope,
        };
    }

    /** Pretty-print a JSON string for the log viewer. */
    public function prettyJson(string $json, int $depth = 512): string
    {
        $decoded = json_decode($json, true, $depth);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return $json;
        }
        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? $json : $encoded;
    }

    /** Icon for a log channel. */
    public function channelIcon(string $channel): string
    {
        return match (strtolower($channel)) {
            'app'    => 'terminal',
            'audit'  => 'scroll-text',
            'gate'   => 'door-open',
            'device' => 'cpu',
            'notify' => 'send',
            'error'  => 'alert-circle',
            'access' => 'globe',
            'update' => 'download-cloud',
            'security' => 'shield',
            default  => 'file-text',
        };
    }

    /** Absolute path of the application root (shown in help snippets). */
    public function root(): string
    {
        return $this->config->root();
    }

    /** Human label for a log channel. */
    public function channelLabel(string $channel): string
    {
        return match (strtolower($channel)) {
            'app'      => 'Приложение',
            'audit'    => 'Аудит действий',
            'gate'     => 'Шлагбаум',
            'device'   => 'Устройства',
            'notify'   => 'Уведомления',
            'error'    => 'Ошибки',
            'access'   => 'HTTP-запросы',
            'update'   => 'Обновления',
            'security' => 'Безопасность',
            default    => ucfirst($channel),
        };
    }

    /** Icon for a diagnostics area. */
    public function areaIcon(string $area): string
    {
        return match (strtolower($area)) {
            'php'      => 'terminal',
            'config'   => 'sliders',
            'fs'       => 'folder',
            'db'       => 'database',
            'security' => 'shield',
            'device'   => 'cpu',
            'data'     => 'users',
            'backup'   => 'archive',
            'queue'    => 'inbox',
            'cron'     => 'calendar-check',
            'update'   => 'download-cloud',
            default    => 'activity',
        };
    }

    /** Human label for a diagnostics area. */
    public function areaLabel(string $area): string
    {
        return match (strtolower($area)) {
            'php'      => 'PHP и расширения',
            'config'   => 'Конфигурация и секреты',
            'fs'       => 'Файловая система и права',
            'db'       => 'База данных',
            'security' => 'Безопасность',
            'device'   => 'Устройства',
            'data'     => 'Качество данных',
            'backup'   => 'Резервные копии',
            'queue'    => 'Очередь уведомлений',
            'cron'     => 'Планировщик',
            'update'   => 'Обновления',
            'system'   => 'Система',
            default    => ucfirst($area),
        };
    }

    /** Human label for the system counters table. */
    public function counterLabel(string $key): string
    {
        return match ($key) {
            'residents'        => 'Жителей (всего)',
            'residents_active' => 'Жителей активных',
            'cars'             => 'Автомобилей',
            'gate_events'      => 'Событий шлагбаума',
            'guest_passes'     => 'Гостевых доступов',
            'invoices'         => 'Счетов',
            'payments'         => 'Оплат',
            'audit_rows'       => 'Записей аудита',
            'users'            => 'Учётных записей',
            'devices'          => 'Устройств',
            default            => $key,
        };
    }

    /** Render a config value for read-only settings screens. */
    public function describeConfigValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'включено' : 'выключено';
        }
        if (is_array($value)) {
            return implode(', ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE), $value));
        }
        if (is_string($value) && preg_match('/(password|secret|token|key)/i', $value)) {
            return Str::mask($value);
        }

        return (string) $value;
    }

    /** Human label for a `billing_history.action` code. */
    public function billingActionLabel(string $action): string
    {
        return match ($action) {
            'issued'          => 'Выставлен счёт',
            'adjusted'        => 'Перерасчёт (доп. счёт)',
            'credited'        => 'Сторно',
            'paid'            => 'Оплата',
            'payment_deleted' => 'Отмена оплаты',
            'voided'          => 'Счёт аннулирован',
            'reminder'        => 'Напоминание об оплате',
            ''                => '—',
            default           => $action,
        };
    }

    /** Badge tone for a generic boolean. */
    public function boolBadge(?bool $value, string $yes = 'да', string $no = 'нет'): string
    {
        return $value
            ? '<span class="badge badge-soft-success">' . htmlspecialchars($yes, ENT_QUOTES) . '</span>'
            : '<span class="badge badge-muted">' . htmlspecialchars($no, ENT_QUOTES) . '</span>';
    }

    public function nonce(): string
    {
        return (string) ($this->shared['cspNonce'] ?? '');
    }

    public function user(): mixed
    {
        return $this->shared['user'] ?? null;
    }

    public function auth(): mixed
    {
        return $this->shared['auth'] ?? null;
    }

    /* ─────────────────────────── internals ─────────────────────────── */

    /** @param array<string, mixed> $data */
    private function renderTemplate(string $template, array $data, bool $captureLayout = true): string
    {
        $file = $this->resolve($template);
        if ($file === null) {
            throw new RuntimeException(sprintf('View "%s" not found (looked in %s).', $template, $this->config->getPath('views')));
        }

        $previousLayout = $this->layout;
        $previousData   = $this->layoutData;
        if (!$captureLayout) {
            $this->layout = null;
        }

        try {
            $output = $this->evaluate($file, $data);
        } catch (Throwable $e) {
            $this->layout = $previousLayout;
            $this->layoutData = $previousData;

            // Include the template file/line — without it a broken view is
            // almost impossible to locate in a stack of nested includes.
            $where = $e->getFile() !== '' ? sprintf(' (%s:%d)', \BasIp\Support\Fs::relative($e->getFile(), $this->config->root()), $e->getLine()) : '';
            throw new RuntimeException(sprintf('Error rendering view "%s"%s: %s', $template, $where, $e->getMessage()), 0, $e);
        }

        if (!$captureLayout) {
            $this->layout = $previousLayout;
            $this->layoutData = $previousData;
        }

        return (string) $output;
    }

    /** @param array<string, mixed> $data */
    private function evaluate(string $__file, array $__data): string
    {
        extract(array_merge($this->shared, $__data), EXTR_OVERWRITE);
        unset($__data);
        ob_start();
        try {
            include $__file;
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }

    private function resolve(string $template): ?string
    {
        $template = str_replace('.', '/', ltrim($template, '/'));
        $base     = rtrim($this->config->getPath('views'), '/');
        foreach ([$base . '/' . $template . '.php', $base . '/' . $template . '.phtml'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
