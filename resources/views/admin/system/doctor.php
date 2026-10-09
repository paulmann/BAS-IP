<?php
/**
 * Self-diagnostic report.
 *
 * @var array<int, array{level: string, area: string, message: string, hint?: string}> $issues
 * @var array $counts
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$groups = [];
foreach ($issues as $issue) {
    $groups[(string) ($issue['area'] ?? 'прочее')][] = $issue;
}
ksort($groups);
$icons = ['ok' => 'check-circle', 'info' => 'info', 'warn' => 'alert-triangle', 'error' => 'alert-circle'];
$tones = ['ok' => 'success', 'info' => 'info', 'warn' => 'warning', 'error' => 'destructive'];
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Диагностика</h2>
    <p class="page-desc">
      Проверено <?= count($issues) ?> пунктов:
      <b class="text-destructive"><?= (int) ($counts['error'] ?? 0) ?> ошибок</b>,
      <b class="text-warning"><?= (int) ($counts['warn'] ?? 0) ?> предупреждений</b>,
      <b class="text-info"><?= (int) ($counts['info'] ?? 0) ?> замечаний</b>.
    </p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/system/doctor?format=json')) ?>" target="_blank" rel="noopener"><?= $this->icon('braces', 'h-3.5 w-3.5') ?> JSON</a>
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/system')) ?>"><?= $this->icon('arrow-left', 'h-3.5 w-3.5') ?> К системе</a>
  </div>
</div>

<?php if ((int) ($counts['error'] ?? 0) === 0 && (int) ($counts['warn'] ?? 0) === 0): ?>
  <div class="alert alert-success mb-5">
    <?= $this->icon('check-circle', 'h-4 w-4') ?>
    <div>
      <div class="alert-title">Система в порядке</div>
      <div class="alert-desc">Критичных проблем и предупреждений не обнаружено. Ниже — справочные замечания.</div>
    </div>
  </div>
<?php endif ?>

<div class="grid gap-5 xl:grid-cols-2">
  <?php foreach ($groups as $area => $items): ?>
    <section class="card">
      <div class="card-header">
        <div class="card-title flex items-center gap-2">
          <?= $this->icon($this->areaIcon((string) $area), 'h-4 w-4') ?>
          <?= $this->e($this->areaLabel((string) $area)) ?>
          <span class="badge badge-muted"><?= count($items) ?></span>
        </div>
      </div>
      <div class="card-content grid gap-2.5">
        <?php foreach ($items as $issue): ?>
          <?php $level = (string) ($issue['level'] ?? 'info'); ?>
          <div class="alert alert-<?= $tones[$level] ?? 'muted' ?>">
            <?= $this->icon($icons[$level] ?? 'info', 'h-4 w-4') ?>
            <div>
              <div class="alert-desc"><?= $this->e((string) $issue['message']) ?></div>
              <?php if (!empty($issue['hint'])): ?>
                <div class="mt-1 rounded bg-background/60 px-2 py-1 font-mono text-[11px]"><?= $this->e((string) $issue['hint']) ?></div>
              <?php endif ?>
            </div>
          </div>
        <?php endforeach ?>
      </div>
    </section>
  <?php endforeach ?>
</div>

<div class="card mt-5">
  <div class="card-header">
    <div class="card-title flex items-center gap-2"><?= $this->icon('terminal', 'h-4 w-4') ?> То же самое из консоли</div>
    <div class="card-desc">Удобно для быстрой проверки после обновления или переезда на новый сервер.</div>
  </div>
  <div class="card-content grid gap-2">
    <pre class="code-block"><code>php bin/bas-ip doctor          # этот отчёт в терминале
php bin/bas-ip doctor --json   # для скриптов и мониторинга
php bin/bas-ip doctor --fix    # создать недостающие каталоги storage
php bin/bas-ip health --deep   # быстрая проверка (код возврата 0 = OK)
php bin/bas-ip gate:test       # проверить связь с каждой панелью</code></pre>
  </div>
</div>
