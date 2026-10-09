<?php

declare(strict_types=1);

namespace BasIp\Services;

use BasIp\Auth\User;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Crypto;
use BasIp\Core\Request;
use BasIp\Db\Database;
use BasIp\Domain\Model\Entity;
use BasIp\Domain\Model\Payment;
use BasIp\Domain\Repository\ResidentRepository;
use BasIp\Support\Money;
use BasIp\Support\Phone;
use BasIp\Support\Plate;
use BasIp\Support\Str;
use Throwable;

/**
 * Export / import of the resident database.
 *
 * Formats: CSV (Excel-friendly, `;` by default, with BOM), JSON (the canonical
 * full-fidelity format — contacts, cars, invoices and payments included) and
 * a "print" variant for the paper register.
 *
 * Import supports a **dry-run** that validates everything and reports what
 * would happen, then applies with `upsert` / `insert` / `replace` semantics.
 */
final class ImportExportService
{
    /** Canonical column set for CSV. Russian headers are accepted on import. */
    public const COLUMNS = [
        'id', 'external_id', 'last_name', 'first_name', 'patronymic', 'full_name',
        'apartment', 'apartment_no', 'building', 'entrance', 'floor',
        'area', 'persons', 'share', 'role_in_home',
        'phone', 'phones', 'email', 'emails', 'telegram',
        'car_plate', 'car_brand', 'car_model', 'cars',
        'access_granted', 'is_active', 'is_billed', 'allow_guest_pass', 'notify_enabled',
        'tags', 'notes', 'balance', 'last_payment_at', 'created_at',
    ];

    /** Alias map for import: what a user's spreadsheet might call the column. */
    private const ALIASES = [
        'last_name'   => ['фамилия', 'lastname', 'surname', 'ф'],
        'first_name'  => ['имя', 'firstname', 'name', 'и'],
        'patronymic'  => ['отчество', 'middlename', 'patronymic', 'о'],
        'full_name'   => ['фио', 'полное имя', 'fullname', 'name_full', 'кто'],
        'apartment'   => ['квартира', 'кв', 'apt', 'apartment', 'номер квартиры', '№ кв'],
        'apartment_no' => ['квартира_число', 'apt_no', 'apartment_no'],
        'building'    => ['корпус', 'дом', 'building', 'block'],
        'entrance'    => ['подъезд', 'entrance', 'paradnaya'],
        'floor'       => ['этаж', 'floor'],
        'area'        => ['площадь', 'area', 'm2', 'м2'],
        'persons'     => ['проживает', 'persons', 'жильцов', 'count_persons'],
        'share'       => ['доля', 'share', 'weight'],
        'role_in_home' => ['статус', 'role', 'role_in_home', 'кто по статусу'],
        'phone'       => ['телефон', 'phone', 'тел', 'mobile', 'мобильный'],
        'phones'      => ['телефоны', 'phones', 'все телефоны'],
        'email'       => ['почта', 'email', 'e-mail', 'mail', 'эл. почта'],
        'emails'      => ['почты', 'emails', 'все почты'],
        'telegram'    => ['телеграм', 'telegram', 'tg'],
        'car_plate'   => ['номер машины', 'госномер', 'plate', 'car_plate', 'авто'],
        'car_brand'   => ['марка', 'brand', 'car_brand', 'марка машины'],
        'car_model'   => ['модель', 'model', 'car_model'],
        'cars'        => ['машины', 'cars', 'автомобили', 'все машины', 'все авто', 'авто', 'транспорт'],
        'access_granted' => ['доступ', 'доступ разрешен', 'доступ разрешён', 'access', 'access_granted', 'шлагбаум'],
        'is_active'   => ['активен', 'включен', 'active', 'is_active', 'статус'],
        'is_billed'   => ['участвует в оплате', 'billed', 'is_billed'],
        'allow_guest_pass' => ['гостевой доступ', 'allow_guest_pass', 'guest'],
        'notify_enabled' => ['уведомления', 'notify', 'notify_enabled'],
        'tags'        => ['метки', 'теги', 'tags'],
        'notes'       => ['комментарий', 'примечание', 'notes', 'comment'],
        'balance'     => ['баланс', 'balance', 'долг'],
        'external_id' => ['внешний id', 'external_id', 'id_1c', 'код'],
        'last_payment_at' => ['последняя оплата', 'last_payment_at'],

        // Банковская выписка (importPayments).
        'amount'      => ['сумма', 'amount', 'sum', 'платёж', 'платеж', 'оплата', 'сумма платежа'],
        'paid_at'     => ['дата', 'date', 'paid_at', 'дата оплаты', 'дата платежа', 'period'],
        'reference'   => ['номер платежа', 'reference', '№ платежа', 'нп', 'назначение', 'назначение платежа', 'doc'],
        'payer_name'  => ['плательщик', 'payer', 'payer_name', 'фио плательщика', 'отправитель'],
        'method'      => ['способ', 'method', 'способ оплаты', 'тип платежа'],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly ResidentRepository $residents,
        private readonly ResidentService $residentService,
        private readonly BillingService $billing,
        private readonly AuditService $audit,
    ) {
    }

    /* ═══════════════════════════ export ═══════════════════════════ */

    /**
     * @param array<string, mixed> $filters  same filter set as the residents table
     * @param array<string, mixed> $options  format, include (payments|invoices|cars|contacts), only_active
     * @return array{filename: string, path: string, bytes: int, rows: int, format: string}
     */
    public function exportResidents(array $filters = [], array $options = []): array
    {
        $format = strtolower((string) ($options['format'] ?? 'csv'));
        $rows = $this->collectRows($filters, $options);

        $stamp = $this->clock->format('Ymd-His');
        $filename = match ($format) {
            'json' => sprintf('residents-%s.json', $stamp),
            'txt'  => sprintf('residents-%s.txt', $stamp),
            default => sprintf('residents-%s.csv', $stamp),
        };
        $path = $this->config->getPath('exports') . '/' . $filename;

        $content = match ($format) {
            'json' => $this->toJson($rows, $options),
            'txt'  => $this->toText($rows),
            default => $this->toCsv($rows),
        };

        \BasIp\Support\Fs::ensureDir(dirname($path));
        $bytes = (int) file_put_contents($path, $content);

        $jobId = $this->db->table('export_jobs')->insertGetId([
            'uuid' => Crypto::uuidv4(), 'kind' => 'residents', 'format' => $format,
            'filename' => $filename, 'rows' => count($rows), 'size_bytes' => $bytes,
            'filters' => (string) json_encode($filters, JSON_UNESCAPED_UNICODE),
            'created_at' => $this->clock->format(),
            'expires_at' => $this->clock->format('Y-m-d H:i:s', $this->clock->now() + 86400),
        ]);

        $this->audit->record('data.export', null, [
            'subject_type' => 'export_job', 'subject_id' => (string) $jobId, 'subject_label' => $filename,
            'meta' => ['rows' => count($rows), 'format' => $format, 'filters' => $filters],
            'is_security' => true,
        ]);

        return ['filename' => $filename, 'path' => $path, 'bytes' => $bytes, 'rows' => count($rows), 'format' => $format, 'job_id' => $jobId];
    }

    /** @param array<string, mixed> $filters @param array<string, mixed> $options @return array<int, array<string, mixed>> */
    private function collectRows(array $filters, array $options): array
    {
        $include = (array) ($options['include'] ?? ['contacts', 'cars', 'payments']);
        $all = ($options['scope'] ?? 'filtered') === 'all';

        $query = $all
            ? $this->db->table('residents')->whereNull('deleted_at')
            : $this->residents->filtered($filters + ['status' => $filters['status'] ?? 'active']);

        $rows = $query->orderBy('residents.apartment_no')->orderBy('residents.last_name')->limit(
            $this->config->int('data.max_rows', 100000)
        )->get();

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $contacts = $cars = $payments = $invoices = [];

        if ($ids !== []) {
            if (in_array('contacts', $include, true)) {
                foreach ($this->db->table('contacts')->whereIn('resident_id', $ids)->get() as $row) {
                    $contacts[(int) $row['resident_id']][] = $row;
                }
            }
            if (in_array('cars', $include, true)) {
                foreach ($this->db->table('cars')->whereIn('resident_id', $ids)->get() as $row) {
                    $cars[(int) $row['resident_id']][] = $row;
                }
            }
            if (in_array('payments', $include, true)) {
                foreach ($this->db->table('payments')->whereIn('resident_id', $ids)->orderBy('paid_at')->get() as $row) {
                    $payments[(int) $row['resident_id']][] = $row;
                }
            }
            if (in_array('invoices', $include, true)) {
                foreach ($this->db->table('invoices')->whereIn('resident_id', $ids)->orderBy('due_date')->get() as $row) {
                    $invoices[(int) $row['resident_id']][] = $row;
                }
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $entry = $row;
            $entry['_contacts'] = $contacts[$id] ?? [];
            $entry['_cars']     = $cars[$id] ?? [];
            $entry['_payments'] = $payments[$id] ?? [];
            $entry['_invoices'] = $invoices[$id] ?? [];
            $out[] = $entry;
        }

        return $out;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function toCsv(array $rows): string
    {
        $delimiter = (string) $this->config->string('data.csv_delimiter', ';');
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Не удалось подготовить CSV.');
        }

        fputcsv($handle, $this->headerLabels(), $delimiter, '"', '\\');

        foreach ($rows as $row) {
            $line = [];
            foreach (self::COLUMNS as $column) {
                $line[] = match ($column) {
                    'phones'  => $this->joinValues($row['_contacts'] ?? [], 'phone'),
                    'emails'  => $this->joinValues($row['_contacts'] ?? [], 'email'),
                    'telegram' => $this->joinValues($row['_contacts'] ?? [], 'telegram'),
                    'cars'    => $this->joinCars($row['_cars'] ?? []),
                    'car_plate' => (string) ($row['_cars'][0]['plate'] ?? ''),
                    'car_brand' => (string) ($row['_cars'][0]['brand'] ?? ''),
                    'car_model' => (string) ($row['_cars'][0]['model'] ?? ''),
                    'tags'    => implode(', ', (array) Entity::decodeJson($row['tags'] ?? null, [])),
                    'balance' => number_format((float) ($row['balance'] ?? 0), 2, '.', ''),
                    'full_name' => (string) ($row['full_name'] ?? ''),
                    default   => is_scalar($row[$column] ?? null) ? (string) ($row[$column] ?? '') : '',
                };
            }
            fputcsv($handle, $line, $delimiter, '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return ($this->config->bool('data.csv_bom', true) ? "\xEF\xBB\xBF" : '') . $csv;
    }

    /** @return array<int, string> */
    private function headerLabels(): array
    {
        $labels = [
            'id' => 'ID', 'external_id' => 'Внешний ID', 'last_name' => 'Фамилия', 'first_name' => 'Имя',
            'patronymic' => 'Отчество', 'full_name' => 'ФИО', 'apartment' => 'Квартира', 'apartment_no' => 'Квартира (число)',
            'building' => 'Корпус', 'entrance' => 'Подъезд', 'floor' => 'Этаж', 'area' => 'Площадь',
            'persons' => 'Проживает', 'share' => 'Доля', 'role_in_home' => 'Статус',
            'phone' => 'Телефон', 'phones' => 'Все телефоны', 'email' => 'E-mail', 'emails' => 'Все e-mail',
            'telegram' => 'Telegram', 'car_plate' => 'Госномер', 'car_brand' => 'Марка', 'car_model' => 'Модель',
            'cars' => 'Все машины', 'access_granted' => 'Доступ', 'is_active' => 'Активен',
            'is_billed' => 'Участвует в оплате', 'allow_guest_pass' => 'Гостевой доступ', 'notify_enabled' => 'Уведомления',
            'tags' => 'Метки', 'notes' => 'Комментарий', 'balance' => 'Баланс',
            'last_payment_at' => 'Последняя оплата', 'created_at' => 'Создан',
        ];

        return array_map(static fn (string $c): string => $labels[$c] ?? $c, self::COLUMNS);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function toJson(array $rows, array $options): string
    {
        $out = [
            'meta' => [
                'app' => 'BAS-IP Gate Manager',
                'version' => $this->config->string('app.version', '2.0.0'),
                'exported_at' => $this->clock->format(DATE_ATOM),
                'rows' => count($rows),
                'options' => $options,
            ],
            'residents' => [],
        ];

        foreach ($rows as $row) {
            $entry = [];
            foreach (self::COLUMNS as $column) {
                if (array_key_exists($column, $row)) {
                    $entry[$column] = $row[$column];
                }
            }
            $entry['tags'] = Entity::decodeJson($row['tags'] ?? null, []);
            $entry['meta'] = Entity::decodeJson($row['meta'] ?? null, []);
            $entry['contacts'] = array_map(static fn (array $c): array => [
                'type' => $c['type'], 'value' => $c['value'], 'label' => $c['label'] ?? null,
                'is_primary' => (bool) $c['is_primary'], 'notify' => (bool) $c['notify'],
            ], $row['_contacts']);
            $entry['cars'] = array_map(static fn (array $c): array => [
                'plate' => $c['plate'], 'brand' => $c['brand'], 'model' => $c['model'], 'color' => $c['color'],
                'year' => $c['year'], 'type' => $c['type'], 'is_active' => (bool) $c['is_active'],
                'rfid_tag' => $c['rfid_tag'], 'valid_from' => $c['valid_from'], 'valid_until' => $c['valid_until'],
                'notes' => $c['notes'],
            ], $row['_cars']);
            $entry['payments'] = array_map(static fn (array $p): array => [
                'date' => $p['paid_at'], 'amount' => (float) $p['amount'], 'method' => $p['method'],
                'reference' => $p['reference'], 'note' => $p['note'],
            ], $row['_payments']);
            $entry['invoices'] = array_map(static fn (array $i): array => [
                'number' => $i['number'], 'period' => $i['period_label'], 'amount' => (float) $i['amount'],
                'paid' => (float) $i['paid_amount'], 'status' => $i['status'], 'due_date' => $i['due_date'],
                'kind' => $i['kind'],
            ], $row['_invoices']);

            $out['residents'][] = $entry;
        }

        // JSON_UNESCAPED_SLASHES keeps URLs readable; INVALID_UTF8_SUBSTITUTE
        // guarantees a usable file even if a legacy row holds broken bytes.
        $encoded = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($encoded === false) {
            throw new \RuntimeException('Не удалось сериализовать выгрузку в JSON: ' . json_last_error_msg());
        }

        return $encoded;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function toText(array $rows): string
    {
        $lines = [
            'РЕЕСТР ЖИТЕЛЕЙ — ' . $this->config->string('app.name', 'BAS-IP'),
            'Выгружено: ' . $this->clock->format('d.m.Y H:i'),
            'Записей: ' . count($rows),
            str_repeat('=', 100),
            '',
            sprintf('%-6s %-28s %-10s %-18s %-24s %-14s %s', 'ID', 'ФИО', 'Кв.', 'Телефон', 'E-mail', 'Авто', 'Статус'),
            str_repeat('-', 100),
        ];
        foreach ($rows as $row) {
            $cars = [];
            foreach ((array) ($row['_cars'] ?? []) as $car) {
                $cars[] = Plate::format((string) ($car['plate'] ?? '')) . ' ' . trim(($car['brand'] ?? '') . ' ' . ($car['model'] ?? ''));
            }
            $lines[] = sprintf(
                '%-6s %-28s %-10s %-18s %-24s %-14s %s',
                (string) $row['id'],
                Str::limit((string) ($row['full_name'] ?? ''), 28),
                (string) ($row['apartment'] ?? ''),
                Phone::format((string) ($row['phone'] ?? '')),
                Str::limit((string) ($row['email'] ?? ''), 24),
                Str::limit(implode(', ', $cars), 14),
                ($row['is_active'] ? 'активен' : 'отключен') . ($row['access_granted'] ? '' : ', без доступа')
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function joinValues(array $contacts, string $type): string
    {
        $out = [];
        foreach ($contacts as $contact) {
            if (($contact['type'] ?? '') === $type && !empty($contact['value'])) {
                $out[] = (string) $contact['value'];
            }
        }

        return implode(', ', $out);
    }

    /** @param array<int, array<string, mixed>> $cars */
    private function joinCars(array $cars): string
    {
        $out = [];
        foreach ($cars as $car) {
            $out[] = trim(implode(' ', array_filter([
                (string) ($car['plate'] ?? ''), (string) ($car['brand'] ?? ''), (string) ($car['model'] ?? ''),
            ])));
        }

        return implode(' | ', array_filter($out));
    }

    /* ═══════════════════════════ import ═══════════════════════════ */

    /**
     * Import residents from a CSV or JSON string.
     *
     * @param array<string, mixed> $options mode(upsert|insert|replace|dry-run), match_on, skip_errors, default_active
     * @return array{ok: bool, job_id: int, total: int, imported: int, updated: int, skipped: int,
     *               failed: int, errors: array<int, array{row: int, error: string}>, dry_run: bool,
     *               preview: array<int, array<string, mixed>>, error?: string}
     */
    public function importResidents(string $content, string $format, array $options = [], ?User $actor = null, ?Request $request = null): array
    {
        $mode = (string) ($options['mode'] ?? $this->config->string('data.import.mode', 'upsert'));
        $dryRun = $mode === 'dry-run' || !empty($options['dry_run']);
        $skipErrors = (bool) ($options['skip_errors'] ?? $this->config->bool('data.import.skip_errors', true));
        $matchOn = (array) ($options['match_on'] ?? $this->config->list('data.import.match_on') ?: ['apartment', 'full_name']);

        $jobId = $this->db->table('import_jobs')->insertGetId([
            'uuid' => Crypto::uuidv4(), 'kind' => 'residents', 'filename' => (string) ($options['filename'] ?? 'upload'),
            'format' => $format, 'mode' => $mode, 'status' => 'running',
            'created_by' => $actor?->id, 'started_at' => $this->clock->format(),
        ]);

        try {
            $records = $format === 'json' ? $this->parseJson($content) : $this->parseCsv($content, $options);
        } catch (Throwable $e) {
            $this->finishJob($jobId, ['status' => 'failed', 'errors' => [['row' => 0, 'error' => $e->getMessage()]]]);

            return ['ok' => false, 'error' => $e->getMessage(), 'job_id' => $jobId, 'total' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [['row' => 0, 'error' => $e->getMessage()]], 'dry_run' => $dryRun, 'preview' => []];
        }

        $total = count($records);
        $imported = $updated = $skipped = $failed = 0;
        $errors = [];
        $preview = [];

        foreach ($records as $index => $record) {
            $rowNumber = $index + 2;
            try {
                $normalized = $this->normalizeRecord($record);
                if ($normalized === null) {
                    $skipped++;
                    $errors[] = ['row' => $rowNumber, 'error' => 'Пустая строка или нет ни ФИО, ни номера квартиры.'];
                    continue;
                }

                $existingId = $this->matchExisting($normalized, $matchOn);
                $action = $existingId === null ? 'create' : 'update';

                if ($dryRun) {
                    $preview[] = ['row' => $rowNumber, 'action' => $action, 'id' => $existingId] + $normalized['summary'];
                    $action === 'create' ? $imported++ : $updated++;
                    continue;
                }

                if ($existingId === null) {
                    if ($mode === 'replace' || $mode === 'insert' || $mode === 'upsert') {
                        $result = $this->residentService->create($normalized['data'] + [
                            'is_active' => $normalized['data']['is_active'] ?? (bool) ($options['default_active'] ?? true),
                        ], $actor, $request);
                        if (!$result['ok']) {
                            throw new \RuntimeException(implode(' ', $result['errors']['_'] ?? array_merge(...array_values($result['errors'] ?? [['Неизвестная ошибка']]))));
                        }
                        $imported++;
                    } else {
                        $skipped++;
                    }
                } else {
                    if ($mode === 'insert') {
                        $skipped++;
                        continue;
                    }
                    $result = $this->residentService->update((int) $existingId, $normalized['data'], $actor, $request);
                    if (!$result['ok']) {
                        throw new \RuntimeException(implode(' ', $result['errors']['_'] ?? array_merge(...array_values($result['errors'] ?? [['Неизвестная ошибка']]))));
                    }
                    $updated++;
                }
            } catch (Throwable $e) {
                $failed++;
                $errors[] = ['row' => $rowNumber, 'error' => $e->getMessage()];
                if (!$skipErrors) {
                    break;
                }
            }

            if (count($errors) > 200) {
                break;     // keep the report usable
            }
        }

        if (!$dryRun && $imported + $updated > 0) {
            $this->residentService->ensureSearchIndexes();
        }

        $this->finishJob($jobId, [
            'status' => $failed > 0 && $imported + $updated === 0 ? 'failed' : 'done',
            'rows_total' => $total, 'rows_imported' => $imported, 'rows_updated' => $updated,
            'rows_skipped' => $skipped, 'rows_failed' => $failed, 'errors' => $errors,
        ]);

        $this->audit->record('data.import', $actor, [
            'subject_type' => 'import_job', 'subject_id' => (string) $jobId,
            'subject_label' => (string) ($options['filename'] ?? 'upload'),
            'meta' => ['format' => $format, 'mode' => $mode, 'total' => $total, 'imported' => $imported, 'updated' => $updated, 'failed' => $failed, 'dry_run' => $dryRun],
            'is_security' => true,
        ], $request);

        return [
            'ok' => true, 'job_id' => $jobId, 'total' => $total, 'imported' => $imported,
            'updated' => $updated, 'skipped' => $skipped, 'failed' => $failed,
            'errors' => $errors, 'dry_run' => $dryRun, 'preview' => $preview, 'mode' => $mode,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function parseCsv(string $content, array $options): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $delimiter = (string) ($options['delimiter'] ?? $this->config->string('data.csv_delimiter', ';'));
        if ($delimiter === 'auto') {
            $firstLine = (string) (explode("\n", $content)[0] ?? '');
            $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        }
        $enclosure = (string) ($options['enclosure'] ?? $this->config->string('data.csv_enclosure', '"'));

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Не удалось прочитать CSV.');
        }
        fwrite($handle, $content);
        rewind($handle);

        $header = fgetcsv($handle, 0, $delimiter, $enclosure, '\\');
        if (!is_array($header) || $header === [null]) {
            fclose($handle);
            throw new \RuntimeException('CSV пуст или отсутствует строка заголовков.');
        }
        $map = $this->mapHeader($header);

        $rows = [];
        $limit = $this->config->int('data.max_rows', 100000);
        while (($line = fgetcsv($handle, 0, $delimiter, $enclosure, '\\')) !== false) {
            if (count($rows) >= $limit) {
                break;
            }
            if ($line === [null] || $line === []) {
                continue;
            }
            $row = [];
            foreach ($map as $index => $column) {
                if ($column !== null) {
                    $row[$column] = isset($line[$index]) ? trim((string) $line[$index]) : '';
                }
            }
            if (array_filter($row, static fn ($v) => $v !== '') === []) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        if ($rows === []) {
            throw new \RuntimeException('В файле нет данных.');
        }

        return $rows;
    }

    /** Map a header row onto canonical column names. @return array<int, ?string> */
    public function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $index => $raw) {
            $name = Str::lower(Str::squash((string) $raw));
            $name = trim($name, "\"' \t\n\r\0\x0B﻿");
            $column = null;

            if (in_array($name, self::COLUMNS, true)) {
                $column = $name;
            } else {
                foreach (self::ALIASES as $canonical => $aliases) {
                    if (in_array($name, $aliases, true)) {
                        $column = $canonical;
                        break;
                    }
                }
            }
            $map[(int) $index] = $column;
        }

        return $map;
    }

    /** @return array{columns: array<int, string>, sample: array<int, array<string, mixed>>} */
    public function inspectCsv(string $content, string $delimiter = 'auto'): array
    {
        $first = (string) (explode("\n", $content)[0] ?? '');
        if ($delimiter === 'auto') {
            $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        }
        $header = str_getcsv($first, $delimiter, '"', '\\');
        $map = $this->mapHeader($header);

        $columns = [];
        foreach ($header as $i => $name) {
            $columns[] = ['raw' => (string) $name, 'mapped' => $map[$i] ?? null];
        }

        $lines = array_slice(preg_split('/\r\n|\r|\n/', $content) ?: [], 1, 4);
        $sample = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, $delimiter, '"', '\\');
            $row = [];
            foreach ($map as $i => $column) {
                if ($column !== null) {
                    $row[$column] = $values[$i] ?? '';
                }
            }
            $sample[] = $row;
        }

        return ['columns' => $columns, 'sample' => $sample, 'delimiter' => $delimiter];
    }

    /** @return array<int, array<string, mixed>> */
    private function parseJson(string $content): array
    {
        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Некорректный JSON: ' . json_last_error_msg());
        }
        $list = $data['residents'] ?? $data['data'] ?? $data;
        if (!is_array($list)) {
            throw new \RuntimeException('В JSON нет массива жителей.');
        }
        // Accept both our own export shape and a flat list.
        if (isset($list[0]) && is_array($list[0])) {
            return $list;
        }

        throw new \RuntimeException('Не распознана структура JSON.');
    }

    /**
     * @param array<string, mixed> $record
     * @return array{data: array<string, mixed>, summary: array<string, mixed>}|null
     */
    private function normalizeRecord(array $record): ?array
    {
        $lastName  = Str::squash((string) ($record['last_name'] ?? ''));
        $firstName = Str::squash((string) ($record['first_name'] ?? ''));
        $patronymic = Str::squash((string) ($record['patronymic'] ?? ''));
        $fullName  = Str::squash((string) ($record['full_name'] ?? ''));
        $apartment = Str::squash((string) ($record['apartment'] ?? ''));

        if ($fullName === '' && $lastName === '' && $apartment === '') {
            return null;
        }

        // Split a full name into parts when the spreadsheet only had one column.
        if ($fullName !== '' && $lastName === '' && $firstName === '') {
            $parts = preg_split('/\s+/u', $fullName) ?: [];
            $lastName   = $parts[0] ?? '';
            $firstName  = $parts[1] ?? '';
            $patronymic = $parts[2] ?? '';
        }
        if ($fullName === '') {
            $fullName = Str::squash(trim($lastName . ' ' . $firstName . ' ' . $patronymic));
        }

        $contacts = [];
        $phones = array_filter(array_map('trim', preg_split('/[,;|\n]+/', (string) ($record['phones'] ?? '')) ?: []));
        foreach ($phones as $phone) {
            $contacts[] = ['type' => 'phone', 'value' => $phone, 'is_primary' => false, 'notify' => true];
        }
        if (!empty($record['phone'])) {
            array_unshift($contacts, ['type' => 'phone', 'value' => (string) $record['phone'], 'is_primary' => true, 'notify' => true]);
        }
        $emails = array_filter(array_map('trim', preg_split('/[,;|\n]+/', (string) ($record['emails'] ?? '')) ?: []));
        foreach ($emails as $email) {
            $contacts[] = ['type' => 'email', 'value' => $email, 'is_primary' => false, 'notify' => true];
        }
        if (!empty($record['email'])) {
            array_unshift($contacts, ['type' => 'email', 'value' => (string) $record['email'], 'is_primary' => true, 'notify' => true]);
        }
        if (!empty($record['telegram'])) {
            foreach (array_filter(array_map('trim', preg_split('/[,;|\n]+/', (string) $record['telegram']) ?: [])) as $tg) {
                $contacts[] = ['type' => 'telegram', 'value' => $tg, 'is_primary' => false, 'notify' => true];
            }
        }

        $cars = [];
        foreach ((array) ($record['cars_raw'] ?? $record['cars'] ?? []) as $car) {
            if (is_array($car)) {
                $cars[] = $car;
                continue;
            }
            $car = trim((string) $car);
            if ($car === '') {
                continue;
            }
            foreach (explode('|', $car) as $piece) {
                $piece = Str::squash($piece);
                if ($piece === '') {
                    continue;
                }
                $parts = preg_split('/\s+/', $piece, 3) ?: [];
                $cars[] = [
                    'plate' => Plate::isValid($parts[0] ?? '') ? (string) $parts[0] : $piece,
                    'brand' => Plate::isValid($parts[0] ?? '') ? ($parts[1] ?? null) : null,
                    'model' => Plate::isValid($parts[0] ?? '') ? ($parts[2] ?? null) : null,
                    'is_active' => true,
                ];
            }
        }
        if ($cars === [] && (!empty($record['car_plate']) || !empty($record['car_brand']))) {
            $cars[] = [
                'plate' => (string) ($record['car_plate'] ?? ''),
                'brand' => (string) ($record['car_brand'] ?? ''),
                'model' => (string) ($record['car_model'] ?? ''),
                'is_active' => true,
            ];
        }

        $data = [
            'last_name' => $lastName, 'first_name' => $firstName, 'patronymic' => $patronymic,
            'full_name' => $fullName, 'apartment' => $apartment,
            'apartment_no' => $record['apartment_no'] ?? null,
            'building' => $record['building'] ?? null, 'entrance' => $record['entrance'] ?? null,
            'floor' => $record['floor'] ?? null,
            'area' => $record['area'] ?? 0, 'persons' => $record['persons'] ?? 1, 'share' => $record['share'] ?? 1,
            'role_in_home' => $this->normalizeRole((string) ($record['role_in_home'] ?? 'owner')),
            'phone' => !empty($record['phone']) ? Phone::normalize((string) $record['phone']) : null,
            'email' => !empty($record['email']) ? mb_strtolower(trim((string) $record['email'])) : null,
            'notes' => $record['notes'] ?? null,
            'tags' => isset($record['tags']) ? $record['tags'] : null,
            'external_id' => $record['external_id'] ?? null,
            'access_granted' => $this->boolFromCell($record['access_granted'] ?? true, true),
            'is_active' => $this->boolFromCell($record['is_active'] ?? true, true),
            'is_billed' => $this->boolFromCell($record['is_billed'] ?? true, true),
            'allow_guest_pass' => $this->boolFromCell($record['allow_guest_pass'] ?? true, true),
            'notify_enabled' => $this->boolFromCell($record['notify_enabled'] ?? true, true),
            'contacts' => $contacts,
            'cars' => $cars,
        ];

        return [
            'data' => $data,
            'summary' => [
                'full_name' => $fullName, 'apartment' => $apartment,
                'phones' => count($contacts) > 0 ? implode(', ', array_column(array_filter($contacts, static fn ($c) => $c['type'] === 'phone'), 'value')) : '',
                'cars' => count($cars) > 0 ? implode(', ', array_column($cars, 'plate')) : '',
                'active' => (bool) $data['is_active'],
            ],
        ];
    }

    private function normalizeRole(string $value): string
    {
        $value = Str::lower(trim($value));

        return match (true) {
            in_array($value, ['owner', 'собственник', 'владелец'], true) => 'owner',
            in_array($value, ['tenant', 'арендатор', 'квартирант'], true) => 'tenant',
            in_array($value, ['relative', 'родственник'], true) => 'relative',
            in_array($value, ['manager', 'управляющий', 'ук'], true) => 'manager',
            default => 'owner',
        };
    }

    private function boolFromCell(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        $normalized = Str::lower(trim((string) $value));

        return match (true) {
            in_array($normalized, ['1', 'true', 'yes', 'on', 'да', 'включен', 'включён', 'активен', 'активный', '+', 'y'], true) => true,
            in_array($normalized, ['0', 'false', 'no', 'off', 'нет', 'выключен', 'неактивен', '-', 'n'], true) => false,
            default => $default,
        };
    }

    /** @param array<string, mixed> $normalized @param array<int, string> $matchOn */
    private function matchExisting(array $normalized, array $matchOn): ?int
    {
        if (!empty($normalized['external_id'])) {
            $id = $this->db->table('residents')->where('external_id', '=', (string) $normalized['external_id'])->value('id');
            if ($id !== null) {
                return (int) $id;
            }
        }

        $q = $this->db->table('residents')->whereNull('deleted_at');
        foreach ($matchOn as $field) {
            $value = $normalized[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($field === 'full_name') {
                $q = $q->where('full_name', '=', Str::squash((string) $value));
            } elseif ($field === 'apartment') {
                $q = $q->where('apartment', '=', Str::squash((string) $value));
            } elseif ($field === 'phone') {
                $q = $q->where('phone', '=', Phone::normalize((string) $value));
            } elseif ($field === 'email') {
                $q = $q->where('email', '=', mb_strtolower((string) $value));
            }
        }
        $id = $q->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @param array<string, mixed> $update */
    private function finishJob(int $jobId, array $update): void
    {
        $row = [
            'finished_at' => $this->clock->format(),
        ];
        foreach (['status', 'rows_total', 'rows_imported', 'rows_updated', 'rows_skipped', 'rows_failed'] as $key) {
            if (array_key_exists($key, $update)) {
                $column = $key === 'rows_updated' ? 'rows_imported' : $key;
                if ($key === 'rows_updated') {
                    $row['rows_imported'] = (int) ($update['rows_imported'] ?? 0) + (int) $update['rows_updated'];
                    continue;
                }
                $row[$column] = is_string($update[$key]) ? $update[$key] : (int) $update[$key];
            }
        }
        if (isset($update['errors'])) {
            $row['errors'] = (string) json_encode(array_slice((array) $update['errors'], 0, 200), JSON_UNESCAPED_UNICODE);
        }
        $this->db->table('import_jobs')->where('id', '=', $jobId)->update($row);
    }

    /** @return array<int, array<string, mixed>> */
    public function jobs(int $limit = 25): array
    {
        return $this->db->table('import_jobs')->orderBy('id', 'desc')->limit($limit)->get();
    }

    /** @return array<int, array<string, mixed>> */
    public function exports(int $limit = 25): array
    {
        return $this->db->table('export_jobs')->orderBy('id', 'desc')->limit($limit)->get();
    }

    /**
     * Import payments from a bank statement CSV.
     *
     * Expected columns (aliases accepted): apartment/квартира, amount/сумма,
     * date/дата, reference/назначение, payer/плательщик.
     *
     * @return array{ok: bool, total: int, imported: int, failed: int, errors: array<int, array<string, mixed>>}
     */
    public function importPayments(string $content, array $options = [], ?User $actor = null): array
    {
        $rows = $this->parseCsv($content, $options);
        $imported = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            try {
                $apartment = Str::squash((string) ($row['apartment'] ?? $row['apartment_no'] ?? ''));
                $amount = Money::round((float) str_replace(',', '.', (string) ($row['amount'] ?? $row['balance'] ?? 0)));
                if ($apartment === '' || $amount == 0.0) {
                    throw new \RuntimeException('Нет квартиры или суммы.');
                }
                $resident = $this->db->table('residents')
                    ->where('apartment', '=', $apartment)
                    ->whereNull('deleted_at')
                    ->orderBy('is_active', 'desc')
                    ->first();
                if ($resident === null) {
                    throw new \RuntimeException('Житель с квартирой «' . $apartment . '» не найден.');
                }
                if (!empty($options['dry_run'])) {
                    $imported++;
                    continue;
                }
                $result = $this->billing->recordPayment([
                    'resident_id' => (int) $resident['id'],
                    'amount' => $amount,
                    'paid_at' => $row['paid_at'] ?? $row['last_payment_at'] ?? $this->clock->format('Y-m-d'),
                    'method' => $row['method'] ?? 'transfer',
                    'reference' => $row['reference'] ?? $row['notes'] ?? null,
                    'payer_name' => $row['payer_name'] ?? $resident['full_name'] ?? null,
                    'note' => 'Импорт из банковской выписки',
                ], $actor);
                if (!$result['ok']) {
                    throw new \RuntimeException((string) ($result['error'] ?? 'неизвестная ошибка'));
                }
                $imported++;
            } catch (Throwable $e) {
                $failed++;
                if (count($errors) < 200) {
                    $errors[] = ['row' => $rowNumber, 'error' => $e->getMessage()];
                }
            }
        }

        return ['ok' => $failed === 0, 'total' => count($rows), 'imported' => $imported, 'failed' => $failed, 'errors' => $errors];
    }

    /** Template CSV so users can see the expected shape. */
    public function sampleCsv(): string
    {
        $delimiter = (string) $this->config->string('data.csv_delimiter', ';');
        $lines = [implode($delimiter, $this->headerLabels())];
        $lines[] = implode($delimiter, [
            '', 'EXT-001', 'Иванов', 'Иван', 'Иванович', 'Иванов Иван Иванович',
            '12', '12', '1', '2', '5', '54.30', '2', '1', 'owner',
            '+79161234567', '+79161234567, +79031112233', 'ivanov@example.com', 'ivanov@example.com',
            '@ivanov', 'А123ВС77', 'Skoda', 'Octavia', 'А123ВС77 Skoda Octavia | В987УК77 Lada Vesta',
            'да', 'да', 'да', 'да', 'да', 'староста подъезда', 'Два парковочных места',
            '0.00', '', '',
        ]);
        $lines[] = implode($delimiter, [
            '', 'EXT-002', 'Петрова', 'Мария', '', 'Петрова Мария',
            '14', '14', '1', '2', '6', '38.00', '1', '1', 'tenant',
            '89031234567', '', 'petrova@example.com', '', '',
            '', '', '', '', 'да', 'да', 'да', 'да', 'да', '', '', '0.00', '', '',
        ]);

        return ($this->config->bool('data.csv_bom', true) ? "\xEF\xBB\xBF" : '') . implode("\r\n", $lines) . "\r\n";
    }
}
