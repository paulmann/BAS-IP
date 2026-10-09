<?php
/**
 * Single log channel viewer.
 *
 * @var string $channel
 * @var array<int, array<string, mixed>> $entries
 * @var array $files
 * @var array $query
 * @var bool $canTruncate
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title flex items-center gap-2">
      <?= $this->icon($this->channelIcon($channel), 'h-5 w-5') ?>
      <?= $this->e($this->channelLabel($channel)) ?>
    </h2>
    <p class="page-desc">Последние <?= count($entries) ?> записей канала <code class="inline-code"><?= $this->e($channel) ?></code>. Новые сверху.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/system/logs')) ?>"><?= $this->icon('arrow-left', 'h-3.5 w-3.5') ?> Все журналы</a>
    <?php if ($canTruncate): ?>
      <form method="post" action="<?= $this->e($this->url('/admin/system/logs/' . rawurlencode($channel) . '/truncate')) ?>" class="inline">
        <?= $this->csrf() ?>
        <button class="btn btn-outline btn-sm text-destructive" type="submit"
                data-confirm="Очистить журнал «<?= $this->e($channel) ?>»? Записи будут удалены безвозвратно." data-confirm-label="Очистить" data-confirm-tone="destructive">
          <?= $this->icon('trash', 'h-3.5 w-3.5') ?> Очистить
        </button>
      </form>
    <?php endif ?>
  </div>
</div>

<div class="card">
  <form class="filter-bar rounded-t-xl" method="get" action="<?= $this->e($this->url('/admin/system/logs/' . rawurlencode($channel))) ?>">
    <div class="filter-field min-w-[14rem] flex-[2]">
      <label class="label" for="q">Поиск по тексту</label>
      <input class="input" id="q" name="q" type="search" value="<?= $this->e((string) ($query['q'] ?? '')) ?>" placeholder="Сообщение, IP, имя…">
    </div>
    <div class="filter-field">
      <label class="label" for="level">Уровень</label>
      <select class="select" id="level" name="level">
        <option value="">Любой</option>
        <?php foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $level): ?>
          <option value="<?= $level ?>" <?= $this->selected((string) ($query['level'] ?? ''), $level) ?>><?= $level ?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="filter-field">
      <label class="label" for="lines">Строк</label>
      <select class="select" id="lines" name="lines">
        <?php foreach ([100, 300, 1000, 2000] as $option): ?>
          <option value="<?= $option ?>" <?= (int) ($query['lines'] ?? 300) === $option ? 'selected' : '' ?>><?= $option ?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="flex items-center gap-2">
      <button class="btn btn-default btn-sm" type="submit"><?= $this->icon('filter', 'h-3.5 w-3.5') ?> Применить</button>
      <button class="btn btn-outline btn-sm" type="button" onclick="window.location.reload()"><?= $this->icon('refresh-cw', 'h-3.5 w-3.5') ?> Обновить</button>
    </div>
  </form>

  <div class="card-content p-0">
    <?php if ($entries === []): ?>
      <?= $this->include('components/empty', ['title' => 'Записей нет', 'description' => 'Журнал пуст или под фильтр ничего не попало.', 'icon' => 'file-text']) ?>
    <?php else: ?>
      <div class="max-h-[70vh] overflow-auto p-2 font-mono text-xs">
        <?php foreach ($entries as $entry): ?>
          <?php
            $level = strtoupper((string) ($entry['level'] ?? 'info'));
            $context = (array) ($entry['context'] ?? []);
            $contextJson = $context === [] ? '' : (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          ?>
          <details class="log-line group">
            <summary class="flex w-full cursor-pointer list-none items-baseline gap-2">
              <span class="log-ts shrink-0"><?= $this->e((string) ($entry['ts'] ?? '')) ?></span>
              <span class="log-level log-level-<?= $this->e(strtolower($level)) ?> shrink-0"><?= $this->e($level) ?></span>
              <span class="min-w-0 flex-1 break-words"><?= $this->e((string) ($entry['message'] ?? '')) ?></span>
              <?php if ($contextJson !== ''): ?><span class="shrink-0 text-muted-foreground group-open:hidden"><?= $this->icon('chevron-right', 'h-3 w-3') ?></span><?php endif ?>
            </summary>
            <?php if ($contextJson !== ''): ?>
              <pre class="mt-1.5 max-h-64 overflow-auto whitespace-pre-wrap rounded bg-muted/50 p-2 text-[11px]"><?= $this->e($this->prettyJson($contextJson)) ?></pre>
            <?php endif ?>
          </details>
        <?php endforeach ?>
      </div>
    <?php endif ?>
  </div>
</div>

<div class="alert alert-muted mt-5">
  <?= $this->icon('info', 'h-4 w-4') ?>
  <div class="alert-desc">
    Журналы пишутся в формате JSON Lines — их можно отдавать в любой агрегатор (Loki, Graylog, ELK).
    Пароли, токены и другие секреты вымарываются до записи. Ротация настраивается в <code class="inline-code">config/config.php</code> → <code class="inline-code">logging.max_files</code>.
  </div>
</div>
