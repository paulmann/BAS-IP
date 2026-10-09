<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers\Admin;

use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Http\Controller;
use BasIp\Services\BackupService;
use BasIp\Services\ImportExportService;
use BasIp\Support\Str;

/**
 * Data hub: export, import (with dry-run preview), backups and restore.
 */
final class DataController extends Controller
{
    private function io(): ImportExportService
    {
        return $this->svc(ImportExportService::class);
    }

    private function backups(): BackupService
    {
        return $this->svc(BackupService::class);
    }

    public function index(Request $request): Response
    {
        $this->requirePermission('data.export');

        return $this->view('admin/data/index', [
            'title'    => 'Данные: выгрузка, загрузка, резервные копии',
            'jobs'     => $this->io()->jobs(15),
            'exports'  => $this->io()->exports(15),
            'backups'  => $this->backups()->list(),
            'backupSize' => Str::bytes($this->backups()->totalSize()),
            'lastBackup' => $this->backups()->lastBackupAt(),
            'columns'  => ImportExportService::COLUMNS,
            'counts'   => [
                'residents' => $this->residentRepo()->countAll(),
                'active'    => $this->residentRepo()->countActive(),
                'cars'      => (int) $this->db()->table('cars')->count(),
                'contacts'  => (int) $this->db()->table('contacts')->count(),
                'invoices'  => (int) $this->db()->table('invoices')->count(),
                'payments'  => (int) $this->db()->table('payments')->count(),
                'events'    => (int) $this->db()->table('gate_events')->count(),
            ],
            'dbSize'   => Str::bytes($this->db()->sizeBytes()),
            'formats'  => ['csv' => 'CSV (Excel)', 'json' => 'JSON (полный)', 'txt' => 'Текстовый реестр'],
            'modes'    => ['dry-run' => 'Только проверка (ничего не менять)', 'upsert' => 'Создать или обновить', 'insert' => 'Только создать новые', 'replace' => 'Заменить найденные'],
            'canImport' => $this->requireUser()->can('data.import'),
            'canBackup' => $this->requireUser()->can('backup.create'),
            'canRestore' => $this->requireUser()->can('backup.restore'),
            'uploadMax' => Str::bytes($this->config()->int('security.upload.max_bytes', 10485760)),
            'phpUploadMax' => ini_get('upload_max_filesize'),
            'phpPostMax' => ini_get('post_max_size'),
        ]);
    }

    /** Analyse an uploaded file before importing: detected columns + 4 sample rows. */
    public function inspect(Request $request): Response
    {
        $this->requirePermission('data.import');
        $content = $this->uploadedContent($request, 'file', ['csv', 'txt', 'json']);
        $format = strtolower(pathinfo((string) ($request->file('file')?->originalName ?? 'file.csv'), PATHINFO_EXTENSION));

        if ($format === 'json') {
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                return $this->jsonError('Некорректный JSON: ' . json_last_error_msg(), 422);
            }
            $list = $decoded['residents'] ?? $decoded;

            return $this->json([
                'ok' => true, 'format' => 'json',
                'rows' => is_array($list) ? count($list) : 0,
                'columns' => is_array($list) && isset($list[0]) && is_array($list[0])
                    ? array_map(static fn ($k): array => ['raw' => $k, 'mapped' => $k], array_keys($list[0]))
                    : [],
                'sample' => is_array($list) ? array_slice($list, 0, 4) : [],
            ]);
        }

        $info = $this->io()->inspectCsv($content, $request->str('delimiter', 'auto'));

        return $this->json([
            'ok' => true, 'format' => 'csv', 'delimiter' => $info['delimiter'],
            'columns' => $info['columns'],
            'sample' => $info['sample'],
            'mapped' => count(array_filter($info['columns'], static fn (array $c): bool => $c['mapped'] !== null)),
            'unmapped' => array_values(array_filter(array_map(static fn (array $c): ?string => $c['mapped'] === null ? $c['raw'] : null, $info['columns']))),
        ]);
    }

    public function import(Request $request): Response
    {
        $user = $this->requirePermission('data.import');
        $file = $request->file('file');
        if ($file === null) {
            return $this->respond($request, false, 'Выберите файл для загрузки.', '/admin/data');
        }
        $extension = $file->extension();
        if (!in_array($extension, ['csv', 'json', 'txt'], true)) {
            return $this->respond($request, false, 'Поддерживаются только CSV, TXT и JSON.', '/admin/data');
        }

        $content = $this->uploadedContent($request, 'file', ['csv', 'json', 'txt']);
        $format = $extension === 'json' ? 'json' : 'csv';

        $result = $this->io()->importResidents($content, $format, [
            'mode'          => $request->str('mode', 'dry-run'),
            'match_on'      => $request->array('match_on') ?: ['apartment', 'full_name'],
            'delimiter'     => $request->str('delimiter', 'auto'),
            'skip_errors'   => $request->bool('skip_errors', true),
            'default_active' => $request->bool('default_active', true),
            'filename'      => $file->originalName,
        ], $user, $request);

        if (!$result['ok']) {
            return $this->respond($request, false, (string) ($result['error'] ?? 'Ошибка импорта.'), '/admin/data');
        }

        $this->session()->set('import_result', $result);

        if ($request->expectsJson()) {
            return $this->json($result);
        }

        $summary = sprintf(
            'Импорт (%s): всего %d, создано %d, обновлено %d, пропущено %d, ошибок %d.',
            $result['dry_run'] ? 'проверка' : (string) $result['mode'],
            $result['total'], $result['imported'], $result['updated'], $result['skipped'], $result['failed']
        );
        $this->flashSuccess($summary);

        return $this->redirect('/admin/data?tab=import&job=' . $result['job_id']);
    }

    public function importPayments(Request $request): Response
    {
        $user = $this->requirePermission('data.import');
        $content = $this->uploadedContent($request, 'file', ['csv', 'txt']);

        $result = $this->io()->importPayments($content, [
            'delimiter' => $request->str('delimiter', 'auto'),
            'dry_run'   => $request->bool('dry_run'),
        ], $user);

        $this->auditService()->info('data.import.payments', $user, 'import_job', null, null, $request, $result);

        return $this->respond($request, (bool) $result['ok'], sprintf(
            'Оплаты: всего %d, проведено %d, ошибок %d.', $result['total'], $result['imported'], $result['failed']
        ), '/admin/data?tab=import');
    }

    public function export(Request $request, string $kind = 'residents'): Response
    {
        $this->requirePermission('data.export');
        $format = $request->str('format', 'csv');

        return match ($kind) {
            'residents' => $this->exportResidents($request, $format),
            'payments'  => $this->exportPayments($request, $format),
            'invoices'  => $this->exportInvoices($request, $format),
            'gate'      => $this->redirect('/admin/gate/export' . $this->queryString($request)),
            'guest'     => $this->redirect('/admin/guest/export' . $this->queryString($request)),
            'full'      => $this->exportFull($request),
            default     => $this->jsonError('Неизвестный тип выгрузки: ' . $kind, 404),
        };
    }

    private function exportResidents(Request $request, string $format): Response
    {
        $filters = $this->filters($request, ['q', 'status', 'building', 'apartment_from', 'apartment_to', 'tag']);
        $filters['status'] = $request->str('status', 'active');

        $result = $this->io()->exportResidents($filters, [
            'format'  => $format,
            'include' => ['contacts', 'cars', 'payments', 'invoices'],
            'scope'   => $request->str('scope', 'filtered'),
        ]);
        $this->auditService()->info('data.export.residents', $this->requireUser(), 'export_job', (string) $result['job_id'], $result['filename'], $request, ['rows' => $result['rows']]);

        return Response::file($result['path'], $result['filename']);
    }

    private function exportPayments(Request $request, string $format): Response
    {
        $paginator = $this->svc(\BasIp\Services\BillingService::class)->payments(
            $this->filters($request, ['q', 'method', 'from', 'to', 'only_active']), 10000, 1
        );
        $rows = [['Дата', 'Житель', 'Квартира', 'Сумма', 'Способ', 'Счёт', 'Период', '№ платежа', 'Примечание']];
        foreach ($paginator->items() as $payment) {
            $rows[] = [
                $payment->dateLabel(), (string) ($payment->residentName ?? ''), (string) ($payment->apartment ?? ''),
                number_format($payment->amount, 2, '.', ''), $payment->methodLabel(),
                (string) ($payment->invoiceNumber ?? ''), (string) ($payment->periodLabel ?? ''),
                (string) ($payment->reference ?? ''), (string) ($payment->note ?? ''),
            ];
        }

        return $this->csvResponse($rows, sprintf('payments-%s.csv', date('Ymd-His')), 'data.export.payments');
    }

    private function exportInvoices(Request $request, string $format): Response
    {
        $paginator = $this->svc(\BasIp\Services\BillingService::class)->invoices(
            $this->filters($request, ['q', 'status', 'kind', 'charge_id', 'from', 'to', 'overdue', 'only_active']), 10000, 1
        );
        $rows = [['Счёт', 'Житель', 'Квартира', 'Период', 'Начислено', 'Оплачено', 'Остаток', 'Статус', 'Срок', 'Тип', 'Начисление']];
        foreach ($paginator->items() as $invoice) {
            $rows[] = [
                $invoice->number, (string) ($invoice->residentName ?? ''), (string) ($invoice->apartment ?? ''),
                $invoice->periodLabel, number_format($invoice->amount, 2, '.', ''),
                number_format($invoice->paidAmount, 2, '.', ''), number_format($invoice->balance, 2, '.', ''),
                $invoice->statusLabel(), (string) ($invoice->dueDate ?? ''), $invoice->kindLabel(),
                (string) ($invoice->chargeNumber ?? ''),
            ];
        }

        return $this->csvResponse($rows, sprintf('invoices-%s.csv', date('Ymd-His')), 'data.export.invoices');
    }

    private function exportFull(Request $request): Response
    {
        $result = $this->backups()->create('full', 'Полная выгрузка из интерфейса', $this->requireUser(), $request);
        if (!$result['ok']) {
            return $this->respond($request, false, (string) ($result['error'] ?? 'Ошибка'), '/admin/data');
        }

        return Response::file((string) $result['path'], (string) $result['file']);
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function csvResponse(array $rows, string $filename, string $auditAction): Response
    {
        $delimiter = (string) $this->config()->string('data.csv_delimiter', ';');
        $csv = $this->config()->bool('data.csv_bom', true) ? "\xEF\xBB\xBF" : '';
        foreach ($rows as $row) {
            $csv .= implode($delimiter, array_map(static function ($v) use ($delimiter): string {
                $v = (string) $v;

                return str_contains($v, $delimiter) || str_contains($v, '"') || str_contains($v, "\n")
                    ? '"' . str_replace('"', '""', $v) . '"' : $v;
            }, $row)) . "\r\n";
        }
        $this->auditService()->info($auditAction, $this->requireUser(), 'export', null, $filename, $this->request(), ['rows' => count($rows)]);

        return Response::csv($csv, $filename);
    }

    public function sample(Request $request): Response
    {
        $this->requireUser();

        return Response::csv($this->io()->sampleCsv(), 'bas-ip-sample-residents.csv');
    }

    /* ─────────────────────────── backups ─────────────────────────── */

    public function backup(Request $request): Response
    {
        $user = $this->requirePermission('backup.create');
        $result = $this->backups()->create(
            $request->str('type', 'db') === 'full' ? 'full' : 'db',
            $request->str('note', ''),
            $user,
            $request
        );

        if (!$result['ok']) {
            return $this->respond($request, false, (string) ($result['error'] ?? 'Ошибка резервного копирования.'), '/admin/data?tab=backups');
        }

        return $this->respond($request, true, sprintf(
            'Резервная копия создана: %s (%s, %s).',
            $result['file'], Str::bytes((int) $result['bytes']), (string) ($result['method'] ?? 'php')
        ), '/admin/data?tab=backups');
    }

    public function downloadBackup(Request $request, string $id = ''): Response
    {
        $this->requirePermission('backup.view');
        $path = $this->backups()->streamPath((int) $id);
        if ($path === null) {
            $this->notFound('Файл резервной копии не найден.');
        }
        $row = $this->backups()->find((int) $id);
        $this->auditService()->security('backup.download', $this->requireUser(), ['subject_type' => 'backup', 'subject_id' => $id, 'subject_label' => (string) ($row['filename'] ?? '')], $request);

        return Response::file((string) $path, (string) ($row['filename'] ?? basename((string) $path)));
    }

    public function restore(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('backup.restore');
        $confirm = $request->str('confirm');
        if ($confirm !== 'RESTORE') {
            return $this->respond($request, false, 'Для подтверждения введите слово RESTORE.', '/admin/data?tab=backups');
        }

        $result = $this->backups()->restore((int) $id, 'restore:' . (int) $id, $user, $request);
        if (!$result['ok']) {
            return $this->respond($request, false, (string) ($result['error'] ?? 'Ошибка восстановления.'), '/admin/data?tab=backups');
        }

        return $this->respond($request, true, sprintf('Восстановление завершено (выполнено операторов: %d).', (int) $result['statements']), '/admin/data?tab=backups');
    }

    public function deleteBackup(Request $request, string $id = ''): Response
    {
        $user = $this->requirePermission('backup.delete');
        $ok = $this->backups()->delete((int) $id, $user, $request);

        return $this->respond($request, $ok, $ok ? 'Резервная копия удалена.' : 'Не найдена.', '/admin/data?tab=backups');
    }
}
