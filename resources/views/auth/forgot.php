<?php
/**
 * Password recovery request.
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<form method="post" action="<?= $this->e($this->url('/password/forgot')) ?>" class="grid gap-4">
  <?= $this->csrf() ?>

  <div class="grid gap-1.5 text-center">
    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-muted text-muted-foreground">
      <?= $this->icon('key-round', 'h-6 w-6 text-2xl') ?>
    </span>
    <h2 class="text-lg font-semibold tracking-tight">Восстановление пароля</h2>
    <p class="text-sm text-muted-foreground">Укажите логин или e-mail — мы отправим ссылку для сброса.</p>
  </div>

  <div class="field">
    <label class="label" for="login">Логин или e-mail</label>
    <input class="input" id="login" name="login" type="text" required autofocus autocomplete="username"
           value="<?= $this->e($this->old('login', '')) ?>" placeholder="admin">
  </div>

  <button class="btn btn-default btn-block" type="submit">
    <?= $this->icon('send', 'h-4 w-4') ?> Отправить ссылку
  </button>

  <div class="alert alert-info">
    <?= $this->icon('info', 'h-4 w-4') ?>
    <div>
      <div class="alert-title">Если письмо не пришло</div>
      <div class="alert-desc">
        Ссылку также может выдать администратор или её можно сбросить из консоли:
        <code class="inline-code">php bin/bas-ip user:password ЛОГИН</code>
      </div>
    </div>
  </div>

  <a class="btn btn-ghost btn-block text-muted-foreground" href="<?= $this->e($this->url('/login')) ?>">
    <?= $this->icon('arrow-left', 'h-4 w-4') ?> Назад к входу
  </a>
</form>
