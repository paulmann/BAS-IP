<?php
/**
 * Resident: personal gate-opening history.
 *
 * @var BasIp\Support\Paginator|null $events
 * @var array $query
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$events = $events ?? null;
$query = $query ?? [];
$items = $events !== null ? $events->items() : [];
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div>
    <h2 class="page-title">История открытий</h2>
    <p class="page-desc">Кто и когда открывал шлагбаум по вашему доступу — включая гостевые пропуска.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline" href="<?= $this->e($this->url('/cabinet')) ?>"><?= $this->icon('arrow-left') ?> В кабинет</a>
  </div>
</div>

<div class="card">
  <div class="card-content p-0">
    <?php if ($items === []): ?>
      <?= $this->include('components/empty', [
        'title' => 'Событий пока нет',
        'description' => 'Как только шлагбаум откроют по вашему доступу или гостевому пропуску — запись появится здесь.',
        'icon' => 'history',
      ]) ?>
    <?php else: ?>
      <div class="table-wrap rounded-none border-0">
        <table class="table">
          <thead>
            <tr><th>Когда</th><th>Доступ</th><th>Кто</th><th>Источник</th><th>Результат</th><th>Комментарий</th></tr>
          </thead>
          <tbody>
          <?php foreach ($items as $event): ?>
            <tr>
              <td class="whitespace-nowrap text-xs tabular">
                <div><?= $this->e($event->when('d.m.Y')) ?></div>
                <div class="text-muted-foreground"><?= $this->e($event->when('H:i:s')) ?></div>
              </td>
              <td><span class="badge badge-soft-<?= $event->accessTone() === 'muted' ? 'warning' : $event->accessTone() ?>"><?= $this->e($event->accessLabel()) ?></span></td>
              <td class="max-w-[14rem] truncate text-sm"><?= $this->e($event->actorLabel()) ?></td>
              <td class="text-xs text-muted-foreground"><?= $this->e($event->sourceLabel()) ?></td>
              <td>
                <span class="badge badge-soft-<?= $event->resultTone() === 'muted' ? 'warning' : $event->resultTone() ?>">
                  <?= $this->icon($event->result === 'success' ? 'check' : 'ban', 'h-3 w-3') ?> <?= $this->e($event->resultLabel()) ?>
                </span>
              </td>
              <td class="max-w-[18rem] truncate text-xs text-muted-foreground"><?= $this->e($event->reason ?? '') ?></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <div class="p-4"><?= $this->include('components/pagination', ['paginator' => $events, 'query' => $query, 'target' => '/cabinet/log']) ?></div>
    <?php endif ?>
  </div>
</div>
