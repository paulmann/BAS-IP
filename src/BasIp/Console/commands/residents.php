<?php

/**
 * Resident database commands: listing, import/export, bulk flags.
 */

declare(strict_types=1);

use BasIp\Console\Command;
use BasIp\Console\Input;
use BasIp\Console\Output;
use BasIp\Core\Container;
use BasIp\Domain\Repository\ResidentRepository;
use BasIp\Services\ImportExportService;
use BasIp\Services\ResidentService;
use BasIp\Support\Money;
use BasIp\Support\Phone;
use BasIp\Support\Plate;
use BasIp\Support\Str;

return [

    new Command(
        name: 'residents:list',
        description: 'Список жителей (по умолчанию только активные)',
        usage: 'bin/bas-ip residents:list [--status=active|inactive|all|debtors] [--q=поиск] [--limit=25]',
        options: [
            'status' => 'active (по умолчанию) | inactive | all | no_access | deleted | debtors',
            'q' => 'Поиск по ФИО, квартире, телефону, почте, машине',
            'building' => 'Корпус', 'limit' => 'Количество записей', 'json' => 'Вывод в JSON',
            'sort' => 'apartment | full_name | balance | debt', 'dir' => 'asc | desc',
        ],
        arguments: [], group: 'Жители',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var ResidentRepository $repo */
            $repo = $c->get(ResidentRepository::class);
            $filters = array_filter([
                'status' => $in->str('status', 'active') ?: 'active',
                'q' => $in->str('q', '') ?: null,
                'building' => $in->str('building', '') ?: null,
                'sort' => $in->str('sort', 'apartment') ?: 'apartment',
                'dir' => $in->str('dir', 'asc') ?: 'asc',
            ], static fn ($v) => $v !== null && $v !== '');

            $paginator = $repo->search($filters, $in->int('limit', 25), 1);
            $items = $paginator->items();
            $repo->loadRelations($items);

            if ($in->bool('json')) {
                $out->line((string) json_encode([
                    'total' => $paginator->total(),
                    'items' => array_map(static fn ($r): array => $r->toArray(), $items),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return 0;
            }

            $symbol = $c->get(\BasIp\Core\Config::class)->string('billing.currency_symbol', '₽');
            $rows = [];
            foreach ($items as $resident) {
                /** @var \BasIp\Domain\Model\Resident $resident */
                $cars = [];
                foreach ($resident->cars as $car) {
                    $cars[] = Plate::format((string) ($car['plate'] ?? ''));
                }
                $rows[] = [
                    (string) $resident->id,
                    (string) ($resident->building ?? '') . '/' . $resident->apartment,
                    Str::limit($resident->displayName(), 26),
                    Phone::format((string) ($resident->phone ?? '')) ?: '—',
                    Str::limit((string) ($resident->email ?? '—'), 22),
                    count($resident->cars) . '× ' . Str::limit(implode(', ', array_filter($cars)), 16),
                    $resident->isActive ? '<green>акт</green>' : '<red>выкл</red>',
                    $resident->accessGranted ? '<green>да</green>' : '<yellow>нет</yellow>',
                    ($resident->debt ?? 0) > 0.005 ? '<red>' . Money::format((float) $resident->debt, $symbol) . '</red>' : Money::format((float) $resident->balance, $symbol),
                ];
            }

            $out->title(sprintf('Жители · %s · всего %d', $filters['status'], $paginator->total()));
            $out->table(['ID', 'Кв.', 'ФИО', 'Телефон', 'E-mail', 'Авто', 'Статус', 'Доступ', 'Баланс'], $rows);
            $out->line('');
            $out->definitions([
                'Всего записей' => $repo->countAll(),
                'Активных' => $repo->countActive(),
                'Участвуют в оплате' => $repo->countBillable(),
                'Отключено' => $repo->countInactive(),
                'Без доступа' => $repo->countWithoutAccess(),
                'В архиве' => $repo->countDeleted(),
            ]);

            return 0;
        },
    ),

    new Command(
        name: 'residents:show',
        description: 'Карточка жителя: контакты, машины, счета, оплаты, журнал',
        usage: 'bin/bas-ip residents:show ID',
        options: [], arguments: ['id' => 'ID жителя'], group: 'Жители',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var ResidentRepository $repo */
            $repo = $c->get(ResidentRepository::class);
            $resident = $repo->findById((int) $in->argument(0, '0'), true);
            if ($resident === null) {
                $out->error('Житель не найден.');

                return 1;
            }
            /** @var \BasIp\Services\BillingService $billing */
            $billing = $c->get(\BasIp\Services\BillingService::class);
            /** @var \BasIp\Services\GateService $gate */
            $gate = $c->get(\BasIp\Services\GateService::class);
            $symbol = $c->get(\BasIp\Core\Config::class)->string('billing.currency_symbol', '₽');
            $statement = $billing->residentStatement($resident->id);

            $out->title($resident->displayName());
            $out->definitions([
                'ID / UUID' => $resident->id . ' · ' . $resident->uuid,
                'Квартира' => $resident->address(),
                'Статус' => $resident->roleLabel(),
                'Активен' => $resident->isActive ? 'да' : 'нет',
                'Доступ' => $resident->accessGranted ? 'разрешён' : 'приостановлен',
                'Участвует в оплате' => $resident->isBilled ? 'да' : 'нет',
                'Площадь / проживает / доля' => $resident->area . ' м² · ' . $resident->persons . ' чел. · ' . $resident->share,
                'Долг' => Money::format($statement['debt'], $symbol),
                'Переплата' => Money::format($statement['overpay'], $symbol),
                'Открытых счетов' => $statement['invoices'],
                'Последняя оплата' => (string) ($resident->lastPaymentAt ?? '—'),
                'Последнее открытие' => (string) ($resident->lastGateEventAt ?? '—'),
                'Комментарий' => Str::limit((string) ($resident->notes ?? '—'), 120),
                'Метки' => $resident->tags === [] ? '—' : implode(', ', $resident->tags),
            ]);

            $out->section('Контакты');
            $rows = [];
            foreach ($repo->contactsFor($resident->id) as $contact) {
                $rows[] = [
                    $contact['type'], (string) $contact['value'], (string) ($contact['label'] ?? '—'),
                    $contact['is_primary'] ? '<green>основной</green>' : '',
                    $contact['notify'] ? 'да' : 'нет',
                ];
            }
            $out->table(['Тип', 'Значение', 'Метка', 'Основной', 'Уведомлять'], $rows);

            $out->section('Автомобили');
            $rows = [];
            foreach ($repo->rawCarsFor($resident->id) as $car) {
                $rows[] = [
                    Plate::format((string) $car['plate']), (string) ($car['brand'] ?? '—'), (string) ($car['model'] ?? '—'),
                    (string) ($car['color'] ?? '—'), (string) ($car['year'] ?? '—'),
                    $car['is_active'] ? '<green>да</green>' : '<red>нет</red>', (string) ($car['rfid_tag'] ?? '—'),
                ];
            }
            $out->table(['Госномер', 'Марка', 'Модель', 'Цвет', 'Год', 'Активен', 'RFID'], $rows);

            $out->section('Счета');
            $rows = [];
            foreach ($billing->invoices(['resident_id' => $resident->id], 15, 1)->items() as $invoice) {
                $rows[] = [
                    $invoice->number, $invoice->periodLabel, Money::format($invoice->amount, $symbol),
                    Money::format($invoice->paidAmount, $symbol), Money::format($invoice->balance, $symbol),
                    (string) ($invoice->dueDate ?? '—'), $invoice->statusLabel(), $invoice->kindLabel(),
                ];
            }
            $out->table(['Счёт', 'Период', 'Начислено', 'Оплачено', 'Остаток', 'Срок', 'Статус', 'Тип'], $rows);

            $out->section('Оплаты');
            $rows = [];
            foreach (array_slice($billing->paymentsForResident($resident->id, 15), 0, 15) as $payment) {
                $rows[] = [
                    $payment->dateLabel(), Money::format($payment->amount, $symbol), $payment->methodLabel(),
                    (string) ($payment->reference ?? '—'), (string) ($payment->invoiceNumber ?? '—'),
                ];
            }
            $out->table(['Дата', 'Сумма', 'Способ', '№ платежа', 'Счёт'], $rows);

            $out->section('Последние открытия шлагбаума');
            $rows = [];
            foreach (array_slice($gate->eventsForResident($resident->id, 10), 0, 10) as $event) {
                $rows[] = [
                    $event->when('d.m.Y H:i:s'), $event->accessLabel(), $event->resultLabel(),
                    $event->sourceLabel(), Str::limit((string) ($event->reason ?? ''), 26),
                ];
            }
            $out->table(['Когда', 'Доступ', 'Результат', 'Источник', 'Причина'], $rows);

            return 0;
        },
    ),

    new Command(
        name: 'residents:import',
        description: 'Импорт жителей из CSV/JSON',
        usage: 'bin/bas-ip residents:import FILE [--mode=upsert] [--dry-run] [--delimiter=auto]',
        options: [
            'mode' => 'dry-run | upsert | insert | replace',
            'dry-run' => 'Только проверить, ничего не менять',
            'delimiter' => 'Разделитель CSV (auto, ;, ,, tab)',
            'match' => 'Поля сопоставления через запятую (apartment,full_name)',
            'skip-errors' => 'Продолжать при ошибках в строках',
        ],
        arguments: ['file' => 'Путь к CSV или JSON'],
        group: 'Жители',
        handler: static function (Input $in, Output $out, Container $c): int {
            $file = (string) $in->argument(0, '');
            if ($file === '' || !is_file($file)) {
                $out->error('Файл не найден: ' . ($file !== '' ? $file : '(не указан)'));

                return 1;
            }

            /** @var ImportExportService $io */
            $io = $c->get(ImportExportService::class);
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($extension, ['csv', 'json', 'txt'], true)) {
                $out->error('Поддерживаются только .csv, .txt и .json');

                return 1;
            }

            $mode = $in->bool('dry-run') ? 'dry-run' : $in->str('mode', 'upsert');
            $delimiter = $in->str('delimiter', 'auto');
            if ($delimiter === 'tab') {
                $delimiter = "\t";
            }

            $out->info(sprintf('Файл: %s (%s), режим: %s', basename($file), Str::bytes((int) filesize($file)), $mode));

            $result = $io->importResidents((string) file_get_contents($file), $extension === 'json' ? 'json' : 'csv', [
                'mode' => $mode,
                'delimiter' => $delimiter,
                'match_on' => array_values(array_filter(array_map('trim', explode(',', $in->str('match', 'apartment,full_name'))))),
                'skip_errors' => $in->bool('skip-errors', true),
                'filename' => basename($file),
            ]);

            if (!$result['ok']) {
                $out->error((string) ($result['error'] ?? 'Ошибка импорта'));

                return 1;
            }

            $out->title(($result['dry_run'] ? 'Проверка' : 'Импорт') . ' завершён');
            $out->definitions([
                'Всего строк' => $result['total'],
                'Создано' => $result['imported'],
                'Обновлено' => $result['updated'],
                'Пропущено' => $result['skipped'],
                'Ошибок' => $result['failed'],
            ]);

            foreach (array_slice((array) $result['errors'], 0, 20) as $error) {
                $out->warn('Строка ' . $error['row'] . ': ' . $error['error']);
            }
            if ($result['dry_run']) {
                $out->line('');
                $out->table(['Строка', 'Действие', 'ID', 'ФИО', 'Кв.', 'Телефоны', 'Машины'], array_map(
                    static fn (array $row): array => [
                        (string) $row['row'], $row['action'] === 'create' ? '<green>создать</green>' : '<cyan>обновить</cyan>',
                        (string) ($row['id'] ?? ''), Str::limit((string) ($row['full_name'] ?? ''), 24),
                        (string) ($row['apartment'] ?? ''), Str::limit((string) ($row['phones'] ?? ''), 22), Str::limit((string) ($row['cars'] ?? ''), 18),
                    ],
                    array_slice((array) $result['preview'], 0, 25)
                ));
                $out->info('Это предпросмотр. Уберите --dry-run, чтобы применить.');
            }

            return $result['failed'] > 0 ? 1 : 0;
        },
    ),

    new Command(
        name: 'residents:export',
        description: 'Выгрузить базу жителей (CSV/JSON/TXT)',
        usage: 'bin/bas-ip residents:export [--format=csv] [--status=active] [--out=FILE]',
        options: [
            'format' => 'csv | json | txt',
            'status' => 'active | inactive | all | deleted | debtors',
            'out' => 'Куда сохранить (по умолчанию storage/app/exports)',
            'include' => 'Что включать: contacts,cars,payments,invoices',
        ],
        arguments: [], group: 'Жители',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var ImportExportService $io */
            $io = $c->get(ImportExportService::class);
            $result = $io->exportResidents(
                ['status' => $in->str('status', 'active') ?: 'active'],
                [
                    'format' => $in->str('format', 'csv'),
                    'scope' => 'all',
                    'include' => array_values(array_filter(explode(',', $in->str('include', 'contacts,cars,payments')))),
                ]
            );

            $target = $in->str('out', '');
            if ($target !== '' && $target !== $result['path']) {
                \BasIp\Support\Fs::ensureDir(dirname($target));
                copy($result['path'], $target);
                $result['path'] = $target;
            }

            $out->success(sprintf('Выгружено %d записей → %s (%s)', $result['rows'], $result['path'], Str::bytes($result['bytes'])));

            return 0;
        },
    ),

    new Command(
        name: 'residents:count',
        description: 'Сводка по базе жителей (учитывается в расчётах)',
        usage: 'bin/bas-ip residents:count',
        options: [], arguments: [], group: 'Жители',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var ResidentRepository $repo */
            $repo = $c->get(ResidentRepository::class);
            /** @var \BasIp\Db\Database $db */
            $db = $c->get(\BasIp\Db\Database::class);

            $out->title('База жителей');
            $out->definitions([
                'Всего записей' => $repo->countAll(),
                'Активных (включены)' => $repo->countActive(),
                'Участвуют в расчёте оплаты' => $repo->countBillable(),
                'Отключены' => $repo->countInactive(),
                'Без галочки «доступ»' => $repo->countWithoutAccess(),
                'В архиве (удалены)' => $repo->countDeleted(),
                'Автомобилей всего' => $db->table('cars')->count(),
                'Активных автомобилей' => $db->table('cars')->where('is_active', '=', 1)->count(),
                'Контактов' => $db->table('contacts')->count(),
                'Телефонов' => $db->table('contacts')->where('type', '=', 'phone')->count(),
                'E-mail' => $db->table('contacts')->where('type', '=', 'email')->count(),
                'Telegram' => $db->table('contacts')->where('type', '=', 'telegram')->count(),
                'Без контактов (активные)' => $db->table('residents')->where('is_active', '=', 1)->whereNull('phone')->whereNull('email')->whereNull('deleted_at')->count(),
                'С долгом' => count($repo->hasDuplicates()) >= 0 ? (int) $db->table('residents')->where('balance', '<', -0.005)->where('is_active', '=', 1)->count() : 0,
            ]);

            $duplicates = $repo->hasDuplicates();
            if ($duplicates !== []) {
                $out->warn('Возможные дубли (одинаковые ФИО + квартира): ' . count($duplicates));
                foreach (array_slice($duplicates, 0, 10) as $duplicate) {
                    $out->bullet(sprintf('кв. %s · %s · %s шт.', $duplicate['apartment'], $duplicate['full_name'], $duplicate['cnt']));
                }
            }

            return 0;
        },
    ),

    new Command(
        name: 'residents:set-flag',
        description: 'Массово включить/выключить жителей или доступ',
        usage: 'bin/bas-ip residents:set-flag --flag=is_active --value=1 [--ids=1,2,3] [--all] [--yes]',
        options: [
            'flag' => 'is_active | access_granted | is_billed | notify_enabled | allow_guest_pass',
            'value' => '1 или 0',
            'ids' => 'Список ID через запятую',
            'all' => 'Применить ко всем активным',
            'yes' => 'Без подтверждения',
        ],
        arguments: [], group: 'Жители',
        handler: static function (Input $in, Output $out, Container $c): int {
            $flag = $in->str('flag', '');
            $allowed = ['is_active', 'access_granted', 'is_billed', 'notify_enabled', 'allow_guest_pass'];
            if (!in_array($flag, $allowed, true)) {
                $out->error('Допустимые флаги: ' . implode(', ', $allowed));

                return 1;
            }
            $value = $in->bool('value');

            /** @var ResidentService $service */
            $service = $c->get(ResidentService::class);
            $ids = $in->has('ids')
                ? array_map('intval', array_filter(explode(',', $in->str('ids'))))
                : ($in->bool('all')
                    ? array_map('intval', $c->get(\BasIp\Db\Database::class)->table('residents')->whereNull('deleted_at')->pluck('id'))
                    : []);

            if ($ids === []) {
                $out->error('Укажите --ids=1,2,3 или --all.');

                return 1;
            }
            if (!$in->bool('yes') && !$out->confirm(sprintf('Изменить %s для %d записей → %s?', $flag, count($ids), $value ? 'да' : 'нет'), false)) {
                return 0;
            }

            $action = match ($flag) {
                'is_active' => $value ? 'activate' : 'deactivate',
                'access_granted' => $value ? 'grant_access' : 'revoke_access',
                'is_billed' => $value ? 'bill' : 'unbill',
                default => null,
            };

            if ($action !== null) {
                $result = $service->bulk($action, $ids, ['only_active' => false]);
                $out->success(sprintf('Обработано: %d, пропущено: %d.', $result['affected'], $result['skipped']));

                return 0;
            }

            $count = $c->get(\BasIp\Db\Database::class)->table('residents')->whereIn('id', $ids)
                ->update([$flag => $value ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]);
            $out->success('Обновлено записей: ' . $count);

            return 0;
        },
    ),

    new Command(
        name: 'residents:reindex',
        description: 'Перестроить поисковые индексы и синхронизировать основные контакты',
        usage: 'bin/bas-ip residents:reindex',
        options: [], arguments: [], group: 'Жители',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var ResidentService $service */
            $service = $c->get(ResidentService::class);
            /** @var \BasIp\Db\Database $db */
            $db = $c->get(\BasIp\Db\Database::class);

            $count = 0;
            foreach ($db->table('residents')->whereNull('deleted_at')->pluck('id') as $id) {
                $service->syncPrimaryContacts((int) $id);
                $service->touchSearchIndex((int) $id);
                $count++;
            }
            $out->success('Переиндексировано жителей: ' . $count);

            return 0;
        },
    ),
];
