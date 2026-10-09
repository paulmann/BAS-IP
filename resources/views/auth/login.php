<?php
/**
 * Login screen.
 *
 * @var bool   $showCaptcha
 * @var array  $captcha      ['question' => '3 + 4 = ?', 'answer' => …]
 * @var int    $attempts
 * @var bool   $rememberEnabled
 * @var bool   $twoFactorEnabled
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$showCaptcha = !empty($showCaptcha);
$old = $this->shared['old'] ?? [];
$errors = $this->shared['errors'] ?? [];
?>
<form method="post" action="<?= $this->e($this->url('/login')) ?>" class="grid gap-4" autocomplete="on" novalidate>
  <?= $this->csrf() ?>

  <div class="grid gap-1.5 text-center">
    <h2 class="text-lg font-semibold tracking-tight">Вход в систему</h2>
    <p class="text-sm text-muted-foreground">
      Для жителей — логин, выданный управляющим.<br class="sm:hidden">
      Для диспетчера и администратора — служебная учётная запись.
    </p>
  </div>

  <div class="field">
    <label class="label" for="login">Логин, e-mail или телефон</label>
    <div class="relative">
      <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-muted-foreground"><?= $this->icon('user', 'h-4 w-4') ?></span>
      <input class="input pl-9" id="login" name="login" type="text" required autofocus
             autocomplete="username" autocapitalize="none" spellcheck="false" inputmode="text"
             value="<?= $this->e($this->old('login', '')) ?>"
             placeholder="admin или +7 999 123-45-67" aria-describedby="login-hint">
    </div>
    <p class="text-xs text-muted-foreground" id="login-hint">Регистр не важен. Можно войти по e-mail.</p>
    <?php if (isset($errors['login'])): ?><p class="form-message"><?= $this->e($errors['login'][0]) ?></p><?php endif ?>
  </div>

  <div class="field">
    <div class="flex items-baseline justify-between gap-2">
      <label class="label" for="password">Пароль</label>
      <a class="text-xs font-medium text-primary underline-offset-4 hover:underline" href="<?= $this->e($this->url('/password/forgot')) ?>">Забыли пароль?</a>
    </div>
    <div class="relative">
      <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-muted-foreground"><?= $this->icon('lock', 'h-4 w-4') ?></span>
      <input class="input pr-10 pl-9" id="password" name="password" type="password" required
             autocomplete="current-password" placeholder="••••••••••">
      <button type="button" class="absolute inset-y-0 right-1.5 my-1.5 inline-flex w-8 items-center justify-center rounded text-muted-foreground hover:bg-accent hover:text-foreground"
              data-secret-toggle="#password" aria-label="Показать пароль"><?= $this->icon('eye', 'h-4 w-4') ?></button>
    </div>
    <?php if (isset($errors['password'])): ?><p class="form-message"><?= $this->e($errors['password'][0]) ?></p><?php endif ?>
  </div>

  <?php if ($showCaptcha): ?>
    <div class="field rounded-lg border border-warning/35 bg-warning/8 p-3">
      <label class="label flex items-center gap-1.5" for="captcha">
        <?= $this->icon('shield-alert', 'h-4 w-4 text-warning') ?>
        Защита от подбора: <?= $this->e($captcha['question'] ?? '') ?>
      </label>
      <input class="input input-mono" id="captcha" name="captcha" type="text" inputmode="numeric"
             autocomplete="one-time-code" required placeholder="Ответ">
      <p class="text-xs text-muted-foreground">
        Слишком много неудачных попыток (<?= (int) ($attempts ?? 0) ?>). Это одноразовый вопрос.
      </p>
    </div>
  <?php endif ?>

  <?php if (!empty($rememberEnabled)): ?>
    <label class="checkbox-row text-sm">
      <input class="checkbox" type="checkbox" name="remember" value="1" checked>
      <span>Запомнить меня на этом устройстве
        <span class="label-hint">До <?= (int) $this->config('auth.session.remember_me.days', 30) ?> дней. Не включайте на общем компьютере.</span>
      </span>
    </label>
  <?php endif ?>

  <button class="btn btn-default btn-lg btn-block" type="submit">
    <?= $this->icon('log-in', 'h-4 w-4') ?> Войти
  </button>

  <?php if (!empty($twoFactorEnabled)): ?>
    <p class="flex items-start gap-2 text-xs text-muted-foreground">
      <?= $this->icon('shield-check', 'h-4 w-4 mt-0.5 shrink-0 text-success') ?>
      <span>Для сотрудников включена двухфакторная аутентификация — после пароля потребуется код из приложения.</span>
    </p>
  <?php endif ?>

  <div class="rounded-lg border bg-muted/30 p-3 text-xs text-muted-foreground">
    <div class="flex items-center gap-1.5 font-medium text-foreground">
      <?= $this->icon('info', 'h-3.5 w-3.5') ?> Первый запуск?
    </div>
    <p class="mt-1 leading-relaxed">
      Если система ещё не установлена, откройте
      <a class="font-medium text-primary underline underline-offset-4" href="<?= $this->e($this->url('/install')) ?>">мастер установки</a>.
      Учётную запись жителя создаёт управляющий в разделе «Пользователи».
    </p>
  </div>
</form>
