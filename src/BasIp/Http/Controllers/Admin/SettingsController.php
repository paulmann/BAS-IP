<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers\Admin;

use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Http\Controller;
use BasIp\Notify\NotificationService;
use BasIp\Services\SettingsService;
use BasIp\Support\Arr;
use BasIp\Support\Str;

/**
 * Settings admin.
 *
 * Two layers are edited here:
 *   • **runtime settings** (the `settings` table) — safe to change at any time,
 *     effective immediately, survive updates;
 *   • **configuration** (`.bas-ip.config.local.php`) — structural values such as
 *     DB credentials and the device password. The UI can generate and download
 *     that file but never writes over a live one silently.
 */
final class SettingsController extends Controller
{
    /** Groups shown as tabs, in order. */
    private const GROUPS = [
        'general'  => ['label' => 'Общие', 'icon' => 'sliders'],
        'billing'  => ['label' => 'Оплаты', 'icon' => 'wallet'],
        'gate'     => ['label' => 'Шлагбаум', 'icon' => 'door-open'],
        'guest'    => ['label' => 'Гостевой доступ', 'icon' => 'user-plus'],
        'notify'   => ['label' => 'Уведомления', 'icon' => 'send'],
        'ui'       => ['label' => 'Интерфейс', 'icon' => 'palette'],
        'security' => ['label' => 'Безопасность', 'icon' => 'shield'],
        'update'   => ['label' => 'Обновления', 'icon' => 'download-cloud'],
        'system'   => ['label' => 'Система', 'icon' => 'server'],
    ];

    /** Settings editable from the UI, per group. */
    private const FIELDS = [
        'general' => [
            ['key' => 'ui.organisation_name', 'label' => 'Название организации / ЖК', 'type' => 'text', 'hint' => 'Подставляется в письма и уведомления'],
            ['key' => 'ui.support_phone', 'label' => 'Телефон поддержки', 'type' => 'text'],
            ['key' => 'ui.support_email', 'label' => 'E-mail поддержки', 'type' => 'text'],
        ],
        'billing' => [
            ['key' => 'billing.default_total', 'label' => 'Сумма обслуживания по умолчанию', 'type' => 'money', 'hint' => 'Общая сумма на шлагбаум за период'],
            ['key' => 'billing.last_allocation', 'label' => 'Способ распределения', 'type' => 'select', 'options' => ['equal' => 'Поровну', 'by_persons' => 'По числу проживающих', 'by_area' => 'По площади', 'by_share' => 'По доле']],
            ['key' => 'billing.pay_link', 'label' => 'Ссылка на оплату', 'type' => 'text', 'hint' => 'Подстановка: {apartment}, {amount}, {period}, {name}'],
            ['key' => 'billing.message_default', 'label' => 'Текст сообщения по умолчанию', 'type' => 'textarea', 'hint' => 'Используйте {name}, {amount}, {period}, {due_date}, {pay_link}'],
            ['key' => 'billing.auto_suspend_debtors', 'label' => 'Автоматически ограничивать доступ должникам', 'type' => 'bool'],
            ['key' => 'billing.suspend_after_days', 'label' => 'Через сколько дней просрочки', 'type' => 'number'],
        ],
        'gate' => [
            ['key' => 'gate.default_device', 'label' => 'Устройство по умолчанию', 'type' => 'text'],
            ['key' => 'gate.open_cooldown', 'label' => 'Пауза между открытиями, сек', 'type' => 'number'],
            ['key' => 'gate.require_access_flag', 'label' => 'Требовать галочку «доступ»', 'type' => 'bool'],
            ['key' => 'gate.block_debtors', 'label' => 'Блокировать открытие при долге', 'type' => 'bool'],
            ['key' => 'gate.debt_threshold', 'label' => 'Порог долга', 'type' => 'money'],
            ['key' => 'gate.quiet_hours_enabled', 'label' => 'Включить «тихие часы»', 'type' => 'bool'],
            ['key' => 'gate.quiet_hours_start', 'label' => 'Начало тихих часов', 'type' => 'time'],
            ['key' => 'gate.quiet_hours_end', 'label' => 'Конец тихих часов', 'type' => 'time'],
            ['key' => 'gate.allow_guest_at_night', 'label' => 'Разрешать гостевой доступ ночью', 'type' => 'bool'],
        ],
        'guest' => [
            ['key' => 'guest.default_ttl_hours', 'label' => 'Срок действия по умолчанию, часов', 'type' => 'number', 'hint' => 'Обычно 12'],
            ['key' => 'guest.max_per_resident', 'label' => 'Максимум активных на жителя', 'type' => 'number'],
            ['key' => 'guest.allow_self_service', 'label' => 'Жители могут создавать сами', 'type' => 'bool'],
            ['key' => 'guest.require_plate', 'label' => 'Требовать номер автомобиля', 'type' => 'bool'],
            ['key' => 'guest.link_base', 'label' => 'База для гостевых ссылок', 'type' => 'text', 'hint' => 'Например https://gate.example.com'],
        ],
        'notify' => [
            ['key' => 'notify.enabled', 'label' => 'Уведомления включены', 'type' => 'bool'],
            ['key' => 'notify.channels_default', 'label' => 'Каналы по умолчанию', 'type' => 'multiselect', 'options' => ['log' => 'Журнал', 'mail' => 'E-mail', 'telegram' => 'Telegram', 'sms' => 'SMS', 'webhook' => 'Webhook', 'whatsapp' => 'WhatsApp']],
            ['key' => 'notify.telegram_chat_id', 'label' => 'Chat ID администратора (Telegram)', 'type' => 'text'],
        ],
        'ui' => [
            ['key' => 'ui.theme', 'label' => 'Тема по умолчанию', 'type' => 'select', 'options' => ['system' => 'Как в системе', 'light' => 'Светлая', 'dark' => 'Тёмная']],
            ['key' => 'ui.accent', 'label' => 'Акцентный цвет', 'type' => 'select', 'options' => ['slate' => 'Графит', 'blue' => 'Синий', 'green' => 'Зелёный', 'violet' => 'Фиолетовый', 'orange' => 'Оранжевый', 'rose' => 'Розовый']],
            ['key' => 'ui.density', 'label' => 'Плотность интерфейса', 'type' => 'select', 'options' => ['comfortable' => 'Обычная', 'compact' => 'Компактная']],
            ['key' => 'ui.camera_enabled', 'label' => 'Показывать камеру', 'type' => 'bool'],
            ['key' => 'ui.dashboard_refresh', 'label' => 'Обновление дашборда, сек', 'type' => 'number'],
        ],
        'security' => [
            ['key' => 'system.maintenance_mode', 'label' => 'Режим обслуживания', 'type' => 'bool', 'hint' => 'Интерфейс закрыт, шлагбаум работает'],
            ['key' => 'system.readonly_mode', 'label' => 'Режим «только чтение»', 'type' => 'bool'],
        ],
    ];

    public function index(Request $request): Response
    {
        return $this->group($request, 'general');
    }

    public function group(Request $request, string $group = 'general'): Response
    {
        $this->requirePermission('settings.view');
        $group = isset(self::GROUPS[$group]) ? $group : 'general';

        /** @var SettingsService $settings */
        $settings = $this->svc(SettingsService::class);

        return $this->view('admin/settings/index', [
            'title'   => 'Настройки',
            'group'   => $group,
            'groups'  => self::GROUPS,
            'fields'  => self::FIELDS[$group] ?? [],
            'values'  => $this->resolveValues($group, $settings),
            'channels' => $this->svc(NotificationService::class)->channelStatus(),
            'config'  => $this->config()->publicView(),
            'sources' => $this->config()->sources(),
            'hasLocal' => $this->config()->hasLocalFile(),
            'localPath' => Str::e(\BasIp\Support\Fs::relative($this->config()->localFilePath(), $this->config()->root())),
            'secrets' => $this->config()->secrets()->names(),
            'secretsEncrypted' => $this->config()->secrets()->encrypted(),
            'definitions' => $settings->definitions(),
            'canEdit' => $this->requireUser()->can('settings.edit'),
            'updateInfo' => [
                'enabled' => $this->config()->bool('update.enabled'),
                'mode'    => $this->config()->string('update.mode', 'notify'),
                'channel' => $this->config()->string('update.channel', 'stable'),
                'repo'    => $this->config()->string('update.repo'),
                'last_check' => $settings->get('update.last_check'),
                'latest'  => $settings->string('update.latest_version', ''),
                'current' => $this->svc(\BasIp\Update\UpdateService::class)->currentVersion(),
            ],
            'dbInfo'  => $this->db()->serverInfo(),
            'phpInfo' => ['version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'memory' => ini_get('memory_limit')],
        ]);
    }

    /** @return array<string, mixed> */
    private function resolveValues(string $group, SettingsService $settings): array
    {
        $values = [];
        foreach (self::FIELDS[$group] ?? [] as $field) {
            $key = (string) $field['key'];
            $value = $settings->get($key);
            if ($value === null && str_starts_with($key, 'billing.default_total')) {
                $value = $this->config()->get('billing.default_total');
            }
            $values[$key] = $value;
        }
        // Extra context values rendered read-only.
        $values['_participants'] = $this->residentRepo()->countBillable();
        $values['_active'] = $this->residentRepo()->countActive();
        $values['_currency'] = $this->config()->string('billing.currency_symbol', '₽');

        return $values;
    }

    public function save(Request $request, string $group = 'general'): Response
    {
        $user = $this->requirePermission('settings.edit');
        if ($this->settingsService()->bool('system.readonly_mode', false) && !$user->isAdmin()) {
            return $this->respond($request, false, 'Включён режим «только чтение».', '/admin/settings/' . $group);
        }
        $group = isset(self::GROUPS[$group]) ? $group : 'general';
        /** @var SettingsService $settings */
        $settings = $this->svc(SettingsService::class);

        $before = $this->resolveValues($group, $settings);
        $changes = [];

        foreach (self::FIELDS[$group] ?? [] as $field) {
            $key  = (string) $field['key'];
            $type = (string) $field['type'];
            $name = $this->settingInputName($key);

            $value = match ($type) {
                'bool'        => $request->bool($name, false),
                'number'      => $request->filled($name) ? $request->int($name) : null,
                'money'       => $request->filled($name) ? $request->float($name) : null,
                'multiselect' => array_values($request->array($name)),
                'textarea'    => (string) $request->input($name, ''),
                default       => $request->filled($name) ? $request->str($name) : null,
            };

            // Skip fields that were not part of the submitted form at all
            // (e.g. a hidden group rendered on another tab).
            if ($value === null && !$request->has($name) && $type !== 'bool' && $type !== 'multiselect') {
                continue;
            }

            if ($this->normalizeForCompare($before[$key] ?? null) !== $this->normalizeForCompare($value)) {
                $changes[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
            $settings->set($key, $value, $user->login);
        }

        if ($changes !== []) {
            $this->auditService()->record('settings.update', $user, [
                'subject_type' => 'settings', 'subject_id' => $group, 'subject_label' => self::GROUPS[$group]['label'] ?? $group,
                'before' => array_column($changes, 'from'), 'after' => array_column($changes, 'to'),
                'meta' => ['group' => $group, 'changed' => array_keys($changes)],
            ], $request);
        }

        // Quiet hours / maintenance live in the gate & security groups.
        if ($group === 'gate' || $group === 'security') {
            /** @var \BasIp\Services\DataService $data */
            $data = $this->svc(\BasIp\Services\DataService::class);
            $data->setMaintenance($settings->bool('system.maintenance_mode', false));
        }

        $this->svc(\BasIp\Services\StatisticsService::class)->flush();

        return $this->respond(
            $request, true,
            $changes === [] ? 'Изменений нет.' : sprintf('Сохранено изменений: %d.', count($changes)),
            '/admin/settings/' . $group
        );
    }

    /** Store a secret into the encrypted keystore. */
    public function saveSecret(Request $request, string $name = ''): Response
    {
        $user = $this->requirePermission('settings.edit');
        $name = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? '');
        if ($name === '') {
            return $this->respond($request, false, 'Не указано имя секрета.', '/admin/settings');
        }

        $value = (string) $request->input('value', '');
        $secrets = $this->config()->secrets();

        if ($request->bool('remove')) {
            $secrets->forget($name);
            $this->auditService()->security('secret.remove', $user, ['subject_type' => 'secret', 'subject_id' => $name], $request);

            return $this->respond($request, true, 'Секрет «' . $name . '» удалён.', '/admin/settings/system');
        }
        if ($value === '') {
            return $this->respond($request, false, 'Пустое значение не сохранено.', '/admin/settings/system');
        }

        $secrets->set($name, $value);
        $this->auditService()->security('secret.set', $user, ['subject_type' => 'secret', 'subject_id' => $name, 'meta' => ['encrypted' => $secrets->encrypted()]], $request);

        return $this->respond($request, true, sprintf(
            'Секрет «%s» сохранён%s. Используйте в конфиге как ${secret:%s}.',
            $name, $secrets->encrypted() ? ' (зашифрован)' : ' (БЕЗ шифрования — задайте app.key!)', $name
        ), '/admin/settings/system');
    }

    /** Generate a ready-to-save `.bas-ip.config.local.php` from the current config. */
    public function downloadLocalConfig(Request $request): Response
    {
        $this->requirePermission('settings.view');

        $view = [
            'app' => Arr::only($this->config()->section('app'), ['name', 'env', 'debug', 'url', 'timezone', 'key']),
            'db'  => [
                'default' => $this->config()->string('db.default'),
                'prefix'  => $this->config()->string('db.prefix'),
                'connections' => array_map(
                    static fn (array $conn): array => Arr::except($conn, ['options']),
                    $this->config()->section('db.connections')
                ),
            ],
            'device' => Arr::only($this->config()->section('device'), ['driver', 'base_url', 'login', 'verify_tls', 'timeout', 'camera', 'lock', 'sip']),
            'billing' => $this->config()->section('billing'),
            'guest'   => $this->config()->section('guest'),
            'notify'  => Arr::only($this->config()->section('notify'), ['default', 'channels', 'mail']),
            'update'  => $this->config()->section('update'),
            'auth'    => Arr::only($this->config()->section('auth'), ['ip_allowlist', 'two_factor', 'session']),
        ];

        $body = "<?php\n\n/**\n * BAS-IP — локальная конфигурация.\n *\n * Сгенерировано из текущих настроек " . date('d.m.Y H:i') . ".\n * Файл в .gitignore: положите его в корень проекта как\n *   .bas-ip.config.local.php\n * и выставьте chmod 600.\n *\n * Пароли заменены на \${secret:NAME} — задайте их командой\n *   php bin/bas-ip secret:set NAME\n */\n\ndeclare(strict_types=1);\n\nreturn " . $this->export($this->redactSecrets($view)) . ";\n";

        $this->auditService()->info('settings.config_export', $this->requireUser(), 'config', 'local', null, $request);

        return (new Response($body, 200, ['Content-Type' => 'application/x-php; charset=UTF-8']))
            ->header('Content-Disposition', 'attachment; filename=".bas-ip.config.local.php"');
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function redactSecrets(array $data): array
    {
        $walk = function (array $node, string $path = '') use (&$walk): array {
            foreach ($node as $key => $value) {
                $full = $path === '' ? (string) $key : $path . '.' . $key;
                if (is_array($value)) {
                    $node[$key] = $walk($value, $full);
                    continue;
                }
                $lower = strtolower((string) $key);
                if (in_array($lower, ['password', 'secret', 'token', 'bot_token', 'api_key', 'key'], true) && is_string($value) && $value !== '') {
                    if ($lower === 'key' && $path === 'app') {
                        continue;   // app.key must stay in the file
                    }
                    $node[$key] = '${secret:' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $full) ?? $full) . '}';
                }
            }

            return $node;
        };

        return $walk($data);
    }

    /** @param mixed $value */
    private function export($value, int $depth = 0): string
    {
        $indent = str_repeat('    ', $depth + 1);
        if (!is_array($value)) {
            return var_export($value, true);
        }
        if ($value === []) {
            return '[]';
        }
        $lines = [];
        $isList = array_is_list($value);
        foreach ($value as $key => $item) {
            $lines[] = $indent . ($isList ? '' : var_export((string) $key, true) . ' => ') . $this->export($item, $depth + 1) . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth) . ']';
    }

    private function settingInputName(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    private function normalizeForCompare(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return (string) json_encode($value);
        }
        if (is_float($value)) {
            return number_format($value, 4, '.', '');
        }
        if ($value === null) {
            return '';
        }
        if (is_numeric($value)) {
            return number_format((float) $value, 4, '.', '');
        }

        return trim((string) $value);
    }
}
