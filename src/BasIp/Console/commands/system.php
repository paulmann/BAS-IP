<?php

/**
 * System commands: cron scheduler, queue, backups, updates, cache, logs, API tokens.
 */

declare(strict_types=1);

use BasIp\Console\Command;
use BasIp\Console\Input;
use BasIp\Console\Output;
use BasIp\Core\App;
use BasIp\Core\Config;
use BasIp\Core\Container;
use BasIp\Core\Logger;
use BasIp\Db\Database;
use BasIp\Notify\NotificationService;
use BasIp\Notify\QueueWorker;
use BasIp\Services\ApiTokenService;
use BasIp\Services\BackupService;
use BasIp\Services\DataService;
use BasIp\Services\GuestPassService;
use BasIp\Update\UpdateService;
use BasIp\Support\Str;

return [

    /* ══════════════════ cron ══════════════════ */

    new Command(
        name: 'schedule:run',
        description: 'Выполнить плановые задачи (добавьте в cron каждую минуту)',
        usage: 'bin/bas-ip schedule:run [--queue] [--backup] [--update]',
        options: [
            'queue' => 'Также обработать очередь уведомлений',
            'backup' => 'Создать резервную копию, если подошёл срок',
            'update' => 'Проверить обновления',
            'verbose-jobs' => 'Показать результат каждой задачи',
        ],
        arguments: [], group: 'Планировщик',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var DataService $data */
            $data = $c->get(DataService::class);
            $report = $data->runScheduledJobs();
            $out->debug('Плановые задачи: ' . json_encode($report['jobs'], JSON_UNESCAPED_UNICODE));

            // Истёкшие гостевые доступы.
            $expired = $c->get(GuestPassService::class)->expireStale();
            $out->debug('Истёкших гостевых доступов: ' . $expired);

            // Просроченные счета и (опционально) блокировка должников.
            $overdue = $c->get(\BasIp\Services\BillingService::class)->markOverdue();
            $out->debug('Просроченных счетов: ' . $overdue);

            if ($in->bool('queue') || $c->get(Config::class)->bool('notify.queue.enabled', true)) {
                /** @var QueueWorker $worker */
                $worker = $c->get(QueueWorker::class);
                $stats = $worker->run($c->get(Config::class)->int('notify.queue.batch_size', 50), 50);
                $out->debug('Очередь: ' . json_encode($stats));
            }

            if ($in->bool('update') && $c->get(Config::class)->bool('update.enabled', true)) {
                /** @var UpdateService $updates */
                $updates = $c->get(UpdateService::class);
                $result = $updates->check(false);
                if (!empty($result['update_available'])) {
                    $out->warn('Доступна новая версия: ' . ($result['latest']?->version() ?? '?'));
                    if ($c->get(Config::class)->string('update.mode') === 'auto') {
                        $install = $updates->install();
                        $install['ok']
                            ? $out->success('Автообновление выполнено: ' . ($install['to'] ?? '?'))
                            : $out->error('Автообновление не удалось: ' . ($install['error'] ?? '?'));
                    }
                }
            }

            if ($in->bool('backup')) {
                $last = $c->get(BackupService::class)->lastBackupAt();
                $stale = $last === null || strtotime($last) < time() - 86400;
                if ($stale) {
                    $result = $c->get(BackupService::class)->create('db', 'Плановая копия (cron)');
                    $result['ok']
                        ? $out->debug('Резервная копия: ' . ($result['file'] ?? ''))
                        : $out->warn('Резервная копия не создана: ' . ($result['error'] ?? ''));
                }
            }

            if ($in->bool('verbose-jobs')) {
                $out->definitions($report['jobs']);
            }

            return 0;
        },
    ),

    /* ══════════════════ очередь уведомлений ══════════════════ */

    new Command(
        name: 'queue:run',
        description: 'Разобрать очередь уведомлений один раз (для cron)',
        usage: 'bin/bas-ip queue:run [--limit=100] [--seconds=50]',
        options: ['limit' => 'Максимум сообщений', 'seconds' => 'Ограничение по времени'],
        arguments: [], group: 'Уведомления',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var QueueWorker $worker */
            $worker = $c->get(QueueWorker::class);
            $stats = $worker->run($in->int('limit', 100), $in->int('seconds', 50));

            $out->definitions([
                'Обработано' => $stats['processed'],
                'Доставлено' => $stats['sent'],
                'Ошибок' => $stats['failed'],
                'Пропущено' => $stats['skipped'],
                'Осталось в очереди' => $worker->pendingCount(),
            ]);

            return $stats['failed'] > 0 ? 1 : 0;
        },
    ),

    new Command(
        name: 'queue:work',
        description: 'Запустить постоянного работника очереди (systemd/ supervisord)',
        usage: 'bin/bas-ip queue:work [--sleep=5]',
        options: ['sleep' => 'Пауза при пустой очереди, сек'],
        arguments: [], group: 'Уведомления',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var QueueWorker $worker */
            $worker = $c->get(QueueWorker::class);
            $out->info('Работник очереди запущен (PID ' . getmypid() . '). Остановка: SIGTERM/SIGINT.');
            $worker->loop($in->int('sleep', 5));

            return 0;
        },
    ),

    new Command(
        name: 'queue:stats',
        description: 'Статистика очереди уведомлений',
        usage: 'bin/bas-ip queue:stats [--retry-failed]',
        options: ['retry-failed' => 'Вернуть ошибки в очередь'],
        arguments: [], group: 'Уведомления',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var NotificationService $notify */
            $notify = $c->get(NotificationService::class);

            if ($in->bool('retry-failed')) {
                $out->success('Возобновлено сообщений: ' . $notify->retryFailed());
            }

            $out->title('Очередь уведомлений');
            $out->definitions($notify->queueStats());

            $out->section('Каналы');
            $rows = [];
            foreach ($notify->channelStatus() as $channel) {
                $rows[] = [
                    $channel['name'], $channel['label'],
                    $channel['enabled'] ? '<green>включён</green>' : '<dim>выключен</dim>',
                ];
            }
            $out->table(['Канал', 'Название', 'Статус'], $rows);

            $out->section('Последние пакеты');
            $rows = [];
            foreach ($notify->batches(10, 1)->items() as $batch) {
                $rows[] = [
                    (string) $batch['id'], Str::limit((string) $batch['name'], 30), (string) $batch['status'],
                    (string) $batch['recipients'], (string) $batch['sent'], (string) $batch['failed'], (string) ($batch['created_at'] ?? ''),
                ];
            }
            $out->table(['ID', 'Название', 'Статус', 'Получателей', 'Отправлено', 'Ошибок', 'Создан'], $rows);

            return 0;
        },
    ),

    new Command(
        name: 'notify:test',
        description: 'Отправить тестовое сообщение через канал',
        usage: 'bin/bas-ip notify:test --channel=mail --to=admin@example.com',
        options: ['channel' => 'log|mail|telegram|sms|webhook|whatsapp', 'to' => 'Получатель'],
        arguments: [], group: 'Уведомления',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var NotificationService $notify */
            $notify = $c->get(NotificationService::class);
            $channel = $notify->channel($in->str('channel', 'log'));
            if ($channel === null) {
                $out->error('Неизвестный канал: ' . $in->str('channel', 'log'));

                return 1;
            }
            $to = $in->str('to', '');
            if ($to === '') {
                $out->error('Укажите --to=получатель.');

                return 1;
            }
            $result = $channel->test($to);
            $result['ok'] ? $out->success($result['message']) : $out->error($result['message']);

            return $result['ok'] ? 0 : 1;
        },
    ),

    new Command(
        name: 'notify:send',
        description: 'Отправить произвольное сообщение жителю или на адрес',
        usage: 'bin/bas-ip notify:send --resident=ID --subject=… --body=… [--channels=mail,telegram]',
        options: [
            'resident' => 'ID жителя', 'to' => 'Или прямой адрес (e-mail/телефон/@telegram)',
            'channel' => 'Канал для прямого адреса', 'subject' => 'Тема', 'body' => 'Текст',
            'channels' => 'Список каналов через запятую', 'now' => 'Отправить сразу, минуя очередь',
        ],
        arguments: [], group: 'Уведомления',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var NotificationService $notify */
            $notify = $c->get(NotificationService::class);
            $subject = $in->str('subject', 'Сообщение от управляющего');
            $body = $in->str('body', '');
            if ($body === '') {
                $body = (string) $out->ask('Текст сообщения', '');
            }
            if ($body === '') {
                $out->error('Пустое сообщение не отправлено.');

                return 1;
            }

            $resident = null;
            if ($in->has('resident')) {
                $resident = $c->get(\BasIp\Domain\Repository\ResidentRepository::class)->findById($in->int('resident'));
                if ($resident === null) {
                    $out->error('Житель не найден.');

                    return 1;
                }
            }

            $message = \BasIp\Notify\Message::make()
                ->subject($subject)
                ->body($body)
                ->template('cli')
                ->channels(array_values(array_filter(explode(',', $in->str('channels', '')))) ?: null);

            if ($resident !== null) {
                $message = \BasIp\Notify\Message::forResident($resident)->subject($subject)->body($body)->template('cli')
                    ->channels(array_values(array_filter(explode(',', $in->str('channels', '')))) ?: null);
            } elseif ($in->has('to')) {
                $message = $message->to($in->str('to'));
                if ($in->has('channel')) {
                    $message = $message->channel($in->str('channel'));
                }
            } else {
                $out->error('Укажите --resident=ID или --to=адрес.');

                return 1;
            }

            if ($in->bool('now')) {
                $results = $notify->sendNow($message, $resident);
                $failed = 0;
                foreach ($results as $result) {
                    if (!empty($result['ok'])) {
                        $out->success('Доставлено через ' . $result['channel'] . ' → ' . ($result['to'] ?? ''));
                        continue;
                    }
                    $out->error($result['channel'] . ': ' . ($result['error'] ?? 'ошибка'));
                    $failed++;
                }

                return $failed > 0 ? 1 : 0;
            }

            $queued = $notify->queue($message, $resident);
            $out->success('В очередь поставлено адресов: ' . $queued);

            return 0;
        },
    ),

    /* ══════════════════ резервные копии ══════════════════ */

    new Command(
        name: 'backup:create',
        description: 'Создать резервную копию',
        usage: 'bin/bas-ip backup:create [--type=db|full] [--note=…]',
        options: ['type' => 'db (только база) или full (база + конфиги)', 'note' => 'Примечание'],
        arguments: [], group: 'Резервные копии',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var BackupService $backups */
            $backups = $c->get(BackupService::class);
            $result = $backups->create($in->str('type', 'db') === 'full' ? 'full' : 'db', $in->str('note', 'Создано из CLI'));

            if (!$result['ok']) {
                $out->error((string) ($result['error'] ?? 'Ошибка'));

                return 1;
            }
            $out->success(sprintf('Копия создана: %s (%s, способ: %s)', $result['file'], Str::bytes((int) $result['bytes']), (string) ($result['method'] ?? 'php')));
            $out->bullet('Путь: <cyan>' . $result['path'] . '</cyan>');

            return 0;
        },
    ),

    new Command(
        name: 'backup:list',
        description: 'Список резервных копий',
        usage: 'bin/bas-ip backup:list',
        options: [], arguments: [], group: 'Резервные копии',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var BackupService $backups */
            $backups = $c->get(BackupService::class);
            $rows = [];
            foreach ($backups->list() as $backup) {
                $rows[] = [
                    (string) $backup['id'], Str::limit((string) $backup['filename'], 40),
                    (string) $backup['type'], Str::bytes((int) $backup['size_bytes']),
                    (string) ($backup['driver'] ?? '—'), (string) $backup['status'],
                    $backup['encrypted'] ? '<green>да</green>' : '—',
                    (string) ($backup['created_at'] ?? ''),
                ];
            }
            $out->title('Резервные копии (' . count($rows) . ', всего ' . Str::bytes($backups->totalSize()) . ')');
            $out->table(['ID', 'Файл', 'Тип', 'Размер', 'Драйвер', 'Статус', 'Шифр.', 'Создана'], $rows);

            return 0;
        },
    ),

    new Command(
        name: 'backup:restore',
        description: 'Восстановить из резервной копии (ОПАСНО)',
        usage: 'bin/bas-ip backup:restore ID [--yes]',
        options: ['yes' => 'Без подтверждения'],
        arguments: ['id' => 'ID копии из backup:list'],
        group: 'Резервные копии',
        handler: static function (Input $in, Output $out, Container $c): int {
            $id = (int) $in->argument(0, '0');
            if ($id <= 0) {
                $out->error('Укажите ID копии.');

                return 1;
            }
            if (!$in->bool('yes') && !$out->confirm('<red>Текущие данные будут перезаписаны.</red> Восстановить копию #' . $id . '?', false)) {
                return 0;
            }

            /** @var BackupService $backups */
            $backups = $c->get(BackupService::class);
            $result = $backups->restore($id, 'restore:' . $id);

            $result['ok']
                ? $out->success('Восстановление завершено (операций: ' . (int) $result['statements'] . ').')
                : $out->error((string) ($result['error'] ?? 'Ошибка'));

            return $result['ok'] ? 0 : 1;
        },
    ),

    /* ══════════════════ обновления ══════════════════ */

    new Command(
        name: 'update:check',
        description: 'Проверить наличие новой версии в репозитории',
        usage: 'bin/bas-ip update:check [--force] [--json]',
        options: ['force' => 'Игнорировать кэш', 'json' => 'Вывод в JSON'],
        arguments: [], group: 'Обновления',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var UpdateService $updates */
            $updates = $c->get(UpdateService::class);
            $result = $updates->check($in->bool('force'));

            if ($in->bool('json')) {
                $out->line((string) json_encode([
                    'ok' => $result['ok'], 'current' => $result['current'],
                    'update_available' => $result['update_available'] ?? false,
                    'latest' => isset($result['latest']) ? $result['latest']->toArray() : null,
                    'error' => $result['error'] ?? null,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

                return $result['ok'] ? 0 : 1;
            }

            if (!$result['ok']) {
                $out->error((string) ($result['error'] ?? 'Не удалось проверить обновления.'));

                return 1;
            }

            /** @var \BasIp\Update\Release|null $latest */
            $latest = $result['latest'] ?? null;
            $out->title('Обновления');
            $out->definitions([
                'Установленная версия' => $result['current'],
                'Доступная версия' => $latest?->version() ?? '—',
                'Канал' => (string) ($result['channel'] ?? 'stable'),
                'Репозиторий' => $updates->repo(),
                'Проверено' => (string) ($result['checked_at'] ?? ''),
                'Режим' => $c->get(Config::class)->string('update.mode', 'notify'),
            ]);

            if (!empty($result['update_available'])) {
                $out->line('');
                $out->warn('Доступна новая версия ' . $latest?->version() . '!');
                $out->bullet('Что нового: ' . Str::limit($latest?->summary(300) ?? '', 300));
                $out->bullet('Страница релиза: <cyan>' . ($latest?->url ?? '') . '</cyan>');
                $out->bullet('Установка: <cyan>bin/bas-ip update:run</cyan>');
            } else {
                $out->success('Установлена актуальная версия.');
            }

            return 0;
        },
    ),

    new Command(
        name: 'update:run',
        description: 'Скачать и установить новую версию',
        usage: 'bin/bas-ip update:run [--yes] [--no-backup] [--ref=v2.1.0]',
        options: [
            'yes' => 'Без подтверждения', 'no-backup' => 'Пропустить резервную копию',
            'ref' => 'Конкретный тег/ветка', 'archive' => 'Путь к уже скачанному zip',
        ],
        arguments: [], group: 'Обновления',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var UpdateService $updates */
            $updates = $c->get(UpdateService::class);

            if (!$updates->enabled()) {
                $out->error('Обновления отключены (update.enabled = false).');

                return 1;
            }

            $check = $updates->check(true);
            if (!$check['ok']) {
                $out->error((string) ($check['error'] ?? 'Проверка не удалась.'));

                return 1;
            }
            $latest = $check['latest'] ?? null;

            if (!$in->has('archive') && !$in->has('ref') && empty($check['update_available'])) {
                $out->success('Уже установлена последняя версия ' . $check['current'] . '.');

                return 0;
            }

            $target = $in->str('ref') ?: ($latest?->tag ?? '');
            $out->warn(sprintf('Обновление %s → %s', $check['current'], $target !== '' ? $target : 'из архива'));
            $out->bullet('Локальный конфиг, .env, storage/ и секреты <green>не затрагиваются</green>.');
            $out->bullet('Будет создана резервная копия' . ($in->bool('no-backup') ? ' (отключено)' : '.') . ' и снимок для отката.');

            if (!$in->bool('yes') && !$out->confirm('Продолжить?', false)) {
                $out->info('Отменено.');

                return 0;
            }

            $archive = $in->str('archive', '');
            if ($archive === '') {
                $out->info('Скачиваю архив…');
                $download = $updates->download(null, $in->str('ref') ?: null);
                if (!$download['ok']) {
                    $out->error((string) ($download['error'] ?? 'Не удалось скачать.'));

                    return 1;
                }
                $archive = (string) $download['path'];
                $out->success('Загружено ' . Str::bytes((int) ($download['bytes'] ?? 0)) . ' · sha256 ' . substr((string) ($download['sha256'] ?? ''), 0, 16) . '…');
            }

            $out->info('Устанавливаю…');
            $result = $updates->install($archive, null, null, $in->bool('no-backup'));

            foreach ((array) ($result['log'] ?? []) as $line) {
                $out->bullet($line);
            }

            if (!$result['ok']) {
                $out->error('Обновление не выполнено: ' . (string) ($result['error'] ?? '?'));
                $out->info('Откат: <cyan>bin/bas-ip update:rollback</cyan>');

                return 1;
            }

            $out->success(sprintf('Обновлено до %s (файлов: %d, миграций: %d).', $result['to'], $result['files'], count($result['migrations'])));
            $out->bullet('Снимок для отката: <cyan>' . basename((string) ($result['snapshot'] ?? '')) . '</cyan>');

            return 0;
        },
    ),

    new Command(
        name: 'update:rollback',
        description: 'Откатиться к предыдущей версии из снимка',
        usage: 'bin/bas-ip update:rollback [--yes]',
        options: ['yes' => 'Без подтверждения'],
        arguments: [], group: 'Обновления',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var UpdateService $updates */
            $updates = $c->get(UpdateService::class);
            $snapshots = $updates->snapshots();
            if ($snapshots === []) {
                $out->error('Снимков для отката нет.');

                return 1;
            }
            $out->info('Будет восстановлен снимок: ' . $snapshots[0]['file']);
            if (!$in->bool('yes') && !$out->confirm('Выполнить откат?', false)) {
                return 0;
            }

            $result = $updates->rollback();
            $result['ok']
                ? $out->success('Откат к ' . $result['version'] . ' выполнен (файлов: ' . $result['files'] . ').')
                : $out->error((string) ($result['error'] ?? 'Ошибка отката'));

            return $result['ok'] ? 0 : 1;
        },
    ),

    new Command(
        name: 'update:snapshots',
        description: 'Показать сохранённые снимки версий',
        usage: 'bin/bas-ip update:snapshots',
        options: [], arguments: [], group: 'Обновления',
        handler: static function (Input $in, Output $out, Container $c): int {
            $rows = [];
            foreach ($c->get(UpdateService::class)->snapshots() as $snapshot) {
                $rows[] = [$snapshot['file'], Str::bytes((int) $snapshot['bytes']), date('d.m.Y H:i:s', (int) $snapshot['at'])];
            }
            $out->title('Снимки версий');
            $out->table(['Файл', 'Размер', 'Создан'], $rows);

            return 0;
        },
    ),

    /* ══════════════════ обслуживание ══════════════════ */

    new Command(
        name: 'cache:clear',
        description: 'Очистить кэш приложения и снимки состояния устройств',
        usage: 'bin/bas-ip cache:clear',
        options: [], arguments: [], group: 'Обслуживание', needsDatabase: false,
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var DataService $data */
            $data = $c->get(DataService::class);
            $count = $data->clearCache();
            $files = $data->clearExpiredFiles();
            $out->success(sprintf('Очищено записей кэша: %d; файлов: %s', $count, json_encode($files, JSON_UNESCAPED_UNICODE)));

            return 0;
        },
    ),

    new Command(
        name: 'logs:tail',
        description: 'Показать последние строки журнала',
        usage: 'bin/bas-ip logs:tail [канал] [--lines=100] [--level=error] [--follow]',
        options: ['lines' => 'Сколько строк', 'level' => 'Фильтр по уровню', 'q' => 'Поиск', 'follow' => 'Следить (как tail -f)'],
        arguments: ['channel' => 'app | audit | gate | device | notify | error | access | update'],
        group: 'Обслуживание', needsDatabase: false,
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var Logger $logger */
            $logger = $c->get(Logger::class);
            $channel = (string) $in->argument(0, 'app');
            $lines = $in->int('lines', 100);

            $entries = $logger->tail($channel, $lines, $in->str('level', '') ?: null, $in->str('q', '') ?: null);
            $entries = array_reverse($entries);

            foreach ($entries as $entry) {
                $out->line(self_formatEntry($entry));
            }

            if (!$in->bool('follow')) {
                return 0;
            }

            $out->info('Слежу за ' . $channel . ' (Ctrl+C для выхода)…');
            $seen = count($entries);
            while (true) {
                sleep(1);
                $fresh = array_reverse($logger->tail($channel, $seen + 20, $in->str('level', '') ?: null));
                foreach (array_slice($fresh, $seen) as $entry) {
                    $out->line(self_formatEntry($entry));
                }
                $seen = max($seen, count($fresh));
            }
        },
    ),

    new Command(
        name: 'logs:list',
        description: 'Список файлов журналов с размерами',
        usage: 'bin/bas-ip logs:list [--truncate=канал]',
        options: ['truncate' => 'Очистить указанный канал'],
        arguments: [], group: 'Обслуживание', needsDatabase: false,
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var Logger $logger */
            $logger = $c->get(Logger::class);

            if ($in->has('truncate')) {
                $count = $logger->truncate($in->str('truncate'));
                $out->success('Удалено файлов канала «' . $in->str('truncate') . '»: ' . $count);

                return 0;
            }

            $rows = [];
            foreach ($logger->availableFiles() as $file) {
                $rows[] = [
                    $file['name'], Str::bytes((int) $file['size']), (string) $file['lines'],
                    date('d.m.Y H:i:s', (int) $file['mtime']),
                ];
            }
            $out->title('Журналы (storage/logs)');
            $out->table(['Файл', 'Размер', 'Строк', 'Изменён'], $rows);

            return 0;
        },
    ),

    new Command(
        name: 'prune',
        description: 'Удалить устаревшие записи согласно политике хранения',
        usage: 'bin/bas-ip prune [--gate-days=730] [--audit-days=365] [--yes]',
        options: [
            'gate-days' => 'Хранить журнал шлагбаума, дней',
            'audit-days' => 'Хранить аудит, дней',
            'login-days' => 'Хранить попытки входа, дней',
            'outbox-days' => 'Хранить отправленные уведомления, дней',
            'history-days' => 'Хранить историю начислений, дней',
            'guest-days' => 'Хранить закрытые гостевые доступы, дней',
            'yes' => 'Без подтверждения',
        ],
        arguments: [], group: 'Обслуживание',
        handler: static function (Input $in, Output $out, Container $c): int {
            if (!$in->bool('yes') && !$out->confirm('Удалить устаревшие записи?', true)) {
                return 0;
            }
            /** @var DataService $data */
            $data = $c->get(DataService::class);
            $result = $data->prune([
                'gate_events' => $in->int('gate-days', 730),
                'audit_log' => $in->int('audit-days', 365),
                'login_attempts' => $in->int('login-days', 90),
                'outbox' => $in->int('outbox-days', 60),
                'billing_history' => $in->int('history-days', 1825),
            ]);
            $result['guest_passes'] = $c->get(GuestPassService::class)->purgeOld($in->int('guest-days', 90));

            $out->title('Очистка устаревших данных');
            $out->definitions($result);

            return 0;
        },
    ),

    new Command(
        name: 'health',
        description: 'Проверка состояния (для мониторинга; код возврата 0 = OK)',
        usage: 'bin/bas-ip health [--deep] [--json]',
        options: ['deep' => 'Проверить и устройство', 'json' => 'Вывод в JSON'],
        arguments: [], group: 'Диагностика', needsDatabase: false,
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var DataService $data */
            $data = $c->get(DataService::class);
            $health = $data->health($in->bool('deep'));

            if ($in->bool('json')) {
                $out->line((string) json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return $health['status'] === 'ok' ? 0 : ($health['status'] === 'degraded' ? 0 : 1);
            }

            $out->title('Состояние системы: ' . strtoupper($health['status']));
            foreach ($health['checks'] as $name => $check) {
                $mark = ($check['ok'] ?? false) ? '<green>✔</green>' : '<red>✖</red>';
                $line = sprintf('  %s %-26s <dim>%s</dim>', $mark, (string) $name, (string) ($check['value'] ?? ''));
                if (isset($check['message']) && $check['message'] !== null) {
                    $line .= ' <yellow>— ' . $check['message'] . '</yellow>';
                }
                $out->line($line);
            }

            return $health['status'] === 'down' ? 1 : 0;
        },
    ),

    new Command(
        name: 'api:token',
        description: 'Создать API-токен для внешних интеграций',
        usage: 'bin/bas-ip api:token --name="Home Assistant" [--scopes=gate.open,gate.log] [--days=365]',
        options: ['name' => 'Название токена', 'scopes' => 'Области доступа через запятую', 'days' => 'Срок действия в днях (0 = бессрочно)'],
        arguments: [], group: 'Обслуживание',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var ApiTokenService $tokens */
            $tokens = $c->get(ApiTokenService::class);
            $name = $in->str('name', '') ?: $out->ask('Название токена', 'integration');
            $scopes = array_values(array_filter(array_map('trim', explode(',', $in->str('scopes', 'gate.open,gate.log,gate.status')))));
            $days = $in->int('days', 0);

            $result = $tokens->create($name, $scopes, null, $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null);

            $out->success('Токен создан: ' . $name);
            $out->line('');
            $out->line('  <bold><green>' . $result['token'] . '</green></bold>');
            $out->line('');
            $out->warn('Показываем один раз — сохраните сейчас. В базе хранится только хеш.');
            $out->bullet('Области: <cyan>' . implode(', ', $scopes) . '</cyan>');
            $out->bullet('Использование: <dim>curl -H "Authorization: Bearer ' . $result['token'] . '" ' . rtrim($c->get(Config::class)->string('app.url', ''), '/') . '/api/v1/gate/status</dim>');

            return 0;
        },
    ),

    new Command(
        name: 'api:tokens',
        description: 'Список API-токенов',
        usage: 'bin/bas-ip api:tokens [--revoke=ID]',
        options: ['revoke' => 'Отозвать токен по ID'],
        arguments: [], group: 'Обслуживание',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var ApiTokenService $tokens */
            $tokens = $c->get(ApiTokenService::class);

            if ($in->has('revoke')) {
                $ok = $tokens->revoke($in->int('revoke'));
                $ok ? $out->success('Токен отозван.') : $out->error('Не найдено.');

                return $ok ? 0 : 1;
            }

            $rows = [];
            foreach ($tokens->all() as $token) {
                $scopes = json_decode((string) ($token['scopes'] ?? '[]'), true) ?: [];
                $rows[] = [
                    (string) $token['id'], Str::limit((string) $token['name'], 24),
                    (string) ($token['prefix'] ?? '') . '…',
                    Str::limit(implode(', ', (array) $scopes), 34),
                    $token['is_active'] ? '<green>активен</green>' : '<red>отозван</red>',
                    (string) ($token['expires_at'] ?? 'бессрочно'),
                    (string) ($token['last_used_at'] ?? '—'),
                ];
            }
            $out->title('API-токены');
            $out->table(['ID', 'Название', 'Префикс', 'Области', 'Статус', 'Действует до', 'Последний вызов'], $rows);

            return 0;
        },
    ),

    new Command(
        name: 'maintenance',
        description: 'Включить/выключить режим обслуживания',
        usage: 'bin/bas-ip maintenance [--on] [--off] [--message=…]',
        options: ['on' => 'Включить', 'off' => 'Выключить', 'message' => 'Сообщение для посетителей'],
        arguments: [], group: 'Обслуживание', needsDatabase: false,
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var DataService $data */
            $data = $c->get(DataService::class);
            $on = $in->bool('on') || (!$in->bool('off') && !$data->isMaintenance());
            if ($in->bool('off')) {
                $on = false;
            }
            $data->setMaintenance($on, $in->str('message', ''));
            $on ? $out->warn('Режим обслуживания включён (шлагбаум продолжает работать).') : $out->success('Режим обслуживания выключен.');

            return 0;
        },
    ),

    new Command(
        name: 'serve',
        description: 'Запустить встроенный сервер PHP для разработки',
        usage: 'bin/bas-ip serve [--host=127.0.0.1] [--port=8080]',
        options: ['host' => 'Адрес', 'port' => 'Порт'],
        arguments: [], group: 'Разработка', needsDatabase: false,
        handler: static function (Input $in, Output $out, Container $c): int {
            $host = $in->str('host', '127.0.0.1');
            $port = $in->int('port', 8080);
            $root = $c->get(Config::class)->getPath('public');

            $out->info(sprintf('Сервер разработки: http://%s:%d (документы: %s)', $host, $port, $root));
            $out->line('  <dim>Остановка: Ctrl+C</dim>');
            $out->line('');

            passthru(sprintf('%s -S %s:%d -t %s %s/router.php 2>&1', escapeshellcmd(PHP_BINARY), escapeshellarg($host), $port, escapeshellarg($root), escapeshellarg($root)));

            return 0;
        },
    ),

    new Command(
        name: 'about',
        description: 'Сводка об установке: версия, окружение, база, счётчики',
        usage: 'bin/bas-ip about',
        options: [], arguments: [], group: 'Диагностика',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var DataService $data */
            $data = $c->get(DataService::class);
            /** @var App $app */
            $app = $c->get(App::class);
            $info = $data->systemInfo();

            $out->title('BAS-IP Gate Manager ' . App::VERSION . ' «' . App::CODENAME . '»');
            $out->section('Приложение');
            $out->definitions($info['app']);
            $out->section('Окружение');
            $out->definitions([
                'PHP' => $info['php']['version'] . ' (' . $info['php']['sapi'] . ')',
                'Рекомендуется' => $info['php']['recommended'],
                'memory_limit' => $info['php']['memory_limit'],
                'OPcache' => $info['php']['opcache'] ? 'включён' : 'выключен',
                'ОС' => $info['server']['os'],
                'Веб-сервер' => $info['server']['software'],
                'Свободно на диске' => $info['server']['disk_free'] . ' из ' . $info['server']['disk_total'],
            ]);
            $out->section('База данных');
            $out->definitions([
                'Драйвер' => $info['database']['pdo_driver'] ?? '?',
                'Версия сервера' => $info['database']['version'] ?? '?',
                'База' => $info['database']['server'] ?? '?',
                'Таблиц' => $info['database']['tables'] ?? 0,
                'Размер' => $info['database']['size'] ?? '—',
                'Префикс' => $info['database']['prefix'] ?? '',
                'Запросов за сессию' => $info['database']['queries'] ?? 0,
            ]);
            $out->section('Данные');
            $out->definitions($info['counters']);
            $out->section('Хранилище');
            $out->definitions($info['storage']);
            $out->section('Конфигурация');
            foreach ($info['config_sources'] as $source) {
                $out->bullet($source);
            }
            $out->line('');

            return 0;
        },
    ),
];

/**
 * Format one log entry for `logs:tail`.
 *
 * @param array<string, mixed> $entry
 */
function self_formatEntry(array $entry): string
{
    $level = strtoupper((string) ($entry['level'] ?? 'INFO'));
    $color = match ($level) {
        'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY' => 'red',
        'WARNING' => 'yellow',
        'DEBUG' => 'dim',
        default => 'cyan',
    };
    $context = (array) ($entry['context'] ?? []);
    $suffix = $context === [] ? '' : ' <dim>' . Str::limit((string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 160) . '</dim>';

    return sprintf(
        '<dim>%s</dim> <%s>%-9s</%s> <bold>%s</bold>%s',
        (string) ($entry['ts'] ?? ''), $color, $level, $color,
        (string) ($entry['message'] ?? ''), $suffix
    );
}
