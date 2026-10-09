<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers\Admin;

use BasIp\Core\Logger;
use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Http\Controller;
use BasIp\Services\AnnouncementService;
use BasIp\Services\ApiTokenService;
use BasIp\Services\AuditService;
use BasIp\Services\DataService;
use BasIp\Update\UpdateService;

/**
 * System administration: diagnostics, logs, cache/queue maintenance,
 * audit trail, API tokens, announcements and self-update.
 */
final class SystemController extends Controller
{
    private function data(): DataService
    {
        return $this->svc(DataService::class);
    }

    public function index(Request $request): Response
    {
        $this->requirePermission('system.view');

        return $this->view('admin/system/index', [
            'title'   => 'Состояние системы',
            'health'  => $this->data()->health(true),
            'info'    => $this->data()->systemInfo(),
            'tables'  => $this->data()->tableSizes(),
            'queue'   => $this->svc(\BasIp\Notify\NotificationService::class)->queueStats(),
            'lastCron' => $this->data()->lastCronRun(),
            'maintenance' => $this->data()->isMaintenance(),
            'logs'    => $this->svc(Logger::class)->availableFiles(),
            'configSources' => $this->config()->sources(),
            'canManage' => $this->requireUser()->can('system.manage'),
            'updateInfo' => $this->updateInfo(),
        ]);
    }

    public function doctor(Request $request): Response
    {
        $this->requirePermission('system.view');
        $issues = $this->data()->doctor();

        if ($request->expectsJson()) {
            return $this->json(['ok' => true, 'issues' => $issues, 'checked_at' => date('c')]);
        }

        return $this->view('admin/system/doctor', [
            'title'  => 'Диагностика',
            'issues' => $issues,
            'counts' => [
                'error' => count(array_filter($issues, static fn ($i) => $i['level'] === 'error')),
                'warn'  => count(array_filter($issues, static fn ($i) => $i['level'] === 'warn')),
                'info'  => count(array_filter($issues, static fn ($i) => $i['level'] === 'info')),
            ],
        ]);
    }

    public function logs(Request $request): Response
    {
        $this->requirePermission('logs.view');

        return $this->view('admin/system/logs', [
            'title' => 'Журналы',
            'files' => $this->svc(Logger::class)->availableFiles(),
            'channels' => array_keys((array) $this->config()->get('logging.channels', [])),
            'levels' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
        ]);
    }

    public function logChannel(Request $request, string $channel = 'app'): Response
    {
        $this->requirePermission('logs.view');
        $channel = preg_replace('/[^a-z0-9_-]/i', '', $channel) ?? 'app';
        $logger = $this->svc(Logger::class);

        $entries = $logger->tail($channel, min(2000, max(10, $request->int('lines', 300))), $request->str('level') ?: null, $request->str('q') ?: null);

        if ($request->expectsJson()) {
            return $this->json(['ok' => true, 'channel' => $channel, 'entries' => $entries]);
        }

        return $this->view('admin/system/log-view', [
            'title'   => 'Журнал: ' . $channel,
            'channel' => $channel,
            'entries' => $entries,
            'files'   => $logger->availableFiles(),
            'query'   => $request->query,
            'canTruncate' => $this->requireUser()->can('system.manage'),
        ]);
    }

    public function truncateLog(Request $request, string $channel = 'app'): Response
    {
        $user = $this->requirePermission('system.manage');
        $channel = preg_replace('/[^a-z0-9_-]/i', '', $channel) ?? 'app';
        $count = $this->svc(Logger::class)->truncate($channel);
        $this->auditService()->info('system.log_truncate', $user, 'log', $channel, null, $request, ['files' => $count]);

        return $this->respond($request, true, sprintf('Удалено файлов журнала: %d.', $count), '/admin/system/logs/' . $channel);
    }

    public function clearCache(Request $request): Response
    {
        $user = $this->requirePermission('system.manage');
        $count = $this->data()->clearCache($user, $request);
        $files = $this->data()->clearExpiredFiles();
        $this->svc(\BasIp\Device\DeviceManager::class)->flush();
        $this->svc(\BasIp\Services\StatisticsService::class)->flush();

        return $this->respond($request, true, sprintf(
            'Кэш очищен (записей: %d, файлов: %d).', $count, array_sum($files)
        ), '/admin/system');
    }

    public function maintenance(Request $request): Response
    {
        $user = $this->requirePermission('system.manage');
        $on = $request->bool('on');
        $this->data()->setMaintenance($on, $request->str('message', ''));
        $this->auditService()->info($on ? 'system.maintenance_on' : 'system.maintenance_off', $user, 'system', 'maintenance', null, $request);

        return $this->respond($request, true, $on ? 'Режим обслуживания включён.' : 'Режим обслуживания выключен.', '/admin/system');
    }

    public function runCron(Request $request): Response
    {
        $this->requirePermission('system.manage');
        $report = $this->data()->runScheduledJobs();

        // Also drain a slice of the notification queue.
        /** @var \BasIp\Notify\QueueWorker $worker */
        $worker = $this->svc(\BasIp\Notify\QueueWorker::class);
        $report['queue'] = $worker->run(100, 30);

        $expired = $this->svc(\BasIp\Services\GuestPassService::class)->expireStale();
        $overdue = $this->svc(\BasIp\Services\BillingService::class)->markOverdue();
        $report['guest_expired'] = $expired;
        $report['invoices_overdue'] = $overdue;

        return $this->respond($request, true, 'Плановые задачи выполнены.', '/admin/system', $report);
    }

    public function prune(Request $request): Response
    {
        $user = $this->requirePermission('system.manage');
        $result = $this->data()->prune([
            'gate_events' => $request->int('gate_days', 730),
            'audit_log' => $request->int('audit_days', 365),
            'login_attempts' => $request->int('login_days', 90),
            'outbox' => $request->int('outbox_days', 60),
            'billing_history' => $request->int('history_days', 1825),
        ], $user);

        $guests = $this->svc(\BasIp\Services\GuestPassService::class)->purgeOld($request->int('guest_days', 90));
        $result['guest_passes'] = $guests;

        $total = array_sum(array_filter($result, 'is_int'));

        return $this->respond($request, true, sprintf('Удалено устаревших записей: %d.', $total), '/admin/system', ['result' => $result]);
    }

    public function phpinfo(Request $request): Response
    {
        $this->requirePermission('system.view');
        ob_start();
        phpinfo(INFO_ALL & ~INFO_ENVIRONMENT & ~INFO_VARIABLES);
        $html = (string) ob_get_clean();

        // Strip everything except the body so it inherits our styles reasonably.
        $html = preg_replace('/^.*<body[^>]*>/s', '', $html) ?? $html;
        $html = preg_replace('/<\/body>.*$/s', '', $html) ?? $html;

        return Response::html(
            '<style>body{font-family:ui-sans-serif,system-ui,sans-serif}table{width:100%;border-collapse:collapse}'
            . 'td,th{border:1px solid rgba(128,128,128,.25);padding:.4rem .6rem;font-size:.8rem;text-align:left;word-break:break-word}'
            . 'h1,h2{font-size:1.1rem;margin:1.5rem 0 .5rem}a{color:#2563eb}</style>' . $html
        );
    }

    public function queryLog(Request $request): Response
    {
        $this->requirePermission('system.view');
        $db = $this->db();
        $db->enableQueryLog();

        // Warm the same queries the dashboard runs so the log is meaningful.
        $this->svc(\BasIp\Services\StatisticsService::class)->flush();
        $this->svc(\BasIp\Services\StatisticsService::class)->overview(0);

        return $this->json([
            'ok' => true,
            'stats' => $db->stats(),
            'queries' => array_slice($db->queryLog(), -100),
        ]);
    }

    /* ─────────────────────────── audit ─────────────────────────── */

    public function audit(Request $request): Response
    {
        $this->requirePermission('audit.view');
        /** @var AuditService $audit */
        $audit = $this->svc(AuditService::class);

        $filters = $this->filters($request, ['q', 'action', 'subject_type', 'subject_id', 'user_id', 'from', 'to', 'security']);
        $paginator = $audit->query($filters, $this->perPage($request, 50), $this->page($request));

        return $this->view('admin/audit', [
            'title'   => 'Журнал аудита',
            'entries' => $paginator,
            'filters' => $filters,
            'query'   => $request->query,
            'actions' => $audit->distinctActions(),
            'users'   => $this->db()->table('users')->select('id', 'login', 'full_name')->whereNull('deleted_at')->orderBy('login')->get(),
            'canExport' => $this->requireUser()->can('data.export'),
            'retention' => $this->config()->int('security.audit.retention_days', 365),
        ]);
    }

    public function exportAudit(Request $request): Response
    {
        $this->requirePermission('data.export');
        /** @var AuditService $audit */
        $audit = $this->svc(AuditService::class);
        $filters = $this->filters($request, ['action', 'subject_type', 'user_id', 'from', 'to', 'security', 'q']);

        $delimiter = (string) $this->config()->string('data.csv_delimiter', ';');
        $rows = [['Дата', 'Действие', 'Кто', 'Роль', 'Объект', 'ID', 'Описание', 'IP', 'Изменения']];
        $page = 1;
        do {
            $paginator = $audit->query($filters, 500, $page);
            foreach ($paginator->items() as $entry) {
                $changes = json_decode((string) ($entry['changes'] ?? ''), true);
                $rows[] = [
                    (string) ($entry['occurred_at'] ?? ''), (string) ($entry['action'] ?? ''),
                    (string) ($entry['actor_name'] ?? ''), (string) ($entry['actor_role'] ?? ''),
                    (string) ($entry['subject_type'] ?? ''), (string) ($entry['subject_id'] ?? ''),
                    (string) ($entry['subject_label'] ?? ''), (string) ($entry['ip'] ?? ''),
                    is_array($changes) && isset($changes['diff']) ? (string) json_encode($changes['diff'], JSON_UNESCAPED_UNICODE) : '',
                ];
            }
            $page++;
        } while ($paginator->hasMore() && $page <= 200);

        $csv = ($this->config()->bool('data.csv_bom', true) ? "\xEF\xBB\xBF" : '');
        foreach ($rows as $row) {
            $csv .= implode($delimiter, array_map(static function ($v) use ($delimiter): string {
                $v = (string) $v;

                return str_contains($v, $delimiter) || str_contains($v, '"') || str_contains($v, "\n")
                    ? '"' . str_replace('"', '""', $v) . '"' : $v;
            }, $row)) . "\r\n";
        }

        return Response::csv($csv, sprintf('audit-%s.csv', date('Ymd-His')));
    }

    /* ─────────────────────────── API tokens ─────────────────────────── */

    public function apiTokens(Request $request): Response
    {
        $this->requirePermission('api.view');

        return $this->view('admin/api-tokens', [
            'title'  => 'API-токены',
            'tokens' => $this->svc(ApiTokenService::class)->all(),
            'scopes' => ApiTokenService::PRESET_SCOPES,
            'newToken' => $this->session()->pull('new_api_token'),
            'baseUrl' => rtrim($this->config()->string('app.url', ''), '/') ?: ($request->scheme . '://' . $request->header('host')),
            'canCreate' => $this->requireUser()->can('api.create'),
            'canDelete' => $this->requireUser()->can('api.delete'),
        ]);
    }

    public function createApiToken(Request $request): Response
    {
        $user = $this->requirePermission('api.create');
        $scopes = $request->array('scopes');
        $expires = $request->str('expires_at');

        $result = $this->svc(ApiTokenService::class)->create(
            $request->str('name', 'Токен'),
            $scopes,
            $user->id,
            $expires !== '' ? date('Y-m-d H:i:s', (int) strtotime($expires)) : null,
            $user,
            $request
        );

        // The plain token is shown exactly once.
        $this->session()->set('new_api_token', ['token' => $result['token'], 'name' => $request->str('name', 'Токен'), 'prefix' => $result['prefix']]);

        return $this->respond($request, true, 'Токен создан. Скопируйте его — повторно показать не получится.', '/admin/api-tokens');
    }

    public function revokeApiToken(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('api.delete');
        $ok = $request->bool('delete')
            ? $this->svc(ApiTokenService::class)->delete((int) $id, $user, $request)
            : $this->svc(ApiTokenService::class)->revoke((int) $id, $user, $request);

        return $this->respond($request, $ok, $ok ? ($request->bool('delete') ? 'Токен удалён.' : 'Токен отозван.') : 'Не найдено.', '/admin/api-tokens');
    }

    /* ─────────────────────────── announcements ─────────────────────────── */

    public function announcements(Request $request): Response
    {
        $this->requirePermission('announcements.manage');

        return $this->view('admin/announcements', [
            'title'   => 'Объявления',
            'items'   => $this->svc(AnnouncementService::class)->search($this->filters($request, ['q', 'level', 'published']), $this->perPage($request, 20), $this->page($request)),
            'levels'  => ['info' => 'Информация', 'success' => 'Успех', 'warning' => 'Внимание', 'urgent' => 'Срочно'],
            'audiences' => ['all' => 'Все', 'active' => 'Активные жители', 'debtors' => 'Должники', 'admins' => 'Только сотрудники'],
            'query'   => $request->query,
        ]);
    }

    public function storeAnnouncement(Request $request): Response
    {
        $user = $this->requirePermission('announcements.manage');
        $id = $this->svc(AnnouncementService::class)->save($request->only([
            'title', 'body', 'level', 'audience', 'is_published', 'pin', 'publish_at', 'expires_at',
        ]), $request->filled('id') ? $request->int('id') : null, $user, $request);

        return $this->respond($request, true, 'Объявление сохранено.', '/admin/announcements');
    }

    public function deleteAnnouncement(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('announcements.manage');
        $ok = $this->svc(AnnouncementService::class)->delete((int) $id, $user, $request);

        return $this->respond($request, $ok, $ok ? 'Объявление удалено.' : 'Не найдено.', '/admin/announcements');
    }

    /* ─────────────────────────── updates ─────────────────────────── */

    public function updates(Request $request): Response
    {
        $this->requirePermission('update.check');

        return $this->view('admin/updates', [
            'title'    => 'Обновления',
            'info'     => $this->updateInfo(),
            'snapshots' => $this->svc(UpdateService::class)->snapshots(),
            'preserve' => (array) $this->config()->get('update.preserve', []),
            'history'  => $this->svc(Logger::class)->tail('update', 100),
            'canRun'   => $this->requireUser()->can('update.run'),
            'canRollback' => $this->requireUser()->can('update.rollback'),
            'changelog' => $this->changelog(),
        ]);
    }

    public function checkUpdate(Request $request): Response
    {
        $this->requirePermission('update.check');
        $result = $this->svc(UpdateService::class)->check($request->bool('force', true));

        if (!$result['ok']) {
            return $request->expectsJson()
                ? $this->jsonError((string) ($result['error'] ?? 'Ошибка проверки'), 502)
                : $this->respond($request, false, (string) ($result['error'] ?? 'Ошибка проверки'), '/admin/updates');
        }

        $latest = $result['latest'];
        $message = !empty($result['update_available'])
            ? sprintf('Доступна новая версия %s (установлена %s).', $latest?->version(), $result['current'])
            : sprintf('Установлена актуальная версия %s.', $result['current']);

        if ($request->expectsJson()) {
            return $this->json([
                'ok' => true,
                'update_available' => (bool) $result['update_available'],
                'current' => $result['current'],
                'latest' => $latest?->toArray(),
                'message' => $message,
            ]);
        }
        $this->flashInfo($message);

        return $this->redirect('/admin/updates');
    }

    public function installUpdate(Request $request): Response
    {
        $user = $this->requirePermission('update.run');
        if ($request->str('confirm') !== 'UPDATE') {
            return $this->respond($request, false, 'Для подтверждения введите слово UPDATE.', '/admin/updates');
        }

        set_time_limit(600);
        $result = $this->svc(UpdateService::class)->install(null, $user, $request, $request->bool('skip_backup'));

        if (!$result['ok']) {
            return $request->expectsJson()
                ? $this->jsonError((string) ($result['error'] ?? 'Ошибка обновления'), 500, ['log' => $result['log'] ?? []])
                : $this->respond($request, false, 'Обновление не выполнено: ' . ($result['error'] ?? '?'), '/admin/updates');
        }

        $message = sprintf('Обновление %s → %s завершено (файлов: %d, миграций: %d).', $result['from'], $result['to'], $result['files'], count($result['migrations']));

        if ($request->expectsJson()) {
            return $this->json(['ok' => true, 'message' => $message] + $result);
        }
        $this->session()->set('update_log', $result['log']);
        $this->flashSuccess($message);

        return $this->redirect('/admin/updates');
    }

    public function rollback(Request $request): Response
    {
        $user = $this->requirePermission('update.rollback');
        if ($request->str('confirm') !== 'ROLLBACK') {
            return $this->respond($request, false, 'Для подтверждения введите слово ROLLBACK.', '/admin/updates');
        }

        $result = $this->svc(UpdateService::class)->rollback($user, $request);

        return $this->respond($request, (bool) $result['ok'], $result['ok']
            ? sprintf('Откат к версии %s выполнен (файлов: %d).', $result['version'], $result['files'])
            : (string) ($result['error'] ?? 'Ошибка отката'), '/admin/updates');
    }

    /** @return array<string, mixed> */
    private function updateInfo(): array
    {
        /** @var UpdateService $updates */
        $updates = $this->svc(UpdateService::class);
        $release = null;
        try {
            $release = $updates->latestRelease();
        } catch (\Throwable) {
            $release = null;
        }

        return [
            'enabled'  => $updates->enabled(),
            'current'  => $updates->currentVersion(),
            'repo'     => $updates->repo(),
            'channel'  => $this->config()->string('update.channel', 'stable'),
            'mode'     => $this->config()->string('update.mode', 'notify'),
            'strategy' => $this->config()->string('update.strategy', 'inplace'),
            'available' => (bool) $this->settingsService()->get('update.available', false),
            'latest_version' => $this->settingsService()->string('update.latest_version', ''),
            'latest_notes'   => $this->settingsService()->string('update.latest_notes', ''),
            'last_check'     => $this->settingsService()->get('update.last_check'),
            'release'  => $release?->toArray(),
            'has_token' => $this->config()->string('update.token', '') !== '',
            'php_min'  => $this->config()->string('update.php_min', '8.2.0'),
        ];
    }

    /** First section of CHANGELOG.md, rendered on the updates page. */
    private function changelog(): string
    {
        $file = $this->config()->root() . '/CHANGELOG.md';
        if (!is_file($file)) {
            return '';
        }
        $content = (string) file_get_contents($file);

        return mb_substr($content, 0, 12000);
    }
}
