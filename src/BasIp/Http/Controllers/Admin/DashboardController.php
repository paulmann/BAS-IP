<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers\Admin;

use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Http\Controller;
use BasIp\Services\BillingService;
use BasIp\Services\DataService;
use BasIp\Services\GateService;
use BasIp\Services\GuestPassService;
use BasIp\Services\StatisticsService;
use BasIp\Update\UpdateService;

/**
 * Admin dashboard: KPIs, charts, live gate state, recent events and the
 * "what needs attention" queue.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->requireUser();

        /** @var StatisticsService $stats */
        $stats = $this->svc(StatisticsService::class);
        /** @var GateService $gate */
        $gate = $this->svc(GateService::class);
        /** @var BillingService $billing */
        $billing = $this->svc(BillingService::class);
        /** @var GuestPassService $guests */
        $guests = $this->svc(GuestPassService::class);
        /** @var DataService $data */
        $data = $this->svc(DataService::class);

        $days = max(1, min(365, $request->int('days', 30)));
        $overview = $stats->overview(30);

        $attention = $this->attentionList($billing, $guests, $data, $stats);

        return $this->view('admin/dashboard', [
            'title'      => 'Дашборд',
            'overview'   => $overview,
            'gateByDay'  => $stats->gateByDay($days),
            'gateByHour' => $stats->gateByHour(14),
            'payments'   => $stats->paymentsByMonth(12),
            'collection' => $stats->collectionRate(12),
            'debtors'    => $stats->debtorsBrief(8),
            'topResidents' => $stats->topResidentsByOpens($days, 8),
            'recentEvents' => $gate->recentEvents(12),
            'gateStatus' => $gate->status(null, true),
            'guestStats' => $guests->stats($days),
            'health'     => $data->health(false),
            'attention'  => $attention,
            'days'       => $days,
            'charges'    => $billing->charges([], 5, 1)->items(),
            'updateInfo' => $this->updateInfo(),
            'currency'   => $this->config()->string('billing.currency_symbol', '₽'),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function attentionList(BillingService $billing, GuestPassService $guests, DataService $data, StatisticsService $stats): array
    {
        $out = [];

        $health = $data->health(false);
        foreach ($health['checks'] as $name => $check) {
            if (!($check['ok'] ?? true)) {
                $out[] = [
                    'level' => in_array($name, ['php', 'database', 'schema', 'app_key'], true) ? 'error' : 'warning',
                    'title' => 'Проверка: ' . $name,
                    'text'  => (string) ($check['message'] ?? $check['value'] ?? ''),
                    'link'  => '/admin/system',
                ];
            }
        }

        $queue = $this->db()->table('outbox')->where('status', '=', 'failed')->count();
        if ($queue > 0) {
            $out[] = ['level' => 'warning', 'title' => 'Недоставленные уведомления', 'text' => $queue . ' сообщений в статусе «ошибка».', 'link' => '/admin/notifications'];
        }

        $totals = $billing->invoiceTotals();
        if ($totals['overdue_count'] > 0) {
            $out[] = [
                'level' => 'warning', 'title' => 'Просроченные счета',
                'text' => sprintf('%d шт. на %s', $totals['overdue_count'], \BasIp\Support\Money::format($totals['overdue'], $this->config()->string('billing.currency_symbol', '₽'))),
                'link' => '/admin/billing/debtors',
            ];
        }

        if ($this->residentRepo()->countActive() === 0) {
            $out[] = ['level' => 'warning', 'title' => 'Нет активных жителей', 'text' => 'Расчёт оплаты и рассылки невозможны.', 'link' => '/admin/residents'];
        }

        if ($this->config()->bool('app.debug') && $this->config()->string('app.env') === 'production') {
            $out[] = ['level' => 'error', 'title' => 'Включён режим отладки', 'text' => 'app.debug = true на боевом сервере.', 'link' => '/admin/settings/app'];
        }

        if (!$this->config()->hasLocalFile()) {
            $out[] = ['level' => 'warning', 'title' => 'Нет локального конфига', 'text' => 'Секреты лежат в отслеживаемом файле — создайте .bas-ip.config.local.php', 'link' => '/admin/settings'];
        }

        $lastBackup = $this->db()->table('backups')->where('status', '=', 'done')->orderBy('id', 'desc')->value('created_at');
        if ($lastBackup === null || strtotime((string) $lastBackup) < time() - 8 * 86400) {
            $out[] = ['level' => 'warning', 'title' => 'Давно не было резервной копии', 'text' => $lastBackup === null ? 'Копий ещё нет.' : 'Последняя: ' . date('d.m.Y H:i', (int) strtotime((string) $lastBackup)), 'link' => '/admin/data'];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function updateInfo(): array
    {
        if (!$this->config()->bool('update.enabled')) {
            return ['enabled' => false];
        }
        /** @var UpdateService $updates */
        $updates = $this->svc(UpdateService::class);

        return [
            'enabled' => true,
            'current' => $updates->currentVersion(),
            'latest'  => $this->settingsService()->string('update.latest_version', ''),
            'available' => (bool) $this->settingsService()->get('update.available', false),
            'checked_at' => $this->settingsService()->get('update.last_check'),
            'notes' => $this->settingsService()->string('update.latest_notes', ''),
        ];
    }
}
