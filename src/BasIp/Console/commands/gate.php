<?php

/**
 * Gate, device and guest-access commands.
 */

declare(strict_types=1);

use BasIp\Console\Command;
use BasIp\Console\Input;
use BasIp\Console\Output;
use BasIp\Core\Container;
use BasIp\Device\DeviceManager;
use BasIp\Domain\Model\GateEvent;
use BasIp\Services\GateService;
use BasIp\Services\GuestPassService;
use BasIp\Support\Money;
use BasIp\Support\Str;

return [

    new Command(
        name: 'gate:open',
        description: 'Открыть шлагбаум (и записать событие в журнал)',
        usage: 'bin/bas-ip gate:open [--resident=ID] [--apartment=12] [--device=main] [--note=…]',
        options: [
            'resident' => 'ID жителя', 'apartment' => 'Номер квартиры', 'plate' => 'Госномер',
            'device' => 'Slug устройства', 'channel' => 'Канал реле', 'note' => 'Комментарий в журнал',
            'access' => 'Тип доступа: own|guest|manual|remote|emergency|api (по умолчанию remote)',
        ],
        arguments: [], group: 'Шлагбаум',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var GateService $gate */
            $gate = $c->get(GateService::class);
            /** @var \BasIp\Domain\Repository\ResidentRepository $residents */
            $residents = $c->get(\BasIp\Domain\Repository\ResidentRepository::class);

            $resident = null;
            if ($in->has('resident')) {
                $resident = $residents->findById($in->int('resident'));
            } elseif ($in->has('apartment')) {
                $row = $c->get(\BasIp\Db\Database::class)->table('residents')
                    ->where('apartment', '=', $in->str('apartment'))->whereNull('deleted_at')->first();
                $resident = $row === null ? null : $residents->hydrate($row, true);
            } elseif ($in->has('plate')) {
                $resident = $gate->findByPlate($in->str('plate'));
            }

            if ($in->has('resident') || $in->has('apartment') || $in->has('plate')) {
                if ($resident === null) {
                    $out->error('Житель не найден.');

                    return 1;
                }
                $out->info('Житель: ' . $resident->displayName() . ' · кв. ' . $resident->apartment);
            }

            $result = $gate->open([
                'resident' => $resident,
                'access_type' => $in->str('access', GateEvent::ACCESS_REMOTE),
                'source' => 'cli',
                'device' => $in->str('device') ?: null,
                'channel' => $in->int('channel') ?: null,
                'note' => $in->str('note', 'Открыто из CLI'),
                'force' => $in->bool('force'),
            ]);

            if ($result->ok) {
                $out->success('Шлагбаум открыт (' . round($result->durationMs) . ' мс).');

                return 0;
            }

            $out->error('Отказ: ' . $result->message . ($result->errorCode !== null ? ' [' . $result->errorCode . ']' : ''));

            return 2;
        },
    ),

    new Command(
        name: 'gate:close',
        description: 'Закрыть шлагбаум',
        usage: 'bin/bas-ip gate:close [--device=main]',
        options: ['device' => 'Slug устройства', 'channel' => 'Канал'],
        arguments: [], group: 'Шлагбаум',
        handler: static function (Input $in, Output $out, Container $c): int {
            $result = $c->get(GateService::class)->close(['source' => 'cli', 'device' => $in->str('device') ?: null, 'channel' => $in->int('channel') ?: null]);
            $result->ok ? $out->success('Закрыто.') : $out->error($result->message);

            return $result->ok ? 0 : 2;
        },
    ),

    new Command(
        name: 'gate:status',
        description: 'Состояние панели, время устройства и SIP',
        usage: 'bin/bas-ip gate:status [--device=main] [--json]',
        options: ['device' => 'Slug устройства', 'json' => 'Вывод в JSON', 'fresh' => 'Игнорировать кэш'],
        arguments: [], group: 'Шлагбаум',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var GateService $gate */
            $gate = $c->get(GateService::class);
            $device = $in->str('device') ?: null;
            $status = $gate->status($device, !$in->bool('fresh'));
            $time = $gate->time($device);

            if ($in->bool('json')) {
                $out->line((string) json_encode(['status' => $status->toArray(), 'time' => $time->toArray()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return $status->ok ? 0 : 2;
            }

            $out->title('Состояние панели');
            $out->definitions([
                'Доступность' => $status->ok ? '<green>на связи</green>' : '<red>нет ответа</red>',
                'Сообщение' => $status->message,
                'Время ответа' => round($status->durationMs) . ' мс',
                'SIP' => (string) ($status->get('sip_status') ?? '—'),
                'HTTP' => (string) ($status->status ?? '—'),
            ]);

            $out->section('Время устройства');
            $out->definitions([
                'Время панели' => (string) ($time->get('device_time') ?? '—'),
                'Часовой пояс' => (string) ($time->get('device_timezone') ?? '—'),
                'Рассинхрон' => ($time->get('drift_seconds') ?? 0) . ' с ' . ($time->ok && $time->get('drift_ok') ? '<green>(в норме)</green>' : '<yellow>(проверьте)</yellow>'),
                'Время сервера' => date('Y-m-d H:i:s'),
            ]);

            if (!$status->ok) {
                return 2;
            }

            return 0;
        },
    ),

    new Command(
        name: 'gate:test',
        description: 'Проверить связь с каждым устройством',
        usage: 'bin/bas-ip gate:test [--device=slug]',
        options: ['device' => 'Только указанное устройство'],
        arguments: [], group: 'Шлагбаум',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var DeviceManager $devices */
            $devices = $c->get(DeviceManager::class);
            $devices->load();
            $list = $in->has('device')
                ? array_filter([$devices->find($in->str('device'))])
                : $devices->all(false);

            if ($list === []) {
                $out->warn('Устройства не настроены. Добавьте в админке или в config: device.base_url.');

                return 1;
            }

            $rows = [];
            $failed = 0;
            foreach ($list as $device) {
                $driver = $devices->driver($device);
                $result = $driver->ping();
                if (!$result->ok) {
                    $failed++;
                }
                $devices->recordStatus($device, $result);
                $rows[] = [
                    $device->slug, $device->label(), $device->driver,
                    $result->ok ? '<green>OK</green>' : '<red>ОТКАЗ</red>',
                    round($result->durationMs) . ' мс',
                    Str::limit($result->ok ? ($device->host()) : $result->message, 46),
                ];
            }

            $out->title('Проверка устройств');
            $out->table(['Slug', 'Название', 'Драйвер', 'Статус', 'Время', 'Подробности'], $rows);

            return $failed > 0 ? 2 : 0;
        },
    ),

    new Command(
        name: 'gate:reboot',
        description: 'Перезагрузить панель',
        usage: 'bin/bas-ip gate:reboot [--device=main] [--yes]',
        options: ['device' => 'Slug устройства', 'yes' => 'Без подтверждения'],
        arguments: [], group: 'Шлагбаум',
        handler: static function (Input $in, Output $out, Container $c): int {
            if (!$in->bool('yes') && !$out->confirm('Перезагрузить панель? Жители временно не смогут открыть шлагбаум.', false)) {
                $out->info('Отменено.');

                return 0;
            }
            $result = $c->get(GateService::class)->reboot($in->str('device') ?: null);
            $result->ok ? $out->success('Команда перезагрузки отправлена.') : $out->error($result->message);

            return $result->ok ? 0 : 2;
        },
    ),

    new Command(
        name: 'gate:sip',
        description: 'Включить или выключить SIP на панели',
        usage: 'bin/bas-ip gate:sip --enable | --disable [--device=main]',
        options: ['enable' => 'Включить SIP', 'disable' => 'Выключить SIP', 'device' => 'Slug устройства'],
        arguments: [], group: 'Шлагбаум',
        handler: static function (Input $in, Output $out, Container $c): int {
            $enable = $in->bool('enable');
            if (!$enable && !$in->bool('disable')) {
                $out->error('Укажите --enable или --disable.');

                return 1;
            }
            $result = $c->get(GateService::class)->sip($enable, $in->str('device') ?: null);
            $result->ok ? $out->success('SIP ' . ($enable ? 'включён' : 'выключен') . '.') : $out->error($result->message);

            return $result->ok ? 0 : 2;
        },
    ),

    new Command(
        name: 'gate:snapshot',
        description: 'Сохранить кадр с камеры в файл',
        usage: 'bin/bas-ip gate:snapshot [out.jpg] [--device=main]',
        options: ['device' => 'Slug устройства'],
        arguments: ['out' => 'Файл назначения (по умолчанию storage/app/exports/snapshot-<ts>.jpg)'],
        group: 'Шлагбаум',
        handler: static function (Input $in, Output $out, Container $c): int {
            $jpeg = $c->get(GateService::class)->snapshot($in->str('device') ?: null);
            if ($jpeg === null || $jpeg === '') {
                $out->error('Камера недоступна или снимок не получен.');

                return 2;
            }
            $target = (string) $in->argument(0, '');
            if ($target === '') {
                $target = $c->get(\BasIp\Core\Config::class)->getPath('exports') . '/snapshot-' . date('Ymd-His') . '.jpg';
            }
            \BasIp\Support\Fs::ensureDir(dirname($target));
            file_put_contents($target, $jpeg);
            $out->success('Сохранено: ' . $target . ' (' . Str::bytes(strlen($jpeg)) . ')');

            return 0;
        },
    ),

    new Command(
        name: 'gate:log',
        description: 'Показать журнал открываний',
        usage: 'bin/bas-ip gate:log [--limit=20] [--access=guest] [--result=denied] [--from=YYYY-MM-DD]',
        options: [
            'limit' => 'Сколько записей показать', 'access' => 'Фильтр по типу доступа',
            'result' => 'success|denied|error', 'from' => 'Дата с', 'to' => 'Дата по',
            'resident' => 'ID жителя', 'json' => 'Вывод в JSON', 'stats' => 'Только сводка',
        ],
        arguments: [], group: 'Шлагбаум',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var GateService $gate */
            $gate = $c->get(GateService::class);

            if ($in->bool('stats')) {
                $stats = $gate->stats($in->int('days', 30));
                $out->title('Статистика за ' . $stats['period_days'] . ' дн.');
                $out->definitions([
                    'Всего событий' => $stats['total'],
                    'Успешных' => $stats['success'],
                    'Отказов' => $stats['denied'],
                    'Ошибок' => $stats['errors'],
                    'Свой доступ' => $stats['own'],
                    'Гостевой' => $stats['guest'],
                    'Диспетчер' => $stats['manual'],
                    'Сегодня' => $stats['today'],
                ]);
                $out->section('По типам доступа');
                $rows = [];
                foreach ($stats['by_type'] as $type) {
                    $rows[] = [$type['label'], $type['type'], $type['count']];
                }
                $out->table(['Тип', 'Код', 'Количество'], $rows);

                return 0;
            }

            $filters = array_filter([
                'access_type' => $in->str('access') ?: null,
                'result' => $in->str('result') ?: null,
                'from' => $in->str('from') ?: null,
                'to' => $in->str('to') ?: null,
                'resident_id' => $in->has('resident') ? $in->int('resident') : null,
            ], static fn ($v) => $v !== null && $v !== '');

            $paginator = $gate->events($filters, $in->int('limit', 20), 1);

            if ($in->bool('json')) {
                $out->line((string) json_encode(array_map(static fn (GateEvent $e): array => [
                    'at' => $e->occurredAt, 'actor' => $e->actorLabel(), 'apartment' => $e->apartment,
                    'access_type' => $e->accessType, 'action' => $e->action, 'result' => $e->result,
                    'reason' => $e->reason, 'source' => $e->source, 'plate' => $e->plate, 'ip' => $e->ip,
                ], $paginator->items()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return 0;
            }

            $rows = [];
            foreach ($paginator->items() as $event) {
                /** @var GateEvent $event */
                $rows[] = [
                    $event->when('d.m.Y H:i:s'),
                    Str::limit($event->actorLabel(), 26),
                    $event->accessLabel(),
                    $event->isSuccess() ? '<green>успех</green>' : ($event->result === 'denied' ? '<yellow>отказ</yellow>' : '<red>ошибка</red>'),
                    $event->sourceLabel(),
                    Str::limit((string) ($event->reason ?? ''), 30),
                ];
            }

            $out->title('Журнал открываний (всего: ' . $paginator->total() . ')');
            $out->table(['Когда', 'Кто', 'Доступ', 'Результат', 'Источник', 'Причина'], $rows);

            return 0;
        },
    ),

    new Command(
        name: 'gate:purge',
        description: 'Удалить старые записи журнала',
        usage: 'bin/bas-ip gate:purge --days=730 [--yes]',
        options: ['days' => 'Хранить дней', 'yes' => 'Без подтверждения'],
        arguments: [], group: 'Шлагбаум',
        handler: static function (Input $in, Output $out, Container $c): int {
            $days = $in->int('days', 730);
            if (!$in->bool('yes') && !$out->confirm('Удалить события старше ' . $days . ' дн.?', false)) {
                return 0;
            }
            $count = $c->get(GateService::class)->purgeEvents($days);
            $out->success('Удалено записей: ' . $count);

            return 0;
        },
    ),

    /* ─────────────────────────── гостевые доступы ─────────────────────────── */

    new Command(
        name: 'guest:create',
        description: 'Создать гостевой доступ (по умолчанию 12 часов)',
        usage: 'bin/bas-ip guest:create --resident=ID [--hours=12] [--name=…] [--plate=…] [--phone=…]',
        options: [
            'resident' => 'ID жителя (обязательно)', 'apartment' => 'Номер квартиры вместо ID',
            'hours' => 'Срок действия в часах', 'name' => 'Имя гостя', 'phone' => 'Телефон гостя',
            'plate' => 'Госномер', 'purpose' => 'Цель визита', 'uses' => 'Лимит использований (0 = без лимита)',
            'url' => 'База для ссылки (по умолчанию app.url)',
        ],
        arguments: [], group: 'Гостевой доступ',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var GuestPassService $passes */
            $passes = $c->get(GuestPassService::class);
            /** @var \BasIp\Domain\Repository\ResidentRepository $residents */
            $residents = $c->get(\BasIp\Domain\Repository\ResidentRepository::class);
            /** @var \BasIp\Db\Database $db */
            $db = $c->get(\BasIp\Db\Database::class);

            $residentId = $in->int('resident');
            if ($residentId <= 0 && $in->has('apartment')) {
                $row = $db->table('residents')->where('apartment', '=', $in->str('apartment'))->whereNull('deleted_at')->first();
                $residentId = $row === null ? 0 : (int) $row['id'];
            }
            if ($residentId <= 0) {
                $out->error('Укажите --resident=ID или --apartment=НОМЕР.');

                return 1;
            }
            $resident = $residents->findById($residentId);
            if ($resident === null) {
                $out->error('Житель #' . $residentId . ' не найден.');

                return 1;
            }

            $result = $passes->create([
                'resident_id' => $residentId,
                'ttl_hours' => $in->int('hours', 12),
                'guest_name' => $in->str('name'),
                'guest_phone' => $in->str('phone'),
                'car_plate' => $in->str('plate'),
                'purpose' => $in->str('purpose', 'Создан из CLI'),
                'max_uses' => $in->int('uses', 0),
                'notify' => false,
                'link_base' => $in->str('url', rtrim($c->get(\BasIp\Core\Config::class)->string('app.url', ''), '/')),
            ]);

            if (!$result['ok']) {
                $out->error((string) ($result['error'] ?? 'Ошибка'));

                return 1;
            }

            $out->title('Гостевой доступ создан');
            $out->definitions([
                'Житель' => $resident->displayName() . ' · кв. ' . $resident->apartment,
                'Код' => $result['code'],
                'PIN' => $result['pin'] ?? '—',
                'Действует до' => (string) ($result['pass']?->validUntil ?? ''),
                'Срок' => $result['pass']->ttlHours . ' ч',
                'Ссылка' => $result['link'],
            ]);
            $out->line('');
            $out->info('Ссылку можно отправить гостю в мессенджер — по ней он сам откроет шлагбаум.');

            return 0;
        },
    ),

    new Command(
        name: 'guest:list',
        description: 'Список гостевых доступов',
        usage: 'bin/bas-ip guest:list [--status=active] [--resident=ID] [--limit=20]',
        options: ['status' => 'active|expired|revoked|all', 'resident' => 'ID жителя', 'limit' => 'Количество'],
        arguments: [], group: 'Гостевой доступ',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var GuestPassService $passes */
            $passes = $c->get(GuestPassService::class);
            $paginator = $passes->list(array_filter([
                'status' => $in->str('status', 'active') ?: 'active',
                'resident_id' => $in->has('resident') ? $in->int('resident') : null,
            ], static fn ($v) => $v !== null), $in->int('limit', 20), 1);

            $rows = [];
            foreach ($paginator->items() as $pass) {
                /** @var \BasIp\Domain\Model\GuestPass $pass */
                $rows[] = [
                    $pass->code,
                    Str::limit((string) ($pass->residentName ?? '—'), 22),
                    (string) ($pass->apartment ?? ''),
                    Str::limit((string) ($pass->guestName ?? '—'), 16),
                    $pass->displayPlate() ?: '—',
                    (string) ($pass->validUntil ?? ''),
                    $pass->usesLabel(),
                    $pass->isActive() ? '<green>активен</green>' : '<dim>' . $pass->statusLabel() . '</dim>',
                ];
            }

            $out->title('Гостевые доступы (всего: ' . $paginator->total() . ')');
            $out->table(['Код', 'Житель', 'Кв.', 'Гость', 'Авто', 'До', 'Использований', 'Статус'], $rows);

            return 0;
        },
    ),

    new Command(
        name: 'guest:revoke',
        description: 'Отозвать гостевой доступ по коду или ID',
        usage: 'bin/bas-ip guest:revoke <код|ID> [--reason=…]',
        options: ['reason' => 'Причина отзыва'],
        arguments: ['pass' => 'Код (7K4M-Q2XD) или числовой ID'],
        group: 'Гостевой доступ',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var GuestPassService $passes */
            $passes = $c->get(GuestPassService::class);
            $needle = (string) $in->argument(0, '');
            if ($needle === '') {
                $out->error('Укажите код или ID.');

                return 1;
            }
            $pass = ctype_digit($needle) ? $passes->find((int) $needle) : $passes->findByCode($needle);
            if ($pass === null) {
                $out->error('Гостевой доступ не найден: ' . $needle);

                return 1;
            }
            $ok = $passes->revoke($pass->id, $in->str('reason', 'Отозван из CLI'));
            $ok ? $out->success('Отозван: ' . $pass->code) : $out->error('Не удалось отозвать.');

            return $ok ? 0 : 1;
        },
    ),

    new Command(
        name: 'guest:expire',
        description: 'Пометить истёкшие гостевые доступы (обычно вызывает cron)',
        usage: 'bin/bas-ip guest:expire',
        options: [], arguments: [], group: 'Гостевой доступ',
        handler: static function (Input $in, Output $out, Container $c): int {
            $count = $c->get(GuestPassService::class)->expireStale();
            $out->success('Помечено истёкших: ' . $count);

            return 0;
        },
    ),
];
