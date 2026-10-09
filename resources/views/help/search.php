<?php
/**
 * Standalone search results (also used by the JSON endpoint).
 *
 * @var string $q
 * @var array<int, array<string, mixed>> $results
 * @var array<int, array<string, mixed>> $sections
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Поиск: «<?= $this->e($q) ?>»</h2>
    <p class="page-desc">Найдено разделов: <?= count($results) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/help')) ?>"><?= $this->icon('arrow-left', 'h-3.5 w-3.5') ?> К справке</a>
  </div>
</div>

<?php if ($results === []): ?>
  <div class="card">
    <?= $this->include('components/empty', [
      'title' => 'Ничего не найдено',
      'description' => 'Попробуйте более короткое слово или посмотрите список всех разделов.',
      'icon' => 'search-x',
      'actionLabel' => 'Все разделы',
      'actionUrl' => '/help',
    ]) ?>
  </div>
<?php else: ?>
  <div class="grid gap-3">
    <?php foreach ($results as $result): ?>
      <a class="card card-hover block p-4" href="<?= $this->e($this->url('/help/' . rawurlencode((string) $result['slug']))) ?>">
        <div class="flex items-start gap-3">
          <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><?= $this->icon((string) ($result['icon'] ?? 'book-open'), 'h-4 w-4') ?></span>
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-baseline gap-2">
              <span class="font-medium"><?= $this->e((string) $result['title']) ?></span>
              <span class="badge badge-muted text-[10px]"><?= $this->e((string) $result['group']) ?></span>
            </div>
            <?php if (!empty($result['summary'])): ?><p class="mt-0.5 text-sm text-muted-foreground"><?= $this->e((string) $result['summary']) ?></p><?php endif ?>
            <?php if (!empty($result['excerpt'])): ?><p class="mt-1.5 text-xs leading-relaxed text-muted-foreground"><?= $result['excerpt'] ?></p><?php endif ?>
          </div>
        </div>
      </a>
    <?php endforeach ?>
  </div>
<?php endif ?>
