<?php
/** @var array $sessions */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$sessions = $sessions ?? [];
?>
<?php $this->layout('layouts/app') ?>
<div class="page-header">
  <div><h2 class="page-title">Все активные сессии</h2><p class="page-desc">Кто сейчас работает в системе.</p></div>
  <div class="page-actions"><a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/users')) ?>"><?= $this->icon('arrow-left', 'h-3.5 w-3.5') ?> К пользователям</a></div>
</div>
<div class="card">
  <div class="card-content p-0">
    <?php if ($sessions === []): ?>
      <?= $this->include('components/empty', ['title' => 'Активных сессий нет', 'icon' => 'monitor']) ?>
    <?php else: ?>
      <div class="table-wrap rounded-none border-0">
        <table class="table">
          <thead><tr><th>Пользователь</th><th>Роль</th><th>IP</th><th>Устройство</th><th>Начата</th><th>Активность</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($sessions as $session): ?>
            <tr>
              <td class="text-sm font-medium"><?= $this->e((string) ($session['login'] ?? '—')) ?><div class="text-xs text-muted-foreground"><?= $this->e((string) ($session['full_name'] ?? '')) ?></div></td>
              <td><span class="badge badge-outline"><?= $this->e((string) ($session['role'] ?? '—')) ?></span></td>
              <td class="font-mono text-xs"><?= $this->e((string) ($session['ip'] ?? '—')) ?></td>
              <td class="max-w-[20rem] truncate text-xs text-muted-foreground"><?= $this->e((string) ($session['user_agent'] ?? '—')) ?></td>
              <td class="whitespace-nowrap text-xs tabular"><?= $this->e($this->datetime($session['created_at'] ?? null)) ?></td>
              <td class="whitespace-nowrap text-xs"><span data-time-ago="<?= $this->e((string) ($session['last_used_at'] ?? '')) ?>"></span></td>
              <td class="text-right"><?= !empty($session['is_current']) ? '<span class="badge badge-soft-success">ваша</span>' : '' ?></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
  </div>
</div>
