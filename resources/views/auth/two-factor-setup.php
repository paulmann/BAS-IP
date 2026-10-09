<?php
/**
 * 2FA enrolment: show the otpauth URI as a QR code + manual key, confirm with a code.
 *
 * @var string $pending  secret already generated for this attempt (may be empty)
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$user = $this->user();
if ($user === null) { return; }
?>
<?php $this->layout('layouts/app') ?>

<div class="mx-auto max-w-2xl">
  <div class="page-header">
    <div>
      <h2 class="page-title">Настройка 2FA</h2>
      <p class="page-desc">Двухфакторная аутентификация по TOTP (RFC 6238). Работает с Google Authenticator, Authy, 1Password, FreeOTP, Aegis.</p>
    </div>
    <div class="page-actions">
      <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/profile')) ?>?tab=security"><?= $this->icon('arrow-left') ?> Назад</a>
    </div>
  </div>

  <div class="grid gap-5">

    <div class="card">
      <div class="card-header">
        <div class="card-title">Шаг 1 · Добавьте ключ в приложение</div>
        <div class="card-desc">Отсканируйте QR-код или введите ключ вручную.</div>
      </div>
      <div class="card-content grid gap-4 sm:grid-cols-[auto_minmax(0,1fr)] sm:items-center">
        <div class="qr-frame mx-auto w-48 shrink-0" data-2fa-qr aria-label="QR-код для добавления ключа 2FA"></div>
        <div class="grid gap-3">
          <div>
            <div class="label mb-1">Секретный ключ</div>
            <div class="flex items-center gap-2">
              <code class="input input-mono flex-1 select-all" id="totp-secret" data-2fa-secret>—</code>
              <button type="button" class="btn btn-outline btn-icon" data-copy="#totp-secret" data-copy-message="Ключ скопирован" aria-label="Скопировать ключ">
                <?= $this->icon('copy') ?>
              </button>
            </div>
          </div>
          <p class="text-xs text-muted-foreground">
            Приложение генерирует коды офлайн — интернет на телефоне не нужен. Сервер хранит ключ в зашифрованном виде.
          </p>
          <button type="button" class="btn btn-outline btn-sm justify-self-start" data-2fa-regenerate>
            <?= $this->icon('refresh-cw', 'h-3.5 w-3.5') ?> Сгенерировать новый ключ
          </button>
        </div>
      </div>
    </div>

    <form class="card" method="post" action="<?= $this->e($this->url('/profile/2fa/confirm')) ?>" data-2fa-confirm-form>
      <?= $this->csrf() ?>
      <div class="card-header">
        <div class="card-title">Шаг 2 · Подтвердите кодом</div>
        <div class="card-desc">Введите текущий 6-значный код из приложения.</div>
      </div>
      <div class="card-content grid gap-4">
        <div class="field">
          <label class="label sr-only" for="code">Код подтверждения</label>
          <input class="input input-lg text-center font-mono text-2xl tracking-[.4em]" id="code" name="code"
                 type="text" inputmode="numeric" pattern="[0-9]*" maxlength="10" required autocomplete="one-time-code" placeholder="000000">
        </div>
        <button class="btn btn-default btn-block" type="submit">
          <?= $this->icon('shield-check') ?> Включить 2FA
        </button>
      </div>
    </form>

    <div class="alert alert-warning">
      <?= $this->icon('alert-triangle', 'h-4 w-4') ?>
      <div>
        <div class="alert-title">Сохраните резервные коды</div>
        <div class="alert-desc">
          После включения мы покажем <?= (int) $this->config('auth.two_factor.recovery_codes', 8) ?> одноразовых кодов.
          Без них потерянный телефон означает восстановление доступа только через администратора.
        </div>
      </div>
    </div>
  </div>
</div>

<?php $this->startPush('scripts') ?>
<script nonce="<?= $this->e($this->nonce()) ?>">
(function () {
  var startUrl = <?= $this->json($this->url('/profile/2fa/start')) ?>;
  var confirmUrl = <?= $this->json($this->url('/profile/2fa/confirm')) ?>;
  var qrHost = document.querySelector('[data-2fa-qr]');
  var secretEl = document.querySelector('[data-2fa-secret]');
  var form = document.querySelector('[data-2fa-confirm-form]');

  function load() {
    window.BasIp.post(startUrl, {}).then(function (data) {
      if (secretEl) secretEl.textContent = (data.formatted || data.secret || '').trim();
      window.BasIp.qr(qrHost, data.uri, 192);
    }).catch(function (err) {
      window.BasIp.toast.error((err && err.message) || 'Не удалось получить ключ 2FA');
    });
  }

  load();
  var regenerate = document.querySelector('[data-2fa-regenerate]');
  if (regenerate) regenerate.addEventListener('click', load);

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      window.BasIp.submitForm(form).then(function (data) {
        var codes = (data && data.recovery_codes) || [];
        showCodes(codes);
      }).catch(function () {});
    });
  }

  function showCodes(codes) {
    if (!codes || !codes.length) { window.location.href = <?= $this->json($this->url('/profile')) ?> + '?tab=security&codes=1'; return; }
    var host = document.createElement('div');
    host.className = 'dialog dialog-sm';
    host.dataset.state = 'closed';
    host.setAttribute('hidden', '');
    host.innerHTML =
      '<div class="dialog-header"><h2 class="dialog-title">2FA включена</h2>' +
      '<p class="dialog-desc">Сохраните резервные коды — они показываются один раз.</p></div>' +
      '<ul class="grid grid-cols-2 gap-1.5 font-mono text-sm">' +
        codes.map(function (c) { return '<li class="rounded border bg-muted/40 px-2 py-1">' + c + '</li>'; }).join('') +
      '</ul>' +
      '<div class="dialog-footer">' +
        '<button type="button" class="btn btn-outline" id="codes-copy">Скопировать все</button>' +
        '<button type="button" class="btn btn-default" id="codes-done">Готово</button>' +
      '</div>';
    document.body.appendChild(host);
    window.BasIp.dialog.open(host);
    host.querySelector('#codes-copy').addEventListener('click', function () {
      window.BasIp.copy(codes.join('\\n'), 'Резервные коды скопированы');
    });
    host.querySelector('#codes-done').addEventListener('click', function () {
      window.location.href = <?= $this->json($this->url('/profile')) ?> + '?tab=security';
    });
  }
})();
</script>
<?php $this->endPush() ?>
