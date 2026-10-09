<?php
/**
 * Minimal, centred layout for login / 2FA / password recovery / installer.
 * No sidebar, no session-dependent chrome — it must work even when the
 * database is unreachable (the installer and the health page rely on it).
 *
 * @var string $title
 * @var string $content
 */
declare(strict_types=1);

/** @var BasIp\Core\View $this */
$theme     = $this->shared['theme'] ?? 'system';
$nonce     = $this->nonce();
$appName   = $this->shared['appName'] ?? 'BAS-IP';
$version   = $this->shared['version'] ?? '2.0.0';
$basePath  = $this->shared['basePath'] ?? '';
$initialDark = $theme === 'dark' || ($theme === 'system' && (date('G') >= 21 || date('G') < 6));
$cookieTheme = $_COOKIE['basip_theme'] ?? null;
if (is_string($cookieTheme) && in_array($cookieTheme, ['light', 'dark'], true)) {
    $initialDark = $cookieTheme === 'dark';
}
$publicSettings = $this->shared['publicSettings'] ?? [];
?>
<!doctype html>
<html lang="<?= $this->e($this->shared['locale'] ?? 'ru') ?>" class="<?= $initialDark ? 'dark' : '' ?>" data-theme="<?= $this->e($theme) ?>" data-accent="<?= $this->e($publicSettings['ui.accent'] ?? 'slate') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="<?= $initialDark ? '#09090b' : '#ffffff' ?>">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= $this->e($this->csrfToken()) ?>">
<title><?= $this->e(($title ?? '') . ' · ' . $appName) ?></title>
<link rel="icon" href="<?= $this->e($this->asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= $this->e($this->asset('css/app.css')) ?>">
<script nonce="<?= $this->e($nonce) ?>">
window.__BASIP = { theme: <?= $this->json($theme) ?>, basePath: <?= $this->json($basePath) ?>, version: <?= $this->json($version) ?>,
  iconBase: <?= $this->json($this->asset('icons/lucide.svg')) ?>, currency: '₽' };
</script>
<script src="<?= $this->e($this->asset('js/theme.js')) ?>" nonce="<?= $this->e($nonce) ?>"></script>
</head>
<body class="min-h-[100dvh] bg-background font-sans text-foreground antialiased">

<div class="relative flex min-h-[100dvh] flex-col items-center justify-center overflow-hidden px-4 py-8">
  <div class="grid-bg pointer-events-none absolute inset-0 opacity-45" aria-hidden="true"></div>
  <div class="pointer-events-none absolute -left-24 -top-24 h-72 w-72 rounded-full bg-primary/8 blur-3xl" aria-hidden="true"></div>
  <div class="pointer-events-none absolute -bottom-32 -right-24 h-80 w-80 rounded-full bg-info/8 blur-3xl" aria-hidden="true"></div>

  <div class="relative w-full max-w-md">
    <div class="mb-6 flex flex-col items-center gap-3 text-center">
      <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-lg">
        <?= $this->icon('door-open', 'h-6 w-6 text-2xl') ?>
      </span>
      <div>
        <h1 class="text-lg font-semibold tracking-tight"><?= $this->e($appName) ?></h1>
        <p class="text-sm text-muted-foreground">Управление шлагбаумом, доступами и оплатами</p>
      </div>
    </div>

    <div class="card p-5 shadow-lg sm:p-6">
      <?= $content ?>
    </div>

    <div class="mt-5 flex items-center justify-between gap-3 text-xs text-muted-foreground">
      <span>v<?= $this->e($version) ?></span>
      <div class="flex items-center gap-2">
        <button type="button" class="btn btn-ghost btn-sm btn-icon" data-theme-toggle="cycle" aria-label="Переключить тему" title="Тема (Shift+T)">
          <?= $this->icon('sun', 'h-4 w-4 dark:hidden') ?>
          <?= $this->icon('moon', 'h-4 w-4 hidden dark:block') ?>
        </button>
        <a class="btn btn-ghost btn-sm btn-icon" href="<?= $this->e($this->url('/health')) ?>" aria-label="Состояние системы" title="Состояние системы">
          <?= $this->icon('activity', 'h-4 w-4') ?>
        </a>
        <a class="btn btn-ghost btn-sm btn-icon" href="<?= $this->e($this->url('/help')) ?>" aria-label="Справка" title="Справка">
          <?= $this->icon('life-buoy', 'h-4 w-4') ?>
        </a>
      </div>
    </div>
  </div>
</div>

<div class="toast-viewport" role="region" aria-label="Уведомления" aria-live="polite"></div>
<script type="application/json" id="basip-flash"><?= $this->json($this->flashPayload($this->shared['flash'] ?? [], $this->shared['errors'] ?? [])) ?></script>
<script src="<?= $this->e($this->asset('js/app.js')) ?>" nonce="<?= $this->e($nonce) ?>"></script>
<?= $this->stack('scripts') ?>
</body>
</html>
