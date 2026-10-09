<?php
/**
 * Installer success screen.
 *
 * @var array<int, string> $log
 * @var string $admin
 * @var bool $autoLogin
 * @var string|null $generatedPassword
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$autoLogin = $autoLogin ?? false;
$generatedPassword = $generatedPassword ?? null;
?>
<?php $this->layout('layouts/auth') ?>

<div class="grid gap-5 text-center">
  <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-success/12 text-success">
    <?= $this->icon('check-circle', 'h-9 w-9 text-4xl') ?>
  </span>
  <div>
    <h2 class="text-xl font-semibold tracking-tight">Установка завершена</h2>
    <p class="mt-1 text-sm text-muted-foreground">Система готова к работе<?= $autoLogin ? ' — вы уже вошли как <b>' . $this->e($admin) . '</b>' : '' ?>.</p>
  </div>

  <?php if ($generatedPassword !== null && $generatedPassword !== ''): ?>
    <div class="rounded-lg border border-warning/50 bg-warning/8 p-4 text-left">
      <div class="label mb-2 flex items-center gap-2 text-warning">
        <?= $this->icon('key-round', 'h-4 w-4') ?> Сгенерированный пароль администратора
      </div>
      <div class="flex items-center gap-2">
        <code class="inline-code flex-1 select-all break-all p-2 font-mono text-sm" id="generated-password"><?= $this->e($generatedPassword) ?></code>
        <button type="button" class="btn btn-outline btn-sm" data-copy-generated>
          <?= $this->icon('copy', 'h-3.5 w-3.5') ?> Копировать
        </button>
      </div>
      <p class="mt-2 text-xs text-muted-foreground">
        Пароль показан <b>один раз</b> — сохраните его сейчас. Сменить можно в любой момент:
        Профиль → Безопасность, или <code class="inline-code">bin/bas-ip user:password <?= $this->e($admin) ?></code>.
      </p>
    </div>
  <?php endif ?>

  <div class="rounded-lg border bg-muted/30 p-4 text-left">
    <div class="label mb-2">Выполнено</div>
    <ol class="grid gap-1.5 text-sm text-muted-foreground">
      <?php foreach ($log as $line): ?>
        <li class="flex gap-2"><span class="text-success">✔</span><span><?= $this->e($line) ?></span></li>
      <?php endforeach ?>
    </ol>
  </div>

  <div class="grid gap-2 text-left">
    <div class="label">Дальше</div>
    <ol class="grid gap-2 text-sm">
      <?php foreach ([
        ['Включить двухфакторную аутентификацию', '/profile?tab=security', 'shield-check'],
        ['Добавить жителей (импорт из CSV)', '/admin/data', 'upload'],
        ['Проверить связь с панелью', '/admin/gate/devices', 'cpu'],
        ['Пройти самодиагностику', '/admin/system/doctor', 'stethoscope'],
        ['Настроить cron и резервные копии', '/help/cron', 'calendar-check'],
      ] as [$label, $path, $icon]): ?>
        <li>
          <a class="flex items-center gap-2.5 rounded-lg border p-3 transition hover:border-primary/50 hover:bg-accent/40" href="<?= $this->e($this->url($path)) ?>">
            <?= $this->icon($icon, 'h-4 w-4 text-muted-foreground') ?>
            <span class="flex-1"><?= $this->e($label) ?></span>
            <?= $this->icon('chevron-right', 'h-4 w-4 text-muted-foreground') ?>
          </a>
        </li>
      <?php endforeach ?>
    </ol>
  </div>

  <?php if ($autoLogin): ?>
    <a class="btn btn-success btn-lg btn-block" href="<?= $this->e($this->url('/admin')) ?>">
      <?= $this->icon('shield') ?> Перейти в админку
    </a>
    <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/logout')) ?>">Выйти (чтобы войти под другим логином)</a>
  <?php else: ?>
    <a class="btn btn-success btn-lg btn-block" href="<?= $this->e($this->url('/login')) ?>">
      <?= $this->icon('log-in') ?> Перейти к входу
    </a>
  <?php endif ?>
</div>

<?php if ($generatedPassword !== null && $generatedPassword !== ''): ?>
<?php $this->startPush('scripts') ?>
<script nonce="<?= $this->e($this->nonce()) ?>">
(function () {
  var btn = document.querySelector('[data-copy-generated]');
  var el = document.getElementById('generated-password');
  if (!btn || !el) return;
  btn.addEventListener('click', function () {
    var text = el.textContent;
    function done() { btn.textContent = 'Скопировано ✓'; }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, done);
    } else {
      var range = document.createRange();
      range.selectNodeContents(el);
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
      try { document.execCommand('copy'); } catch (e) {}
      done();
    }
  });
})();
</script>
<?php $this->endPush() ?>
<?php endif ?>
