<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers\Admin;

use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Domain\Model\Charge;
use BasIp\Http\Controller;
use BasIp\Services\BillingService;
use BasIp\Services\StatisticsService;
use BasIp\Support\Money;
use BasIp\Support\Str;

/**
 * Billing admin: the charge wizard (total ÷ active residents), recalculation
 * with adjustment invoices, the invoice/payment registries, debtors and the
 * mass reminder mailings.
 */
final class BillingController extends Controller
{
    private function billing(): BillingService
    {
        return $this->svc(BillingService::class);
    }

    /* ═══════════════════════════ overview ═══════════════════════════ */

    public function index(Request $request): Response
    {
        $this->requirePermission('billing.view');
        $billing = $this->billing();
        $symbol = $this->config()->string('billing.currency_symbol', '₽');

        return $this->view('admin/billing/index', [
            'title'     => 'Оплаты и начисления',
            'charges'   => $billing->charges($this->filters($request, ['q', 'status']), $this->perPage($request, 10), $this->page($request)),
            'totals'    => $billing->invoiceTotals(),
            'overview'  => $this->svc(StatisticsService::class)->overview(30),
            'payments'  => $this->svc(StatisticsService::class)->paymentsByMonth(12),
            'collection' => $this->svc(StatisticsService::class)->collectionRate(12),
            'debtors'   => $billing->debtors(10),
            'recent'    => $billing->payments([], 10, 1)->items(),
            'symbol'    => $symbol,
            'lastTotal' => $this->settingsService()->get('billing.last_total'),
            'participants' => $billing->activeParticipants(),
            'canCharge' => $this->requireUser()->can('billing.charge.create'),
            'canNotify' => $this->requireUser()->can('notifications.send'),
            'query'     => $request->query,
        ]);
    }

    /* ═══════════════════════════ charge wizard ═══════════════════════════ */

    public function wizard(Request $request): Response
    {
        $this->requirePermission('billing.charge.create');
        $billing = $this->billing();

        [$periodStart, $periodEnd] = $billing->guessPeriod($request->str('period_label'));
        $lastTotal = $this->settingsService()->get('billing.last_total');

        return $this->view('admin/billing/wizard', [
            'title'        => 'Новое начисление',
            'participants' => $billing->activeParticipants(),
            'activeTotal'  => $this->residentRepo()->countActive(),
            'billableTotal' => $this->residentRepo()->countBillable(),
            'lastTotal'    => $lastTotal !== null ? (float) $lastTotal : $this->config()->float('billing.default_total'),
            'periodLabel'  => $billing->periodLabel($periodStart, $periodEnd),
            'periodStart'  => $periodStart,
            'periodEnd'    => $periodEnd,
            'dueDays'      => $this->config()->int('billing.due_days', 14),
            'payLink'      => $billing->normalizePayLink(null),
            'allocations'  => ['equal' => 'Поровну', 'by_persons' => 'По числу проживающих', 'by_area' => 'По площади', 'by_share' => 'По доле'],
            'templates'    => array_keys((array) $this->config()->get('notify.templates', [])),
            'channels'     => $this->channelList(),
            'symbol'       => $this->config()->string('billing.currency_symbol', '₽'),
            'previewRows'  => $lastTotal > 0 ? $billing->preview((float) $lastTotal, (string) $this->settingsService()->string('billing.last_allocation', 'equal')) : null,
            'messageHelp'  => $this->svc(\BasIp\Notify\TemplateRenderer::class)->placeholders(),
            'defaultMessage' => (string) ($this->config()->get('notify.templates.payment_new.body') ?? ''),
        ]);
    }

    /** Live recalculation for the wizard (AJAX). */
    public function preview(Request $request): Response
    {
        $this->requirePermission('billing.charge.create');
        $total = $request->float('total_amount');
        $allocation = $request->str('allocation', 'equal');

        if ($total <= 0) {
            return $this->jsonError('Укажите сумму больше нуля.', 422);
        }

        $preview = $this->billing()->preview($total, $allocation, $request->str('period_label'));
        $symbol = $this->config()->string('billing.currency_symbol', '₽');

        return $this->json([
            'total'        => $preview['total'],
            'participants' => $preview['participants'],
            'per_unit'     => $preview['per_unit'],
            'per_unit_formatted' => Money::format($preview['per_unit'], $symbol),
            'total_formatted'    => Money::format($preview['total'], $symbol),
            'sum'            => $preview['sum'],
            'rounding_delta' => $preview['rounding_delta'],
            'allocation'     => $preview['allocation'],
            'rows'           => array_map(static fn (array $row): array => [
                'id' => $row['id'], 'name' => $row['name'], 'apartment' => $row['apartment'],
                'building' => $row['building'], 'weight' => $row['weight'],
                'share_pct' => $row['share_pct'], 'amount' => $row['amount'],
                'amount_formatted' => Money::format($row['amount'], $symbol),
                'has_contact' => $row['email'] !== null || $row['phone'] !== null,
            ], $preview['rows']),
            'message' => $preview['participants'] === 0
                ? 'Нет активных жителей, участвующих в расчёте.'
                : sprintf('%s ÷ %d %s = %s на каждого', Money::format($preview['total'], $symbol), $preview['participants'], Str::plural($preview['participants'], 'участник', 'участника', 'участников'), Money::format($preview['per_unit'], $symbol)),
        ]);
    }

    public function storeCharge(Request $request): Response
    {
        $user = $this->requirePermission('billing.charge.create');
        $billing = $this->billing();

        $result = $billing->createCharge([
            'total_amount' => $request->float('total_amount'),
            'allocation'   => $request->str('allocation', 'equal'),
            'title'        => $request->str('title', 'Обслуживание шлагбаума'),
            'description'  => $request->str('description'),
            'period_label' => $request->str('period_label'),
            'period_start' => $request->str('period_start') ?: null,
            'period_end'   => $request->str('period_end') ?: null,
            'due_days'     => $request->int('due_days') ?: $this->config()->int('billing.due_days', 14),
            'due_date'     => $request->str('due_date') ?: null,
            'message'      => $request->input('message'),
            'pay_link'     => $request->str('pay_link') ?: null,
            'notes'        => $request->str('notes'),
            'issue'        => $request->bool('issue', true),
        ], $user, $request);

        if (!$result['ok']) {
            return $this->respond($request, false, (string) ($result['error'] ?? 'Ошибка'), '/admin/billing/wizard');
        }

        $message = sprintf('Начисление %s создано: %d %s по %s.', $result['number'], (int) $result['invoices'], Str::plural((int) $result['invoices'], 'счёт', 'счёта', 'счетов'), Money::format((float) $result['per_unit'], $this->config()->string('billing.currency_symbol', '₽')));

        // Optional immediate mailing.
        if ($request->bool('notify_now') && (int) $result['invoices'] > 0) {
            $notify = $billing->notifyCharge(
                (int) $result['id'],
                [],
                $request->str('template', 'payment_new'),
                $request->input('message') ?: null,
                $request->str('subject') ?: null,
                $request->array('channels') ?: [],
                $user
            );
            $message .= sprintf(' В рассылку поставлено %d сообщений.', $notify['queued']);
        }

        return $this->respond($request, true, $message, '/admin/billing/charges/' . (int) $result['id']);
    }

    public function showCharge(Request $request, string $id = ''): Response
    {
        $this->requirePermission('billing.view');
        $billing = $this->billing();
        $charge = $billing->charge((int) $id);
        if ($charge === null) {
            $this->notFound('Начисление не найдено.');
        }

        $invoices = $billing->invoicesForCharge((int) $id, $this->filters($request, ['status', 'q', 'sort', 'dir', 'only_active']));
        $stats = $billing->chargeStats((int) $id);

        return $this->view('admin/billing/charge', [
            'title'    => 'Начисление ' . $charge->number,
            'charge'   => $charge,
            'invoices' => $invoices,
            'stats'    => $stats,
            'symbol'   => $this->config()->string('billing.currency_symbol', '₽'),
            'query'    => $request->query,
            'channels' => $this->channelList(),
            'templates' => array_keys((array) $this->config()->get('notify.templates', [])),
            'recalcPreview' => null,
            'canRecalc' => $this->requireUser()->can('billing.charge.recalc'),
            'canNotify' => $this->requireUser()->can('notifications.send'),
            'canEdit'   => $this->requireUser()->can('billing.charge.issue'),
            'payments'  => $this->db()->table('payments p')
                ->select('p.*', 'r.full_name AS resident_name', 'r.apartment')
                ->leftJoin('residents r', 'r.id', '=', 'p.resident_id')
                ->where('p.charge_id', '=', (int) $id)
                ->orderBy('p.paid_at', 'desc')->limit(50)->get(),
            'history'  => $this->db()->table('charges')->where('recalc_of', '=', (int) $id)->get(),
        ]);
    }

    public function updateCharge(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('billing.charge.issue');
        $ok = $this->billing()->updateCharge((int) $id, $request->only([
            'title', 'description', 'message', 'notes', 'pay_link', 'due_date', 'due_days',
        ]), $user, $request);

        return $this->respond($request, $ok, $ok ? 'Начисление обновлено.' : 'Не удалось обновить.', '/admin/billing/charges/' . (int) $id);
    }

    /**
     * Recalculate: dry-run first (preview), then apply.
     * Creates adjustment / credit / new invoices without touching issued ones.
     */
    public function recalc(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('billing.charge.recalc');
        $billing = $this->billing();
        $dryRun = $request->bool('dry_run') || $request->str('mode') === 'preview';

        $input = [
            'total_amount'     => $request->filled('total_amount') ? $request->float('total_amount') : null,
            'allocation'       => $request->str('allocation') ?: null,
            'include_new'      => $request->bool('include_new', true),
            'exclude_inactive' => $request->bool('exclude_inactive', true),
            'reason'           => $request->str('reason'),
            'due_date'         => $request->str('due_date') ?: null,
        ];

        $result = $billing->recalculate((int) $id, $input, $user, $request, $dryRun);

        if (!$result['ok']) {
            return $this->respond($request, false, (string) ($result['error'] ?? 'Ошибка перерасчёта.'), '/admin/billing/charges/' . (int) $id);
        }

        if ($dryRun) {
            return $this->json($result);
        }

        if (!empty($result['unchanged'])) {
            return $this->respond($request, true, 'Перерасчёт не потребовал изменений: суммы совпадают.', '/admin/billing/charges/' . (int) $id);
        }

        $message = sprintf(
            'Перерасчёт выполнен: доп. счетов — %d (%s), новых — %d (%s), сторно — %d (%s).',
            (int) $result['adjustments'], Money::format((float) $result['adjustment_sum']),
            (int) $result['new_invoices'], Money::format((float) $result['new_sum']),
            (int) $result['credits'], Money::format((float) $result['credit_sum'])
        );

        if ($request->bool('notify_now')) {
            $notify = $billing->notifyCharge((int) $id, [], $request->str('template', 'charge_recalculated'), $request->input('message') ?: null, $request->str('subject') ?: null, $request->array('channels') ?: [], $user);
            $message .= sprintf(' В рассылку поставлено %d.', $notify['queued']);
        }

        return $this->respond($request, true, $message, '/admin/billing/charges/' . (int) $id, $result);
    }

    public function notifyCharge(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('notifications.send');
        $billing = $this->billing();

        $charge = $billing->charge((int) $id);
        if ($charge === null) {
            return $this->respond($request, false, 'Начисление не найдено.', '/admin/billing');
        }

        $result = $billing->notifyCharge(
            (int) $id,
            array_map('intval', $request->array('resident_ids')),
            $request->str('template', 'payment_new'),
            $request->input('message') ?: $charge->message,
            $request->str('subject') ?: null,
            $request->array('channels') ?: [],
            $user
        );

        return $this->respond(
            $request, true,
            sprintf('В очередь поставлено %d сообщений (пропущено %d). Отслеживать: «Рассылки».', $result['queued'], $result['skipped']),
            '/admin/notifications/batches/' . $result['batch_id']
        );
    }

    public function closeCharge(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('billing.charge.close');
        $ok = $this->billing()->closeCharge((int) $id, $user, $request);

        return $this->respond($request, $ok, $ok ? 'Период закрыт.' : 'Не удалось закрыть.', '/admin/billing/charges/' . (int) $id);
    }

    public function cancelCharge(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('billing.charge.close');
        $ok = $this->billing()->cancelCharge((int) $id, $request->str('reason', 'Отменено администратором'), $user, $request);

        return $this->respond($request, $ok, $ok ? 'Начисление отменено, счета аннулированы.' : 'Не удалось отменить.', '/admin/billing');
    }

    public function exportCharge(Request $request, string $id = ''): Response
    {
        $this->requirePermission('data.export');
        $billing = $this->billing();
        $charge = $billing->charge((int) $id);
        if ($charge === null) {
            $this->notFound('Начисление не найдено.');
        }

        $invoices = $billing->invoicesForCharge((int) $id, ['sort' => 'apartment', 'dir' => 'asc']);
        $symbol = $this->config()->string('billing.currency_symbol', '₽');
        $delimiter = (string) $this->config()->string('data.csv_delimiter', ';');

        $rows = [[
            'Счёт', 'Квартира', 'ФИО', 'Период', 'Начислено', 'Оплачено', 'Остаток',
            'Статус', 'Срок', 'Тип', 'Ссылка на оплату',
        ]];
        foreach ($invoices as $invoice) {
            $rows[] = [
                $invoice->number, $invoice->apartment ?? '', $invoice->residentName ?? '',
                $invoice->periodLabel, number_format($invoice->amount, 2, '.', ''),
                number_format($invoice->paidAmount, 2, '.', ''), number_format($invoice->balance, 2, '.', ''),
                $invoice->statusLabel(), (string) $invoice->dueDate, $invoice->kindLabel(), (string) $invoice->payLink,
            ];
        }
        $rows[] = [];
        $rows[] = ['ИТОГО', '', '', $charge->periodLabel,
            number_format($charge->invoicesAmount, 2, '.', ''),
            number_format($charge->paidAmount, 2, '.', ''),
            number_format($charge->outstanding(), 2, '.', ''),
            $charge->statusLabel(), (string) $charge->dueDate, '', ''];

        $csv = ($this->config()->bool('data.csv_bom', true) ? "\xEF\xBB\xBF" : '');
        foreach ($rows as $row) {
            $csv .= implode($delimiter, array_map(
                static fn ($v): string => str_contains((string) $v, $delimiter) || str_contains((string) $v, '"')
                    ? '"' . str_replace('"', '""', (string) $v) . '"'
                    : (string) $v,
                $row
            )) . "\r\n";
        }

        $this->auditService()->info('data.export.charge', $this->requireUser(), 'charge', (string) $id, $charge->number, $request, ['rows' => count($invoices)]);

        return Response::csv($csv, sprintf('charge-%s-%s.csv', $charge->number, date('Ymd-His')));
    }

    /* ═══════════════════════════ invoices & payments ═══════════════════════════ */

    public function invoices(Request $request): Response
    {
        $this->requirePermission('billing.view');
        $billing = $this->billing();

        $filters = $this->filters($request, ['q', 'status', 'kind', 'charge_id', 'resident_id', 'from', 'to', 'overdue', 'only_active']);
        [$sort, $dir] = $this->sorting($request, ['due', 'amount', 'name', 'apartment', 'status', 'issued'], 'due', 'desc');
        $filters['sort'] = $sort;
        $filters['dir'] = $dir;

        return $this->view('admin/billing/invoices', [
            'title'    => 'Счета',
            'invoices' => $billing->invoices($filters, $this->perPage($request, 50), $this->page($request)),
            'filters'  => $filters,
            'query'    => $request->query,
            'totals'   => $billing->invoiceTotals(!empty($filters['only_active'])),
            'charges'  => $billing->allCharges(100),
            'symbol'   => $this->config()->string('billing.currency_symbol', '₽'),
            'canVoid'  => $this->requireUser()->can('billing.invoice.void'),
            'canPay'   => $this->requireUser()->can('billing.payment.record'),
        ]);
    }

    public function payments(Request $request): Response
    {
        $this->requirePermission('billing.view');
        $billing = $this->billing();

        $filters = $this->filters($request, ['q', 'method', 'from', 'to', 'resident_id', 'only_active']);
        [$sort, $dir] = $this->sorting($request, ['paid_at', 'amount', 'name', 'method'], 'paid_at', 'desc');
        $filters['sort'] = $sort;
        $filters['dir'] = $dir;

        $paginator = $billing->payments($filters, $this->perPage($request, 50), $this->page($request));
        $sum = 0.0;
        foreach ($paginator->items() as $payment) {
            $sum += $payment->amount;
        }

        return $this->view('admin/billing/payments', [
            'title'    => 'Реестр оплат',
            'payments' => $paginator,
            'filters'  => $filters,
            'query'    => $request->query,
            'pageSum'  => $sum,
            'periodSum' => $this->residentRepo()->totalCollected($filters['from'] ?? null, $filters['to'] ?? null),
            'methods'  => ['cash' => 'Наличные', 'card' => 'Карта', 'transfer' => 'Перевод', 'online' => 'Онлайн', 'sbp' => 'СБП', 'other' => 'Другое'],
            'symbol'   => $this->config()->string('billing.currency_symbol', '₽'),
            'canDelete' => $this->requireUser()->can('billing.payment.delete'),
            'canRecord' => $this->requireUser()->can('billing.payment.record'),
        ]);
    }

    public function storePayment(Request $request): Response
    {
        $user = $this->requirePermission('billing.payment.record');
        $billing = $this->billing();

        $result = $billing->recordPayment([
            'resident_id' => $request->int('resident_id'),
            'amount'      => $request->float('amount'),
            'invoice_id'  => $request->filled('invoice_id') ? $request->int('invoice_id') : null,
            'paid_at'     => $request->str('paid_at') ?: null,
            'method'      => $request->str('method', 'transfer'),
            'reference'   => $request->str('reference'),
            'payer_name'  => $request->str('payer_name'),
            'note'        => $request->str('note'),
        ], $user, $request);

        $message = $result['ok']
            ? sprintf('Оплата %s проведена.', Money::format($request->float('amount'), $this->config()->string('billing.currency_symbol', '₽')))
            : (string) ($result['error'] ?? 'Ошибка');

        return $this->respond($request, (bool) $result['ok'], $message, $request->str('redirect', '/admin/billing/payments'));
    }

    public function deletePayment(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('billing.payment.delete');
        $ok = $this->billing()->deletePayment((int) $id, $user, $request);

        return $this->respond($request, $ok, $ok ? 'Оплата удалена, счета пересчитаны.' : 'Не удалось удалить.', $request->str('redirect', '/admin/billing/payments'));
    }

    public function voidInvoice(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('billing.invoice.void');
        try {
            $ok = $this->billing()->voidInvoice((int) $id, $request->str('reason', ''), $user, $request);
        } catch (\RuntimeException $e) {
            return $this->respond($request, false, $e->getMessage(), $request->str('redirect', '/admin/billing/invoices'));
        }

        return $this->respond($request, $ok, $ok ? 'Счёт аннулирован.' : 'Не удалось аннулировать.', $request->str('redirect', '/admin/billing/invoices'));
    }

    public function debtors(Request $request): Response
    {
        $this->requirePermission('billing.view');
        $billing = $this->billing();
        $symbol = $this->config()->string('billing.currency_symbol', '₽');
        $onlyActive = !$request->bool('include_inactive', false);

        $debtors = $billing->debtors(1000, $onlyActive);
        $totalDebt = array_sum(array_column($debtors, 'debt'));

        return $this->view('admin/billing/debtors', [
            'title'    => 'Должники',
            'debtors'  => $debtors,
            'totalDebt' => round($totalDebt, 2),
            'symbol'   => $symbol,
            'onlyActive' => $onlyActive,
            'canNotify' => $this->requireUser()->can('notifications.send'),
            'channels' => $this->channelList(),
            'query'    => $request->query,
        ]);
    }

    public function remind(Request $request): Response
    {
        $user = $this->requirePermission('notifications.send');
        $result = $this->billing()->sendOverdueReminders($request->array('channels') ?: [], $user);

        return $this->respond(
            $request, true,
            sprintf('Напоминания поставлены в очередь: %d (пропущено %d).', $result['queued'], $result['skipped']),
            '/admin/notifications/batches/' . (int) $result['batch_id']
        );
    }

    public function markOverdue(Request $request): Response
    {
        $user = $this->requirePermission('billing.charge.issue');
        $count = $this->billing()->markOverdue();

        return $this->respond($request, true, sprintf('Помечено просроченных счетов: %d.', $count), '/admin/billing/invoices?status=overdue');
    }

    /** @return array<int, array<string, mixed>> */
    private function channelList(): array
    {
        /** @var \BasIp\Notify\NotificationService $notify */
        $notify = $this->svc(\BasIp\Notify\NotificationService::class);

        return array_values(array_filter(array_map(
            static fn (array $c): array => ['name' => $c['name'], 'label' => $c['label'], 'enabled' => (bool) $c['enabled']],
            $notify->channelStatus()
        ), static fn (array $c): bool => $c['name'] !== 'log' || $c['enabled']));
    }
}
