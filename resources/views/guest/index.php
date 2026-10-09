<?php
/**
 * Guest landing — open by a human-readable code (no link required).
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<?php $this->layout('layouts/guest') ?>

<div class="card">
  <div class="card-header text-center">
    <div class="card-title">Открыть по коду</div>
    <div class="card-desc">Введите код гостевого доступа, который вам прислал житель. Код выглядит как <code class="inline-code">7K4M-Q2XD</code>.</div>
  </div>
  <form class="card-content grid gap-4" method="post" action="<?= $this->e($this->url('/guest/code')) ?>" data-guest-code-form>
    <?= $this->csrf() ?>
    <div class="field">
      <label class="label sr-only" for="code">Код доступа</label>
      <input class="input input-lg text-center font-mono text-2xl uppercase tracking-[.25em]" id="code" name="code"
             type="text" inputmode="text" autocapitalize="characters" autocomplete="off" spellcheck="false"
             maxlength="16" required autofocus placeholder="0000-0000">
      <p class="text-xs text-muted-foreground">Дефис можно не вводить. Также подходит PIN из SMS.</p>
    </div>
    <button class="btn btn-success btn-xl btn-block" type="submit">
      <?= $this->icon('door-open') ?> Открыть шлагбаум
    </button>
    <p class="text-center text-sm font-medium" data-guest-code-status aria-live="polite"></p>
  </form>
</div>

<div class="mt-4 grid gap-3">
  <div class="alert alert-info">
    <?= $this->icon('link', 'h-4 w-4') ?>
    <div>
      <div class="alert-title">Есть ссылка?</div>
      <div class="alert-desc">Просто откройте её — кнопка «Открыть» будет прямо на странице. Код нужен, если ссылка не открылась.</div>
    </div>
  </div>
  <div class="alert alert-muted">
    <?= $this->icon('shield-check', 'h-4 w-4') ?>
    <div class="alert-desc">Код действует ограниченное время (обычно 12 часов) и может быть отозван жителем в любой момент.</div>
  </div>
</div>

<?php $this->startPush('scripts') ?>
<script nonce="<?= $this->e($this->nonce()) ?>">
(function () {
  var form = document.querySelector('[data-guest-code-form]');
  if (!form) return;
  var input = document.getElementById('code');
  var status = document.querySelector('[data-guest-code-status]');
  var btn = form.querySelector('button[type=submit]');

  input.addEventListener('input', function () {
    input.value = input.value.toUpperCase().replace(/[^A-Z0-9\-]/g, '').slice(0, 16);
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!input.value.trim()) { input.focus(); return; }

    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    status.textContent = 'Отправляем команду…';
    status.className = 'text-center text-sm font-medium text-muted-foreground';

    window.BasIp.post(form.getAttribute('action'), { code: input.value.trim() })
      .then(function (data) {
        status.textContent = data.message || 'Шлагбаум открыт. Проезжайте!';
        status.className = 'text-center text-sm font-medium text-success';
        window.BasIp.toast.success(data.message || 'Шлагбаум открыт');
        if (navigator.vibrate) { try { navigator.vibrate([15, 40, 15]); } catch (err) {} }
      })
      .catch(function (err) {
        status.textContent = (err && err.message) || 'Не удалось открыть шлагбаум';
        status.className = 'text-center text-sm font-medium text-destructive';
        window.BasIp.toast.error((err && err.message) || 'Отказ');
        if (navigator.vibrate) { try { navigator.vibrate(160); } catch (err2) {} }
      })
      .then(function () {
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
      });
  });
})();
</script>
<?php $this->endPush() ?>
