<?php
/**
 * The gate log — every opening, denial and error.
 *
 * @var BasIp\Support\Paginator $events
 * @var array $filters
 * @var array $query
 * @var array $stats
 * @var array<int, BasIp\Domain\Model\Device> $devices
 * @var array<string, string> $accessTypes
 * @var array<string, string> $results
 * @var array<string, string> $sources
 * @var array<string, string> $actorTypes
 * @var bool $canOpen
 * @var bool $canExport
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$items = $events->items();
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Журнал открываний</h2>
    <p class="page-desc">Кто, когда и по какому доступу открыл шлагбаум. Записываются и отказы — с причиной.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/gate/stats')) ?>"><?= $this->icon('bar-chart-3', 'h-3.5 w-3.5') ?> Статистика</a>
    <?php if ($canExport): ?>
      <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/gate/export', $query)) ?>"><?= $this->icon('download', 'h-3.5 w-3.5') ?> CSV</a>
    <?php endif ?>
    <?php if ($canOpen): ?>
      <button class="btn btn-success btn-sm" type="button" data-shortcut="open" data-gate-open="<?= $this->e($this->url('/admin/gate/open')) ?>"
              data-confirm="Открыть шлагбаум сейчас?" data-confirm-title="Открытие шлагбаума" data-confirm-label="Открыть">
        <?= $this->icon('door-open', 'h-3.5 w-3.5') ?> Открыть
      </button>
    <?php endif ?>
  </div>
</div>

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
  <?= $this->include('components/stat', ['label' => 'Событий за 30 дней', 'value' => (string) (int) ($stats['total'] ?? 0), 'icon' => 'history']) ?>
  <?= $this->include('components/stat', ['label' => 'Сегодня', 'value' => (string) (int) ($stats['today'] ?? 0), 'icon' => 'door-open', 'accent' => 'success']) ?>
  <?= $this->include('components/stat', ['label' => 'Отказов за месяц', 'value' => (string) (int) ($stats['denied'] ?? 0), 'icon' => 'ban', 'accent' => (int) ($stats['denied'] ?? 0) > 0 ? 'warning' : '']) ?>
  <?= $this->include('components/stat', ['label' => 'Гостевых за месяц', 'value' => (string) (int) ($stats['guest'] ?? 0), 'icon' => 'user-plus', 'accent' => 'info']) ?>
</div>

<div class="card mt-5">
  <form class="filter-bar rounded-t-xl" method="get" action="<?= $this->e($this->url('/admin/gate')) ?>">
    <div class="filter-field min-w-[14rem] flex-[2]">
      <label class="label" for="q">Поиск</label>
      <input class="input" id="q" name="q" type="search" value="<?= $this->e((string) ($filters['q'] ?? '')) ?>" placeholder="ФИО, квартира, госномер, код пропуска, причина">
    </div>
    <div class="filter-field">
      <label class="label" for="from">Период с</label>
      <input class="input" id="from" name="from" type="date" value="<?= $this->e((string) ($filters['from'] ?? '')) ?>" data-date-from>
    </div>
    <div class="filter-field">
      <label class="label" for="to">по</label>
      <input class="input" id="to" name="to" type="date" value="<?= $this->e((string) ($filters['to'] ?? '')) ?>" data-date-to>
    </div>
    <div class="filter-field">
      <label class="label" for="access_type">Тип доступа</label>
      <select class="select" id="access_type" name="access_type">
        <option value="">Любой</option>
        <?php foreach ($accessTypes as $value => $label): ?>
          <option value="<?= $value ?>" <?= $this->selected($filters['access_type'] ?? '', $value) ?>><?= $label ?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="filter-field">
      <label class="label" for="result">Результат</label>
      <select class="select" id="result" name="result">
        <option value="">Любой</option>
        <?php foreach ($results as $value => $label): ?>
          <option value="<?= $value ?>" <?= $this->selected($filters['result'] ?? '', $value) ?>><?= $label ?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="filter-field">
      <label class="label" for="source">Источник</label>
      <select class="select" id="source" name="source">
        <option value="">Любой</option>
        <?php foreach ($sources as $value => $label): ?>
          <option value="<?= $value ?>" <?= $this->selected($filters['source'] ?? '', $value) ?>><?= $label ?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="filter-field">
      <label class="label" for="device_id">Устройство</label>
      <select class="select" id="device_id" name="device_id">
        <option value="">Все</option>
        <?php foreach ($devices as $device): ?>
          <option value="<?= (int) $device->id ?>" <?= (string) ($filters['device_id'] ?? '') === (string) $device->id ? 'selected' : '' ?>><?= $this->e($device->label()) ?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="filter-field">
      <span class="label">Быстрый выбор</span>
      <div class="flex flex-wrap gap-1.5">
        <?php foreach ([['today', 'Сегодня'], ['week', 'Неделя'], ['month', 'Месяц'], ['quarter', 'Квартал']] as [$preset, $label]): ?>
          <button type="button" class="chip" data-date-preset="<?= $preset ?>"><?= $label ?></button>
        <?php endforeach ?>
        <label class="chip <?= !empty($filters['only_guest']) ? 'is-active' : '' ?>"><input type="checkbox" class="sr-only" name="only_guest" value="1" <?= !empty($filters['only_guest']) ? 'checked' : '' ?>> только гости</label>
      </div>
    </div>
    <div class="flex items-center gap-2">
      <button class="btn btn-default btn-sm" type="submit"><?= $this->icon('filter', 'h-3.5 w-3.5') ?> Применить</button>
      <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/gate')) ?>"><?= $this->icon('rotate-ccw', 'h-3.5 w-3.5') ?> Сброс</a>
    </div>
  </form>

  <div class="card-content p-0">
    <?php if ($items === []): ?>
      <?= $this->include('components/empty', ['title' => 'Событий не найдено', 'description' => 'Измените период или снимите фильтры.', 'icon' => 'history']) ?>
    <?php else: ?>
      <div class="table-wrap rounded-none border-0">
        <table class="table">
          <thead>
            <tr><th>Когда</th><th>Кто</th><th>Доступ</th><th>Источник</th><th>Результат</th>
                <th>Устройство</th><th>Авто</th><th>Причина / комментарий</th><th class="text-right">IP · мс</th></tr>
          </thead>
          <tbody>
          <?php foreach ($items as $event): ?>
            <tr>
              <td class="whitespace-nowrap text-xs tabular">
                <div class="font-medium text-foreground"><?= $this->e($event->when('d.m.Y')) ?></div>
                <div class="text-muted-foreground"><?= $this->e($event->when('H:i:s')) ?></div>
              </td>
              <td>
                <div class="flex items-start gap-2">
                  <span class="avatar avatar-sm shrink-0 <?= $event->isGuest() ? 'bg-warning/15 text-warning' : ($event->result === 'success' ? 'bg-success/12 text-success' : 'bg-destructive/12 text-destructive') ?>">
                    <?= $this->icon($event->isGuest() ? 'user-plus' : ($event->actorType === 'admin' || $event->actorType === 'operator' ? 'shield' : 'user'), 'h-3.5 w-3.5') ?>
                  </span>
                  <span class="min-w-0">
                    <?php if ($event->residentId !== null): ?>
                      <a class="block truncate text-sm font-medium hover:underline" href="<?= $this->e($this->url('/admin/residents/' . (int) $event->residentId)) ?>"><?= $this->e($event->actorLabel()) ?></a>
                    <?php else: ?>
                      <span class="block truncate text-sm font-medium"><?= $this->e($event->actorLabel()) ?></span>
                    <?php endif ?>
                    <?php if ($event->apartment !== null): ?><span class="block text-xs text-muted-foreground">кв. <?= $this->e($event->apartment) ?></span><?php endif ?>
                  </span>
                </div>
              </td>
              <td><span class="badge badge-soft-<?= $event->accessTone() === 'muted' ? 'warning' : $event->accessTone() ?>"><?= $this->e($event->accessLabel()) ?></span></td>
              <td class="text-xs text-muted-foreground"><?= $this->e($event->sourceLabel()) ?></td>
              <td>
                <span class="badge badge-soft-<?= $event->resultTone() === 'muted' ? 'warning' : $event->resultTone() ?>">
                  <?= $this->icon($event->result === 'success' ? 'check' : 'ban', 'h-3 w-3') ?> <?= $this->e($event->resultLabel()) ?>
                </span>
              </td>
              <td class="text-xs text-muted-foreground"><?= $this->e($event->deviceName ?? '—') ?><?= $event->channel > 1 ? ' · к' . $event->channel : '' ?></td>
              <td class="font-mono text-xs"><?= $this->e($event->plate ?? '—') ?></td>
              <td class="max-w-[18rem] truncate text-xs text-muted-foreground" title="<?= $this->e($event->reason ?? '') ?>"><?= $this->e($event->reason ?? '') ?></td>
              <td class="text-right text-[11px] tabular text-muted-foreground">
                <div class="font-mono"><?= $this->e(\BasIp\Support\Ip::anonymize((string) ($event->ip ?? ''))) ?></div>
                <?php if ($event->durationMs !== null): ?><div><?= (int) $event->durationMs ?> мс</div><?php endif ?>
              </td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <div class="p-4"><?= $this->include('components/pagination', ['paginator' => $events, 'query' => $query, 'target' => '/admin/gate']) ?></div>
    <?php endif ?>
  </div>
</div>

<div class="alert alert-muted mt-5">
  <?= $this->icon('info', 'h-4 w-4') ?>
  <div>
    <div class="alert-title">Журнал неизменяемый</div>
    <div class="alert-desc">
      Записи нельзя редактировать или удалять из интерфейса — только архивировать по сроку хранения
      (сейчас <?= (int) ($retention ?? 730) ?> дней). Это сделано намеренно: журнал — доказательство того, кто и когда открывал шлагбаум.
    </div>
  </div>
</div>
