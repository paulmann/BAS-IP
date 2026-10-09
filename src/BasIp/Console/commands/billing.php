<?php

/**
 * Billing commands: charge creation, recalculation, payments, reminders.
 */

declare(strict_types=1);

use BasIp\Console\Command;
use BasIp\Console\Input;
use BasIp\Console\Output;
use BasIp\Core\Config;
use BasIp\Core\Container;
use BasIp\Domain\Model\Invoice;
use BasIp\Services\BillingService;
use BasIp\Services\SettingsService;
use BasIp\Support\Money;
use BasIp\Support\Str;

return [

    new Command(
        name: 'billing:preview',
        description: 'Показать, как сумма разделится между активными жителями',
        usage: 'bin/bas-ip billing:preview --total=15000 [--allocation=equal] [--limit=15]',
        options: [
            'total' => 'Общая сумма на обслуживание шлагбаума',
            'allocation' => 'equal | by_persons | by_area | by_share',
            'limit' => 'Сколько строк показать', 'json' => 'Вывод в JSON',
        ],
        arguments: [], group: 'Оплаты',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var BillingService $billing */
            $billing = $c->get(BillingService::class);
            /** @var Config $config */
            $config = $c->get(Config::class);
            $symbol = $config->string('billing.currency_symbol', '₽');

            $total = $in->has('total') ? $in->float('total') : (float) $c->get(SettingsService::class)->get('billing.last_total', $config->float('billing.default_total'));
            if ($total <= 0) {
                $out->error('Укажите --total=СУММА.');

                return 1;
            }

            $preview = $billing->preview($total, $in->str('allocation', 'equal'));

            if ($in->bool('json')) {
                $out->line((string) json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return 0;
            }

            $out->title('Расчёт: ' . Money::format($total, $symbol));
            $out->definitions([
                'Активных участников' => $preview['participants'],
                'На каждого (справочно)' => Money::format($preview['per_unit'], $symbol),
                'Способ' => $preview['allocation'],
                'Сумма счетов' => Money::format($preview['sum'], $symbol),
                'Расхождение округления' => Money::format($preview['rounding_delta'], $symbol),
            ]);
            $out->line('');

            if ($preview['participants'] === 0) {
                $out->warn('Нет активных жителей, участвующих в расчёте (is_active = 1 и is_billed = 1).');

                return 1;
            }

            $rows = [];
            foreach (array_slice($preview['rows'], 0, $in->int('limit', 15)) as $row) {
                $rows[] = [
                    (string) $row['apartment'],
                    Str::limit((string) $row['name'], 28),
                    $row['weight'] != 1.0 ? (string) $row['weight'] : '—',
                    Money::format((float) $row['amount'], $symbol),
                    $row['share_pct'] . '%',
                ];
            }
            $out->table(['Кв.', 'ФИО', 'Вес', 'К оплате', 'Доля'], $rows);
            if (count($preview['rows']) > $in->int('limit', 15)) {
                $out->line('  <dim>…и ещё ' . (count($preview['rows']) - $in->int('limit', 15)) . ' записей</dim>');
            }

            return 0;
        },
    ),

    new Command(
        name: 'billing:charge',
        description: 'Создать начисление и выставить счета активным жителям',
        usage: 'bin/bas-ip billing:charge --total=15000 [--period="09.2026"] [--due-days=14] [--notify] [--dry-run]',
        options: [
            'total' => 'Общая сумма', 'allocation' => 'Способ распределения',
            'period' => 'Период, например 09.2026', 'title' => 'Название',
            'due-days' => 'Срок оплаты в днях', 'due-date' => 'Точная дата (YYYY-MM-DD)',
            'pay-link' => 'Ссылка на оплату', 'message' => 'Текст сообщения',
            'notify' => 'Сразу поставить рассылку в очередь', 'template' => 'Шаблон письма',
            'dry-run' => 'Только показать расчёт, ничего не создавать',
            'no-issue' => 'Создать черновик без выставления счетов',
            'yes' => 'Не спрашивать подтверждение',
        ],
        arguments: [], group: 'Оплаты',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var BillingService $billing */
            $billing = $c->get(BillingService::class);
            /** @var Config $config */
            $config = $c->get(Config::class);
            $symbol = $config->string('billing.currency_symbol', '₽');

            $total = $in->float('total');
            if ($total <= 0) {
                $out->error('Укажите --total=СУММА.');

                return 1;
            }
            $allocation = $in->str('allocation', $c->get(SettingsService::class)->string('billing.last_allocation', 'equal'));
            $period = $in->str('period', '');
            if ($period !== '' && $billing->parsePeriodLabel($period) === null) {
                $out->error(sprintf(
                    'Не удалось распознать период «%s». Примеры: 10.2026, 2026-10, «Октябрь 2026».',
                    $period,
                ));

                return 1;
            }
            $preview = $billing->preview($total, $allocation, $period);

            if ($in->bool('dry-run')) {
                $out->title('Предпросмотр начисления');
                $out->definitions([
                    'Сумма' => Money::format($total, $symbol),
                    'Участников' => $preview['participants'],
                    'На каждого' => Money::format($preview['per_unit'], $symbol),
                    'Сумма счетов' => Money::format($preview['sum'], $symbol),
                ]);

                return 0;
            }

            if ($preview['participants'] === 0) {
                $out->error('Нет активных жителей — некому выставлять счета.');

                return 1;
            }

            $out->info(sprintf(
                '%s ÷ %d активных = %s на каждого',
                Money::format($total, $symbol), $preview['participants'], Money::format($preview['per_unit'], $symbol)
            ));
            if (!$in->bool('yes') && !$out->confirm('Выставить счета?', true)) {
                $out->info('Отменено.');

                return 0;
            }

            $result = $billing->createCharge([
                'total_amount' => $total,
                'allocation'   => $allocation,
                'title'        => $in->str('title', 'Обслуживание шлагбаума'),
                'period_label' => $in->str('period', ''),
                'due_days'     => $in->int('due-days', $config->int('billing.due_days', 14)),
                'due_date'     => $in->str('due-date', '') ?: null,
                'pay_link'     => $in->str('pay-link', '') ?: null,
                'message'      => $in->str('message', ''),
                'issue'        => !$in->bool('no-issue'),
            ]);

            if (!$result['ok']) {
                $out->error((string) ($result['error'] ?? 'Ошибка'));

                return 1;
            }

            $out->success(sprintf('Начисление %s: выставлено %d %s по %s.', $result['number'], (int) $result['invoices'], Str::plural((int) $result['invoices'], 'счёт', 'счёта', 'счетов'), Money::format((float) $result['per_unit'], $symbol)));

            if ($in->bool('notify') && (int) $result['invoices'] > 0) {
                $notify = $billing->notifyCharge((int) $result['id'], [], $in->str('template', 'payment_new'), $in->str('message', '') ?: null, null, []);
                $out->info('В рассылку поставлено ' . $notify['queued'] . ' сообщений (пропущено ' . $notify['skipped'] . ').');
                $out->bullet('Обработайте очередь: <cyan>bin/bas-ip queue:run</cyan>');
            }

            return 0;
        },
    ),

    new Command(
        name: 'billing:recalc',
        description: 'Пересчитать начисление и выставить дополнительные счета',
        usage: 'bin/bas-ip billing:recalc CHARGE_ID [--total=…] [--allocation=…] [--dry-run]',
        options: [
            'total' => 'Новая общая сумма', 'allocation' => 'Новый способ распределения',
            'reason' => 'Причина перерасчёта', 'due-date' => 'Новый срок оплаты',
            'include-new' => 'Включить новых активных жителей (по умолчанию да)',
            'exclude-inactive' => 'Сторнировать счета отключённых (по умолчанию да)',
            'dry-run' => 'Показать, что изменится, не применяя',
            'notify' => 'Отправить уведомления о перерасчёте',
        ],
        arguments: ['charge' => 'ID начисления'],
        group: 'Оплаты',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var BillingService $billing */
            $billing = $c->get(BillingService::class);
            /** @var Config $config */
            $config = $c->get(Config::class);
            $symbol = $config->string('billing.currency_symbol', '₽');

            $chargeId = (int) $in->argument(0, '0');
            if ($chargeId <= 0) {
                $out->error('Укажите ID начисления: bin/bas-ip billing:charges');

                return 1;
            }
            $charge = $billing->charge($chargeId);
            if ($charge === null) {
                $out->error('Начисление #' . $chargeId . ' не найдено.');

                return 1;
            }

            $input = array_filter([
                'total_amount' => $in->has('total') ? $in->float('total') : null,
                'allocation' => $in->str('allocation', '') ?: null,
                'reason' => $in->str('reason', '') ?: null,
                'due_date' => $in->str('due-date', '') ?: null,
            ], static fn ($v) => $v !== null);
            $input['include_new'] = !$in->has('include-new') || $in->bool('include-new');
            $input['exclude_inactive'] = !$in->has('exclude-inactive') || $in->bool('exclude-inactive');

            $dryRun = $in->bool('dry-run');
            $result = $billing->recalculate($chargeId, $input, null, null, $dryRun);

            if (!$result['ok']) {
                $out->error((string) ($result['error'] ?? 'Ошибка'));

                return 1;
            }

            $out->title(($dryRun ? 'Предпросмотр перерасчёта' : 'Перерасчёт') . ' · ' . $charge->number);
            $out->definitions([
                'Сумма' => Money::format((float) $result['old_total'], $symbol) . ' → ' . Money::format((float) $result['total'], $symbol),
                'Участников' => $result['old_participants'] . ' → ' . $result['participants'],
                'На каждого' => Money::format((float) $result['old_per_unit'], $symbol) . ' → ' . Money::format((float) $result['per_unit'], $symbol),
                'Доп. счетов' => $result['adjustments'] . ' на ' . Money::format((float) $result['adjustment_sum'], $symbol),
                'Новых счетов' => $result['new_invoices'] . ' на ' . Money::format((float) $result['new_sum'], $symbol),
                'Сторно' => $result['credits'] . ' на ' . Money::format((float) $result['credit_sum'], $symbol),
                'Причина' => (string) $result['reason'],
            ]);

            if (!empty($result['unchanged'])) {
                $out->info('Изменений не требуется — суммы совпадают.');

                return 0;
            }
            if ($dryRun) {
                $out->line('');
                $out->info('Это предпросмотр. Добавьте --dry-run=false (или уберите флаг) для применения.');
                $rows = [];
                foreach (array_slice((array) ($result['details'] ?? []), 0, 20) as $detail) {
                    $rows[] = [
                        (string) $detail['apartment'], Str::limit((string) $detail['name'], 26),
                        $detail['active'] ? '<green>да</green>' : '<red>нет</red>',
                        match ($detail['type']) { 'credit' => '<yellow>сторно</yellow>', 'new' => '<cyan>новый</cyan>', default => 'доп. счёт' },
                        Money::format((float) $detail['amount'], $symbol),
                    ];
                }
                $out->table(['Кв.', 'ФИО', 'Активен', 'Тип', 'Сумма'], $rows);

                return 0;
            }

            $out->success('Перерасчёт применён. Выставленные счета не изменялись — созданы дополнительные.');

            if ($in->bool('notify')) {
                $notify = $billing->notifyCharge($chargeId, [], 'charge_recalculated', null, null, []);
                $out->info('Уведомлений в очереди: ' . $notify['queued']);
            }

            return 0;
        },
    ),

    new Command(
        name: 'billing:charges',
        description: 'Список начислений',
        usage: 'bin/bas-ip billing:charges [--limit=20]',
        options: ['limit' => 'Количество', 'status' => 'Фильтр по статусу'],
        arguments: [], group: 'Оплаты',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var BillingService $billing */
            $billing = $c->get(BillingService::class);
            /** @var Config $config */
            $config = $c->get(Config::class);
            $symbol = $config->string('billing.currency_symbol', '₽');

            $paginator = $billing->charges($in->has('status') ? ['status' => $in->str('status')] : [], $in->int('limit', 20), 1);
            $rows = [];
            foreach ($paginator->items() as $charge) {
                /** @var \BasIp\Domain\Model\Charge $charge */
                $rows[] = [
                    $charge->id, $charge->number, Str::limit($charge->periodLabel, 18),
                    Money::format($charge->totalAmount, $symbol),
                    (string) $charge->activeCount,
                    Money::format($charge->perUnitAmount, $symbol),
                    Money::format($charge->paidAmount, $symbol),
                    $charge->collectedPercent() . '%',
                    $charge->statusLabel() . ($charge->isRecalc() ? ' <dim>(перерасчёт)</dim>' : ''),
                ];
            }

            $out->title('Начисления (' . $paginator->total() . ')');
            $out->table(['ID', 'Номер', 'Период', 'Сумма', 'Участн.', 'На 1', 'Собрано', 'Сбор', 'Статус'], $rows);

            return 0;
        },
    ),

    new Command(
        name: 'billing:payment',
        description: 'Провести оплату жителя',
        usage: 'bin/bas-ip billing:payment --resident=ID --amount=5000 [--invoice=ID] [--method=transfer] [--date=YYYY-MM-DD]',
        options: [
            'resident' => 'ID жителя', 'apartment' => 'Квартира вместо ID',
            'amount' => 'Сумма', 'invoice' => 'Привязать к конкретному счёту',
            'method' => 'cash|card|transfer|online|sbp|other', 'date' => 'Дата оплаты',
            'reference' => 'Номер платежа', 'note' => 'Примечание',
        ],
        arguments: [], group: 'Оплаты',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var BillingService $billing */
            $billing = $c->get(BillingService::class);
            /** @var \BasIp\Db\Database $db */
            $db = $c->get(\BasIp\Db\Database::class);

            $residentId = $in->int('resident');
            if ($residentId <= 0 && $in->has('apartment')) {
                $row = $db->table('residents')->where('apartment', '=', $in->str('apartment'))->whereNull('deleted_at')->first();
                $residentId = $row === null ? 0 : (int) $row['id'];
            }
            if ($residentId <= 0 || !$in->has('amount')) {
                $out->error('Нужны --resident (или --apartment) и --amount.');

                return 1;
            }

            $result = $billing->recordPayment([
                'resident_id' => $residentId,
                'amount' => $in->float('amount'),
                'invoice_id' => $in->has('invoice') ? $in->int('invoice') : null,
                'paid_at' => $in->str('date', '') ?: null,
                'method' => $in->str('method', 'transfer'),
                'reference' => $in->str('reference'),
                'note' => $in->str('note', 'Проведено из CLI'),
            ]);

            if (!$result['ok']) {
                $out->error((string) ($result['error'] ?? 'Ошибка'));

                return 1;
            }

            $symbol = $c->get(Config::class)->string('billing.currency_symbol', '₽');
            $out->success('Оплата ' . Money::format($in->float('amount'), $symbol) . ' проведена.');
            foreach ((array) ($result['allocated'] ?? []) as $allocated) {
                $out->bullet(sprintf('Счёт %s: %s → %s', $allocated['number'], Money::format((float) $allocated['applied'], $symbol), $allocated['status'] === Invoice::STATUS_PAID ? 'оплачен' : 'частично'));
            }
            if (($result['overpay'] ?? 0) > 0.004) {
                $out->info('Переплата ' . Money::format((float) $result['overpay'], $symbol) . ' учтена на балансе.');
            }
            $out->bullet('Баланс жителя: ' . Money::format((float) ($result['balance'] ?? 0), $symbol));

            return 0;
        },
    ),

    new Command(
        name: 'billing:debtors',
        description: 'Список должников',
        usage: 'bin/bas-ip billing:debtors [--limit=50] [--all] [--remind]',
        options: ['limit' => 'Количество', 'all' => 'Включая отключённых', 'remind' => 'Отправить напоминания', 'json' => 'Вывод в JSON'],
        arguments: [], group: 'Оплаты',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var BillingService $billing */
            $billing = $c->get(BillingService::class);
            /** @var Config $config */
            $config = $c->get(Config::class);
            $symbol = $config->string('billing.currency_symbol', '₽');

            $debtors = $billing->debtors($in->int('limit', 50), !$in->bool('all'));

            if ($in->bool('json')) {
                $out->line((string) json_encode($debtors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return 0;
            }

            $rows = [];
            $total = 0.0;
            foreach ($debtors as $debtor) {
                $total += (float) $debtor['debt'];
                $rows[] = [
                    (string) $debtor['apartment'],
                    Str::limit((string) $debtor['name'], 26),
                    $debtor['active'] ? '<green>да</green>' : '<red>нет</red>',
                    Money::format((float) $debtor['debt'], $symbol),
                    (string) $debtor['invoices'],
                    (string) ($debtor['oldest_due'] ?? '—'),
                    $debtor['days_overdue'] > 0 ? '<red>' . $debtor['days_overdue'] . ' дн.</red>' : '—',
                    Str::limit((string) ($debtor['phone'] ?? $debtor['email'] ?? '—'), 20),
                ];
            }

            $out->title('Должники');
            $out->table(['Кв.', 'ФИО', 'Активен', 'Долг', 'Счетов', 'Старший срок', 'Просрочка', 'Контакт'], $rows);
            $out->line('');
            $out->line('  Итого к сбору: <bold>' . Money::format($total, $symbol) . '</bold> · должников: ' . count($debtors));

            if ($in->bool('remind')) {
                $result = $billing->sendOverdueReminders([]);
                $out->info(sprintf('Напоминаний в очереди: %d (пропущено %d).', $result['queued'], $result['skipped']));
            }

            return 0;
        },
    ),

    new Command(
        name: 'billing:overdue',
        description: 'Пометить просроченные счета (cron)',
        usage: 'bin/bas-ip billing:overdue',
        options: [], arguments: [], group: 'Оплаты',
        handler: static function (Input $in, Output $out, Container $c): int {
            $count = $c->get(BillingService::class)->markOverdue();
            $out->success('Помечено просроченных счетов: ' . $count);

            return 0;
        },
    ),

    new Command(
        name: 'billing:remind',
        description: 'Отправить напоминания всем активным должникам',
        usage: 'bin/bas-ip billing:remind [--channels=mail,telegram] [--yes]',
        options: ['channels' => 'Список каналов через запятую', 'yes' => 'Без подтверждения'],
        arguments: [], group: 'Оплаты',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var BillingService $billing */
            $billing = $c->get(BillingService::class);
            $debtors = count($billing->debtors(2000));
            if ($debtors === 0) {
                $out->info('Должников нет — напоминания не требуются.');

                return 0;
            }
            if (!$in->bool('yes') && !$out->confirm('Отправить напоминания ' . $debtors . ' должникам?', true)) {
                return 0;
            }

            $channels = array_values(array_filter(array_map('trim', explode(',', $in->str('channels', '')))));
            $result = $billing->sendOverdueReminders($channels);
            $out->success(sprintf('В очереди: %d, пропущено: %d, пакет #%d.', $result['queued'], $result['skipped'], $result['batch_id']));

            return 0;
        },
    ),

    new Command(
        name: 'billing:balances',
        description: 'Пересчитать кешированные балансы всех жителей',
        usage: 'bin/bas-ip billing:balances',
        options: [], arguments: [], group: 'Оплаты',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var BillingService $billing */
            $billing = $c->get(BillingService::class);
            /** @var \BasIp\Db\Database $db */
            $db = $c->get(\BasIp\Db\Database::class);

            $ids = $db->table('residents')->whereNull('deleted_at')->pluck('id');
            $total = count($ids);
            $done = 0;
            foreach ($ids as $id) {
                $billing->recalculateBalance((int) $id);
                $done++;
                if ($done % 25 === 0 || $done === $total) {
                    $out->progress($done, $total, 'балансы');
                }
            }
            $out->success('Пересчитано балансов: ' . $done);

            return 0;
        },
    ),
];
