<?php
/**
 * Single account.
 *
 * @var BasIp\Auth\User $account
 * @var array $sessions
 * @var array $logins
 * @var array $audit
 * @var BasIp\Domain\Model\Resident|null $resident
 * @var array<int, string> $permissions
 * @var array<string, string> $permissionLabels
 * @var array $roles
 * @var bool $canEdit
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="flex min-w-0 items-start gap-3">
    <span class="avatar avatar-xl shrink-0"><?= $this->e($account->initials()) ?></span>
    <div class="min-w-0">
      <h2 class="page-title truncate"><?= $this->e($account->displayName()) ?></h2>
      <p class="page-desc">@<?= $this->e($account->login) ?><?= $account->email !== null ? ' · ' . $this->e($account->email) : '' ?> · <?= $this->e($account->roleLabel()) ?></p>
      <div class="mt-2 flex flex-wrap gap-1.5">
        <?= $account->isActive ? '<span class="badge badge-soft-success">активен</span>' : '<span class="badge badge-muted">отключён</span>' ?>
        <?= $account->totpEnabled ? '<span class="badge badge-soft-success">2FA</span>' : '<span class="badge badge-soft-warning">без 2FA</span>' ?>
        <?php if ($account->isLocked()): ?><span class="badge badge-soft-destructive">заблокирован до <?= $this->e($account->lockedUntil ?? '') ?></span><?php endif ?>
        <?php if ($account->mustChangePassword): ?><span class="badge badge-soft-warning">сменит пароль при входе</span><?php endif ?>
      </div>
    </div>
  </div>
  <div class="page-actions">
    <?php if ($canEdit): ?>
      <form method="post" action="<?= $this->e($this->url('/admin/users/' . $account->id . '/unlock')) ?>" data-ajax class="inline">
        <?= $this->csrf() ?>
        <button class="btn btn-outline btn-sm" type="submit"><?= $this->icon('unlock', 'h-3.5 w-3.5') ?> Разблокировать</button>
      </form>
      <form method="post" action="<?= $this->e($this->url('/admin/users/' . $account->id . '/logout')) ?>" data-ajax class="inline">
        <?= $this->csrf() ?>
        <button class="btn btn-outline btn-sm" type="submit" data-confirm="Завершить все сессии?" data-confirm-label="Завершить"><?= $this->icon('log-out', 'h-3.5 w-3.5') ?> Завершить сессии</button>
      </form>
    <?php endif ?>
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/users')) ?>"><?= $this->icon('arrow-left', 'h-3.5 w-3.5') ?> К списку</a>
  </div>
</div>

<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]" data-tabs data-tabs-persist="user" data-tabs-hash>
  <div class="grid content-start gap-5">
    <div class="tabs-list is-scrollable" role="tablist">
      <button class="tabs-trigger" role="tab" data-tab="sessions" data-state="active" aria-selected="true"><?= $this->icon('monitor') ?> Сессии <span class="count"><?= count($sessions) ?></span></button>
      <button class="tabs-trigger" role="tab" data-tab="logins" data-state="inactive" aria-selected="false"><?= $this->icon('history') ?> Входы <span class="count"><?= count($logins) ?></span></button>
      <button class="tabs-trigger" role="tab" data-tab="rights" data-state="inactive" aria-selected="false"><?= $this->icon('shield') ?> Права</button>
      <button class="tabs-trigger" role="tab" data-tab="audit" data-state="inactive" aria-selected="false"><?= $this->icon('scroll-text') ?> Аудит</button>
    </div>

    <section class="tabs-content" role="tabpanel" data-tab-panel="sessions">
      <div class="card">
        <div class="card-header"><div class="card-title">Активные сессии</div></div>
        <div class="card-content p-0">
          <?php if ($sessions === []): ?>
            <?= $this->include('components/empty', ['title' => 'Активных сессий нет', 'icon' => 'monitor']) ?>
          <?php else: ?>
            <div class="table-wrap rounded-none border-0">
              <table class="table">
                <thead><tr><th>Устройство</th><th>IP</th><th>Начата</th><th>Активность</th><th class="text-right">Статус</th></tr></thead>
                <tbody>
                <?php foreach ($sessions as $session): ?>
                  <tr>
                    <td class="max-w-[22rem] truncate text-sm"><?= $this->e((string) ($session['user_agent'] ?? '—')) ?></td>
                    <td class="font-mono text-xs"><?= $this->e((string) ($session['ip'] ?? '—')) ?></td>
                    <td class="whitespace-nowrap text-xs tabular"><?= $this->e($this->datetime($session['created_at'] ?? null)) ?></td>
                    <td class="whitespace-nowrap text-xs"><span data-time-ago="<?= $this->e((string) ($session['last_used_at'] ?? '')) ?>"></span></td>
                    <td class="text-right"><?= !empty($session['is_current']) ? '<span class="badge badge-soft-success">ваша</span>' : '<span class="badge badge-muted">другая</span>' ?></td>
                  </tr>
                <?php endforeach ?>
                </tbody>
              </table>
            </div>
          <?php endif ?>
        </div>
      </div>
    </section>

    <section class="tabs-content" role="tabpanel" data-tab-panel="logins" hidden>
      <div class="card">
        <div class="card-header has-action">
          <div class="card-title">История входов</div>
          <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/users/logins')) ?>">Полный журнал</a>
        </div>
        <div class="card-content p-0">
          <?php if ($logins === []): ?>
            <?= $this->include('components/empty', ['title' => 'Записей нет', 'icon' => 'history']) ?>
          <?php else: ?>
            <div class="table-wrap rounded-none border-0">
              <table class="table">
                <thead><tr><th>Когда</th><th>Результат</th><th>Способ</th><th>IP</th><th>Устройство</th></tr></thead>
                <tbody>
                <?php foreach ($logins as $entry): ?>
                  <tr>
                    <td class="whitespace-nowrap text-xs tabular"><?= $this->e($this->datetime($entry['created_at'] ?? null)) ?></td>
                    <td><?= !empty($entry['successful']) ? '<span class="badge badge-soft-success">успешно</span>' : '<span class="badge badge-soft-destructive">' . $this->e($this->loginFailureLabel((string) ($entry['reason'] ?? ''))) . '</span>' ?></td>
                    <td class="text-xs"><?= $this->e($this->loginMethodLabel((string) ($entry['method'] ?? ''))) ?></td>
                    <td class="font-mono text-xs"><?= $this->e(\BasIp\Support\Ip::anonymize((string) ($entry['ip'] ?? ''))) ?></td>
                    <td class="max-w-[16rem] truncate text-xs text-muted-foreground"><?= $this->e((string) ($entry['user_agent'] ?? '—')) ?></td>
                  </tr>
                <?php endforeach ?>
                </tbody>
              </table>
            </div>
          <?php endif ?>
        </div>
      </div>
    </section>

    <section class="tabs-content" role="tabpanel" data-tab-panel="rights" hidden>
      <div class="card">
        <div class="card-header">
          <div class="card-title">Права роли «<?= $this->e($account->roleLabel()) ?>»</div>
          <div class="card-desc">Определяются ролью, а не индивидуально для каждого пользователя.</div>
        </div>
        <div class="card-content">
          <div class="flex flex-wrap gap-1.5">
            <?php foreach ($permissions as $permission): ?>
              <span class="badge badge-outline" data-tooltip="<?= $this->e($permission) ?>">
                <?= $this->icon('check', 'h-3 w-3') ?> <?= $this->e($permissionLabels[$permission] ?? $permission) ?>
              </span>
            <?php endforeach ?>
            <?php if ($permissions === []): ?><p class="text-sm text-muted-foreground">Права не заданы.</p><?php endif ?>
          </div>
        </div>
      </div>
    </section>

    <section class="tabs-content" role="tabpanel" data-tab-panel="audit" hidden>
      <div class="card">
        <div class="card-header"><div class="card-title">Действия с учётной записью</div></div>
        <div class="card-content">
          <?php if ($audit === []): ?>
            <?= $this->include('components/empty', ['title' => 'Записей нет', 'icon' => 'scroll-text']) ?>
          <?php else: ?>
            <div class="timeline">
              <?php foreach (array_slice($audit, 0, 40) as $entry): ?>
                <div class="timeline-item is-<?= !empty($entry['is_security']) ? 'warning' : 'info' ?>">
                  <div class="timeline-time"><?= $this->e($this->datetime($entry['occurred_at'] ?? null)) ?></div>
                  <div class="timeline-title"><?= $this->e((string) ($entry['action'] ?? '')) ?></div>
                  <div class="timeline-desc"><?= $this->e((string) ($entry['actor_name'] ?? '')) ?><?= !empty($entry['ip']) ? ' · ' . $this->e(\BasIp\Support\Ip::anonymize((string) $entry['ip'])) : '' ?></div>
                </div>
              <?php endforeach ?>
            </div>
          <?php endif ?>
        </div>
      </div>
    </section>
  </div>

  <aside class="grid content-start gap-5">
    <div class="card">
      <div class="card-header"><div class="card-title">Сведения</div></div>
      <div class="card-content grid gap-2 text-sm">
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">ID</span><b class="tabular"><?= (int) $account->id ?></b></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Роль</span><span><?= $this->e($account->roleLabel()) ?></span></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Пароль изменён</span><span class="text-xs tabular"><?= $this->e($this->date($account->passwordChangedAt)) ?></span></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Последний вход</span><span class="text-xs tabular"><?= $this->e($this->datetime($account->lastLoginAt)) ?></span></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Неудачных попыток</span><b class="tabular"><?= (int) $account->failedAttempts ?></span></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Создан</span><span class="text-xs tabular"><?= $this->e($this->date($account->createdAt)) ?></span></div>
      </div>
    </div>

    <?php if ($resident !== null): ?>
      <div class="card">
        <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('home', 'h-4 w-4') ?> Карточка жителя</div></div>
        <div class="card-content grid gap-2 text-sm">
          <div class="flex justify-between gap-2"><span class="text-muted-foreground">ФИО</span><b><?= $this->e($resident->displayName()) ?></b></div>
          <div class="flex justify-between gap-2"><span class="text-muted-foreground">Квартира</span><span><?= $this->e($resident->apartment) ?></span></div>
          <div class="flex justify-between gap-2"><span class="text-muted-foreground">Статус</span><?= $resident->isActive ? '<span class="badge badge-soft-success">активен</span>' : '<span class="badge badge-muted">отключён</span>' ?></div>
          <a class="btn btn-outline btn-sm btn-block mt-1" href="<?= $this->e($this->url('/admin/residents/' . $resident->id)) ?>"><?= $this->icon('eye', 'h-3.5 w-3.5') ?> Открыть карточку</a>
          <a class="btn btn-ghost btn-sm btn-block" href="<?= $this->e($this->url('/cabinet?as=' . $resident->id)) ?>"><?= $this->icon('user', 'h-3.5 w-3.5') ?> Посмотреть его кабинет</a>
        </div>
      </div>
    <?php endif ?>

    <?php if ($canEdit && $account->totpEnabled): ?>
      <div class="card border-warning/40">
        <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('shield-off', 'h-4 w-4 text-warning') ?> Сброс 2FA</div></div>
        <div class="card-content grid gap-3">
          <p class="text-xs text-muted-foreground">Если пользователь потерял телефон — сбросьте 2FA. Ему придётся настроить её заново при следующем входе.</p>
          <form method="post" action="<?= $this->e($this->url('/admin/users/' . $account->id . '/reset-2fa')) ?>">
            <?= $this->csrf() ?>
            <button class="btn btn-outline btn-block btn-sm" type="submit" data-confirm="Сбросить 2FA для <?= $this->e($account->login) ?>?" data-confirm-label="Сбросить" data-confirm-tone="destructive">
              <?= $this->icon('shield-off', 'h-3.5 w-3.5') ?> Сбросить 2FA
            </button>
          </form>
        </div>
      </div>
    <?php endif ?>
  </aside>
</div>
