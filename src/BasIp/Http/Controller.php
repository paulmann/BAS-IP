<?php

declare(strict_types=1);

namespace BasIp\Http;

use BasIp\Auth\Guard;
use BasIp\Auth\User;
use BasIp\Core\App;
use BasIp\Core\Config;
use BasIp\Core\Container;
use BasIp\Core\Csrf;
use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Core\Session;
use BasIp\Core\View;
use BasIp\Db\Database;
use BasIp\Domain\Repository\ResidentRepository;
use BasIp\Http\RoutingException;
use BasIp\Services\AnnouncementService;
use BasIp\Services\AuditService;
use BasIp\Services\SettingsService;
use BasIp\Support\Paginator;
use BasIp\Support\Validator;
use BasIp\Update\UpdateService;

/**
 * Base controller.
 *
 * Provides the view/json/redirect helpers, request-scoped validation and the
 * shared view data (current user, settings, flash messages, CSRF, base path)
 * that every template relies on.
 */
abstract class Controller
{
    public function __construct(protected readonly Container $c)
    {
    }

    /* ─────────────────────────── services ─────────────────────────── */

    protected function config(): Config        { return $this->c->get(Config::class); }
    protected function db(): Database          { return $this->c->get(Database::class); }
    protected function renderer(): View        { return $this->c->get(View::class); }
    protected function session(): Session      { return $this->c->get(Session::class); }
    protected function auth(): Guard          { return $this->c->get(Guard::class); }
    protected function csrf(): Csrf            { return $this->c->get(Csrf::class); }
    protected function settingsService(): SettingsService { return $this->c->get(SettingsService::class); }
    protected function auditService(): AuditService   { return $this->c->get(AuditService::class); }
    protected function residentRepo(): ResidentRepository { return $this->c->get(ResidentRepository::class); }
    protected function announcementService(): AnnouncementService { return $this->c->get(AnnouncementService::class); }
    protected function updateService(): UpdateService { return $this->c->get(UpdateService::class); }

    /** @template T @param class-string<T> $id @return T */
    protected function svc(string $id): mixed
    {
        return $this->c->get($id);
    }

    protected function user(): ?User
    {
        return $this->auth()->user();
    }

    protected function requireUser(): User
    {
        return $this->auth()->requireUser();
    }

    protected function requirePermission(string $permission): User
    {
        return $this->auth()->requirePermission($permission);
    }

    /** The resident record behind a `resident` account (null for staff). */
    protected function currentResidentId(): ?int
    {
        return $this->user()?->residentId;
    }

    /* ─────────────────────────── responses ─────────────────────────── */

    /** @param array<string, mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        $this->shareViewData();

        return Response::html($this->renderer()->render($template, $data), $status);
    }

    /** @param array<string, mixed> $data */
    protected function json(mixed $data, int $status = 200): Response
    {
        if (is_array($data) && !isset($data['ok']) && $status < 400) {
            $data = ['ok' => true] + $data;
        }

        return Response::json($data, $status);
    }

    protected function jsonError(string $message, int $status = 400, array $extra = []): Response
    {
        return Response::json(['ok' => false, 'error' => $message] + $extra, $status);
    }

    protected function redirect(string $to, int $status = 302): Response
    {
        $url = str_starts_with($to, 'http') ? $to : $this->request()->url($to);

        return Response::redirect($url, $status);
    }

    protected function back(Request $request, string $fallback = '/'): Response
    {
        $referer = $request->referer();
        $target = $referer !== '' ? $referer : $request->url($fallback);

        return Response::redirect($target);
    }

    protected function noContent(): Response
    {
        return Response::noContent();
    }

    /* ─────────────────────────── flash ─────────────────────────── */

    protected function flash(string $type, string $message): void
    {
        $this->session()->flash($type, $message);
    }

    protected function flashSuccess(string $message): void { $this->flash('success', $message); }
    protected function flashError(string $message): void   { $this->flash('error', $message); }
    protected function flashInfo(string $message): void    { $this->flash('info', $message); }
    protected function flashWarning(string $message): void { $this->flash('warning', $message); }

    /** @param array<string, array<int, string>> $errors @param array<string, mixed> $input */
    protected function withErrors(array $errors, array $input = []): void
    {
        $this->session()->flash('_errors', $errors);
        $this->session()->set('_old_input', $input);
    }

    /* ─────────────────────────── validation ─────────────────────────── */

    /**
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     * @return array<string, mixed> validated data
     * @throws RoutingException 422 with the field errors for JSON callers
     */
    protected function validate(Request $request, array $rules, array $labels = [], array $messages = []): array
    {
        $validator = Validator::make($request->all(), $rules, $labels, $messages);
        if ($validator->passes()) {
            return $validator->validated();
        }

        if ($request->expectsJson()) {
            throw new RoutingException($validator->firstError() ?? 'Ошибка валидации', 422, [], null);
        }

        $this->withErrors($validator->errors(), $request->all());
        throw RoutingException::badRequest($validator->firstError() ?? 'Ошибка валидации');
    }

    /** Same as validate() but returns errors instead of throwing. */
    protected function validateSoft(Request $request, array $rules, array $labels = []): Validator
    {
        $validator = Validator::make($request->all(), $rules, $labels);
        $validator->validate();

        return $validator;
    }

    /* ─────────────────────────── request helpers ─────────────────────────── */

    protected function request(): Request
    {
        return $this->c->get(Request::class);
    }

    protected function page(Request $request): int
    {
        return max(1, $request->int('page', 1));
    }

    protected function perPage(Request $request, int $default = 25): int
    {
        $allowed = $this->config()->list('ui.per_page_options') ?: [10, 25, 50, 100];
        $value = $request->int('per_page', $default);
        if (!in_array($value, array_map('intval', $allowed), true)) {
            return $default;
        }

        return $value;
    }

    /** @return array{sort: string, dir: string} */
    protected function sorting(Request $request, array $allowed, string $defaultSort = 'id', string $defaultDir = 'desc'): array
    {
        $sort = $request->str('sort', $defaultSort);
        if (!in_array($sort, $allowed, true)) {
            $sort = $defaultSort;
        }
        $dir = strtolower($request->str('dir', $defaultDir)) === 'asc' ? 'asc' : 'desc';

        return [$sort, $dir];
    }

    /** Collect the common table filters from the query string. @return array<string, mixed> */
    protected function filters(Request $request, array $keys = []): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = $request->input($key);
            if ($value !== null && $value !== '') {
                $out[$key] = is_string($value) ? trim($value) : $value;
            }
        }

        return $out;
    }

    /** Query string preserving the current filters (for pagination links). */
    protected function queryString(Request $request, array $override = []): string
    {
        $query = array_merge($request->query, $override);
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');

        return $query === [] ? '' : '?' . http_build_query($query);
    }

    /* ─────────────────────────── view data ─────────────────────────── */

    protected function shareViewData(): void
    {
        $view = $this->renderer();
        if ($view->shared('basipShared') === true) {
            return;
        }

        $request = $this->c->has(Request::class) ? $this->request() : Request::make('GET', '/');
        $user = $this->user();
        $settings = $this->settingsService();
        $config = $this->config();

        $theme = 'system';
        if ($user !== null) {
            $theme = $user->theme();
        }
        $cookieTheme = $request->cookie('basip_theme');
        if (is_string($cookieTheme) && in_array($cookieTheme, ['light', 'dark', 'system'], true)) {
            $theme = $cookieTheme;
        }

        $resident = null;
        if ($user !== null && $user->residentId !== null) {
            $resident = $this->residentRepo()->findById($user->residentId);
        }

        $updateAvailable = false;
        $latestVersion = null;
        if ($config->bool('update.enabled') && $user !== null && $user->can('update.check')) {
            $updateAvailable = (bool) $settings->get('update.available', false);
            $latestVersion = $settings->get('update.latest_version');
        }

        $announcements = [];
        try {
            $announcements = $this->announcementService()->visible($user, $resident !== null && $resident->hasDebt());
        } catch (\Throwable) {
            $announcements = [];
        }

        $session = $this->session();
        $errors = (array) ($session->flashed('_errors') ?? []);

        $view->shareMany([
            'basipShared' => true,
            'app'         => $this->app(),
            'config'      => $config,
            'settings'    => $settings->all(),
            'user'        => $user,
            'resident'    => $resident,
            'guard'       => $this->auth(),
            'basePath'    => $request->basePath(),
            'path'        => $request->path,
            'query'       => $request->query,
            'request'     => $request,
            'cspNonce'    => $this->c->has('cspNonce') ? (string) $this->c->get('cspNonce') : '',
            'theme'       => $theme,
            'flash'       => $session->allFlashed(),
            'errors'      => $errors,
            'old'         => (array) ($session->get('_old_input') ?? []),
            'announcements' => $announcements,
            'updateAvailable' => $updateAvailable,
            'latestVersion'   => $latestVersion,
            'version'     => App::VERSION,
            'appName'     => $settings->string('ui.organisation_name', '') ?: $config->string('app.name', 'BAS-IP'),
            'shortName'   => $config->string('app.short_name', 'BAS-IP'),
            'currency'    => $config->string('billing.currency_symbol', '₽'),
            'locale'      => $user?->locale ?? $config->string('ui.language', 'ru'),
            'nav'         => $this->navigation($user),
            'permissions' => $user !== null ? $this->permissionMap($user) : [],
            'publicSettings' => $settings->publicPayload(),
            'generatedAt' => date('c'),
        ]);
    }

    protected function app(): App
    {
        return $this->c->get(App::class);
    }

    /**
     * Sidebar navigation, filtered by permission.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function navigation(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $items = [
            ['section' => 'Обзор'],
            ['title' => 'Панель управления', 'path' => '/panel', 'icon' => 'gauge', 'permission' => 'gate.open'],
            ['title' => 'Личный кабинет', 'path' => '/cabinet', 'icon' => 'home', 'role' => 'resident'],
        ];

        if ($user->isStaff()) {
            $items[] = ['section' => 'Администрирование'];
            $items[] = ['title' => 'Дашборд', 'path' => '/admin', 'icon' => 'layout-dashboard', 'permission' => 'residents.view'];
            $items[] = ['title' => 'Жители', 'path' => '/admin/residents', 'icon' => 'users', 'permission' => 'residents.view', 'badge' => 'residents'];
            $items[] = ['title' => 'Оплаты', 'path' => '/admin/billing', 'icon' => 'wallet', 'permission' => 'billing.view'];
            $items[] = ['title' => 'Начисления', 'path' => '/admin/billing/wizard', 'icon' => 'calculator', 'permission' => 'billing.charge.create'];
            $items[] = ['title' => 'Счета', 'path' => '/admin/billing/invoices', 'icon' => 'receipt', 'permission' => 'billing.view'];
            $items[] = ['title' => 'Оплаты (реестр)', 'path' => '/admin/billing/payments', 'icon' => 'banknote', 'permission' => 'billing.view'];
            $items[] = ['title' => 'Должники', 'path' => '/admin/billing/debtors', 'icon' => 'alert-triangle', 'permission' => 'billing.view', 'badge' => 'debtors'];
            $items[] = ['section' => 'Шлагбаум'];
            $items[] = ['title' => 'Журнал открываний', 'path' => '/admin/gate', 'icon' => 'history', 'permission' => 'gate.log'];
            $items[] = ['title' => 'Гостевые доступы', 'path' => '/admin/guest', 'icon' => 'user-plus', 'permission' => 'guest.view', 'badge' => 'guests'];
            $items[] = ['title' => 'Устройства', 'path' => '/admin/gate/devices', 'icon' => 'cpu', 'permission' => 'devices.manage'];
            $items[] = ['title' => 'Расписания', 'path' => '/admin/gate/schedules', 'icon' => 'clock', 'permission' => 'schedules.manage'];
            $items[] = ['section' => 'Коммуникации'];
            $items[] = ['title' => 'Рассылки', 'path' => '/admin/notifications', 'icon' => 'send', 'permission' => 'notifications.view'];
            $items[] = ['title' => 'Объявления', 'path' => '/admin/announcements', 'icon' => 'megaphone', 'permission' => 'announcements.manage'];
            $items[] = ['section' => 'Система'];
            $items[] = ['title' => 'Пользователи', 'path' => '/admin/users', 'icon' => 'shield', 'permission' => 'users.view'];
            $items[] = ['title' => 'Данные', 'path' => '/admin/data', 'icon' => 'database', 'permission' => 'data.export'];
            $items[] = ['title' => 'Настройки', 'path' => '/admin/settings', 'icon' => 'settings', 'permission' => 'settings.view'];
            $items[] = ['title' => 'Журнал аудита', 'path' => '/admin/audit', 'icon' => 'scroll-text', 'permission' => 'audit.view'];
            $items[] = ['title' => 'Диагностика', 'path' => '/admin/system', 'icon' => 'activity', 'permission' => 'system.view'];
            $items[] = ['title' => 'Обновления', 'path' => '/admin/updates', 'icon' => 'download-cloud', 'permission' => 'update.check'];
        }

        $items[] = ['section' => 'Аккаунт'];
        $items[] = ['title' => 'Профиль', 'path' => '/profile', 'icon' => 'user'];
        $items[] = ['title' => 'Справка', 'path' => '/help', 'icon' => 'life-buoy'];

        $filtered = [];
        foreach ($items as $item) {
            if (isset($item['section'])) {
                $filtered[] = $item;
                continue;
            }
            if (isset($item['role']) && $user->role !== $item['role']) {
                continue;
            }
            if (isset($item['permission']) && !$user->can((string) $item['permission'])) {
                continue;
            }
            $filtered[] = $item;
        }

        // Drop section headers that ended up with no items below them.
        $out = [];
        foreach ($filtered as $index => $item) {
            if (isset($item['section'])) {
                $hasChildren = false;
                for ($i = $index + 1; $i < count($filtered); $i++) {
                    if (isset($filtered[$i]['section'])) {
                        break;
                    }
                    $hasChildren = true;
                    break;
                }
                if ($hasChildren) {
                    $out[] = $item;
                }
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    /** @return array<string, bool> */
    protected function permissionMap(User $user): array
    {
        $map = [];
        foreach (\BasIp\Auth\Permissions::ALL as $permission) {
            $map[$permission] = $user->can($permission);
        }

        return $map;
    }

    /* ─────────────────────────── misc ─────────────────────────── */

    protected function notFound(string $message = 'Запись не найдена'): never
    {
        throw RoutingException::notFound($message);
    }

    protected function forbidden(string $message = 'Недостаточно прав'): never
    {
        throw RoutingException::forbidden($message);
    }

    /** Normalise an uploaded file into a string, rejecting oversize content. */
    protected function uploadedContent(Request $request, string $field = 'file', array $extensions = ['csv', 'json']): string
    {
        $file = $request->file($field);
        if ($file === null) {
            throw RoutingException::badRequest('Файл не загружен.');
        }
        [$ok, $error] = $file->validate($extensions, $this->config()->int('security.upload.max_bytes', 10485760));
        if (!$ok) {
            throw RoutingException::badRequest((string) $error);
        }

        return $file->contents();
    }

    /** Build a paginator-aware JSON payload. */
    protected function paginateJson(Paginator $paginator, callable $mapper): Response
    {
        return $this->json([
            'data' => array_map($mapper, $paginator->items()),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    /** Respond either with JSON or by flashing + redirecting (for AJAX-enhanced forms). */
    protected function respond(Request $request, bool $ok, string $message, string $redirect = '/', array $extra = []): Response
    {
        if ($request->expectsJson()) {
            return $ok
                ? $this->json(['message' => $message] + $extra)
                : $this->jsonError($message, 400, $extra);
        }
        $ok ? $this->flashSuccess($message) : $this->flashError($message);

        return $this->redirect($redirect);
    }
}
