<?php
/**
 * Notification templates reference.
 *
 * @var array $templates
 * @var array $placeholders
 * @var array<int, string> $names
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Шаблоны уведомлений</h2>
    <p class="page-desc">Шаблоны описаны в <code class="inline-code">config/config.php</code> → <code class="inline-code">notify.templates</code>. Переопределите их в локальном конфиге, чтобы не потерять при обновлении.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/notifications')) ?>"><?= $this->icon('arrow-left', 'h-3.5 w-3.5') ?> К рассылкам</a>
  </div>
</div>

<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
  <div class="grid content-start gap-4">
    <?php foreach ($templates as $name => $template): ?>
      <div class="card">
        <div class="card-header has-action">
          <div>
            <div class="card-title font-mono text-sm"><?= $this->e((string) $name) ?></div>
            <div class="card-desc">Тема: <?= $this->e((string) ($template['subject'] ?? '—')) ?></div>
          </div>
          <button class="btn btn-outline btn-sm" type="button" data-copy="<?= $this->e((string) ($template['body'] ?? '')) ?>" data-copy-message="Шаблон скопирован">
            <?= $this->icon('copy', 'h-3.5 w-3.5') ?> Копировать
          </button>
        </div>
        <div class="card-content">
          <pre class="code-block"><code><?= $this->e((string) ($template['body'] ?? '')) ?></code></pre>
          <div class="mt-3 rounded-lg border bg-muted/25 p-3">
            <div class="label mb-2">Предпросмотр</div>
            <div class="text-sm font-semibold" data-tpl-subject="<?= $this->e((string) $name) ?>"></div>
            <pre class="mt-1 whitespace-pre-wrap font-sans text-sm text-muted-foreground" data-tpl-body="<?= $this->e((string) $name) ?>"></pre>
          </div>
        </div>
      </div>
    <?php endforeach ?>
  </div>

  <aside class="grid content-start gap-5">
    <div class="card">
      <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('braces', 'h-4 w-4') ?> Подстановки</div></div>
      <div class="card-content grid gap-2">
        <?php foreach ($placeholders as $token => $hint): ?>
          <div class="grid gap-0.5 rounded-md border p-2">
            <code class="font-mono text-xs font-semibold text-primary"><?= $this->e($token) ?></code>
            <span class="text-xs text-muted-foreground"><?= $this->e($hint) ?></span>
          </div>
        <?php endforeach ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title">Модификаторы</div></div>
      <div class="card-content grid gap-2 text-xs">
        <p class="text-muted-foreground">Подстановку можно форматировать через «трубу»:</p>
        <div class="grid gap-1.5">
          <?php foreach ([
            '{amount|money}' => 'формат валюты: 5 000 ₽',
            '{due_date|date:d.m.Y}' => 'переформатировать дату',
            '{full_name|upper}' => 'ВЕРХНИЙ РЕГИСТР',
            '{name|limit:20}' => 'обрезать до 20 символов',
            '{count|plural:житель,жителя,жителей}' => 'склонение',
            '{phone|mask}' => 'скрыть часть номера',
            '{value|default:—}' => 'значение по умолчанию',
          ] as $example => $hint): ?>
            <div class="rounded-md border bg-muted/25 p-2">
              <code class="font-mono text-[11px] text-primary"><?= $this->e($example) ?></code>
              <div class="text-[11px] text-muted-foreground"><?= $this->e($hint) ?></div>
            </div>
          <?php endforeach ?>
        </div>
      </div>
    </div>
  </aside>
</div>

<?php $this->startPush('scripts') ?>
<script nonce="<?= $this->e($this->nonce()) ?>">
(function () {
  var B = window.BasIp;
  if (!B) return;
  var names = <?= $this->json(array_keys($templates)) ?>;
  names.forEach(function (name) {
    B.post(<?= $this->json($this->url('/admin/notifications/preview')) ?>, { template: name }).then(function (data) {
      var s = document.querySelector('[data-tpl-subject="' + name + '"]');
      var b = document.querySelector('[data-tpl-body="' + name + '"]');
      if (s) s.textContent = data.subject || '';
      if (b) b.textContent = data.body || '';
    }).catch(function () {});
  });
})();
</script>
<?php $this->endPush() ?>
