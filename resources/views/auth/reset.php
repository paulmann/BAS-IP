<?php
/**
 * Set a new password using a recovery token.
 *
 * @var string $token
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$minLength = (int) $this->config('auth.password.min_length', 10);
?>
<form method="post" action="<?= $this->e($this->url('/password/reset/' . rawurlencode((string) ($token ?? '')))) ?>" class="grid gap-4">
  <?= $this->csrf() ?>

  <div class="grid gap-1.5 text-center">
    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary">
      <?= $this->icon('lock-keyhole', 'h-6 w-6 text-2xl') ?>
    </span>
    <h2 class="text-lg font-semibold tracking-tight">Новый пароль</h2>
    <p class="text-sm text-muted-foreground">Ссылка действует один час и только один раз.</p>
  </div>

  <div class="field">
    <label class="label" for="login">Логин или e-mail</label>
    <input class="input" id="login" name="login" type="text" required autocomplete="username" value="<?= $this->e($_GET['login'] ?? '') ?>">
  </div>

  <div class="field">
    <label class="label" for="password">Новый пароль</label>
    <input class="input" id="password" name="password" type="password" required minlength="<?= $minLength ?>" autocomplete="new-password">
    <div class="mt-1.5" data-password-strength></div>
    <p class="text-xs text-muted-foreground">
      Не короче <?= $minLength ?> символов<?= $this->config('auth.password.require_mixed_case') ? ', строчные и прописные буквы' : '' ?><?= $this->config('auth.password.require_digit') ? ', хотя бы одна цифра' : '' ?>.
    </p>
  </div>

  <div class="field">
    <label class="label" for="password_confirm">Повторите пароль</label>
    <input class="input" id="password_confirm" name="password_confirm" type="password" required minlength="<?= $minLength ?>" autocomplete="new-password">
  </div>

  <button class="btn btn-default btn-block" type="submit">
    <?= $this->icon('save', 'h-4 w-4') ?> Сохранить новый пароль
  </button>
</form>

<?php $this->startPush('scripts') ?>
<script nonce="<?= $this->e($this->nonce()) ?>">
(function () {
  var input = document.getElementById('password');
  var host = document.querySelector('[data-password-strength]');
  if (!input || !host) return;
  var labels = ['очень слабый', 'слабый', 'средний', 'хороший', 'отличный'];
  input.addEventListener('input', function () {
    var v = input.value;
    var score = 0;
    if (v.length >= 10) score++;
    if (v.length >= 14) score++;
    if (/[a-zа-я]/.test(v) && /[A-ZА-Я]/.test(v)) score++;
    if (/\d/.test(v)) score++;
    if (/[^\p{L}\p{N}]/u.test(v)) score++;
    score = Math.min(4, score);
    host.innerHTML = '<div class="flex gap-1">' +
      [0,1,2,3,4].map(function (i) {
        var on = i <= score;
        var color = score <= 1 ? 'hsl(var(--destructive))' : (score === 2 ? 'hsl(var(--warning))' : 'hsl(var(--success))');
        return '<span style="height:4px;flex:1;border-radius:9999px;background:' + (on ? color : 'hsl(var(--muted))') + '"></span>';
      }).join('') + '</div>' +
      (v ? '<p class="mt-1 text-xs text-muted-foreground">Надёжность: ' + labels[score] + '</p>' : '');
  });
})();
</script>
<?php $this->endPush() ?>
