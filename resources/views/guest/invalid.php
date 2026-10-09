<?php
/**
 * Guest link is unknown, expired or revoked.
 *
 * @var string $reason
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$supportPhone = $this->setting('ui.support_phone', '');
?>
<?php $this->layout('layouts/guest') ?>

<div class="card">
  <div class="card-content grid gap-4 p-6 text-center">
    <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-destructive/12 text-destructive">
      <?= $this->icon('ban', 'h-7 w-7 text-3xl') ?>
    </span>
    <div>
      <h2 class="text-lg font-semibold tracking-tight">Ссылка недействительна</h2>
      <p class="mt-1 text-sm text-muted-foreground"><?= $this->e($reason ?? 'Гостевой доступ не найден.') ?></p>
    </div>

    <div class="alert alert-muted text-left">
      <?= $this->icon('info', 'h-4 w-4') ?>
      <div class="alert-desc">
        Возможные причины: истёк срок действия, доступ отозвали, или ссылка скопирована не полностью.
        Попросите жителя создать новый пропуск — это занимает несколько секунд.
      </div>
    </div>

    <div class="grid gap-2">
      <a class="btn btn-default btn-block" href="<?= $this->e($this->url('/guest')) ?>">
        <?= $this->icon('hash', 'h-4 w-4') ?> Открыть по коду
      </a>
      <?php if (!empty($supportPhone)): ?>
        <a class="btn btn-outline btn-block" href="tel:<?= $this->e(preg_replace('/[^\d+]/', '', (string) $supportPhone)) ?>">
          <?= $this->icon('phone', 'h-4 w-4') ?> Позвонить управляющему
        </a>
      <?php endif ?>
    </div>
  </div>
</div>
