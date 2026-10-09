<?php
/**
 * Log files index.
 *
 * @var array $files
 * @var array<int, string> $channels
 * @var array<int, string> $levels
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Журналы</h2>
    <p class="page-desc">storage/logs · формат JSON Lines. Отдельный файл на канал: приложение, аудит, шлагбаум, устройства, уведомления, ошибки.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/system')) ?>"><?= $this->icon('arrow-left', 'h-3.5 w-3.5') ?> К системе</a>
  </div>
</div>

<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
  <?php foreach ($channels as $channel): ?>
    <?php
      $file = null;
      foreach ($files as $candidate) {
          if ((string) ($candidate['name'] ?? '') === $channel . '.log') { $file = $candidate; break; }
      }
    ?>
    <a class="card card-hover block" href="<?= $this->e($this->url('/admin/system/logs/' . rawurlencode($channel))) ?>">
      <div class="card-header has-action">
        <div>
          <div class="card-title flex items-center gap-2">
            <?= $this->icon($this->channelIcon($channel), 'h-4 w-4') ?>
            <?= $this->e($this->channelLabel($channel)) ?>
          </div>
          <div class="card-desc font-mono text-xs"><?= $this->e($channel) ?>.log</div>
        </div>
        <?= $this->icon('chevron-right', 'h-4 w-4 text-muted-foreground') ?>
      </div>
      <div class="card-content grid gap-1 text-xs text-muted-foreground">
        <div class="flex justify-between gap-2"><span>Размер</span><b class="tabular text-foreground"><?= $file !== null ? $this->e(\BasIp\Support\Str::bytes((int) $file['size'])) : 'нет файла' ?></b></div>
        <div class="flex justify-between gap-2"><span>Строк</span><b class="tabular text-foreground"><?= $file !== null ? number_format((int) $file['lines'], 0, ',', ' ') : '0' ?></b></div>
        <div class="flex justify-between gap-2"><span>Изменён</span><b class="tabular text-foreground"><?= $file !== null ? $this->e($this->datetime((string) $file['mtime'], 'd.m H:i')) : '—' ?></b></div>
      </div>
    </a>
  <?php endforeach ?>
</div>

<div class="card mt-5">
  <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('terminal', 'h-4 w-4') ?> Журналы из консоли</div></div>
  <div class="card-content">
    <pre class="code-block"><code>php bin/bas-ip logs:list                     # размеры всех журналов
php bin/bas-ip logs:tail gate --lines=200    # последние 200 строк журнала шлагбаума
php bin/bas-ip logs:tail error --level=error # только ошибки
php bin/bas-ip logs:tail app --follow        # следить в реальном времени
php bin/bas-ip logs:list --truncate=audit    # очистить журнал аудита</code></pre>
  </div>
</div>
