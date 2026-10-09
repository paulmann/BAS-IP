<?php
/**
 * Shared error page body.
 *
 * Rendered by errors/4xx.php and errors/5xx.php. Works with or without a signed-in
 * user and with or without a working database — the layout is deliberately
 * dependency-free because it is also the fallback when the DB is down.
 *
 * @var int    $status
 * @var string $message
 * @var string|null $requestId
 * @var mixed  $exception
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$status = (int) ($status ?? 500);
$message = (string) ($message ?? 'Произошла ошибка');
$requestId = $requestId ?? null;
$exception = $exception ?? null;

$presets = [
    400 => ['title' => 'Некорректный запрос', 'icon' => 'alert-circle', 'hint' => 'Проверьте введённые данные и попробуйте снова.'],
    401 => ['title' => 'Требуется вход', 'icon' => 'lock', 'hint' => 'Войдите, чтобы продолжить.'],
    403 => ['title' => 'Доступ запрещён', 'icon' => 'shield-ban', 'hint' => 'У вашей учётной записи нет прав на этот раздел.'],
    404 => ['title' => 'Страница не найдена', 'icon' => 'search-x', 'hint' => 'Возможно, ссылка устарела или адрес набран с опечаткой.'],
    405 => ['title' => 'Метод не поддерживается', 'icon' => 'ban', 'hint' => 'Этот адрес принимает запросы другого типа.'],
    419 => ['title' => 'Сессия устарела', 'icon' => 'timer', 'hint' => 'Обновите страницу и повторите действие.'],
    422 => ['title' => 'Данные не прошли проверку', 'icon' => 'alert-triangle', 'hint' => 'Проверьте заполнение полей формы.'],
    429 => ['title' => 'Слишком много запросов', 'icon' => 'gauge', 'hint' => 'Подождите минуту и повторите.'],
    500 => ['title' => 'Внутренняя ошибка', 'icon' => 'flame', 'hint' => 'Мы уже записали подробности в журнал. Попробуйте позже.'],
    503 => ['title' => 'Сервис временно недоступен', 'icon' => 'wrench', 'hint' => 'Ведутся технические работы.'],
];
$preset = $presets[$status] ?? ['title' => 'Ошибка', 'icon' => 'alert-circle', 'hint' => ''];
$theme = $this->shared['theme'] ?? 'system';
$nonce = $this->nonce();
$initialDark = $theme === 'dark' || ($theme === 'system' && (date('G') >= 21 || date('G') < 6));
$user = $this->shared['user'] ?? null;
?>
<!doctype html>
<html lang="ru" class="<?= $initialDark ? 'dark' : '' ?>" data-theme="<?= $this->e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="<?= $initialDark ? '#09090b' : '#ffffff' ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= $status ?> · <?= $this->e($preset['title']) ?></title>
<link rel="icon" href="<?= $this->e($this->asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= $this->e($this->asset('css/app.css')) ?>">
<script nonce="<?= $this->e($nonce) ?>">window.__BASIP = { theme: <?= $this->json($theme) ?>, basePath: <?= $this->json($this->shared['basePath'] ?? '') ?>, iconBase: <?= $this->json($this->asset('icons/lucide.svg')) ?> };</script>
<script src="<?= $this->e($this->asset('js/theme.js')) ?>" nonce="<?= $this->e($nonce) ?>"></script>
</head>
<body class="min-h-[100dvh] bg-background font-sans text-foreground antialiased">
<div class="relative flex min-h-[100dvh] items-center justify-center overflow-hidden px-4 py-10">
  <div class="grid-bg pointer-events-none absolute inset-0 opacity-40" aria-hidden="true"></div>

  <div class="relative w-full max-w-lg">
    <div class="card p-6 text-center shadow-lg sm:p-8">
      <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
        <?= $this->icon($preset['icon'], 'h-8 w-8 text-4xl') ?>
      </div>

      <div class="text-6xl font-bold tracking-tight tabular text-muted-foreground/40"><?= $status ?></div>
      <h1 class="mt-1 text-xl font-semibold tracking-tight"><?= $this->e($preset['title']) ?></h1>
      <p class="mt-2 text-sm text-muted-foreground"><?= $this->e($message !== '' ? $message : $preset['hint']) ?></p>
      <?php if ($message !== '' && $message !== $preset['hint']): ?>
        <p class="mt-1 text-xs text-muted-foreground"><?= $this->e($preset['hint']) ?></p>
      <?php endif ?>

      <?php if ($requestId !== null): ?>
        <p class="mt-4 inline-flex items-center gap-2 rounded-md border bg-muted/40 px-2.5 py-1 font-mono text-xs text-muted-foreground">
          <?= $this->icon('hash', 'h-3 w-3') ?> ID запроса: <?= $this->e((string) $requestId) ?>
        </p>
      <?php endif ?>

      <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-center">
        <a class="btn btn-default" href="<?= $this->e($this->url('/')) ?>"><?= $this->icon('home', 'h-4 w-4') ?> На главную</a>
        <button class="btn btn-outline" type="button" onclick="history.back()"><?= $this->icon('arrow-left', 'h-4 w-4') ?> Назад</button>
        <?php if ($user === null): ?>
          <a class="btn btn-outline" href="<?= $this->e($this->url('/login')) ?>"><?= $this->icon('log-in', 'h-4 w-4') ?> Войти</a>
        <?php else: ?>
          <a class="btn btn-outline" href="<?= $this->e($this->url('/help')) ?>"><?= $this->icon('life-buoy', 'h-4 w-4') ?> Справка</a>
        <?php endif ?>
      </div>
    </div>

    <?php if ($exception !== null): ?>
      <details class="card mt-4 p-4">
        <summary class="cursor-pointer text-sm font-medium text-destructive">Технические подробности (режим отладки)</summary>
        <pre class="mt-3 max-h-80 overflow-auto rounded-lg bg-zinc-950 p-3 font-mono text-xs leading-relaxed text-zinc-100"><?= $this->e(get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getFile() . ':' . $exception->getLine() . "\n\n" . $exception->getTraceAsString()) ?></pre>
      </details>
    <?php endif ?>

    <p class="mt-5 text-center text-xs text-muted-foreground">
      BAS-IP Gate Manager · <a class="underline underline-offset-4 hover:text-foreground" href="<?= $this->e($this->url('/health')) ?>">состояние системы</a>
    </p>
  </div>
</div>
<script src="<?= $this->e($this->asset('js/app.js')) ?>" nonce="<?= $this->e($nonce) ?>"></script>
</body>
</html>
