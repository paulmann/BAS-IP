<?php
/**
 * Second factor: TOTP code or a recovery code.
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<form method="post" action="<?= $this->e($this->url('/two-factor')) ?>" class="grid gap-4" autocomplete="off">
  <?= $this->csrf() ?>

  <div class="grid gap-1.5 text-center">
    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary">
      <?= $this->icon('shield-check', 'h-6 w-6 text-2xl') ?>
    </span>
    <h2 class="text-lg font-semibold tracking-tight">Двухфакторная аутентификация</h2>
    <p class="text-sm text-muted-foreground">Введите 6-значный код из приложения-аутентификатора.</p>
  </div>

  <div class="field">
    <label class="label sr-only" for="code">Код подтверждения</label>
    <input class="input input-lg text-center font-mono text-2xl tracking-[.4em]" id="code" name="code"
           type="text" inputmode="numeric" pattern="[0-9]*" maxlength="10" required autofocus
           autocomplete="one-time-code" placeholder="000000">
    <p class="text-xs text-muted-foreground">Код обновляется каждые 30 секунд. Допускается небольшая расхождение часов.</p>
  </div>

  <button class="btn btn-default btn-lg btn-block" type="submit">
    <?= $this->icon('arrow-right', 'h-4 w-4') ?> Подтвердить
  </button>

  <details class="rounded-lg border bg-muted/30 p-3 text-sm">
    <summary class="cursor-pointer font-medium">Нет доступа к приложению?</summary>
    <div class="mt-2 grid gap-2 text-xs text-muted-foreground">
      <p>Введите один из резервных кодов, которые вы сохранили при включении 2FA — в это же поле. Каждый код одноразовый.</p>
      <p>Если кодов нет — обратитесь к администратору: он сбросит 2FA в разделе «Пользователи».</p>
    </div>
  </details>

  <a class="btn btn-ghost btn-block text-muted-foreground" href="<?= $this->e($this->url('/login')) ?>">
    <?= $this->icon('arrow-left', 'h-4 w-4') ?> Назад к входу
  </a>
</form>

<?php $this->startPush('scripts') ?>
<script nonce="<?= $this->e($this->nonce()) ?>">
(function () {
  var input = document.getElementById('code');
  if (!input) return;
  // Accept "123 456" / "123-456" and auto-submit once 6 digits are in.
  input.addEventListener('input', function () {
    var digits = input.value.replace(/\D/g, '');
    input.value = digits.slice(0, 10);
    if (digits.length === 6) input.form.requestSubmit();
  });
  input.addEventListener('paste', function (e) {
    var text = (e.clipboardData || window.clipboardData).getData('text') || '';
    var digits = text.replace(/\D/g, '').slice(0, 10);
    if (digits) { e.preventDefault(); input.value = digits; if (digits.length === 6) input.form.requestSubmit(); }
  });
})();
</script>
<?php $this->endPush() ?>
