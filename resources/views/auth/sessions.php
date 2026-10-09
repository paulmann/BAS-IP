<?php
/**
 * All active sessions of the current user.
 *
 * @var array<int, array<string, mixed>> $sessions
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$sessions = $sessions ?? [];
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div>
    <h2 class="page-title">Активные сессии</h2>
    <p class="page-desc">Все устройства, где выполнен вход под вашей учётной записью.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline" href="<?= $this->e($this->url('/profile')) ?>"><?= $this->icon('arrow-left') ?> В профиль</a>
  </div>
</div>

<div class="card">
  <div class="card-content p-0">
    <?php if ($sessions === []): ?>
      <?= $this->include('components/empty', ['title' => 'Сессий нет', 'icon' => 'monitor']) ?>
    <?php else: ?>
      <div class="table-wrap rounded-none border-0">
        <table class="table" data-table>
          <thead>
            <tr><th>Устройство</th><th>IP-адрес</th><th>Начата</th><th>Активность</th><th class="text-right">Действие</th></tr>
          </thead>
          <tbody>
          <?php foreach ($sessions as $session): ?>
            <tr>
              <td>
                <div class="flex items-start gap-2">
                  <?= $this->icon(!empty($session['is_current']) ? 'monitor' : 'smartphone', 'h-4 w-4 mt-0.5 text-muted-foreground') ?>
                  <div class="min-w-0">
                    <div class="text-sm"><?= $this->e(\BasIp\Support\Str::limit((string) ($session['user_agent'] ?? 'Неизвестное устройство'), 70)) ?></div>
                    <?php if (!empty($session['is_current'])): ?>
                      <span class="badge badge-soft-success mt-1">текущая сессия</span>
                    <?php endif ?>
                  </div>
                </div>
              </td>
              <td class="font-mono text-xs"><?= $this->e((string) ($session['ip'] ?? '—')) ?></td>
              <td class="whitespace-nowrap text-xs tabular"><?= $this->e($this->datetime($session['created_at'] ?? null)) ?></td>
              <td class="whitespace-nowrap text-xs"><span data-time-ago="<?= $this->e((string) ($session['last_used_at'] ?? '')) ?>"></span></td>
              <td class="text-right">
                <?php if (empty($session['is_current'])): ?>
                  <form method="post" action="<?= $this->e($this->url('/profile/sessions/' . (int) $session['id'] . '/revoke')) ?>" data-ajax class="inline">
                    <?= $this->csrf() ?>
                    <button class="btn btn-outline btn-sm" type="submit" data-confirm="Завершить сессию?" data-confirm-label="Завершить">
                      <?= $this->icon('log-out', 'h-3.5 w-3.5') ?> Завершить
                    </button>
                  </form>
                <?php else: ?>
                  <span class="text-xs text-muted-foreground">—</span>
                <?php endif ?>
              </td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
  </div>
</div>

<div class="alert alert-info mt-5">
  <?= $this->icon('info', 'h-4 w-4') ?>
  <div>
    <div class="alert-title">Заметили чужое устройство?</div>
    <div class="alert-desc">
      Завершите сессию и сразу смените пароль в <a class="underline underline-offset-4" href="<?= $this->e($this->url('/profile')) ?>?tab=security">профиле</a>.
      Смена пароля автоматически завершает все остальные сессии.
    </div>
  </div>
</div>
