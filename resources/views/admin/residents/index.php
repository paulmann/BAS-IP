<?php
/**
 * Residents table — the core admin screen.
 *
 * Defaults to **active only**; every filter, sort and bulk action preserves
 * that rule, because calculations and mailings must never touch switched-off
 * records.
 *
 * @var BasIp\Support\Paginator $residents
 * @var array $filters
 * @var array $query
 * @var array $counts
 * @var array<int, string> $buildings
 * @var array<int, string> $tags
 * @var bool $canEdit
 * @var bool $canCreate
 * @var bool $canGate
 * @var array<int, string> $contactTypes
 * @var array<int, string> $carTypes
 * @var array<int, string> $roles
 * @var array $perPageOptions
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
/** @var BasIp\Domain\Model\Resident[] $items */
$items = $residents->items();
$status = (string) ($filters['status'] ?? 'active');
$currency = $this->config('billing.currency_symbol', '₽');
/** Build a sort link that preserves the current filters. */
$sortUrl = function (string $column) use ($query, $filters): string {
    $dir = strtolower((string) ($filters['dir'] ?? 'asc')) === 'asc' ? 'desc' : 'asc';
    if (($filters['sort'] ?? '') === $column) {
        $dir = $dir;                 // clicking the active column flips it
    }
    return $this->url('/admin/residents', $query + ['sort' => $column, 'dir' => $dir]);
};

$statusTabs = [
    'active'    => ['Активные', (int) ($counts['active'] ?? 0)],
    'inactive'  => ['Отключённые', (int) ($counts['inactive'] ?? 0)],
    'no_access' => ['Без доступа', (int) ($counts['no_access'] ?? 0)],
    'debtors'   => ['Должники', null],
    'deleted'   => ['Архив', (int) ($counts['deleted'] ?? 0)],
    'all'       => ['Все', (int) ($counts['all'] ?? 0)],
];
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Жители</h2>
    <p class="page-desc">
      В расчётах и рассылках участвуют только <b class="text-foreground">активные</b> записи
      (<?= (int) ($counts['billable'] ?? 0) ?> из <?= (int) ($counts['all'] ?? 0) ?>).
    </p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/residents/export.csv', $query + ['format' => 'csv'])) ?>">
      <?= $this->icon('download', 'h-3.5 w-3.5') ?> Выгрузить
    </a>
    <?php if ($canCreate): ?>
      <a class="btn btn-default btn-sm" href="<?= $this->e($this->url('/admin/residents/create')) ?>" data-command="Добавить жителя" data-command-group="Действия" data-command-icon="user-plus">
        <?= $this->icon('user-plus', 'h-3.5 w-3.5') ?> Добавить
      </a>
    <?php endif ?>
  </div>
</div>

<div class="card">
  <?php /* ── вкладки статусов ───────────────────────────────── */ ?>
  <div class="table-toolbar flex-wrap">
    <div class="tabs-list is-scrollable" role="tablist" aria-label="Фильтр по статусу">
      <?php foreach ($statusTabs as $key => [$label, $count]): ?>
        <a class="tabs-trigger" role="tab"
           href="<?= $this->e($this->url('/admin/residents', array_merge($query, ['status' => $key, 'page' => null]))) ?>"
           data-state="<?= $status === $key ? 'active' : 'inactive' ?>" <?= $status === $key ? 'aria-selected="true"' : '' ?>>
          <?= $this->e($label) ?><?php if ($count !== null): ?> <span class="count"><?= $count ?></span><?php endif ?>
        </a>
      <?php endforeach ?>
    </div>
  </div>

  <?php /* ── фильтры ────────────────────────────────────────── */ ?>
  <form class="filter-bar rounded-none border-x-0 border-t-0" method="get" action="<?= $this->e($this->url('/admin/residents')) ?>">
    <input type="hidden" name="status" value="<?= $this->e($status) ?>">
    <div class="filter-field min-w-[14rem] flex-[2]">
      <label class="label" for="q">Поиск</label>
      <div class="relative">
        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-muted-foreground"><?= $this->icon('search', 'h-4 w-4') ?></span>
        <input class="input pl-9" id="q" name="q" type="search" value="<?= $this->e((string) ($filters['q'] ?? '')) ?>"
               placeholder="ФИО, квартира, телефон, почта, госномер…" autocomplete="off" data-global-search>
      </div>
    </div>
    <div class="filter-field">
      <label class="label" for="building">Корпус</label>
      <select class="select" id="building" name="building">
        <option value="">Все</option>
        <?php foreach ($buildings as $building): ?>
          <option value="<?= $this->e($building) ?>" <?= $this->selected($filters['building'] ?? '', $building) ?>><?= $this->e($building) ?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="filter-field">
      <label class="label" for="apartment_from">Квартиры с</label>
      <input class="input" id="apartment_from" name="apartment_from" type="number" min="0" value="<?= $this->e((string) ($filters['apartment_from'] ?? '')) ?>">
    </div>
    <div class="filter-field">
      <label class="label" for="apartment_to">по</label>
      <input class="input" id="apartment_to" name="apartment_to" type="number" min="0" value="<?= $this->e((string) ($filters['apartment_to'] ?? '')) ?>">
    </div>
    <div class="filter-field">
      <label class="label" for="tag">Метка</label>
      <select class="select" id="tag" name="tag">
        <option value="">Любая</option>
        <?php foreach ($tags as $tag): ?>
          <option value="<?= $this->e($tag) ?>" <?= $this->selected($filters['tag'] ?? '', $tag) ?>><?= $this->e($tag) ?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="filter-field">
      <label class="label" for="access">Доступ</label>
      <select class="select" id="access" name="access">
        <option value="">Любой</option>
        <option value="1" <?= ($filters['access'] ?? '') === '1' ? 'selected' : '' ?>>Разрешён</option>
        <option value="0" <?= ($filters['access'] ?? '') === '0' ? 'selected' : '' ?>>Приостановлен</option>
      </select>
    </div>
    <div class="filter-field">
      <span class="label">Есть</span>
      <div class="flex flex-wrap gap-1.5">
        <?php foreach ([['has_phone', 'Телефон'], ['has_email', 'Почта'], ['has_car', 'Машина'], ['no_contact', 'Без контактов']] as [$key, $label]): ?>
          <label class="chip <?= !empty($filters[$key]) ? 'is-active' : '' ?>">
            <input type="checkbox" class="sr-only" name="<?= $key ?>" value="1" <?= !empty($filters[$key]) ? 'checked' : '' ?>> <?= $label ?>
          </label>
        <?php endforeach ?>
      </div>
    </div>
    <div class="flex items-center gap-2">
      <button class="btn btn-default btn-sm" type="submit"><?= $this->icon('filter', 'h-3.5 w-3.5') ?> Применить</button>
      <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/residents')) ?>"><?= $this->icon('rotate-ccw', 'h-3.5 w-3.5') ?> Сброс</a>
    </div>
  </form>

  <?php /* ── массовые действия ──────────────────────────────── */ ?>
  <?php if ($canEdit): ?>
    <div class="flex flex-wrap items-center gap-2 border-b bg-muted/30 px-3 py-2.5" data-bulk-bar hidden>
      <span class="text-sm font-medium">Выбрано: <b class="tabular" data-bulk-count>0</b></span>
      <div class="mx-1 h-5 w-px bg-border"></div>
      <div class="flex flex-wrap items-center gap-1.5">
        <?php if ($status !== 'active'): ?>
          <button class="btn btn-outline btn-sm" type="button" data-bulk-action="activate"><?= $this->icon('check', 'h-3.5 w-3.5') ?> Включить</button>
        <?php endif ?>
        <?php if ($status !== 'inactive'): ?>
          <button class="btn btn-outline btn-sm" type="button" data-bulk-action="deactivate"><?= $this->icon('ban', 'h-3.5 w-3.5') ?> Отключить</button>
        <?php endif ?>
        <button class="btn btn-outline btn-sm" type="button" data-bulk-action="grant_access"><?= $this->icon('unlock', 'h-3.5 w-3.5') ?> Разрешить доступ</button>
        <button class="btn btn-outline btn-sm" type="button" data-bulk-action="revoke_access"><?= $this->icon('lock', 'h-3.5 w-3.5') ?> Приостановить</button>
        <button class="btn btn-outline btn-sm" type="button" data-bulk-action="tag"><?= $this->icon('tag', 'h-3.5 w-3.5') ?> Метка</button>
        <button class="btn btn-outline btn-sm" type="button" data-bulk-action="note"><?= $this->icon('notebook-pen', 'h-3.5 w-3.5') ?> Комментарий</button>
        <button class="btn btn-outline btn-sm" type="button" data-bulk-action="recalc_balance"><?= $this->icon('calculator', 'h-3.5 w-3.5') ?> Пересчитать баланс</button>
        <?php if ($status === 'deleted'): ?>
          <button class="btn btn-outline btn-sm" type="button" data-bulk-action="delete" data-bulk-hard="1"><?= $this->icon('trash', 'h-3.5 w-3.5') ?> Удалить навсегда</button>
        <?php else: ?>
          <button class="btn btn-outline btn-sm text-destructive" type="button" data-bulk-action="delete"><?= $this->icon('archive', 'h-3.5 w-3.5') ?> В архив</button>
        <?php endif ?>
      </div>
      <label class="ml-auto inline-flex items-center gap-2 text-xs text-muted-foreground">
        <input class="checkbox" type="checkbox" data-bulk-only-active checked>
        только активные
      </label>
    </div>
    <form method="post" action="<?= $this->e($this->url('/admin/residents/bulk')) ?>" data-bulk-form class="hidden" aria-hidden="true">
      <?= $this->csrf() ?>
      <input type="hidden" name="action" value="">
      <input type="hidden" name="only_active" value="1">
      <input type="hidden" name="tag" value="">
      <input type="hidden" name="note" value="">
      <input type="hidden" name="hard" value="">
      <input type="hidden" name="tag_mode" value="add" data-bulk-tag-mode>
      <input type="hidden" name="redirect" value="<?= $this->e($this->url('/admin/residents', $query)) ?>">
    </form>
    <input type="hidden" data-known-tags value="<?= $this->e(implode(',', $tags)) ?>">
  <?php endif ?>

  <?php /* ── таблица ────────────────────────────────────────── */ ?>
  <div class="card-content p-0">
    <?php if ($items === []): ?>
      <?= $this->include('components/empty', [
        'title' => $status === 'active' ? 'Нет активных жителей' : 'Ничего не найдено',
        'description' => $status === 'active'
          ? 'Добавьте жителей вручную или импортируйте базу из CSV/Excel. Только активные записи участвуют в расчёте оплаты.'
          : 'Попробуйте изменить условия поиска.',
        'icon' => 'users',
        'actionLabel' => $canCreate ? 'Добавить жителя' : null,
        'actionUrl' => $canCreate ? '/admin/residents/create' : null,
      ]) ?>
    <?php else: ?>
      <div class="table-wrap rounded-none border-0" data-residents-table data-status="<?= $this->e($status) ?>"
           data-table data-counters-url="<?= $this->e($this->url('/admin/residents?counts=1')) ?>"
           data-all-url="<?= $this->e($this->url('/admin/residents?status=all')) ?>">
        <table class="table">
          <thead>
            <tr>
              <?php if ($canEdit): ?>
                <th class="w-9">
                  <input type="checkbox" class="checkbox" data-select-all aria-label="Выбрать все">
                </th>
              <?php endif ?>
              <th class="w-24">
                <a class="table-sortable <?= ($filters['sort'] ?? '') === 'apartment' ? 'is-active' : '' ?>"
                   href="<?= $this->e($sortUrl('apartment')) ?>">
                  Квартира <span class="sort-icon"><?= $this->icon('arrow-up-down', 'h-3 w-3') ?></span>
                </a>
              </th>
              <th>
                <a class="table-sortable <?= ($filters['sort'] ?? '') === 'full_name' ? 'is-active' : '' ?>"
                   href="<?= $this->e($sortUrl('full_name')) ?>">
                  ФИО <span class="sort-icon"><?= $this->icon('arrow-up-down', 'h-3 w-3') ?></span>
                </a>
              </th>
              <th class="hidden lg:table-cell">Контакты</th>
              <th class="hidden md:table-cell">Автомобили</th>
              <th class="w-20 text-center" data-tooltip="Включен/выключен — участвует в расчётах">Активен</th>
              <th class="w-20 text-center" data-tooltip="Разрешено ли открывать шлагбаум">Доступ</th>
              <th class="w-28 text-right">
                <a class="table-sortable <?= ($filters['sort'] ?? '') === 'balance' ? 'is-active' : '' ?>"
                   href="<?= $this->e($sortUrl('balance')) ?>">
                  Баланс <span class="sort-icon"><?= $this->icon('arrow-up-down', 'h-3 w-3') ?></span>
                </a>
              </th>
              <th class="w-32 text-right">Действия</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($items as $resident): ?>
            <tr class="<?= $resident->isActive ? '' : 'is-inactive' ?>">
              <?php if ($canEdit): ?>
                <td><input type="checkbox" class="checkbox" data-row-select value="<?= (int) $resident->id ?>" aria-label="Выбрать <?= $this->e($resident->displayName()) ?>"></td>
              <?php endif ?>

              <td>
                <div class="font-medium tabular">кв. <?= $this->e($resident->apartment ?: '—') ?></div>
                <?php if ($resident->building !== null): ?><div class="text-xs text-muted-foreground">корп. <?= $this->e($resident->building) ?></div><?php endif ?>
              </td>

              <td>
                <a class="group flex items-start gap-2.5 hover:underline" href="<?= $this->e($this->url('/admin/residents/' . $resident->id)) ?>">
                  <span class="avatar avatar-sm shrink-0"><?= $this->e($resident->initials()) ?></span>
                  <span class="min-w-0">
                    <span class="block truncate font-medium"><?= $this->e($resident->displayName()) ?></span>
                    <span class="block truncate text-xs text-muted-foreground">
                      <?= $this->e($resident->roleLabel()) ?>
                      <?php if ($resident->tags !== []): ?> · <?= $this->e(implode(', ', array_slice($resident->tags, 0, 2))) ?><?php endif ?>
                    </span>
                  </span>
                </a>
              </td>

              <td class="hidden lg:table-cell">
                <div class="grid gap-0.5 text-xs">
                  <?php if ($resident->phone !== null): ?>
                    <a class="inline-flex items-center gap-1.5 hover:text-primary" href="tel:<?= $this->e(\BasIp\Support\Phone::tel($resident->phone)) ?>">
                      <?= $this->icon('phone', 'h-3 w-3 text-muted-foreground') ?> <?= $this->e($resident->formattedPhone()) ?>
                      <?php if (count($resident->contacts) > 1): ?><span class="badge badge-muted">+<?= max(0, count(array_filter($resident->contacts, static fn ($c) => ($c['type'] ?? '') === 'phone')) - 1) ?></span><?php endif ?>
                    </a>
                  <?php endif ?>
                  <?php if ($resident->email !== null): ?>
                    <a class="inline-flex max-w-[16rem] items-center gap-1.5 truncate hover:text-primary" href="mailto:<?= $this->e($resident->email) ?>">
                      <?= $this->icon('mail', 'h-3 w-3 shrink-0 text-muted-foreground') ?> <span class="truncate"><?= $this->e($resident->email) ?></span>
                    </a>
                  <?php endif ?>
                  <?php if ($resident->phone === null && $resident->email === null): ?>
                    <span class="badge badge-soft-destructive" data-tooltip="Уведомления не дойдут — добавьте контакт"><?= $this->icon('alert-triangle', 'h-3 w-3') ?> нет контактов</span>
                  <?php endif ?>
                </div>
              </td>

              <td class="hidden md:table-cell">
                <?php if ($resident->cars === []): ?>
                  <span class="text-xs text-muted-foreground">—</span>
                <?php else: ?>
                  <div class="flex flex-wrap gap-1">
                    <?php foreach (array_slice($resident->cars, 0, 2) as $car): ?>
                      <span class="badge badge-outline font-mono text-[11px]"
                            data-tooltip="<?= $this->e(trim(($car['brand'] ?? '') . ' ' . ($car['model'] ?? '')) ?: 'без описания') ?>">
                        <?= $this->e(\BasIp\Support\Plate::format((string) ($car['plate'] ?? ''))) ?>
                      </span>
                    <?php endforeach ?>
                    <?php if (count($resident->cars) > 2): ?>
                      <span class="badge badge-muted">+<?= count($resident->cars) - 2 ?></span>
                    <?php endif ?>
                  </div>
                <?php endif ?>
              </td>

              <td class="text-center">
                <?php if ($canEdit): ?>
                  <input type="checkbox" class="switch switch-success" role="switch"
                         aria-label="Активен"
                         data-toggle-url="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/toggle')) ?>"
                         data-toggle-field="is_active" <?= $resident->isActive ? 'checked' : '' ?>>
                <?php else: ?>
                  <span class="badge badge-soft-<?= $resident->isActive ? 'success' : 'warning' ?>"><?= $resident->isActive ? 'да' : 'нет' ?></span>
                <?php endif ?>
              </td>

              <td class="text-center">
                <?php if ($canEdit): ?>
                  <input type="checkbox" class="switch" role="switch" aria-label="Доступ"
                         data-toggle-url="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/toggle')) ?>"
                         data-toggle-field="access_granted" <?= $resident->accessGranted ? 'checked' : '' ?>>
                <?php else: ?>
                  <span class="badge badge-soft-<?= $resident->accessGranted ? 'success' : 'destructive' ?>"><?= $resident->accessGranted ? 'да' : 'нет' ?></span>
                <?php endif ?>
              </td>

              <td class="table-numeric">
                <?php $balance = (float) ($resident->debt ?? 0) > 0.005 ? -(float) $resident->debt : (float) $resident->balance; ?>
                <span class="font-semibold tabular <?= $balance < -0.005 ? 'text-destructive' : ($balance > 0.005 ? 'text-success' : 'text-muted-foreground') ?>">
                  <?= $this->e($this->money($balance)) ?>
                </span>
                <?php if ((float) ($resident->overdue ?? 0) > 0.005): ?>
                  <div class="text-[11px] text-destructive">просрочено <?= $this->e($this->money((float) $resident->overdue)) ?></div>
                <?php endif ?>
              </td>

              <td>
                <div class="flex justify-end gap-1">
                  <?php if ($canGate && $resident->isActive && $resident->accessGranted): ?>
                    <button class="btn btn-ghost btn-sm btn-icon text-success" type="button"
                            data-quick-open="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/open-gate')) ?>"
                            data-quick-open-name="<?= $this->e($resident->displayName()) ?>"
                            data-tooltip="Открыть шлагбаум" aria-label="Открыть шлагбаум">
                      <?= $this->icon('door-open', 'h-4 w-4') ?>
                    </button>
                  <?php endif ?>
                  <a class="btn btn-ghost btn-sm btn-icon" href="<?= $this->e($this->url('/admin/residents/' . $resident->id)) ?>" aria-label="Карточка" data-tooltip="Карточка">
                    <?= $this->icon('eye', 'h-4 w-4') ?>
                  </a>
                  <?php if ($canEdit): ?>
                    <div class="relative">
                      <button class="btn btn-ghost btn-sm btn-icon" type="button" data-dropdown aria-haspopup="true" aria-expanded="false" aria-label="Ещё действия">
                        <?= $this->icon('more-horizontal', 'h-4 w-4') ?>
                      </button>
                      <div class="dropdown right-0 top-full mt-1 w-56" data-dropdown-panel data-state="closed" hidden role="menu">
                        <a class="dropdown-item" role="menuitem" href="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/edit')) ?>"><?= $this->icon('pencil') ?> Редактировать</a>
                        <a class="dropdown-item" role="menuitem" href="<?= $this->e($this->url('/admin/billing/payments?resident_id=' . $resident->id)) ?>"><?= $this->icon('banknote') ?> Оплаты</a>
                        <a class="dropdown-item" role="menuitem" href="<?= $this->e($this->url('/admin/guest?resident_id=' . $resident->id)) ?>"><?= $this->icon('user-plus') ?> Гостевые доступы</a>
                        <a class="dropdown-item" role="menuitem" href="<?= $this->e($this->url('/admin/gate?resident_id=' . $resident->id)) ?>"><?= $this->icon('history') ?> Журнал открытий</a>
                        <div class="dropdown-separator"></div>
                        <button class="dropdown-item" role="menuitem" data-dialog="#dialog-payment-<?= (int) $resident->id ?>"><?= $this->icon('coins') ?> Принять оплату</button>
                        <button class="dropdown-item" role="menuitem" data-bulk-action="tag" data-bulk-single="<?= (int) $resident->id ?>"><?= $this->icon('tag') ?> Метка</button>
                        <div class="dropdown-separator"></div>
                        <?php if ($status === 'deleted'): ?>
                          <form method="post" action="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/restore')) ?>">
                            <?= $this->csrf() ?>
                            <button class="dropdown-item w-full" role="menuitem" type="submit"><?= $this->icon('rotate-ccw') ?> Восстановить</button>
                          </form>
                        <?php else: ?>
                          <form method="post" action="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/delete')) ?>">
                            <?= $this->csrf() ?>
                            <button class="dropdown-item w-full" role="menuitem" type="submit" data-destructive
                                    data-confirm="Переместить «<?= $this->e($resident->displayName()) ?>» в архив?" data-confirm-label="В архив" data-confirm-tone="destructive">
                              <?= $this->icon('archive') ?> В архив
                            </button>
                          </form>
                        <?php endif ?>
                      </div>
                    </div>
                  <?php endif ?>
                </div>
              </td>
            </tr>

            <?php /* Диалог приёма оплаты (один на строку, рендерится лениво) */ ?>
            <?php if ($canEdit): ?>
              <div class="dialog dialog-sm" id="dialog-payment-<?= (int) $resident->id ?>" data-state="closed" hidden role="dialog" aria-labelledby="pay-t-<?= (int) $resident->id ?>">
                <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
                <form method="post" action="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/payment')) ?>" data-ajax
                      data-redirect="<?= $this->e($this->url('/admin/residents/' . $resident->id)) ?>">
                  <?= $this->csrf() ?>
                  <div class="dialog-header">
                    <h2 class="dialog-title" id="pay-t-<?= (int) $resident->id ?>">Принять оплату</h2>
                    <p class="dialog-desc"><?= $this->e($resident->displayName()) ?> · кв. <?= $this->e($resident->apartment) ?></p>
                  </div>
                  <div class="grid gap-3">
                    <div class="field">
                      <label class="label" for="pay-amount-<?= (int) $resident->id ?>">Сумма</label>
                      <div class="input-group">
                        <input class="input input-mono" id="pay-amount-<?= (int) $resident->id ?>" name="amount" type="text" inputmode="decimal" required
                               value="<?= (float) ($resident->debt ?? 0) > 0.005 ? number_format((float) $resident->debt, 2, '.', '') : '' ?>" placeholder="0.00">
                        <span class="addon"><?= $this->e($currency) ?></span>
                      </div>
                      <?php if ((float) ($resident->debt ?? 0) > 0.005): ?>
                        <button type="button" class="btn btn-ghost btn-sm mt-1" data-payment-fill="<?= (float) $resident->debt ?>">Задолженность: <?= $this->e($this->money((float) $resident->debt)) ?></button>
                      <?php endif ?>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                      <div class="field">
                        <label class="label" for="pay-date-<?= (int) $resident->id ?>">Дата</label>
                        <input class="input" id="pay-date-<?= (int) $resident->id ?>" name="paid_at" type="date" value="<?= $this->e(date('Y-m-d')) ?>">
                      </div>
                      <div class="field">
                        <label class="label" for="pay-method-<?= (int) $resident->id ?>">Способ</label>
                        <select class="select" id="pay-method-<?= (int) $resident->id ?>" name="method">
                          <?php foreach (['transfer' => 'Перевод', 'cash' => 'Наличные', 'card' => 'Карта', 'sbp' => 'СБП', 'online' => 'Онлайн', 'other' => 'Другое'] as $value => $label): ?>
                            <option value="<?= $value ?>"><?= $label ?></option>
                          <?php endforeach ?>
                        </select>
                      </div>
                    </div>
                    <div class="field">
                      <label class="label" for="pay-ref-<?= (int) $resident->id ?>">№ платежа</label>
                      <input class="input input-mono" id="pay-ref-<?= (int) $resident->id ?>" name="reference" type="text" maxlength="120" placeholder="Номер платёжного поручения">
                    </div>
                  </div>
                  <div class="dialog-footer mt-4">
                    <button type="button" class="btn btn-outline" data-close>Отмена</button>
                    <button type="submit" class="btn btn-default"><?= $this->icon('check') ?> Провести</button>
                  </div>
                </form>
              </div>
            <?php endif ?>
          <?php endforeach ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="<?= $canEdit ? 9 : 8 ?>">
                <div class="flex flex-wrap items-center justify-between gap-3">
                  <span>На странице: <?= count($items) ?> · всего по фильтру: <b class="tabular"><?= number_format($residents->total(), 0, ',', ' ') ?></b></span>
                  <form method="get" action="<?= $this->e($this->url('/admin/residents')) ?>" class="flex items-center gap-2">
                    <?php foreach ($query as $key => $value): if ($key === 'per_page' || $value === '' || $value === null) { continue; } ?>
                      <input type="hidden" name="<?= $this->e((string) $key) ?>" value="<?= $this->e((string) $value) ?>">
                    <?php endforeach ?>
                    <label class="text-xs text-muted-foreground" for="per_page">Строк:</label>
                    <select class="select select-sm w-auto" id="per_page" name="per_page" data-auto-submit>
                      <?php foreach (($perPageOptions ?: [25, 50, 100]) as $option): ?>
                        <option value="<?= (int) $option ?>" <?= $residents->perPage() === (int) $option ? 'selected' : '' ?>><?= (int) $option ?></option>
                      <?php endforeach ?>
                    </select>
                  </form>
                </div>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
      <div class="p-4"><?= $this->include('components/pagination', ['paginator' => $residents, 'query' => $query, 'target' => '/admin/residents']) ?></div>
    <?php endif ?>
  </div>
</div>
