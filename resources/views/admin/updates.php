<?php
/**
 * Self-update centre.
 *
 * @var array $info
 * @var array $snapshots
 * @var array<int, string> $preserve
 * @var array $history
 * @var bool $canRun
 * @var bool $canRollback
 * @var string $changelog
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$release = $info['release'] ?? null;
$available = !empty($info['available']);
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Обновления</h2>
    <p class="page-desc">
      Установлена версия <b class="text-foreground"><?= $this->e((string) $info['current']) ?></b>
      · канал <b><?= $this->e((string) $info['channel']) ?></b>
      · репозиторий <code class="inline-code"><?= $this->e((string) $info['repo']) ?></code>
    </p>
  </div>
  <div class="page-actions">
    <form method="post" action="<?= $this->e($this->url('/admin/updates/check')) ?>" class="inline">
      <?= $this->csrf() ?>
      <input type="hidden" name="force" value="1">
      <button class="btn btn-outline btn-sm" type="submit"><?= $this->icon('refresh-cw', 'h-3.5 w-3.5') ?> Проверить сейчас</button>
    </form>
    <?php if ($canRun && $available): ?>
      <form method="post" action="<?= $this->e($this->url('/admin/updates/install')) ?>" class="inline">
        <?= $this->csrf() ?>
        <input type="hidden" name="confirm" value="">
        <button class="btn btn-success btn-sm" type="submit"
                data-confirm="Установить версию <?= $this->e((string) ($release['version'] ?? '')) ?>? Будет создана резервная копия и снимок для отката. Локальный конфиг и данные не затрагиваются."
                data-confirm-title="Обновление системы" data-confirm-label="Установить" data-confirm-text="UPDATE">
          <?= $this->icon('download-cloud', 'h-3.5 w-3.5') ?> Обновить до <?= $this->e((string) ($release['version'] ?? '')) ?>
        </button>
      </form>
    <?php endif ?>
  </div>
</div>

<?php if (!$info['enabled']): ?>
  <div class="alert alert-warning mb-5">
    <?= $this->icon('alert-triangle', 'h-4 w-4') ?>
    <div>
      <div class="alert-title">Проверка обновлений отключена</div>
      <div class="alert-desc">Включите её в <code class="inline-code">.bas-ip.config.local.php</code>: <code class="inline-code">'update' => ['enabled' => true]</code>.</div>
    </div>
  </div>
<?php endif ?>

<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
  <div class="grid content-start gap-5">

    <?php if ($available && $release !== null): ?>
      <div class="card border-info/40">
        <div class="card-header">
          <div class="card-title flex items-center gap-2 text-info"><?= $this->icon('download-cloud', 'h-4 w-4') ?> Доступна версия <?= $this->e((string) ($release['version'] ?? '')) ?></div>
          <div class="card-desc">
            <?= $this->e((string) ($release['name'] ?? '')) ?>
            <?php if (!empty($release['published_at'])): ?> · <?= $this->e($this->date((string) $release['published_at'])) ?><?php endif ?>
          </div>
        </div>
        <div class="card-content grid gap-4">
          <?php if (!empty($release['body'])): ?>
            <div class="prose-basip max-h-80 overflow-auto rounded-lg border bg-muted/25 p-4">
              <?= \BasIp\Support\Markdown::toHtml((string) $release['body']) ?>
            </div>
          <?php endif ?>
          <?php if (!empty($release['url'])): ?>
            <a class="btn btn-outline btn-sm justify-self-start" href="<?= $this->e((string) $release['url']) ?>" target="_blank" rel="noopener noreferrer">
              <?= $this->icon('external-link', 'h-3.5 w-3.5') ?> Страница релиза на GitHub
            </a>
          <?php endif ?>
          <?php if ($canRun): ?>
            <form method="post" action="<?= $this->e($this->url('/admin/updates/install')) ?>">
              <?= $this->csrf() ?>
              <input type="hidden" name="confirm" value="UPDATE">
              <button class="btn btn-success" type="submit"><?= $this->icon('download-cloud') ?> Скачать и установить</button>
            </form>
          <?php endif ?>
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-success">
        <?= $this->icon('check-circle', 'h-4 w-4') ?>
        <div>
          <div class="alert-title">Установлена актуальная версия</div>
          <div class="alert-desc">
            <?= $this->e((string) $info['current']) ?>
            <?php if (!empty($info['last_check'])): ?> · проверено <?= $this->e($this->datetime((string) $info['last_check'])) ?><?php endif ?>
          </div>
        </div>
      </div>
    <?php endif ?>

    <div class="card">
      <div class="card-header">
        <div class="card-title flex items-center gap-2"><?= $this->icon('shield-check', 'h-4 w-4') ?> Как проходит обновление</div>
        <div class="card-desc">Порядок действий и что при этом не затрагивается.</div>
      </div>
      <div class="card-content grid gap-4">
        <ol class="grid gap-2.5 text-sm text-muted-foreground">
          <?php foreach ([
            'Проверка версии в репозитории GitHub (сравнение semver).',
            'Включение режима обслуживания — веб-интерфейс закрывается, шлагбаум продолжает работать.',
            'Снимок текущих файлов (src, public, config, database, resources) для возможного отката.',
            'Резервная копия базы данных.',
            'Скачивание архива и проверка контрольной суммы SHA-256 (если задана).',
            'Распаковка и копирование файлов поверх текущих, с защитой от zip-slip.',
            'Применение новых миграций базы данных.',
            'Очистка кэша, выключение режима обслуживания, запись в аудит.',
          ] as $index => $step): ?>
            <li class="flex gap-2.5">
              <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/12 text-[11px] font-semibold text-primary"><?= $index + 1 ?></span>
              <span><?= $this->e($step) ?></span>
            </li>
          <?php endforeach ?>
        </ol>

        <div class="alert alert-success">
          <?= $this->icon('check-circle', 'h-4 w-4') ?>
          <div>
            <div class="alert-title">Что НЕ затрагивается</div>
            <div class="alert-desc">
              <ul class="mt-1 grid gap-0.5">
                <li>· <code class="inline-code">.bas-ip.config.local.php</code>, <code class="inline-code">config/*.local.php</code>, <code class="inline-code">.env</code></li>
                <li>· <code class="inline-code">storage/</code> — журналы, кэш, сессии, загрузки, резервные копии, хранилище секретов</li>
                <li>· <code class="inline-code">public/install.lock</code></li>
                <li>· вся база данных (миграции только добавляют недостающее)</li>
              </ul>
            </div>
          </div>
        </div>

        <div>
          <div class="label mb-1.5">Список сохраняемых путей (<code class="inline-code">update.preserve</code>)</div>
          <div class="flex flex-wrap gap-1.5">
            <?php foreach ($preserve as $path): ?><span class="badge badge-outline font-mono text-[11px]"><?= $this->e($path) ?></span><?php endforeach ?>
          </div>
        </div>
      </div>
    </div>

    <?php if ($changelog !== ''): ?>
      <div class="card">
        <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('scroll-text', 'h-4 w-4') ?> CHANGELOG</div></div>
        <div class="card-content">
          <div class="prose-basip max-h-[32rem] overflow-auto"><?= \BasIp\Support\Markdown::toHtml($changelog) ?></div>
        </div>
      </div>
    <?php endif ?>
  </div>

  <aside class="grid content-start gap-5">
    <div class="card">
      <div class="card-header"><div class="card-title">Параметры обновления</div></div>
      <div class="card-content grid gap-1.5 text-sm">
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Включено</span><b><?= $info['enabled'] ? 'да' : 'нет' ?></b></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Режим</span><b><?= $this->e((string) $info['mode']) ?></b></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Стратегия</span><b><?= $this->e((string) $info['strategy']) ?></b></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Канал</span><b><?= $this->e((string) $info['channel']) ?></b></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Мин. PHP</span><b><?= $this->e((string) $info['php_min']) ?></b></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Токен GitHub</span><b><?= $info['has_token'] ? 'задан' : 'не задан' ?></b></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Текущий PHP</span><b><?= $this->e(PHP_VERSION) ?></b></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title flex items-center gap-2"><?= $this->icon('archive', 'h-4 w-4') ?> Снимки для отката</div>
        <div class="card-desc">Хранится <?= (int) $this->config('update.keep_releases', 5) ?> последних.</div>
      </div>
      <div class="card-content grid gap-2">
        <?php if ($snapshots === []): ?>
          <p class="text-sm text-muted-foreground">Снимков пока нет — первый появится при обновлении.</p>
        <?php else: ?>
          <ul class="grid gap-1.5">
            <?php foreach ($snapshots as $snapshot): ?>
              <li class="flex items-center gap-2 rounded-md border bg-muted/20 px-2.5 py-1.5">
                <code class="min-w-0 flex-1 truncate font-mono text-[11px]"><?= $this->e((string) $snapshot['file']) ?></code>
                <span class="shrink-0 text-[11px] tabular text-muted-foreground"><?= $this->e(\BasIp\Support\Str::bytes((int) $snapshot['bytes'])) ?></span>
              </li>
            <?php endforeach ?>
          </ul>
          <?php if ($canRollback): ?>
            <form method="post" action="<?= $this->e($this->url('/admin/updates/rollback')) ?>">
              <?= $this->csrf() ?>
              <input type="hidden" name="confirm" value="ROLLBACK">
              <button class="btn btn-outline btn-block btn-sm text-warning" type="submit"
                      data-confirm="Откатиться к предыдущей версии? Текущие файлы будут заменены снимком."
                      data-confirm-title="Откат версии" data-confirm-label="Откатить" data-confirm-tone="destructive" data-confirm-text="ROLLBACK">
                <?= $this->icon('rotate-ccw', 'h-3.5 w-3.5') ?> Откатить версию
              </button>
            </form>
          <?php endif ?>
        <?php endif ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('terminal', 'h-4 w-4') ?> Из консоли</div></div>
      <div class="card-content">
        <pre class="code-block"><code>php bin/bas-ip update:check          # проверить
php bin/bas-ip update:check --json   # для cron/мониторинга
php bin/bas-ip update:run            # скачать и установить
php bin/bas-ip update:run --ref=v2.1.0   # конкретный тег
php bin/bas-ip update:rollback       # откат
php bin/bas-ip update:snapshots      # список снимков</code></pre>
        <p class="mt-2 text-xs text-muted-foreground">
          Для автоматической установки по cron: <code class="inline-code">bin/bas-ip schedule:run --update</code>
          и режим <code class="inline-code">update.mode: auto</code> в конфиге.
        </p>
      </div>
    </div>

    <?php if ($history !== []): ?>
      <div class="card">
        <div class="card-header"><div class="card-title">Журнал обновлений</div></div>
        <div class="card-content p-0">
          <div class="max-h-72 overflow-auto p-2 font-mono text-[11px]">
            <?php foreach ($history as $entry): ?>
              <div class="log-line">
                <span class="log-ts"><?= $this->e((string) ($entry['ts'] ?? '')) ?></span>
                <span class="log-level log-level-<?= $this->e((string) ($entry['level'] ?? 'info')) ?>"><?= $this->e(strtoupper((string) ($entry['level'] ?? ''))) ?></span>
                <span class="min-w-0 flex-1 break-words"><?= $this->e((string) ($entry['message'] ?? '')) ?></span>
              </div>
            <?php endforeach ?>
          </div>
        </div>
      </div>
    <?php endif ?>
  </aside>
</div>

<?php $this->startPush('scripts') ?>
<script nonce="<?= $this->e($this->nonce()) ?>">
// Кнопки с data-confirm-text подставляют подтверждение в скрытое поле confirm.
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-confirm-text]');
  if (!btn) return;
  var form = btn.closest('form');
  if (!form) return;
  var field = form.querySelector('input[name="confirm"]');
  if (!field) return;
  var value = btn.getAttribute('data-confirm-text');
  var original = field.value;
  field.value = value;
  // app.js прочитает data-confirm и покажет диалог; после подтверждения форма отправится.
  setTimeout(function () { if (!form.dataset.confirmed) field.value = original; }, 300);
});
</script>
<?php $this->endPush() ?>
