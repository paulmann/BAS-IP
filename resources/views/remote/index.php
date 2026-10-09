<?php
/**
 * The resident gate remote — a single enormous OPEN button plus the live
 * camera of the selected gate. Designed for one-handed use from a car:
 * the control area occupies ~95% of the viewport, targets are huge, there
 * is no navigation to miss-tap.
 *
 * @var BasIp\Auth\User $user
 * @var array<int, BasIp\Domain\Model\Device> $devices
 * @var array<int, array<string, mixed>> $cameras
 * @var bool $canClose
 * @var int $cooldown
 * @var int $cameraRefresh
 * @var string $organisation
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$cameras = $cameras ?? [];
?>
<?php $this->layout('layouts/remote') ?>

<div class="flex h-[100dvh] flex-col" data-remote
     data-open-url="<?= $this->e($this->url('/panel/open')) ?>"
     data-close-url="<?= $this->e($this->url('/panel/close')) ?>"
     data-status-url="<?= $this->e($this->url('/panel/status')) ?>"
     data-cameras='<?= $this->e(json_encode(array_values($cameras), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS)) ?>'
     data-cooldown="<?= (int) $cooldown ?>">

  <!-- ── компактная верхняя панель (~5%) ─────────────────────────── -->
  <header class="flex shrink-0 items-center gap-2 border-b bg-card/60 px-3 py-2 backdrop-blur" style="min-height:5dvh">
    <span class="health-dot" data-remote-status-dot aria-hidden="true"></span>
    <div class="min-w-0 flex-1">
      <div class="truncate text-sm font-semibold leading-tight"><?= $this->e($organisation) ?></div>
      <div class="truncate text-[11px] text-muted-foreground" data-remote-status-text>подключение…</div>
    </div>

    <?php if (count($cameras) > 1): ?>
      <div class="flex max-w-[38vw] gap-1 overflow-x-auto" role="tablist" aria-label="Камеры">
        <?php foreach ($cameras as $i => $camera): ?>
          <button type="button" role="tab"
                  class="chip shrink-0 rounded-full border px-2.5 py-1 text-[11px] font-medium transition data-[state=active]:border-primary data-[state=active]:bg-primary data-[state=active]:text-primary-foreground"
                  data-camera-chip="<?= $this->e((string) $camera['device']) ?>"
                  data-state="<?= $i === 0 ? 'active' : 'inactive' ?>">
            <?= $this->icon('cctv', 'h-3 w-3') ?> <span class="hidden xs:inline"><?= $this->e((string) $camera['name']) ?></span><span class="xs:hidden">№<?= $i + 1 ?></span>
          </button>
        <?php endforeach ?>
      </div>
    <?php endif ?>

    <button type="button" class="btn btn-ghost btn-sm btn-icon" data-theme-toggle="cycle" aria-label="Сменить тему"><?= $this->icon('sun', 'h-4 w-4') ?></button>
    <a class="btn btn-ghost btn-sm btn-icon" href="<?= $this->e($this->url($user->isResident() ? '/cabinet' : '/panel')) ?>" aria-label="Кабинет"><?= $this->icon('layout-dashboard', 'h-4 w-4') ?></a>
    <a class="btn btn-ghost btn-sm btn-icon" href="<?= $this->e($this->url('/logout')) ?>" aria-label="Выйти"
       onclick="return confirm('Выйти из пульта?')"><?= $this->icon('log-out', 'h-4 w-4') ?></a>
  </header>

  <!-- ── видео выбранного шлагбаума ──────────────────────────────── -->
  <div class="relative shrink-0 overflow-hidden bg-zinc-950 <?= $cameras === [] ? 'hidden' : '' ?>"
       data-camera-stage style="height:<?= $cameras === [] ? 0 : 30 ?>dvh" aria-label="Видео с камеры шлагбаума">
    <img alt="Камера шлагбаума" class="h-full w-full object-cover" data-camera-img
         src="<?= $cameras !== [] ? $this->e((string) ($cameras[0]['stream_url'] ?? ($cameras[0]['frame_url'] . '&t=0'))) : '' ?>"
         referrerpolicy="no-referrer">
    <span class="camera-live" data-camera-live hidden>live</span>
    <div class="absolute inset-0 hidden items-center justify-center bg-zinc-950/85 p-4 text-center text-sm text-zinc-300" data-camera-offline>
      <?= $this->icon('video-off', 'h-6 w-6') ?>
      <span class="mt-1 block">Камера недоступна — открытие работает и без видео.</span>
    </div>
  </div>

  <!-- ── ГЛАВНАЯ КНОПКА: ~95% экрана по ширине, доминирует по высоте ── -->
  <main class="flex min-h-0 flex-1 flex-col gap-2 p-2" style="flex-basis:<?= $cameras === [] ? '95' : '65' ?>%">
    <button type="button" data-open-gate
            class="group relative flex w-full min-h-0 flex-1 select-none flex-col items-center justify-center gap-2 rounded-3xl bg-success text-white shadow-xl transition active:scale-[0.985] active:brightness-95 disabled:cursor-not-allowed disabled:opacity-55"
            style="font-size:clamp(2.5rem,13vw,7rem);line-height:1;-webkit-tap-highlight-color:transparent;touch-action:manipulation"
            aria-label="Открыть шлагбаум">
      <?= $this->icon('door-open', 'h-[0.55em] w-[0.55em] opacity-90', 'Открыть шлагбаум') ?>
      <span class="font-extrabold tracking-wide" data-open-label>ОТКРЫТЬ</span>
      <span class="text-[clamp(0.75rem,3.2vw,1.1rem)] font-medium opacity-85" data-open-hint>
        <?= $this->e($cameras !== [] ? (string) $cameras[0]['name'] : 'шлагбаум') ?>
      </span>
      <span class="absolute inset-x-0 bottom-3 text-center text-[clamp(0.7rem,2.8vw,1rem)] font-semibold opacity-0 transition" data-open-countdown></span>
    </button>

    <?php if ($canClose): ?>
      <button type="button" data-close-gate
              class="btn btn-outline w-full shrink-0 py-5 text-lg font-semibold"
              style="min-height:9dvh;-webkit-tap-highlight-color:transparent">
        <?= $this->icon('door-closed', 'h-5 w-5') ?> Закрыть
      </button>
    <?php endif ?>
  </main>

  <!-- ── полноэкранный отклик на действие ────────────────────────── -->
  <div class="fixed inset-0 z-modal hidden flex-col items-center justify-center gap-3 p-6 text-center" data-remote-overlay aria-live="assertive">
    <span data-overlay-icon></span>
    <span class="text-2xl font-bold" data-overlay-text></span>
    <span class="max-w-md text-sm opacity-80" data-overlay-note></span>
  </div>
</div>
