<?php
/**
 * Public guest pass page — the screen a guest sees after tapping the link.
 *
 * @var BasIp\Domain\Model\GuestPass $pass
 * @var BasIp\Domain\Model\Resident|null $resident
 * @var bool $valid
 * @var string|null $reason
 * @var int|null $opensLeft
 * @var string $token
 * @var string $organisation
 * @var string $supportPhone
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$openUrl = $this->url('/guest/' . rawurlencode((string) ($token ?? '')) . '/open');
?>
<?php $this->layout('layouts/guest') ?>

<div class="grid gap-4">

  <div class="card overflow-hidden">
    <div class="<?= $valid ? 'bg-success/10 text-success' : 'bg-muted text-muted-foreground' ?> flex items-center gap-3 px-5 py-4">
      <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-background/70">
        <?= $this->icon($valid ? 'ticket' : 'ban', 'h-5 w-5 text-xl') ?>
      </span>
      <div class="min-w-0">
        <div class="text-sm font-semibold"><?= $valid ? 'Гостевой доступ активен' : 'Гостевой доступ не действует' ?></div>
        <div class="truncate text-xs opacity-80"><?= $this->e($organisation ?: 'Шлагбаум') ?></div>
      </div>
      <?php if ($valid): ?>
        <span class="badge badge-soft-success ml-auto shrink-0"><?= $this->icon('check-circle', 'h-3 w-3') ?> действителен</span>
      <?php endif ?>
    </div>

    <div class="card-content grid gap-4 p-5">

      <?php if (!$valid): ?>
        <div class="alert alert-destructive">
          <?= $this->icon('alert-circle', 'h-4 w-4') ?>
          <div>
            <div class="alert-title">Открыть нельзя</div>
            <div class="alert-desc"><?= $this->e($reason ?? 'Ссылка недействительна.') ?></div>
          </div>
        </div>
        <?php if (!empty($supportPhone)): ?>
          <a class="btn btn-outline btn-block" href="tel:<?= $this->e(preg_replace('/[^\d+]/', '', $supportPhone)) ?>">
            <?= $this->icon('phone', 'h-4 w-4') ?> Позвонить управляющему
          </a>
        <?php endif ?>
        <a class="btn btn-ghost btn-block text-muted-foreground" href="<?= $this->e($this->url('/guest')) ?>">
          <?= $this->icon('hash', 'h-4 w-4') ?> Ввести код вручную
        </a>
      <?php else: ?>

        <?php /* Главная кнопка — крупная, под большой палец */ ?>
        <button type="button" class="btn btn-gate" data-guest-open="<?= $this->e($openUrl) ?>" data-guest-cooldown="5">
          <?= $this->icon('door-open', 'h-12 w-12') ?>
          <span>Открыть шлагбаум</span>
          <span class="text-xs font-normal opacity-85">Нажмите один раз и дождитесь подъёма стрелы</span>
        </button>

        <p class="text-center text-sm font-medium" data-guest-status aria-live="polite">Готовы к открытию</p>

        <div class="gate-visual h-28" aria-hidden="true">
          <div class="gate-bar" data-gate-bar></div>
          <div class="gate-post"></div>
        </div>

        <?php /* Данные пропуска */ ?>
        <dl class="grid gap-2.5 rounded-xl border bg-muted/25 p-4 text-sm">
          <?php if ($pass->guestName !== null): ?>
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Гость</dt><dd class="text-right font-medium"><?= $this->e($pass->guestName) ?></dd></div>
          <?php endif ?>
          <?php if ($pass->carPlate !== null): ?>
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Автомобиль</dt>
              <dd class="text-right font-mono font-medium"><?= $this->e($pass->displayPlate()) ?></dd></div>
          <?php endif ?>
          <?php if ($pass->purpose !== null): ?>
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Цель</dt><dd class="text-right"><?= $this->e($pass->purpose) ?></dd></div>
          <?php endif ?>
          <?php if ($resident !== null): ?>
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">К кому</dt>
              <dd class="text-right font-medium"><?= $this->e($resident->shortName()) ?><?= $resident->apartment !== '' ? ' · кв. ' . $this->e($resident->apartment) : '' ?></dd></div>
          <?php endif ?>
          <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Действует до</dt>
            <dd class="text-right font-medium tabular"><?= $this->e($this->datetime($pass->validUntil, 'd.m.Y H:i')) ?></dd></div>
          <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Осталось</dt>
            <dd class="text-right font-medium tabular" data-countdown="<?= $this->e((string) $pass->validUntil) ?>"></dd></div>
          <div class="h-1.5 overflow-hidden rounded-full bg-muted">
            <div class="h-full rounded-full bg-success transition-[width] duration-700" style="width: <?= 100 - (float) $pass->percentElapsed() ?>%"></div>
          </div>
          <?php if ($opensLeft !== null): ?>
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Открытий осталось</dt>
              <dd class="text-right font-medium tabular" data-guest-uses-left><?= (int) $opensLeft ?></dd></div>
          <?php endif ?>
          <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Использований</dt>
            <dd class="text-right font-medium tabular"><?= $this->e($pass->usesLabel()) ?></dd></div>
        </dl>

        <?php /* Код и PIN — на случай, если ссылка не открылась */ ?>
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="rounded-xl border p-4 text-center">
            <div class="text-xs uppercase tracking-wide text-muted-foreground">Код доступа</div>
            <div class="mt-1 font-mono text-2xl font-semibold tracking-wider"><?= $this->e($pass->code) ?></div>
            <button type="button" class="btn btn-ghost btn-sm mt-2" data-copy="<?= $this->e($pass->code) ?>" data-copy-message="Код скопирован">
              <?= $this->icon('copy', 'h-3.5 w-3.5') ?> Скопировать
            </button>
          </div>
          <?php if ($pass->pin !== null): ?>
            <div class="rounded-xl border p-4 text-center">
              <div class="text-xs uppercase tracking-wide text-muted-foreground">PIN для клавиатуры</div>
              <div class="mt-1 font-mono text-2xl font-semibold tabular"><?= $this->e($pass->pin) ?></div>
              <button type="button" class="btn btn-ghost btn-sm mt-2" data-copy="<?= $this->e($pass->pin) ?>" data-copy-message="PIN скопирован">
                <?= $this->icon('copy', 'h-3.5 w-3.5') ?> Скопировать
              </button>
            </div>
          <?php endif ?>
        </div>

        <div class="rounded-xl border bg-muted/25 p-4">
          <div class="flex items-center gap-2 text-sm font-medium">
            <?= $this->icon('qr-code', 'h-4 w-4') ?> QR-код этой страницы
          </div>
          <p class="mt-1 text-xs text-muted-foreground">Покажите охраннику или распечатайте — работает без интернета на телефоне.</p>
          <div class="qr-frame mx-auto mt-3 w-40" data-qr="<?= $this->e($this->url('/guest/' . rawurlencode((string) ($token ?? '')))) ?>" data-qr-size="160"></div>
        </div>

        <div class="grid gap-2 sm:grid-cols-2">
          <button type="button" class="btn btn-outline" data-copy="<?= $this->e((string) ($pass->link ?? '')) ?>" data-copy-message="Ссылка скопирована">
            <?= $this->icon('link', 'h-4 w-4') ?> Скопировать ссылку
          </button>
          <?php if (!empty($supportPhone)): ?>
            <a class="btn btn-outline" href="tel:<?= $this->e(preg_replace('/[^\d+]/', '', $supportPhone)) ?>">
              <?= $this->icon('phone', 'h-4 w-4') ?> Позвонить
            </a>
          <?php endif ?>
        </div>

        <div class="alert alert-muted">
          <?= $this->icon('info', 'h-4 w-4') ?>
          <div class="alert-desc">
            Каждое открытие по этой ссылке записывается в журнал с временем, IP и номером автомобиля.
            Ссылку можно отозвать в любой момент — из личного кабинета жителя или у диспетчера.
          </div>
        </div>
      <?php endif ?>
    </div>
  </div>
</div>
