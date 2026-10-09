<?php
/**
 * Gate analytics.
 *
 * @var int $days
 * @var array $stats
 * @var array $byHour
 * @var array $top
 * @var array $denials
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$byType = (array) ($stats['by_type'] ?? []);
$total = max(1, (int) ($stats['total'] ?? 0));
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Статистика шлагбаума</h2>
    <p class="page-desc">За последние <?= (int) $days ?> дней. Данные берутся из неизменяемого журнала открываний.</p>
  </div>
  <div class="page-actions">
    <form method="get" class="flex items-center gap-2">
      <select class="select select-sm w-auto" name="days" data-auto-submit>
        <?php foreach ([7, 14, 30, 60, 90, 180, 365] as $value): ?>
          <option value="<?= $value ?>" <?= (int) $days === $value ? 'selected' : '' ?>><?= $value ?> дн.</option>
        <?php endforeach ?>
      </select>
      <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/gate')) ?>"><?= $this->icon('history', 'h-3.5 w-3.5') ?> Журнал</a>
    </form>
  </div>
</div>

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
  <?= $this->include('components/stat', ['label' => 'Всего событий', 'value' => (string) (int) ($stats['total'] ?? 0), 'icon' => 'activity']) ?>
  <?= $this->include('components/stat', ['label' => 'Успешных', 'value' => (string) (int) ($stats['success'] ?? 0), 'icon' => 'check-circle', 'accent' => 'success', 'hint' => round(((int) ($stats['success'] ?? 0) / $total) * 100, 1) . '% от всех']) ?>
  <?= $this->include('components/stat', ['label' => 'Отказов', 'value' => (string) (int) ($stats['denied'] ?? 0), 'icon' => 'ban', 'accent' => (int) ($stats['denied'] ?? 0) > 0 ? 'warning' : '']) ?>
  <?= $this->include('components/stat', ['label' => 'Ошибок устройства', 'value' => (string) (int) ($stats['errors'] ?? 0), 'icon' => 'wifi-off', 'accent' => (int) ($stats['errors'] ?? 0) > 0 ? 'destructive' : 'success']) ?>
</div>

<div class="grid gap-5 mt-5 lg:grid-cols-2">
  <section class="card">
    <div class="card-header">
      <div class="card-title flex items-center gap-2"><?= $this->icon('bar-chart-3', 'h-4 w-4') ?> По дням</div>
      <div class="card-desc">Интенсивность использования шлагбаума.</div>
    </div>
    <div class="card-content">
      <?= $this->include('components/chart', ['type' => 'bars', 'height' => 160, 'ariaLabel' => 'Открытия по дням',
        'data' => ['labels' => array_column($stats['by_day'] ?? [], 'label'), 'values' => array_column($stats['by_day'] ?? [], 'count')]]) ?>
    </div>
  </section>

  <section class="card">
    <div class="card-header">
      <div class="card-title flex items-center gap-2"><?= $this->icon('clock', 'h-4 w-4') ?> По часам суток</div>
      <div class="card-desc">За 14 дней. Помогает понять пиковые часы и настроить тихие часы.</div>
    </div>
    <div class="card-content">
      <?= $this->include('components/chart', ['type' => 'bars', 'height' => 160, 'ariaLabel' => 'Открытия по часам',
        'data' => ['labels' => array_column($byHour, 'label'), 'values' => array_column($byHour, 'count')]]) ?>
    </div>
  </section>

  <section class="card">
    <div class="card-header">
      <div class="card-title flex items-center gap-2"><?= $this->icon('pie-chart', 'h-4 w-4') ?> По типу доступа</div>
      <div class="card-desc">Свой доступ против гостевого и ручного открытия диспетчером.</div>
    </div>
    <div class="card-content">
      <?php if ($byType === []): ?>
        <?= $this->include('components/empty', ['title' => 'Нет данных', 'icon' => 'pie-chart']) ?>
      <?php else: ?>
        <?= $this->include('components/chart', [
          'type' => 'donut',
          'center' => (string) (int) ($stats['total'] ?? 0),
          'centerSub' => 'событий',
          'data' => ['items' => array_map(static fn (array $t): array => ['label' => $t['label'], 'value' => (int) $t['count']], $byType)],
        ]) ?>
        <div class="mt-4 table-wrap">
          <table class="table">
            <thead><tr><th>Тип доступа</th><th class="text-right">Количество</th><th class="w-40">Доля</th></tr></thead>
            <tbody>
            <?php foreach ($byType as $type): ?>
              <tr>
                <td class="text-sm"><?= $this->e($type['label']) ?> <span class="font-mono text-xs text-muted-foreground"><?= $this->e($type['type']) ?></span></td>
                <td class="table-numeric font-semibold tabular"><?= (int) $type['count'] ?></td>
                <td>
                  <div class="flex items-center gap-2">
                    <div class="progress progress-sm flex-1"><div class="progress-bar" style="width: <?= round(((int) $type['count'] / $total) * 100, 1) ?>%"></div></div>
                    <span class="w-10 text-right text-xs tabular"><?= round(((int) $type['count'] / $total) * 100, 1) ?>%</span>
                  </div>
                </td>
              </tr>
            <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
    </div>
  </section>

  <section class="card">
    <div class="card-header">
      <div class="card-title flex items-center gap-2"><?= $this->icon('trending-up', 'h-4 w-4') ?> Чаще всех открывают</div>
      <div class="card-desc">Только успешные открытия.</div>
    </div>
    <div class="card-content">
      <?php if ($top === []): ?>
        <?= $this->include('components/empty', ['title' => 'Нет данных', 'icon' => 'trending-up']) ?>
      <?php else: ?>
        <?= $this->include('components/chart', ['type' => 'ranks', 'data' => ['items' => array_map(
          static fn (array $r): array => ['label' => 'кв. ' . ($r['apartment'] ?? '—') . ' · ' . \BasIp\Support\Str::initials((string) ($r['full_name'] ?? ''), 1), 'value' => (int) $r['cnt']],
          $top
        )]]) ?>
      <?php endif ?>
    </div>
  </section>
</div>

<?php if ($denials !== []): ?>
  <section class="card mt-5">
    <div class="card-header">
      <div class="card-title flex items-center gap-2"><?= $this->icon('alert-triangle', 'h-4 w-4 text-warning') ?> Причины отказов</div>
      <div class="card-desc">Почему шлагбаум не открылся. Полезно для ответов на вопросы жителей.</div>
    </div>
    <div class="card-content p-0">
      <div class="table-wrap rounded-none border-0">
        <table class="table">
          <thead><tr><th>Причина</th><th class="text-right">Количество</th><th class="w-48">Доля отказов</th></tr></thead>
          <tbody>
          <?php $deniedTotal = max(1, array_sum(array_map(static fn (array $d): int => (int) $d['cnt'], $denials))); ?>
          <?php foreach ($denials as $denial): ?>
            <tr>
              <td class="text-sm"><?= $this->e((string) $denial['reason']) ?></td>
              <td class="table-numeric font-semibold tabular"><?= (int) $denial['cnt'] ?></td>
              <td>
                <div class="flex items-center gap-2">
                  <div class="progress progress-sm progress-warning flex-1"><div class="progress-bar" style="width: <?= round(((int) $denial['cnt'] / $deniedTotal) * 100, 1) ?>%"></div></div>
                  <span class="w-10 text-right text-xs tabular"><?= round(((int) $denial['cnt'] / $deniedTotal) * 100) ?>%</span>
                </div>
              </td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
<?php endif ?>
