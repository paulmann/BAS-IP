<?php
/**
 * Data hub: export, import (with dry-run), backups.
 *
 * @var array $jobs
 * @var array $exports
 * @var array $backups
 * @var string $backupSize
 * @var string|null $lastBackup
 * @var array<int, string> $columns
 * @var array $counts
 * @var string $dbSize
 * @var array $formats
 * @var array $modes
 * @var bool $canImport
 * @var bool $canBackup
 * @var bool $canRestore
 * @var string $uploadMax
 * @var string $phpUploadMax
 * @var string $phpPostMax
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$query = $this->shared['query'] ?? [];
$importResult = $this->sessionPull('import_result');
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Данные</h2>
    <p class="page-desc">Выгрузка и загрузка базы жителей, реестры оплат и счетов, резервные копии и восстановление.</p>
  </div>
  <div class="page-actions">
    <?php if ($canBackup): ?>
      <form method="post" action="<?= $this->e($this->url('/admin/data/backup')) ?>" class="inline">
        <?= $this->csrf() ?>
        <input type="hidden" name="type" value="full">
        <input type="hidden" name="note" value="Полная копия из интерфейса">
        <button class="btn btn-default btn-sm" type="submit"><?= $this->icon('database', 'h-3.5 w-3.5') ?> Создать резервную копию</button>
      </form>
    <?php endif ?>
  </div>
</div>

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
  <?= $this->include('components/stat', ['label' => 'Жителей', 'value' => (string) (int) $counts['residents'], 'icon' => 'users', 'hint' => 'активных: ' . (int) $counts['active']]) ?>
  <?= $this->include('components/stat', ['label' => 'Размер базы', 'value' => $dbSize, 'icon' => 'database', 'hint' => 'счетов: ' . (int) $counts['invoices'] . ', оплат: ' . (int) $counts['payments']]) ?>
  <?= $this->include('components/stat', ['label' => 'Резервных копий', 'value' => (string) count($backups), 'icon' => 'archive', 'accent' => $lastBackup === null ? 'warning' : 'success', 'hint' => 'занимают ' . $backupSize]) ?>
  <?= $this->include('components/stat', ['label' => 'Событий шлагбаума', 'value' => (string) (int) $counts['events'], 'icon' => 'history', 'hint' => 'гостевых пропусков: ' . (int) ($counts['guests'] ?? 0)]) ?>
</div>

<?php if (is_array($importResult)): ?>
  <div class="card mt-5 border-info/40">
    <div class="card-header">
      <div class="card-title flex items-center gap-2"><?= $this->icon('upload', 'h-4 w-4') ?> Результат импорта</div>
      <div class="card-desc">Режим: <?= $this->e(!empty($importResult['dry_run']) ? 'проверка (ничего не изменено)' : (string) ($importResult['mode'] ?? '')) ?></div>
    </div>
    <div class="card-content grid gap-4">
      <div class="grid gap-3 sm:grid-cols-5">
        <?php foreach ([['Всего строк', (int) ($importResult['total'] ?? 0), ''], ['Создано', (int) ($importResult['imported'] ?? 0), 'success'], ['Обновлено', (int) ($importResult['updated'] ?? 0), 'info'], ['Пропущено', (int) ($importResult['skipped'] ?? 0), ''], ['Ошибок', (int) ($importResult['failed'] ?? 0), (int) ($importResult['failed'] ?? 0) > 0 ? 'destructive' : 'success']] as [$label, $val, $accent]): ?>
          <div class="rounded-lg border p-3 text-center">
            <div class="text-2xl font-semibold tabular <?= $accent === 'destructive' ? 'text-destructive' : ($accent === 'success' ? 'text-success' : '') ?>"><?= (int) $val ?></div>
            <div class="mt-0.5 text-xs text-muted-foreground"><?= $this->e($label) ?></div>
          </div>
        <?php endforeach ?>
      </div>

      <?php if (!empty($importResult['preview'])): ?>
        <div class="table-wrap max-h-80 overflow-auto">
          <table class="table">
            <thead><tr><th>Строка</th><th>Действие</th><th>ФИО</th><th>Квартира</th><th>Телефоны</th><th>Машины</th></tr></thead>
            <tbody>
            <?php foreach (array_slice((array) $importResult['preview'], 0, 50) as $row): ?>
              <tr>
                <td class="tabular text-xs"><?= (int) $row['row'] ?></td>
                <td><?= ($row['action'] ?? '') === 'create' ? '<span class="badge badge-soft-success">создать</span>' : '<span class="badge badge-soft-info">обновить</span>' ?></td>
                <td class="text-sm"><?= $this->e((string) ($row['full_name'] ?? '')) ?></td>
                <td class="text-sm tabular"><?= $this->e((string) ($row['apartment'] ?? '')) ?></td>
                <td class="text-xs"><?= $this->e(\BasIp\Support\Str::limit((string) ($row['phones'] ?? ''), 30)) ?></td>
                <td class="text-xs"><?= $this->e(\BasIp\Support\Str::limit((string) ($row['cars'] ?? ''), 24)) ?></td>
              </tr>
            <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>

      <?php if (!empty($importResult['errors'])): ?>
        <details class="rounded-lg border border-destructive/30 bg-destructive/5 p-3">
          <summary class="cursor-pointer text-sm font-medium text-destructive">Ошибок: <?= count((array) $importResult['errors']) ?></summary>
          <ul class="mt-2 grid gap-1 text-xs">
            <?php foreach (array_slice((array) $importResult['errors'], 0, 40) as $error): ?>
              <li class="flex gap-2"><span class="tabular text-muted-foreground">строка <?= (int) ($error['row'] ?? 0) ?>:</span><span><?= $this->e((string) ($error['error'] ?? '')) ?></span></li>
            <?php endforeach ?>
          </ul>
        </details>
      <?php endif ?>

      <?php if (!empty($importResult['dry_run'])): ?>
        <div class="alert alert-info">
          <?= $this->icon('info', 'h-4 w-4') ?>
          <div class="alert-desc">Это была проверка — данные не изменялись. Повторите загрузку, выбрав режим «Создать или обновить».</div>
        </div>
      <?php endif ?>
    </div>
  </div>
<?php endif ?>

<div class="grid gap-5 mt-5" data-tabs data-tabs-persist="data" data-tabs-hash>
  <div class="tabs-list is-scrollable" role="tablist">
    <button class="tabs-trigger" role="tab" data-tab="export" data-state="active" aria-selected="true"><?= $this->icon('download') ?> Выгрузка</button>
    <button class="tabs-trigger" role="tab" data-tab="import" data-state="inactive" aria-selected="false"><?= $this->icon('upload') ?> Загрузка</button>
    <button class="tabs-trigger" role="tab" data-tab="backups" data-state="inactive" aria-selected="false"><?= $this->icon('archive') ?> Резервные копии <span class="count"><?= count($backups) ?></span></button>
    <button class="tabs-trigger" role="tab" data-tab="history" data-state="inactive" aria-selected="false"><?= $this->icon('history') ?> История операций</button>
  </div>

  <?php /* ── выгрузка ───────────────────────────────────────── */ ?>
  <section class="tabs-content" role="tabpanel" data-tab-panel="export">
    <div class="grid gap-5 lg:grid-cols-2">
      <div class="card">
        <div class="card-header">
          <div class="card-title flex items-center gap-2"><?= $this->icon('users', 'h-4 w-4') ?> База жителей</div>
          <div class="card-desc">Полная выгрузка с контактами, автомобилями и оплатами. JSON сохраняет максимум данных — его же можно загрузить обратно.</div>
        </div>
        <div class="card-content grid gap-3">
          <div class="grid gap-3 sm:grid-cols-2">
            <div class="field">
              <label class="label" for="exp-scope">Объём</label>
              <select class="select" id="exp-scope">
                <option value="all">Все записи</option>
                <option value="active">Только активные</option>
                <option value="debtors">Только должники</option>
                <option value="inactive">Только отключённые</option>
              </select>
            </div>
            <div class="field">
              <label class="label" for="exp-format">Формат</label>
              <select class="select" id="exp-format">
                <?php foreach ($formats as $value => $label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach ?>
              </select>
            </div>
          </div>
          <div class="field">
            <div class="label mb-1.5">Что включать</div>
            <div class="flex flex-wrap gap-1.5">
              <?php foreach ([['contacts', 'Контакты', true], ['cars', 'Автомобили', true], ['payments', 'Оплаты', true], ['invoices', 'Счета', false]] as [$value, $label, $checked]): ?>
                <label class="chip <?= $checked ? 'is-active' : '' ?>">
                  <input type="checkbox" class="sr-only" data-exp-include value="<?= $value ?>" <?= $checked ? 'checked' : '' ?>> <?= $label ?>
                </label>
              <?php endforeach ?>
            </div>
          </div>
          <a class="btn btn-default" href="<?= $this->e($this->url('/admin/data/export/residents')) ?>" data-export-residents>
            <?= $this->icon('download') ?> Выгрузить жителей
          </a>
          <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/data/sample')) ?>"><?= $this->icon('file-spreadsheet', 'h-3.5 w-3.5') ?> Скачать образец CSV</a>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title flex items-center gap-2"><?= $this->icon('file-spreadsheet', 'h-4 w-4') ?> Прочие реестры</div>
          <div class="card-desc">Готовые отчёты для бухгалтерии и собраний.</div>
        </div>
        <div class="card-content grid gap-2">
          <a class="btn btn-outline justify-start" href="<?= $this->e($this->url('/admin/data/export/payments')) ?>"><?= $this->icon('banknote', 'h-4 w-4') ?> Реестр оплат</a>
          <a class="btn btn-outline justify-start" href="<?= $this->e($this->url('/admin/data/export/invoices')) ?>"><?= $this->icon('receipt', 'h-4 w-4') ?> Реестр счетов</a>
          <a class="btn btn-outline justify-start" href="<?= $this->e($this->url('/admin/gate/export')) ?>"><?= $this->icon('history', 'h-4 w-4') ?> Журнал открываний</a>
          <a class="btn btn-outline justify-start" href="<?= $this->e($this->url('/admin/guest/export')) ?>"><?= $this->icon('user-plus', 'h-4 w-4') ?> Гостевые доступы</a>
          <a class="btn btn-outline justify-start" href="<?= $this->e($this->url('/admin/audit/export')) ?>"><?= $this->icon('scroll-text', 'h-4 w-4') ?> Журнал аудита</a>
          <form method="post" action="<?= $this->e($this->url('/admin/data/export/full')) ?>" class="mt-1">
            <?= $this->csrf() ?>
            <button class="btn btn-outline justify-start w-full" type="submit"><?= $this->icon('package', 'h-4 w-4') ?> Полный архив (база + конфиги)</button>
          </form>
        </div>
      </div>

      <div class="card lg:col-span-2">
        <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('table', 'h-4 w-4') ?> Колонки выгрузки</div></div>
        <div class="card-content">
          <div class="flex flex-wrap gap-1.5">
            <?php foreach ($columns as $column): ?><span class="badge badge-outline font-mono text-[11px]"><?= $this->e($column) ?></span><?php endforeach ?>
          </div>
          <p class="mt-3 text-xs text-muted-foreground">
            CSV использует разделитель <code class="inline-code"><?= $this->e((string) $this->config('data.csv_delimiter', ';')) ?></code>
            и BOM для корректного открытия кириллицы в Excel. При загрузке русские заголовки («Фамилия», «Квартира», «Телефон») распознаются автоматически.
          </p>
        </div>
      </div>
    </div>
  </section>

  <?php /* ── загрузка ───────────────────────────────────────── */ ?>
  <section class="tabs-content" role="tabpanel" data-tab-panel="import" hidden>
    <?php if (!$canImport): ?>
      <div class="card"><?= $this->include('components/empty', ['title' => 'Недостаточно прав', 'description' => 'Загрузка данных доступна только администратору.', 'icon' => 'shield-ban']) ?></div>
    <?php else: ?>
      <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <form class="card" method="post" action="<?= $this->e($this->url('/admin/data/import')) ?>" enctype="multipart/form-data" data-import-form
              data-import-inspect="<?= $this->e($this->url('/admin/data/inspect')) ?>">
          <?= $this->csrf() ?>
          <div class="card-header">
            <div class="card-title flex items-center gap-2"><?= $this->icon('upload', 'h-4 w-4') ?> Загрузка базы жителей</div>
            <div class="card-desc">CSV из Excel, Google Таблиц или наш JSON-экспорт. Сначала файл разбирается и показывается предпросмотр — можно ничего не применять.</div>
          </div>
          <div class="card-content grid gap-4">
            <div class="file-drop" data-file-drop>
              <?= $this->icon('file-up', 'h-8 w-8 text-3xl text-muted-foreground') ?>
              <div class="text-sm font-medium">Перетащите файл сюда или нажмите для выбора</div>
              <div class="text-xs text-muted-foreground">CSV, TXT или JSON · до <?= $this->e($uploadMax) ?></div>
              <input class="sr-only" type="file" name="file" accept=".csv,.txt,.json,text/csv,application/json" required>
            </div>
            <p class="text-xs text-muted-foreground" data-file-size></p>

            <div class="grid gap-4 sm:grid-cols-3">
              <div class="field">
                <label class="label" for="imp-mode">Режим</label>
                <select class="select" id="imp-mode" name="mode">
                  <?php foreach ($modes as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $value === 'dry-run' ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach ?>
                </select>
              </div>
              <div class="field">
                <label class="label" for="imp-delimiter">Разделитель</label>
                <select class="select" id="imp-delimiter" name="delimiter">
                  <option value="auto">Определить автоматически</option>
                  <option value=";">Точка с запятой ( ; )</option>
                  <option value=",">Запятая ( , )</option>
                  <option value="tab">Табуляция</option>
                </select>
              </div>
              <div class="field">
                <label class="label" for="imp-match">Сопоставлять по</label>
                <select class="select" id="imp-match" name="match_on[]">
                  <option value="apartment">Квартира</option>
                  <option value="full_name">ФИО</option>
                  <option value="apartment" selected>Квартира + ФИО (по умолчанию)</option>
                  <option value="external_id">Внешний ID</option>
                  <option value="phone">Телефон</option>
                  <option value="email">E-mail</option>
                </select>
              </div>
            </div>

            <div class="grid gap-2 rounded-lg border bg-muted/25 p-3">
              <label class="checkbox-row text-sm"><input class="checkbox" type="checkbox" name="skip_errors" value="1" checked><span>Пропускать строки с ошибками и продолжать</span></label>
              <label class="checkbox-row text-sm"><input class="checkbox" type="checkbox" name="default_active" value="1" checked><span>Считать новых жителей активными</span></label>
            </div>

            <div data-import-preview hidden></div>

            <div class="flex flex-wrap gap-2">
              <button class="btn btn-outline" type="submit" data-import-mode="dry-run"><?= $this->icon('eye') ?> Проверить (ничего не менять)</button>
              <button class="btn btn-default" type="submit"
                      data-confirm="Применить загрузку? Существующие записи, совпавшие по ключу, будут обновлены."
                      data-confirm-title="Загрузка данных" data-confirm-label="Применить">
                <?= $this->icon('upload') ?> Загрузить
              </button>
            </div>
          </div>
        </form>

        <aside class="grid content-start gap-5">
          <div class="card">
            <div class="card-header"><div class="card-title">Формат файла</div></div>
            <div class="card-content grid gap-3 text-sm">
              <p class="text-muted-foreground">Первая строка — заголовки. Распознаются и русские, и английские названия колонок:</p>
              <div class="grid gap-1 text-xs">
                <?php foreach ([
                  ['Фамилия, Имя, Отчество', 'или одна колонка «ФИО» — разделится автоматически'],
                  ['Квартира', 'обязательно; «12А» тоже подойдёт'],
                  ['Телефон', 'можно несколько через запятую в колонке «Телефоны»'],
                  ['Почта', 'или «Все почты» через запятую'],
                  ['Номер машины, Марка', 'или «Все машины»: «А123ВС77 Skoda Octavia | В987УК77 Lada Vesta»'],
                  ['Доступ, Активен', '«да»/«нет», 1/0'],
                  ['Метки, Комментарий', 'произвольный текст'],
                ] as [$col, $hint]): ?>
                  <div class="rounded-md border bg-muted/20 p-2">
                    <div class="font-medium"><?= $this->e($col) ?></div>
                    <div class="text-[11px] text-muted-foreground"><?= $this->e($hint) ?></div>
                  </div>
                <?php endforeach ?>
              </div>
              <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/data/sample')) ?>"><?= $this->icon('file-spreadsheet', 'h-3.5 w-3.5') ?> Скачать образец</a>
            </div>
          </div>

          <div class="card">
            <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('banknote', 'h-4 w-4') ?> Загрузка оплат</div></div>
            <div class="card-content grid gap-3">
              <p class="text-xs text-muted-foreground">Банковская выписка: колонки «Квартира», «Сумма», «Дата», «№ платежа». Платёж зачтётся в старейший неоплаченный счёт.</p>
              <form method="post" action="<?= $this->e($this->url('/admin/data/import/payments')) ?>" enctype="multipart/form-data">
                <?= $this->csrf() ?>
                <div class="field">
                  <label class="label" for="pay-file">Файл выписки</label>
                  <input class="input" id="pay-file" name="file" type="file" accept=".csv,.txt" required>
                </div>
                <label class="checkbox-row text-xs my-2"><input class="checkbox" type="checkbox" name="dry_run" value="1" checked><span>только проверить</span></label>
                <button class="btn btn-outline btn-sm btn-block" type="submit"><?= $this->icon('upload', 'h-3.5 w-3.5') ?> Загрузить выписку</button>
              </form>
            </div>
          </div>

          <div class="alert alert-muted">
            <?= $this->icon('info', 'h-4 w-4') ?>
            <div class="alert-desc">
              Ограничения PHP на размер загрузки: <code class="inline-code">upload_max_filesize=<?= $this->e($phpUploadMax) ?></code>,
              <code class="inline-code">post_max_size=<?= $this->e($phpPostMax) ?></code>. Для больших файлов используйте CLI:
              <code class="inline-code">bin/bas-ip residents:import file.csv</code>
            </div>
          </div>
        </aside>
      </div>
    <?php endif ?>
  </section>

  <?php /* ── резервные копии ────────────────────────────────── */ ?>
  <section class="tabs-content" role="tabpanel" data-tab-panel="backups" hidden>
    <div class="card">
      <div class="card-header has-action">
        <div>
          <div class="card-title flex items-center gap-2"><?= $this->icon('archive', 'h-4 w-4') ?> Резервные копии</div>
          <div class="card-desc">
            Последняя: <b><?= $lastBackup !== null ? $this->e($this->datetime($lastBackup)) : 'нет' ?></b> · всего <?= count($backups) ?> (<?= $this->e($backupSize) ?>) ·
            хранится <?= (int) $this->config('backup.keep', 14) ?> последних.
          </div>
        </div>
        <?php if ($canBackup): ?>
          <div class="flex gap-2">
            <form method="post" action="<?= $this->e($this->url('/admin/data/backup')) ?>">
              <?= $this->csrf() ?>
              <input type="hidden" name="type" value="db">
              <button class="btn btn-outline btn-sm" type="submit"><?= $this->icon('database', 'h-3.5 w-3.5') ?> Только база</button>
            </form>
            <form method="post" action="<?= $this->e($this->url('/admin/data/backup')) ?>">
              <?= $this->csrf() ?>
              <input type="hidden" name="type" value="full">
              <button class="btn btn-default btn-sm" type="submit"><?= $this->icon('package', 'h-3.5 w-3.5') ?> Полный архив</button>
            </form>
          </div>
        <?php endif ?>
      </div>
      <div class="card-content p-0">
        <?php if ($backups === []): ?>
          <?= $this->include('components/empty', ['title' => 'Резервных копий нет', 'description' => 'Создайте первую — это занимает несколько секунд.', 'icon' => 'archive']) ?>
        <?php else: ?>
          <div class="table-wrap rounded-none border-0">
            <table class="table">
              <thead><tr><th>Файл</th><th>Тип</th><th class="text-right">Размер</th><th>СУБД</th><th>Шифрование</th><th>Статус</th><th>Создана</th><th class="text-right">Действия</th></tr></thead>
              <tbody>
              <?php foreach ($backups as $backup): ?>
                <tr>
                  <td>
                    <div class="font-mono text-xs"><?= $this->e((string) $backup['filename']) ?></div>
                    <?php if (!empty($backup['note'])): ?><div class="text-xs text-muted-foreground"><?= $this->e((string) $backup['note']) ?></div><?php endif ?>
                  </td>
                  <td><span class="badge badge-outline"><?= $this->e((string) $backup['type']) ?></span></td>
                  <td class="table-numeric tabular"><?= $this->e(\BasIp\Support\Str::bytes((int) $backup['size_bytes'])) ?></td>
                  <td class="text-xs"><?= $this->e((string) ($backup['driver'] ?? '—')) ?></td>
                  <td><?= !empty($backup['encrypted']) ? '<span class="badge badge-soft-success">AES-256-GCM</span>' : '<span class="badge badge-muted">нет</span>' ?></td>
                  <td>
                    <?php $st = (string) ($backup['status'] ?? 'done'); ?>
                    <span class="badge badge-soft-<?= ['done' => 'success', 'failed' => 'destructive', 'restored' => 'info'][$st] ?? 'warning' ?>">
                      <?= $this->e(['done' => 'готова', 'failed' => 'ошибка', 'restored' => 'восстановлена'][$st] ?? $st) ?>
                    </span>
                  </td>
                  <td class="whitespace-nowrap text-xs tabular text-muted-foreground"><?= $this->e($this->datetime($backup['created_at'] ?? null)) ?></td>
                  <td class="text-right">
                    <div class="flex justify-end gap-1">
                      <a class="btn btn-ghost btn-sm btn-icon" href="<?= $this->e($this->url('/admin/data/backup/' . (int) $backup['id'])) ?>" aria-label="Скачать" data-tooltip="Скачать"><?= $this->icon('download', 'h-3.5 w-3.5') ?></a>
                      <?php if ($canRestore && ($backup['status'] ?? '') === 'done'): ?>
                        <form method="post" action="<?= $this->e($this->url('/admin/data/backup/' . (int) $backup['id'] . '/restore')) ?>" class="inline">
                          <?= $this->csrf() ?>
                          <input type="hidden" name="confirm" value="">
                          <button class="btn btn-ghost btn-sm btn-icon" type="submit" aria-label="Восстановить" data-tooltip="Восстановить"
                                  data-confirm="Восстановить базу из этой копии? Текущие данные будут перезаписаны (сначала создаётся страховочная копия). Введите RESTORE."
                                  data-confirm-text="RESTORE" data-confirm-label="Восстановить" data-confirm-tone="destructive">
                            <?= $this->icon('rotate-ccw', 'h-3.5 w-3.5') ?>
                          </button>
                        </form>
                      <?php endif ?>
                      <?php if ($this->user()?->can('backup.delete')): ?>
                        <form method="post" action="<?= $this->e($this->url('/admin/data/backup/' . (int) $backup['id'] . '/delete')) ?>" class="inline">
                          <?= $this->csrf() ?>
                          <button class="btn btn-ghost btn-sm btn-icon text-destructive" type="submit" aria-label="Удалить" data-tooltip="Удалить"
                                  data-confirm="Удалить резервную копию?" data-confirm-label="Удалить" data-confirm-tone="destructive"><?= $this->icon('trash', 'h-3.5 w-3.5') ?></button>
                        </form>
                      <?php endif ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach ?>
              </tbody>
            </table>
          </div>
        <?php endif ?>
      </div>
    </div>

    <div class="alert alert-warning mt-5">
      <?= $this->icon('alert-triangle', 'h-4 w-4') ?>
      <div>
        <div class="alert-title">Восстановление необратимо</div>
        <div class="alert-desc">
          Перед восстановлением система автоматически создаёт страховочную копию текущего состояния.
          Для подтверждения потребуется ввести слово <code class="inline-code">RESTORE</code>.
          Рекомендуем делать копию по расписанию: <code class="inline-code">bin/bas-ip schedule:run --backup</code> в cron.
        </div>
      </div>
    </div>
  </section>

  <?php /* ── история ────────────────────────────────────────── */ ?>
  <section class="tabs-content" role="tabpanel" data-tab-panel="history" hidden>
    <div class="grid gap-5 lg:grid-cols-2">
      <div class="card">
        <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('upload', 'h-4 w-4') ?> Загрузки</div></div>
        <div class="card-content p-0">
          <?php if ($jobs === []): ?>
            <?= $this->include('components/empty', ['title' => 'Загрузок не было', 'icon' => 'upload']) ?>
          <?php else: ?>
            <div class="table-wrap rounded-none border-0">
              <table class="table">
                <thead><tr><th>Файл</th><th>Режим</th><th class="text-right">Строк</th><th class="text-right">Создано/обновлено</th><th class="text-right">Ошибок</th><th>Статус</th><th>Когда</th></tr></thead>
                <tbody>
                <?php foreach ($jobs as $job): ?>
                  <tr>
                    <td class="max-w-[14rem] truncate font-mono text-xs"><?= $this->e((string) $job['filename']) ?></td>
                    <td><span class="badge badge-outline"><?= $this->e((string) $job['mode']) ?></span></td>
                    <td class="table-numeric tabular"><?= (int) $job['rows_total'] ?></td>
                    <td class="table-numeric tabular"><?= (int) $job['rows_imported'] ?></td>
                    <td class="table-numeric tabular <?= (int) $job['rows_failed'] > 0 ? 'text-destructive' : '' ?>"><?= (int) $job['rows_failed'] ?></td>
                    <td><span class="badge badge-soft-<?= ($job['status'] ?? '') === 'done' ? 'success' : 'destructive' ?>"><?= $this->e((string) ($job['status'] ?? '')) ?></span></td>
                    <td class="whitespace-nowrap text-xs tabular text-muted-foreground"><?= $this->e($this->datetime($job['started_at'] ?? null)) ?></td>
                  </tr>
                <?php endforeach ?>
                </tbody>
              </table>
            </div>
          <?php endif ?>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('download', 'h-4 w-4') ?> Выгрузки</div></div>
        <div class="card-content p-0">
          <?php if ($exports === []): ?>
            <?= $this->include('components/empty', ['title' => 'Выгрузок не было', 'icon' => 'download']) ?>
          <?php else: ?>
            <div class="table-wrap rounded-none border-0">
              <table class="table">
                <thead><tr><th>Файл</th><th>Тип</th><th class="text-right">Строк</th><th class="text-right">Размер</th><th>Когда</th></tr></thead>
                <tbody>
                <?php foreach ($exports as $export): ?>
                  <tr>
                    <td class="max-w-[16rem] truncate font-mono text-xs"><?= $this->e((string) $export['filename']) ?></td>
                    <td><span class="badge badge-outline"><?= $this->e((string) $export['kind']) ?>/<?= $this->e((string) $export['format']) ?></span></td>
                    <td class="table-numeric tabular"><?= (int) $export['rows'] ?></td>
                    <td class="table-numeric tabular"><?= $this->e(\BasIp\Support\Str::bytes((int) $export['size_bytes'])) ?></td>
                    <td class="whitespace-nowrap text-xs tabular text-muted-foreground"><?= $this->e($this->datetime($export['created_at'] ?? null)) ?></td>
                  </tr>
                <?php endforeach ?>
                </tbody>
              </table>
            </div>
          <?php endif ?>
        </div>
      </div>
    </div>
  </section>
</div>

<?php $this->startPush('scripts') ?>
<script nonce="<?= $this->e($this->nonce()) ?>">
(function () {
  // Кнопка «Выгрузить жителей» собирает параметры из формы.
  var link = document.querySelector('[data-export-residents]');
  if (link) {
    link.addEventListener('click', function (e) {
      var params = new URLSearchParams();
      var scope = document.getElementById('exp-scope');
      var format = document.getElementById('exp-format');
      if (scope && scope.value !== 'all') params.set('status', scope.value);
      if (format) params.set('format', format.value);
      document.querySelectorAll('[data-exp-include]:checked').forEach(function (cb) { params.append('include[]', cb.value); });
      link.href = link.href.split('?')[0] + (params.toString() ? '?' + params.toString() : '');
    });
  }

  // Выбор режима импорта кнопками.
  document.querySelectorAll('[data-import-mode]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var mode = document.getElementById('imp-mode');
      if (mode) mode.value = btn.getAttribute('data-import-mode');
    });
  });
})();
</script>
<?php $this->endPush() ?>
