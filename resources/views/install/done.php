<?php
/**
 * Installer success screen.
 *
 * @var array<int, string> $log
 * @var string $admin
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<?php $this->layout('layouts/auth') ?>

<div class="grid gap-5 text-center">
  <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-success/12 text-success">
    <?= $this->icon('check-circle', 'h-9 w-9 text-4xl') ?>
  </span>
  <div>
    <h2 class="text-xl font-semibold tracking-tight">Установка завершена</h2>
    <p class="mt-1 text-sm text-muted-foreground">Система готова к работе. Осталось направить веб-сервер на каталог <code class="inline-code">public/</code>.</p>
  </div>

  <div class="rounded-lg border bg-muted/30 p-4 text-left">
    <div class="label mb-2">Выполнено</div>
    <ol class="grid gap-1.5 text-sm text-muted-foreground">
      <?php foreach ($log as $line): ?>
        <li class="flex gap-2"><span class="text-success">✔</span><span><?= $this->e($line) ?></span></li>
      <?php endforeach ?>
    </ol>
  </div>

  <div class="grid gap-2 text-left">
    <div class="label">Дальше</div>
    <ol class="grid gap-2 text-sm">
      <?php foreach ([
        ['Войти как ' . ($admin ?? 'admin'), '/login', 'log-in'],
        ['Включить двухфакторную аутентификацию', '/profile?tab=security', 'shield-check'],
        ['Добавить жителей (импорт из CSV)', '/admin/data', 'upload'],
        ['Проверить связь с панелью', '/admin/gate/devices', 'cpu'],
        ['Пройти самодиагностику', '/admin/system/doctor', 'stethoscope'],
        ['Настроить cron и резервные копии', '/help/cron', 'calendar-check'],
      ] as [$label, $path, $icon]): ?>
        <li>
          <a class="flex items-center gap-2.5 rounded-lg border p-3 transition hover:border-primary/50 hover:bg-accent/40" href="<?= $this->e($this->url($path)) ?>">
            <?= $this->icon($icon, 'h-4 w-4 text-muted-foreground') ?>
            <span class="flex-1"><?= $this->e($label) ?></span>
            <?= $this->icon('chevron-right', 'h-4 w-4 text-muted-foreground') ?>
          </a>
        </li>
      <?php endforeach ?>
    </ol>
  </div>

  <a class="btn btn-success btn-lg btn-block" href="<?= $this->e($this->url('/login')) ?>">
    <?= $this->icon('log-in') ?> Перейти к входу
  </a>
</div>
