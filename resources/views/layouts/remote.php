<?php
/**
 * Full-screen «gate remote» layout: no sidebar, no chrome — the giant
 * control button and the camera feed own the entire viewport.
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
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="<?= $initialDark ? '#09090b' : '#ffffff' ?>">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= $this->e($this->csrfToken()) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?= $this->e(($title ?? '') !== '' ? $title . ' · ' . $appName : $appName) ?></title>
<link rel="icon" href="<?= $this->e($this->asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= $this->e($this->asset('css/app.css')) ?>">
<script nonce="<?= $this->e($nonce) ?>">
window.__BASIP = { theme: <?= $this->json($theme) ?>, basePath: <?= $this->json($basePath) ?>, version: <?= $this->json($version) ?>,
  iconBase: <?= $this->json($this->asset('icons/lucide.svg')) ?>, currency: '₽' };
</script>
<script src="<?= $this->e($this->asset('js/theme.js')) ?>" nonce="<?= $this->e($nonce) ?>"></script>
</head>
<body class="h-[100dvh] overflow-hidden bg-background font-sans text-foreground antialiased" data-remote-body>
<?= $content ?>
<script src="<?= $this->e($this->asset('js/app.js')) ?>" nonce="<?= $this->e($nonce) ?>"></script>
<script src="<?= $this->e($this->asset('js/remote.js')) ?>" nonce="<?= $this->e($nonce) ?>"></script>
<?= $this->stack('scripts') ?>
</body>
</html>
