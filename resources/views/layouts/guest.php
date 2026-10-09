<?php
/**
 * Public layout for the guest-access pages (`/guest/<token>`).
 *
 * Deliberately minimal and fast: a guest opens it on a phone, standing at the
 * barrier, possibly on a slow mobile connection. No sidebar, no auth chrome.
 *
 * @var string $title
 * @var string $content
 */
declare(strict_types=1);

/** @var BasIp\Core\View $this */
$nonce = $this->nonce();
$appName = $this->shared['appName'] ?? 'BAS-IP';
$version = $this->shared['version'] ?? '2.0.0';
$basePath = $this->shared['basePath'] ?? '';
$theme = $this->shared['theme'] ?? 'system';
$initialDark = $theme === 'dark' || ($theme === 'system' && (date('G') >= 21 || date('G') < 6));
?>
<!doctype html>
<html lang="ru" class="<?= $initialDark ? 'dark' : '' ?>" data-theme="<?= $this->e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="<?= $initialDark ? '#09090b' : '#ffffff' ?>">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="csrf-token" content="<?= $this->e($this->csrfToken()) ?>">
<title><?= $this->e(($title ?? 'Гостевой доступ') . ' · ' . $appName) ?></title>
<link rel="icon" href="<?= $this->e($this->asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= $this->e($this->asset('img/apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="<?= $this->e($this->asset('css/app.css')) ?>">
<script nonce="<?= $this->e($nonce) ?>">
window.__BASIP = { theme: <?= $this->json($theme) ?>, basePath: <?= $this->json($basePath) ?>, version: <?= $this->json($version) ?>,
  iconBase: <?= $this->json($this->asset('icons/lucide.svg')) ?>, currency: '₽' };
</script>
<script src="<?= $this->e($this->asset('js/theme.js')) ?>" nonce="<?= $this->e($nonce) ?>"></script>
</head>
<body class="min-h-[100dvh] bg-background font-sans text-foreground antialiased">
<div class="mx-auto flex min-h-[100dvh] w-full max-w-lg flex-col px-4 py-6 pb-[calc(1.5rem+env(safe-area-inset-bottom))]">

  <header class="mb-6 flex items-center gap-3">
    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary text-primary-foreground">
      <?= $this->icon('door-open', 'h-5 w-5') ?>
    </span>
    <div class="min-w-0 flex-1">
      <div class="truncate text-sm font-semibold"><?= $this->e($appName) ?></div>
      <div class="text-xs text-muted-foreground">Гостевой доступ к шлагбауму</div>
    </div>
    <button type="button" class="btn btn-ghost btn-icon btn-sm" data-theme-toggle="cycle" aria-label="Переключить тему">
      <?= $this->icon('sun', 'h-4 w-4 dark:hidden') ?>
      <?= $this->icon('moon', 'h-4 w-4 hidden dark:block') ?>
    </button>
  </header>

  <main class="flex-1"><?= $content ?></main>

  <footer class="mt-8 text-center text-xs text-muted-foreground">
    Все открытия шлагбаума записываются в журнал.
    <br><a class="underline underline-offset-4 hover:text-foreground" href="<?= $this->e($this->url('/login')) ?>">Вход для жителей и диспетчера</a>
  </footer>
</div>

<div class="toast-viewport" role="region" aria-label="Уведомления" aria-live="polite"></div>
<script type="application/json" id="basip-flash"><?= $this->json($this->flashPayload($this->shared['flash'] ?? [], $this->shared['errors'] ?? [])) ?></script>
<script src="<?= $this->e($this->asset('js/app.js')) ?>" nonce="<?= $this->e($nonce) ?>"></script>
<script src="<?= $this->e($this->asset('js/vendor/qrcode.js')) ?>" nonce="<?= $this->e($nonce) ?>"></script>
<script src="<?= $this->e($this->asset('js/guest.js')) ?>" nonce="<?= $this->e($nonce) ?>"></script>
<?= $this->stack('scripts') ?>
</body>
</html>
