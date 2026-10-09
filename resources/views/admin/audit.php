<?php
/**
 * Audit trail.
 *
 * @var BasIp\Support\Paginator $entries
 * @var array $filters
 * @var array $query
 * @var array<int, string> $actions
 * @var array $users
 * @var bool $canExport
 * @var int $retention
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$items = $entries->items();
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Журнал аудита</h2>
    <p class="page-desc">Кто, что и когда изменил. Записи неизменяемы; хранятся <?= (int) $retention ?> дней.</p>
  </div>
  <div class="page-actions">
    <?php if ($canExport): ?>
      <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/audit/export', $query)) ?>"><?= $this->icon('download', 'h-3.5 w-3.5') ?> CSV</a>
    <?php endif ?>
  </div>
</div>

<div class="card">
  <form class="filter-bar rounded-t-xl" method="get" action="<?= $this->e($this->url('/admin/audit')) ?>">
    <div class="filter-field min-w-[14rem] flex-[2]">
      <label class="label" for="q">Поиск</label>
      <input class="input" id="q" name="q" type="search" value="<?= $this->e((string) ($filters['q'] ?? '')) ?>" placeholder="Действие, субъект, IP…">
    </div>
    <div class="filter-field">
      <label class="label" for="action">Действие</label>
      <select class="select" id="action" name="action">
        <option value="">Любое</option>
        <?php foreach ($actions as $action): ?>
          <option value="<?= $this->e($action) ?>" <?= $this->selected($filters['action'] ?? '', $action) ?>><?= $this->e($action) ?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="filter-field">
      <label class="label" for="subject_type">Объект</label>
      <select class="select" id="subject_type" name="subject_type">
        <option value="">Любой</option>
        <?php foreach (['resident', 'user', 'charge', 'invoice', 'payment', 'guest_pass', 'device', 'settings', 'secret', 'api_token', 'backup', 'system', 'announcement', 'schedule', 'batch'] as $type): ?>
          <option value="<?= $type ?>" <?= $this->selected($filters['subject_type'] ?? '', $type) ?>><?= $type ?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="filter-field">
      <label class="label" for="user_id">Кто</label>
      <select class="select" id="user_id" name="user_id">
        <option value="">Все</option>
        <?php foreach ($users as $user): ?>
          <option value="<?= (int) $user['id'] ?>" <?= (string) ($filters['user_id'] ?? '') === (string) $user['id'] ? 'selected' : '' ?>><?= $this->e((string) $user['login']) ?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="filter-field">
      <label class="label" for="from">Период с</label>
      <input class="input" id="from" name="from" type="date" value="<?= $this->e((string) ($filters['from'] ?? '')) ?>" data-date-from>
    </div>
    <div class="filter-field">
      <label class="label" for="to">по</label>
      <input class="input" id="to" name="to" type="date" value="<?= $this->e((string) ($filters['to'] ?? '')) ?>" data-date-to>
    </div>
    <div class="flex items-center gap-2">
      <label class="chip <?= !empty($filters['security']) ? 'is-active' : '' ?>"><input type="checkbox" class="sr-only" name="security" value="1" <?= !empty($filters['security']) ? 'checked' : '' ?>> только безопасность</label>
      <button class="btn btn-default btn-sm" type="submit"><?= $this->icon('filter', 'h-3.5 w-3.5') ?> Применить</button>
      <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/audit')) ?>"><?= $this->icon('rotate-ccw', 'h-3.5 w-3.5') ?></a>
    </div>
  </form>

  <div class="card-content p-0">
    <?php if ($items === []): ?>
      <?= $this->include('components/empty', ['title' => 'Записей не найдено', 'icon' => 'scroll-text']) ?>
    <?php else: ?>
      <div class="table-wrap rounded-none border-0">
        <table class="table">
          <thead><tr><th>Когда</th><th>Действие</th><th>Кто</th><th>Объект</th><th>Изменения</th><th>IP</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($items as $entry): ?>
            <tr>
              <td class="whitespace-nowrap text-xs tabular"><?= $this->e($this->datetime($entry['occurred_at'] ?? null)) ?></td>
              <td>
                <span class="badge badge-soft-<?= !empty($entry['is_security']) ? 'warning' : 'info' ?> font-mono text-[11px]"><?= $this->e((string) ($entry['action'] ?? '')) ?></span>
              </td>
              <td class="text-sm">
                <div class="truncate"><?= $this->e((string) ($entry['actor_name'] ?? 'system')) ?></div>
                <div class="text-xs text-muted-foreground"><?= $this->e((string) ($entry['actor_role'] ?? '')) ?></div>
              </td>
              <td class="text-sm">
                <?php if (!empty($entry['subject_type'])): ?>
                  <div class="font-mono text-xs text-muted-foreground"><?= $this->e((string) $entry['subject_type']) ?><?= !empty($entry['subject_id']) ? '#' . $this->e((string) $entry['subject_id']) : '' ?></div>
                  <div class="max-w-[16rem] truncate"><?= $this->e((string) ($entry['subject_label'] ?? '')) ?></div>
                <?php else: ?><span class="text-muted-foreground">—</span><?php endif ?>
              </td>
              <td class="max-w-[22rem]">
                <?php $changes = json_decode((string) ($entry['changes'] ?? ''), true); ?>
                <?php if (is_array($changes) && !empty($changes['diff'])): ?>
                  <div class="grid gap-0.5 text-xs">
                    <?php foreach (array_slice((array) $changes['diff'], 0, 4, true) as $field => $diff): ?>
                      <div class="flex flex-wrap items-center gap-1">
                        <code class="font-mono text-[11px] text-muted-foreground"><?= $this->e((string) $field) ?>:</code>
                        <span class="diff-del rounded px-1"><?= $this->e(\BasIp\Support\Str::limit((string) ($diff['from'] ?? ''), 24)) ?></span>
                        <?= $this->icon('arrow-right', 'h-3 w-3 text-muted-foreground') ?>
                        <span class="diff-add rounded px-1"><?= $this->e(\BasIp\Support\Str::limit((string) ($diff['to'] ?? ''), 24)) ?></span>
                      </div>
                    <?php endforeach ?>
                    <?php if (count((array) $changes['diff']) > 4): ?>
                      <div class="text-[11px] text-muted-foreground">…и ещё <?= count((array) $changes['diff']) - 4 ?> полей</div>
                    <?php endif ?>
                  </div>
                <?php elseif (!empty($entry['meta'])): ?>
                  <code class="block max-w-full truncate font-mono text-[11px] text-muted-foreground"><?= $this->e(\BasIp\Support\Str::limit((string) $entry['meta'], 90)) ?></code>
                <?php else: ?>
                  <span class="text-xs text-muted-foreground">—</span>
                <?php endif ?>
              </td>
              <td class="font-mono text-xs"><?= $this->e(\BasIp\Support\Ip::anonymize((string) ($entry['ip'] ?? ''))) ?></td>
              <td class="text-right">
                <?php if (!empty($entry['changes']) || !empty($entry['meta'])): ?>
                  <button class="btn btn-ghost btn-sm btn-icon" type="button" data-dialog="#dialog-audit-<?= (int) $entry['id'] ?>" aria-label="Подробнее"><?= $this->icon('eye', 'h-3.5 w-3.5') ?></button>
                <?php endif ?>
              </td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <div class="p-4"><?= $this->include('components/pagination', ['paginator' => $entries, 'query' => $query, 'target' => '/admin/audit']) ?></div>
    <?php endif ?>
  </div>
</div>

<?php foreach ($items as $entry): if (empty($entry['changes']) && empty($entry['meta'])) { continue; } ?>
  <div class="dialog dialog-lg" id="dialog-audit-<?= (int) $entry['id'] ?>" data-state="closed" hidden role="dialog" aria-labelledby="au-<?= (int) $entry['id'] ?>">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <div class="dialog-header">
      <h2 class="dialog-title font-mono" id="au-<?= (int) $entry['id'] ?>"><?= $this->e((string) ($entry['action'] ?? '')) ?></h2>
      <p class="dialog-desc"><?= $this->e($this->datetime($entry['occurred_at'] ?? null)) ?> · <?= $this->e((string) ($entry['actor_name'] ?? '')) ?> · <?= $this->e((string) ($entry['subject_type'] ?? '')) ?>#<?= $this->e((string) ($entry['subject_id'] ?? '')) ?></p>
    </div>
    <?php if (!empty($entry['changes'])): ?>
      <div class="field">
        <div class="label">Изменения</div>
        <pre class="code-block max-h-72 overflow-auto"><code><?= $this->e($this->prettyJson((string) $entry['changes'])) ?></code></pre>
      </div>
    <?php endif ?>
    <?php if (!empty($entry['meta'])): ?>
      <div class="field">
        <div class="label">Дополнительно</div>
        <pre class="code-block max-h-52 overflow-auto"><code><?= $this->e($this->prettyJson((string) $entry['meta'])) ?></code></pre>
      </div>
    <?php endif ?>
    <div class="grid gap-1.5 text-xs text-muted-foreground">
      <div class="flex justify-between gap-2"><span>IP</span><b class="font-mono"><?= $this->e((string) ($entry['ip'] ?? '—')) ?></b></div>
      <div class="flex justify-between gap-2"><span>ID запроса</span><b class="font-mono"><?= $this->e((string) ($entry['request_id'] ?? '—')) ?></b></div>
      <div class="flex justify-between gap-2"><span>Безопасность</span><b><?= !empty($entry['is_security']) ? 'да' : 'нет' ?></b></div>
    </div>
    <div class="mt-2 truncate text-[11px] text-muted-foreground">User-Agent: <?= $this->e((string) ($entry['user_agent'] ?? '—')) ?></div>
  </div>
<?php endforeach ?>
