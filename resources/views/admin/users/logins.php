<?php
/**
 * Login attempts audit.
 *
 * @var BasIp\Support\Paginator $attempts
 * @var array $query
 * @var array $stats
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$items = $attempts->items();
?>
<?php $this->layout('layouts/app') ?>
<div class="page-header">
  <div><h2 class="page-title">История входов</h2><p class="page-desc">Все попытки аутентификации — успешные и неудачные. Хранятся <?= (int) $this->config('auth.login.history_days', 90) ?> дней.</p></div>
  <div class="page-actions"><a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/users')) ?>"><?= $this->icon('arrow-left', 'h-3.5 w-3.5') ?> К пользователям</a></div>
</div>

<div class="grid gap-3 sm:grid-cols-3">
  <?= $this->include('components/stat', ['label' => 'Попыток за 24 ч', 'value' => (string) (int) $stats['total_24h'], 'icon' => 'activity']) ?>
  <?= $this->include('components/stat', ['label' => 'Неудачных за 24 ч', 'value' => (string) (int) $stats['failed_24h'], 'icon' => 'alert-triangle', 'accent' => (int) $stats['failed_24h'] > 10 ? 'destructive' : '']) ?>
  <?= $this->include('components/stat', ['label' => 'Уникальных IP с ошибками', 'value' => (string) (int) $stats['unique_ips'], 'icon' => 'globe']) ?>
</div>

<div class="card mt-5">
  <form class="filter-bar rounded-t-xl" method="get" action="<?= $this->e($this->url('/admin/users/logins')) ?>">
    <div class="filter-field min-w-[14rem] flex-[2]">
      <label class="label" for="q">Поиск</label>
      <input class="input" id="q" name="q" type="search" value="<?= $this->e((string) ($query['q'] ?? '')) ?>" placeholder="Логин или IP">
    </div>
    <label class="chip <?= !empty($query['only_failed']) ? 'is-active' : '' ?>"><input type="checkbox" class="sr-only" name="only_failed" value="1" <?= !empty($query['only_failed']) ? 'checked' : '' ?> onchange="this.form.submit()"> только неудачные</label>
    <button class="btn btn-default btn-sm" type="submit"><?= $this->icon('filter', 'h-3.5 w-3.5') ?> Применить</button>
  </form>
  <div class="card-content p-0">
    <?php if ($items === []): ?>
      <?= $this->include('components/empty', ['title' => 'Записей нет', 'icon' => 'history']) ?>
    <?php else: ?>
      <div class="table-wrap rounded-none border-0">
        <table class="table">
          <thead><tr><th>Когда</th><th>Кто</th><th>Результат</th><th>Способ</th><th>IP</th><th>Устройство</th></tr></thead>
          <tbody>
          <?php foreach ($items as $entry): ?>
            <tr>
              <td class="whitespace-nowrap text-xs tabular"><?= $this->e($this->datetime($entry['created_at'] ?? null)) ?></td>
              <td class="text-sm"><?= $this->e((string) ($entry['user_login'] ?? $entry['identifier'] ?? '—')) ?></td>
              <td><?= !empty($entry['successful']) ? '<span class="badge badge-soft-success">успешно</span>' : '<span class="badge badge-soft-destructive">' . $this->e($this->loginFailureLabel((string) ($entry['reason'] ?? ''))) . '</span>' ?></td>
              <td class="text-xs"><?= $this->e($this->loginMethodLabel((string) ($entry['method'] ?? ''))) ?></td>
              <td class="font-mono text-xs"><?= $this->e(\BasIp\Support\Ip::anonymize((string) ($entry['ip'] ?? ''))) ?></td>
              <td class="max-w-[20rem] truncate text-xs text-muted-foreground"><?= $this->e((string) ($entry['user_agent'] ?? '—')) ?></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <div class="p-4"><?= $this->include('components/pagination', ['paginator' => $attempts, 'query' => $query, 'target' => '/admin/users/logins']) ?></div>
    <?php endif ?>
  </div>
</div>
