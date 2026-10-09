<?php
/**
 * Authenticated application shell.
 *
 * Sidebar (desktop) + drawer (mobile) + sticky header + content + toasts.
 * The theme class is applied *before* first paint twice over: by PHP (from the
 * `basip_theme` cookie) and by an inline script (from localStorage/OS), so
 * there is no white flash on a dark-mode phone.
 *
 * @var string $title
 * @var string $content
 */

declare(strict_types=1);

/** @var BasIp\Core\View $this */
$theme      = $this->shared['theme'] ?? 'system';
$nonce      = $this->nonce();
$user       = $this->shared['user'] ?? null;
$nav        = $this->shared['nav'] ?? [];
$flash      = $this->shared['flash'] ?? [];
$errors     = $this->shared['errors'] ?? [];
$appName    = $this->shared['appName'] ?? 'BAS-IP';
$shortName  = $this->shared['shortName'] ?? 'BAS-IP';
$basePath   = $this->shared['basePath'] ?? '';
$path       = $this->shared['path'] ?? '/';
$version    = $this->shared['version'] ?? '2.0.0';
$announcements = $this->shared['announcements'] ?? [];
$updateAvailable = $this->shared['updateAvailable'] ?? false;
$latestVersion   = $this->shared['latestVersion'] ?? '';
$publicSettings  = $this->shared['publicSettings'] ?? [];
$accent     = $publicSettings['ui.accent'] ?? 'slate';
$density    = $publicSettings['ui.density'] ?? 'comfortable';
$locale     = $this->shared['locale'] ?? 'ru';
$isStaff    = $user !== null && $user->isStaff();
$resident   = $this->shared['resident'] ?? null;

// Effective theme for the initial <html class> — the inline script refines it.
$initialDark = $theme === 'dark' || ($theme === 'system' && (date('G') >= 21 || date('G') < 6));
$cookieTheme = $_COOKIE['basip_theme'] ?? null;
if (is_string($cookieTheme) && in_array($cookieTheme, ['light', 'dark'], true)) {
    $initialDark = $cookieTheme === 'dark';
}
?>
<!doctype html>
<html lang="<?= $this->e($locale) ?>" class="<?= $initialDark ? 'dark' : '' ?>" data-theme="<?= $this->e($theme) ?>" data-accent="<?= $this->e($accent) ?>" data-density="<?= $this->e($density) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=5">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="<?= $initialDark ? '#09090b' : '#ffffff' ?>">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="description" content="<?= $this->e($appName) ?> — управление шлагбаумом, доступами и оплатами.">
<meta name="csrf-token" content="<?= $this->e($this->csrfToken()) ?>">
<title><?= $this->e(($title ?? '') !== '' ? $title . ' · ' . $appName : $appName) ?></title>

<link rel="icon" href="<?= $this->e($this->asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= $this->e($this->asset('img/apple-touch-icon.png')) ?>">
<link rel="manifest" href="<?= $this->e($this->url('/manifest.webmanifest')) ?>">
<link rel="stylesheet" href="<?= $this->e($this->asset('css/app.css')) ?>">
<link rel="preload" href="<?= $this->e($this->asset('icons/lucide.svg')) ?>" as="fetch" type="image/svg+xml" crossorigin>

<?php /* Pre-paint theme bootstrap — must run before CSS paints anything. */ ?>
<script nonce="<?= $this->e($nonce) ?>">
window.__BASIP = {
  theme: <?= $this->json($theme) ?>,
  accent: <?= $this->json($accent) ?>,
  density: <?= $this->json($density) ?>,
  basePath: <?= $this->json($basePath) ?>,
  version: <?= $this->json($version) ?>,
  currency: <?= $this->json($this->config('billing.currency_symbol', '₽')) ?>,
  iconBase: <?= $this->json($this->asset('icons/lucide.svg')) ?>,
  locale: <?= $this->json($locale) ?>,
  isStaff: <?= $isStaff ? 'true' : 'false' ?>,
  templates: <?= $this->json(array_map(static fn ($t) => ['subject' => $t['subject'] ?? '', 'body' => $t['body'] ?? ''], (array) $this->config('notify.templates', []))) ?>
};
</script>
<script src="<?= $this->e($this->asset('js/theme.js')) ?>" nonce="<?= $this->e($nonce) ?>"></script>
<?= $this->stack('head') ?>
</head>

<body class="min-h-[100dvh] bg-background font-sans text-foreground antialiased" data-page="<?= $this->e(trim($path, '/')) ?>">
<a href="#main" class="sr-focusable">Перейти к содержимому</a>

<div class="app-shell">

  <?php /* ══════════════════════ sidebar ══════════════════════ */ ?>
  <aside class="app-sidebar no-print" data-sidebar data-state="closed" aria-label="Основная навигация">
    <div class="app-sidebar-brand">
      <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
        <?= $this->icon('door-open', 'h-4.5 w-4.5 text-lg') ?>
      </span>
      <span class="min-w-0 flex-1">
        <span class="block truncate text-sm font-semibold leading-tight"><?= $this->e($appName) ?></span>
        <span class="block text-[11px] text-muted-foreground">v<?= $this->e($version) ?> · Gate Manager</span>
      </span>
      <button type="button" class="btn btn-ghost btn-icon btn-sm lg:hidden" data-sidebar-toggle aria-label="Закрыть меню">
        <?= $this->icon('x') ?>
      </button>
    </div>

    <nav class="app-sidebar-nav" aria-label="Разделы">
      <?php foreach ($nav as $item): ?>
        <?php if (isset($item['section'])): ?>
          <div class="nav-section"><?= $this->e($item['section']) ?></div>
        <?php else:
            $href = $this->url($item['path']);
            $active = $this->active($item['path'], $item['path'] === '/panel');
            $badgeKey = $item['badge'] ?? null;
        ?>
          <a href="<?= $this->e($href) ?>"
             class="nav-item <?= $active ?>"
             <?= $active ? 'aria-current="page"' : '' ?>
             data-command="<?= $this->e($item['title']) ?>"
             data-command-group="Разделы"
             data-command-icon="<?= $this->e($item['icon']) ?>">
            <?= $this->icon($item['icon'], 'h-4 w-4') ?>
            <span class="truncate"><?= $this->e($item['title']) ?></span>
            <?php
              // Badges are filled client-side from /admin/nav-counts so they stay
              // fresh without re-rendering the whole shell.
              $badgeClass = ['debtors' => 'nav-badge nav-badge-warning'][$badgeKey] ?? 'nav-badge';
            ?>
            <?php if ($badgeKey !== null): ?>
              <span class="<?= $this->e($badgeClass) ?>" data-count="<?= $this->e((string) $badgeKey) ?>"></span>
            <?php endif ?>
          </a>
        <?php endif ?>
      <?php endforeach ?>
    </nav>

    <div class="app-sidebar-footer">
      <button type="button" class="nav-item w-full" data-command-palette data-command="Командная палитра" data-command-group="Действия" data-command-icon="command">
        <?= $this->icon('command', 'h-4 w-4') ?>
        <span class="flex-1 text-left">Быстрый переход</span>
        <kbd class="kbd">Ctrl</kbd><kbd class="kbd">K</kbd>
      </button>

      <?php if ($user !== null): ?>
        <a href="<?= $this->e($this->url('/profile')) ?>" class="nav-item mt-1">
          <span class="avatar avatar-sm"><?= $this->e($user->initials()) ?></span>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-xs font-medium"><?= $this->e($user->displayName()) ?></span>
            <span class="block truncate text-[11px] text-muted-foreground"><?= $this->e($user->roleLabel()) ?></span>
          </span>
          <?= $this->icon('settings', 'h-3.5 w-3.5 opacity-60') ?>
        </a>
      <?php endif ?>
    </div>
  </aside>

  <div class="sidebar-backdrop" data-sidebar-backdrop data-state="closed" hidden aria-hidden="true"></div>

  <?php /* ══════════════════════ main ══════════════════════ */ ?>
  <div class="app-main min-w-0">

    <header class="app-header no-print">
      <button type="button" class="btn btn-ghost btn-icon lg:hidden" data-sidebar-toggle aria-label="Открыть меню" aria-controls="sidebar">
        <?= $this->icon('menu') ?>
      </button>

      <h1 class="app-header-title"><?= $this->e($title ?? $appName) ?></h1>

      <div class="hidden items-center gap-2 md:flex">
        <button type="button" class="btn btn-outline btn-sm gap-2 text-muted-foreground" data-command-palette>
          <?= $this->icon('search', 'h-3.5 w-3.5') ?>
          <span>Поиск…</span>
          <kbd class="kbd ml-4">Ctrl K</kbd>
        </button>
      </div>

      <div class="ml-auto flex items-center gap-1">
        <?php if ($isStaff): ?>
          <button type="button"
                  class="btn btn-success btn-sm gap-2"
                  data-shortcut="open"
                  data-gate-open="<?= $this->e($this->url('/panel/open')) ?>"
                  data-confirm="Открыть шлагбаум сейчас?"
                  data-confirm-title="Открытие шлагбаума"
                  data-confirm-label="Открыть"
                  data-tooltip="Открыть шлагбаум (Shift+O)">
            <?= $this->icon('door-open', 'h-4 w-4') ?>
            <span class="hidden sm:inline">Открыть</span>
          </button>
        <?php endif ?>

        <div class="relative">
          <button type="button" class="btn btn-ghost btn-icon" data-dropdown aria-haspopup="true" aria-expanded="false" aria-label="Тема оформления" data-tooltip="Тема (Shift+T)">
            <?= $this->icon('sun', 'h-4 w-4 dark:hidden') ?>
            <?= $this->icon('moon', 'h-4 w-4 hidden dark:block') ?>
          </button>
          <div class="dropdown right-0 top-full mt-1 w-44" data-dropdown-panel data-state="closed" hidden role="menu">
            <div class="dropdown-label">Оформление</div>
            <button type="button" class="dropdown-item" role="menuitemradio" data-theme-toggle="light">
              <?= $this->icon('sun') ?> Светлая
            </button>
            <button type="button" class="dropdown-item" role="menuitemradio" data-theme-toggle="dark">
              <?= $this->icon('moon') ?> Тёмная
            </button>
            <button type="button" class="dropdown-item" role="menuitemradio" data-theme-toggle="system">
              <?= $this->icon('monitor') ?> Как в системе
            </button>
            <div class="dropdown-separator"></div>
            <div class="dropdown-label">Акцент</div>
            <div class="grid grid-cols-6 gap-1 px-2 pb-2">
              <?php foreach (['slate' => '#18181b', 'blue' => '#2563eb', 'green' => '#16a34a', 'violet' => '#7c3aed', 'orange' => '#ea580c', 'rose' => '#e11d48'] as $name => $color): ?>
                <button type="button" class="h-6 w-6 rounded-full border border-border ring-offset-2 ring-offset-background transition hover:scale-110 focus-visible:ring-2 focus-visible:ring-ring"
                        style="background: <?= $color ?>" data-accent-set="<?= $name ?>" aria-label="Акцент: <?= $name ?>" title="<?= $this->e($name) ?>"></button>
              <?php endforeach ?>
            </div>
          </div>
        </div>

        <?php if ($isStaff): ?>
          <a href="<?= $this->e($this->url('/help')) ?>" class="btn btn-ghost btn-icon" aria-label="Справка" data-tooltip="Справка (Shift+?)">
            <?= $this->icon('life-buoy') ?>
          </a>
        <?php endif ?>

        <div class="relative">
          <button type="button" class="btn btn-ghost btn-icon relative" data-dropdown aria-haspopup="true" aria-expanded="false" aria-label="Меню пользователя">
            <?php if ($user !== null): ?>
              <span class="avatar avatar-sm"><?= $this->e($user->initials()) ?></span>
            <?php else: ?>
              <?= $this->icon('user') ?>
            <?php endif ?>
          </button>
          <div class="dropdown right-0 top-full mt-1 w-60" data-dropdown-panel data-state="closed" hidden role="menu">
            <?php if ($user !== null): ?>
              <div class="px-2 py-1.5">
                <div class="truncate text-sm font-medium"><?= $this->e($user->displayName()) ?></div>
                <div class="truncate text-xs text-muted-foreground"><?= $this->e($user->login) ?> · <?= $this->e($user->roleLabel()) ?></div>
              </div>
              <div class="dropdown-separator"></div>
              <a class="dropdown-item" role="menuitem" href="<?= $this->e($this->url('/profile')) ?>"><?= $this->icon('user') ?> Профиль и безопасность</a>
              <?php if ($resident !== null): ?>
                <a class="dropdown-item" role="menuitem" href="<?= $this->e($this->url('/cabinet')) ?>"><?= $this->icon('home') ?> Личный кабинет</a>
              <?php endif ?>
              <?php if ($isStaff): ?>
                <a class="dropdown-item" role="menuitem" href="<?= $this->e($this->url('/admin/system')) ?>"><?= $this->icon('activity') ?> Состояние системы</a>
                <a class="dropdown-item" role="menuitem" href="<?= $this->e($this->url('/admin/updates')) ?>"><?= $this->icon('download-cloud') ?> Обновления
                  <?php if ($updateAvailable): ?><span class="nav-badge nav-badge-warning ml-auto">new</span><?php endif ?>
                </a>
              <?php endif ?>
              <div class="dropdown-separator"></div>
              <form method="post" action="<?= $this->e($this->url('/logout')) ?>">
                <?= $this->csrf() ?>
                <button type="submit" class="dropdown-item w-full" role="menuitem" data-destructive>
                  <?= $this->icon('log-out') ?> Выйти
                </button>
              </form>
            <?php else: ?>
              <a class="dropdown-item" role="menuitem" href="<?= $this->e($this->url('/login')) ?>"><?= $this->icon('log-in') ?> Войти</a>
            <?php endif ?>
          </div>
        </div>
      </div>
    </header>

    <?php /* ── banners ─────────────────────────────────────────────── */ ?>
    <?php if ($updateAvailable && $isStaff): ?>
      <div class="border-b border-info/25 bg-info/8 px-4 py-2 text-sm no-print">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-3 gap-y-1">
          <?= $this->icon('download-cloud', 'h-4 w-4 text-info') ?>
          <span>Доступна новая версия <b><?= $this->e($latestVersion) ?></b>.</span>
          <a href="<?= $this->e($this->url('/admin/updates')) ?>" class="font-medium text-info underline underline-offset-4">Обновить</a>
        </div>
      </div>
    <?php endif ?>

    <?php foreach ($announcements as $announcement):
        $level = (string) ($announcement['level'] ?? 'info');
        $tone = ['info' => 'alert-info', 'success' => 'alert-success', 'warning' => 'alert-warning', 'urgent' => 'alert-destructive'][$level] ?? 'alert-info';
        $icon = ['info' => 'info', 'success' => 'check-circle', 'warning' => 'alert-triangle', 'urgent' => 'siren'][$level] ?? 'info';
    ?>
      <div class="border-b px-4 py-2 no-print">
        <div class="alert <?= $tone ?> mx-auto max-w-7xl">
          <?= $this->icon($icon, 'h-4 w-4') ?>
          <div>
            <div class="alert-title"><?= $this->e($announcement['title'] ?? '') ?></div>
            <?php if (!empty($announcement['body'])): ?>
              <div class="alert-desc whitespace-pre-line"><?= $this->e($announcement['body']) ?></div>
            <?php endif ?>
          </div>
        </div>
      </div>
    <?php endforeach ?>

    <main id="main" class="app-content">
      <div class="mx-auto w-full max-w-[1500px]">
        <?= $content ?>
      </div>
    </main>

    <footer class="border-t px-4 py-4 text-xs text-muted-foreground no-print">
      <div class="mx-auto flex max-w-[1500px] flex-wrap items-center justify-between gap-2">
        <span><?= $this->e($appName) ?> · BAS-IP Gate Manager v<?= $this->e($version) ?></span>
        <span class="flex items-center gap-3">
          <a class="hover:text-foreground" href="<?= $this->e($this->url('/help')) ?>">Справка</a>
          <a class="hover:text-foreground" href="<?= $this->e($this->url('/health')) ?>">Статус</a>
          <a class="hover:text-foreground" href="https://github.com/paulmann/BAS-IP" target="_blank" rel="noopener noreferrer">GitHub</a>
        </span>
      </div>
    </footer>
  </div>
</div>

<?php /* ══════════════════════ toasts & flash ══════════════════════ */ ?>
<div class="toast-viewport" role="region" aria-label="Уведомления" aria-live="polite"></div>

<script type="application/json" id="basip-flash"><?= $this->json($this->flashPayload($flash, $errors)) ?></script>

<?= $this->once('components/command-palette') ?>

<script src="<?= $this->e($this->asset('js/vendor/qrcode.js')) ?>" nonce="<?= $this->e($nonce) ?>"></script>
<script src="<?= $this->e($this->asset('js/app.js')) ?>" nonce="<?= $this->e($nonce) ?>"></script>
<?php if ($isStaff || $resident !== null): ?>
  <script src="<?= $this->e($this->asset('js/panel.js')) ?>" nonce="<?= $this->e($nonce) ?>"></script>
<?php endif ?>
<?php if ($isStaff): ?>
  <script src="<?= $this->e($this->asset('js/admin.js')) ?>" nonce="<?= $this->e($nonce) ?>"></script>
<?php endif ?>
<script nonce="<?= $this->e($nonce) ?>">
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-accent-set]');
  if (!btn) return;
  var name = btn.getAttribute('data-accent-set');
  if (window.BasIpTheme) window.BasIpTheme.setAccent(name);
  if (window.BasIp) window.BasIp.toast.info('Акцент: ' + name, { duration: 1600 });
});
</script>
<?= $this->stack('scripts') ?>
</body>
</html>
