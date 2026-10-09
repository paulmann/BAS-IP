<?php
/**
 * Accounts: staff and resident logins.
 *
 * @var BasIp\Support\Paginator $users
 * @var array $filters
 * @var array $query
 * @var array $stats
 * @var array $roles
 * @var array<int, string> $permissions
 * @var array<string, string> $permissionLabels
 * @var array $matrix
 * @var array $residents
 * @var bool $canEdit
 * @var bool $canCreate
 * @var array $logins
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$items = $users->items();
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Пользователи</h2>
    <p class="page-desc">Три роли: администратор (всё), диспетчер (жители, шлагбаум, оплаты), житель (личный кабинет).</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/api-tokens')) ?>"><?= $this->icon('plug', 'h-3.5 w-3.5') ?> API-токены</a>
    <?php if ($canCreate): ?>
      <button class="btn btn-default btn-sm" type="button" data-dialog="#dialog-user-new"><?= $this->icon('user-plus', 'h-3.5 w-3.5') ?> Создать</button>
    <?php endif ?>
  </div>
</div>

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
  <?= $this->include('components/stat', ['label' => 'Всего учётных записей', 'value' => (string) (int) $stats['total'], 'icon' => 'users']) ?>
  <?= $this->include('components/stat', ['label' => 'Сотрудников', 'value' => (string) ((int) $stats['admins'] + (int) $stats['operators']), 'icon' => 'shield', 'hint' => 'админов: ' . (int) $stats['admins'] . ', диспетчеров: ' . (int) $stats['operators']]) ?>
  <?= $this->include('components/stat', ['label' => 'С 2FA', 'value' => (string) (int) $stats['with_2fa'], 'icon' => 'shield-check', 'accent' => (int) $stats['with_2fa'] > 0 ? 'success' : 'warning', 'hint' => 'рекомендуется включить всем']) ?>
  <?= $this->include('components/stat', ['label' => 'Неудачных входов за 24 ч', 'value' => (string) (int) $stats['failed_logins_24h'], 'icon' => 'alert-triangle', 'accent' => (int) $stats['failed_logins_24h'] > 5 ? 'destructive' : '', 'href' => '/admin/users/logins']) ?>
</div>

<div class="card mt-5" data-tabs data-tabs-persist="users">
  <div class="table-toolbar">
    <div class="tabs-list" role="tablist">
      <button class="tabs-trigger" role="tab" data-tab="list" data-state="active" aria-selected="true"><?= $this->icon('list') ?> Список</button>
      <button class="tabs-trigger" role="tab" data-tab="matrix" data-state="inactive" aria-selected="false"><?= $this->icon('shield') ?> Права ролей</button>
      <button class="tabs-trigger" role="tab" data-tab="logins" data-state="inactive" aria-selected="false"><?= $this->icon('history') ?> Последние входы</button>
    </div>
  </div>

  <section class="tabs-content" role="tabpanel" data-tab-panel="list">
    <form class="filter-bar rounded-none border-x-0" method="get" action="<?= $this->e($this->url('/admin/users')) ?>">
      <div class="filter-field min-w-[14rem] flex-[2]">
        <label class="label" for="q">Поиск</label>
        <input class="input" id="q" name="q" type="search" value="<?= $this->e((string) ($filters['q'] ?? '')) ?>" placeholder="Логин, ФИО, e-mail">
      </div>
      <div class="filter-field">
        <label class="label" for="role">Роль</label>
        <select class="select" id="role" name="role">
          <option value="">Любая</option>
          <?php foreach ($roles as $value => $label): ?>
            <option value="<?= $value ?>" <?= $this->selected($filters['role'] ?? '', $value) ?>><?= $label ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div class="filter-field">
        <label class="label" for="is_active">Статус</label>
        <select class="select" id="is_active" name="is_active">
          <option value="">Любой</option>
          <option value="1" <?= $this->selected($filters['is_active'] ?? '', '1') ?>>Активные</option>
          <option value="0" <?= $this->selected($filters['is_active'] ?? '', '0') ?>>Отключённые</option>
        </select>
      </div>
      <div class="flex items-center gap-2">
        <label class="chip <?= !empty($filters['two_factor']) ? 'is-active' : '' ?>"><input type="checkbox" class="sr-only" name="two_factor" value="1" <?= !empty($filters['two_factor']) ? 'checked' : '' ?>> только с 2FA</label>
        <button class="btn btn-default btn-sm" type="submit"><?= $this->icon('filter', 'h-3.5 w-3.5') ?> Применить</button>
        <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/users')) ?>"><?= $this->icon('rotate-ccw', 'h-3.5 w-3.5') ?></a>
      </div>
    </form>

    <div class="p-0">
      <?php if ($items === []): ?>
        <?= $this->include('components/empty', ['title' => 'Учётных записей не найдено', 'icon' => 'users']) ?>
      <?php else: ?>
        <div class="table-wrap rounded-none border-0">
          <table class="table">
            <thead><tr><th>Пользователь</th><th>Роль</th><th>Житель</th><th>2FA</th><th>Статус</th><th>Последний вход</th><th class="text-right">Действия</th></tr></thead>
            <tbody>
            <?php foreach ($items as $row): ?>
              <?php $account = \BasIp\Auth\User::fromRow($row); ?>
              <tr class="<?= $account->isActive ? '' : 'is-inactive' ?>">
                <td>
                  <div class="flex items-center gap-2.5">
                    <span class="avatar avatar-sm shrink-0"><?= $this->e($account->initials()) ?></span>
                    <span class="min-w-0">
                      <a class="block truncate text-sm font-medium hover:underline" href="<?= $this->e($this->url('/admin/users/' . $account->id)) ?>"><?= $this->e($account->displayName()) ?></a>
                      <span class="block truncate text-xs text-muted-foreground">@<?= $this->e($account->login) ?><?= $account->email !== null ? ' · ' . $this->e($account->email) : '' ?></span>
                    </span>
                  </div>
                </td>
                <td>
                  <span class="badge badge-soft-<?= $account->isAdmin() ? 'destructive' : ($account->isOperator() ? 'info' : 'warning') ?>">
                    <?= $this->icon($account->isAdmin() ? 'shield' : ($account->isOperator() ? 'headset' : 'user'), 'h-3 w-3') ?>
                    <?= $this->e($account->roleLabel()) ?>
                  </span>
                </td>
                <td class="text-xs">
                  <?php if ($account->residentId !== null): ?>
                    <a class="hover:underline" href="<?= $this->e($this->url('/admin/residents/' . $account->residentId)) ?>">карточка #<?= (int) $account->residentId ?></a>
                  <?php else: ?><span class="text-muted-foreground">—</span><?php endif ?>
                </td>
                <td><?= $account->totpEnabled ? '<span class="badge badge-soft-success">включена</span>' : '<span class="badge badge-muted">нет</span>' ?></td>
                <td>
                  <?php if ($account->isLocked()): ?>
                    <span class="badge badge-soft-destructive" data-tooltip="до <?= $this->e($account->lockedUntil ?? '') ?>">заблокирован</span>
                  <?php else: ?>
                    <?= $account->isActive ? '<span class="badge badge-soft-success">активен</span>' : '<span class="badge badge-muted">отключён</span>' ?>
                  <?php endif ?>
                  <?php if ($account->mustChangePassword): ?><span class="badge badge-soft-warning mt-0.5" data-tooltip="Пароль выдан автоматически">сменит пароль</span><?php endif ?>
                </td>
                <td class="whitespace-nowrap text-xs tabular text-muted-foreground">
                  <?= $this->e($this->datetime($account->lastLoginAt)) ?>
                  <?php if ($account->lastLoginIp !== null): ?><div class="font-mono text-[11px]"><?= $this->e(\BasIp\Support\Ip::anonymize($account->lastLoginIp)) ?></div><?php endif ?>
                </td>
                <td class="text-right">
                  <div class="flex justify-end gap-1">
                    <a class="btn btn-ghost btn-sm btn-icon" href="<?= $this->e($this->url('/admin/users/' . $account->id)) ?>" aria-label="Открыть" data-tooltip="Карточка"><?= $this->icon('eye', 'h-3.5 w-3.5') ?></a>
                    <?php if ($canEdit): ?>
                      <div class="relative">
                        <button class="btn btn-ghost btn-sm btn-icon" type="button" data-dropdown aria-haspopup="true" aria-expanded="false" aria-label="Ещё"><?= $this->icon('more-horizontal', 'h-3.5 w-3.5') ?></button>
                        <div class="dropdown right-0 top-full mt-1 w-56" data-dropdown-panel data-state="closed" hidden role="menu">
                          <button class="dropdown-item" role="menuitem" data-dialog="#dialog-user-<?= (int) $account->id ?>"><?= $this->icon('pencil') ?> Изменить</button>
                          <form method="post" action="<?= $this->e($this->url('/admin/users/' . $account->id . '/unlock')) ?>" data-ajax>
                            <?= $this->csrf() ?><button class="dropdown-item w-full" role="menuitem" type="submit"><?= $this->icon('unlock') ?> Разблокировать</button>
                          </form>
                          <form method="post" action="<?= $this->e($this->url('/admin/users/' . $account->id . '/logout')) ?>" data-ajax>
                            <?= $this->csrf() ?><button class="dropdown-item" role="menuitem" type="submit"
                              data-confirm="Завершить все сессии «<?= $this->e($account->login) ?>»?" data-confirm-label="Завершить"><?= $this->icon('log-out') ?> Завершить сессии</button>
                          </form>
                          <?php if ($account->totpEnabled): ?>
                            <form method="post" action="<?= $this->e($this->url('/admin/users/' . $account->id . '/reset-2fa')) ?>">
                              <?= $this->csrf() ?><button class="dropdown-item w-full" role="menuitem" type="submit"
                                data-confirm="Сбросить 2FA для «<?= $this->e($account->login) ?>»? Пользователь настроит её заново." data-confirm-label="Сбросить" data-confirm-tone="destructive">
                                <?= $this->icon('shield-off') ?> Сбросить 2FA</button>
                            </form>
                          <?php endif ?>
                          <div class="dropdown-separator"></div>
                          <form method="post" action="<?= $this->e($this->url('/admin/users/' . $account->id . '/delete')) ?>">
                            <?= $this->csrf() ?><button class="dropdown-item w-full" role="menuitem" type="submit" data-destructive
                              data-confirm="Удалить учётную запись «<?= $this->e($account->login) ?>»?" data-confirm-label="Удалить" data-confirm-tone="destructive">
                              <?= $this->icon('trash') ?> Удалить</button>
                          </form>
                        </div>
                      </div>
                    <?php endif ?>
                  </div>
                </td>
              </tr>
            <?php endforeach ?>
            </tbody>
          </table>
        </div>
        <div class="p-4"><?= $this->include('components/pagination', ['paginator' => $users, 'query' => $query, 'target' => '/admin/users']) ?></div>
      <?php endif ?>
    </div>
  </section>

  <section class="tabs-content" role="tabpanel" data-tab-panel="matrix" hidden>
    <div class="p-5">
      <div class="table-wrap max-h-[70vh] overflow-auto">
        <table class="table">
          <thead><tr><th class="sticky left-0 bg-muted">Право</th><?php foreach ($roles as $role => $label): ?><th class="text-center"><?= $this->e($label) ?></th><?php endforeach ?></tr></thead>
          <tbody>
          <?php foreach ($permissions as $permission): ?>
            <tr>
              <td class="sticky left-0 bg-card text-sm">
                <div class="font-medium"><?= $this->e($permissionLabels[$permission] ?? $permission) ?></div>
                <div class="font-mono text-[11px] text-muted-foreground"><?= $this->e($permission) ?></div>
              </td>
              <?php foreach ($roles as $role => $label): ?>
                <td class="text-center">
                  <?php if (!empty($matrix[$permission][$role])): ?>
                    <span class="badge badge-soft-success" aria-label="разрешено"><?= $this->icon('check', 'h-3 w-3') ?></span>
                  <?php else: ?>
                    <span class="text-muted-foreground/40" aria-label="запрещено">—</span>
                  <?php endif ?>
                </td>
              <?php endforeach ?>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <p class="mt-3 text-xs text-muted-foreground">
        Матрица задана в коде (<code class="inline-code">BasIp\Auth\Permissions</code>) и не редактируется из интерфейса —
        это защищает от случайной выдачи прав. Роль жителя дополнительно ограничена своей карточкой: чужие счета и журналы ему недоступны.
      </p>
    </div>
  </section>

  <section class="tabs-content" role="tabpanel" data-tab-panel="logins" hidden>
    <div class="p-0">
      <?php if ($logins === []): ?>
        <?= $this->include('components/empty', ['title' => 'Записей о входах нет', 'icon' => 'history']) ?>
      <?php else: ?>
        <div class="table-wrap rounded-none border-0">
          <table class="table">
            <thead><tr><th>Когда</th><th>Кто</th><th>Результат</th><th>Способ</th><th>IP</th><th>Устройство</th></tr></thead>
            <tbody>
            <?php foreach ($logins as $entry): ?>
              <tr>
                <td class="whitespace-nowrap text-xs tabular"><?= $this->e($this->datetime($entry['created_at'] ?? null)) ?></td>
                <td class="text-sm"><?= $this->e((string) ($entry['user_login'] ?? $entry['identifier'] ?? '—')) ?></td>
                <td><?= !empty($entry['successful']) ? '<span class="badge badge-soft-success">успешно</span>' : '<span class="badge badge-soft-destructive">' . $this->e($this->loginFailureLabel((string) ($entry['reason'] ?? ''))) . '</span>' ?></td>
                <td class="text-xs"><?= $this->e($this->loginMethodLabel((string) ($entry['method'] ?? ''))) ?></td>
                <td class="font-mono text-xs"><?= $this->e(\BasIp\Support\Ip::anonymize((string) ($entry['ip'] ?? ''))) ?></td>
                <td class="max-w-[18rem] truncate text-xs text-muted-foreground"><?= $this->e((string) ($entry['user_agent'] ?? '—')) ?></td>
              </tr>
            <?php endforeach ?>
            </tbody>
          </table>
        </div>
        <div class="p-4"><a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/users/logins')) ?>">Вся история входов <?= $this->icon('arrow-right', 'h-3.5 w-3.5') ?></a></div>
      <?php endif ?>
    </div>
  </section>
</div>

<?php /* ═══════════ диалоги ═══════════ */ ?>
<?php
$userForm = function (?array $row, string $idSuffix) use ($roles, $residents, $canEdit): void {
    $id = (int) ($row['id'] ?? 0);
    ?>
    <div class="grid gap-3">
      <div class="grid gap-3 sm:grid-cols-2">
        <div class="field">
          <label class="label" for="u-login-<?= $idSuffix ?>">Логин <span class="text-destructive">*</span></label>
          <input class="input input-mono" id="u-login-<?= $idSuffix ?>" name="login" type="text" maxlength="64" required
                 value="<?= $this->e((string) ($row['login'] ?? '')) ?>" <?= $id > 0 ? 'readonly disabled' : '' ?> autocomplete="off"
                 placeholder="admin">
          <?php if ($id > 0): ?><p class="text-xs text-muted-foreground">Логин не изменяется.</p><?php endif ?>
        </div>
        <div class="field">
          <label class="label" for="u-role-<?= $idSuffix ?>">Роль</label>
          <select class="select" id="u-role-<?= $idSuffix ?>" name="role" <?= $canEdit ? '' : 'disabled' ?>>
            <?php foreach ($roles as $value => $label): ?>
              <option value="<?= $value ?>" <?= $this->selected((string) ($row['role'] ?? 'resident'), $value) ?>><?= $label ?></option>
            <?php endforeach ?>
          </select>
        </div>
      </div>

      <div class="grid gap-3 sm:grid-cols-2">
        <div class="field">
          <label class="label" for="u-name-<?= $idSuffix ?>">ФИО <span class="text-destructive">*</span></label>
          <input class="input" id="u-name-<?= $idSuffix ?>" name="full_name" type="text" maxlength="190" required value="<?= $this->e((string) ($row['full_name'] ?? '')) ?>">
        </div>
        <div class="field">
          <label class="label" for="u-email-<?= $idSuffix ?>">E-mail</label>
          <input class="input" id="u-email-<?= $idSuffix ?>" name="email" type="email" maxlength="190" value="<?= $this->e((string) ($row['email'] ?? '')) ?>">
        </div>
      </div>

      <div class="field">
        <label class="label" for="u-phone-<?= $idSuffix ?>">Телефон</label>
        <input class="input input-mono" id="u-phone-<?= $idSuffix ?>" name="phone" type="tel" maxlength="32"
               value="<?= $this->e((string) ($row['phone'] ?? '')) ?>" placeholder="+7 900 000-00-00" autocomplete="off">
        <p class="text-xs text-muted-foreground">
          По телефону можно входить: номер приводится к E.164, вход работает в любом написании.
        </p>
      </div>

      <div class="field">
        <label class="label" for="u-resident-<?= $idSuffix ?>">Привязать к жителю</label>
        <select class="select" id="u-resident-<?= $idSuffix ?>" name="resident_id">
          <option value="">— не привязывать —</option>
          <?php foreach ($residents as $resident): ?>
            <option value="<?= (int) $resident['id'] ?>" <?= (int) ($row['resident_id'] ?? 0) === (int) $resident['id'] ? 'selected' : '' ?>>
              кв. <?= $this->e((string) $resident['apartment']) ?> · <?= $this->e(\BasIp\Support\Str::limit((string) $resident['full_name'], 30)) ?>
            </option>
          <?php endforeach ?>
        </select>
        <p class="text-xs text-muted-foreground">Обязательно для роли «Житель» — по этой связи открывается личный кабинет.</p>
      </div>

      <div class="field">
        <label class="label" for="u-pass-<?= $idSuffix ?>"><?= $id > 0 ? 'Новый пароль' : 'Пароль' ?></label>
        <input class="input input-mono" id="u-pass-<?= $idSuffix ?>" name="password" type="text" maxlength="128" autocomplete="new-password"
               placeholder="<?= $id > 0 ? 'оставьте пустым, чтобы не менять' : 'пусто — сгенерируем автоматически' ?>">
        <p class="text-xs text-muted-foreground">Минимум <?= (int) $this->config('auth.password.min_length', 10) ?> символов, строчные и прописные буквы, цифра.</p>
      </div>

      <div class="grid gap-2 sm:grid-cols-2">
        <label class="checkbox-row text-sm">
          <input class="checkbox" type="checkbox" name="is_active" value="1" <?= empty($row) || !empty($row['is_active']) ? 'checked' : '' ?>>
          <span>Учётная запись активна</span>
        </label>
        <label class="checkbox-row text-sm">
          <input class="checkbox" type="checkbox" name="must_change_password" value="1" <?= !empty($row['must_change_password']) ? 'checked' : '' ?>>
          <span>Требовать смену пароля при входе</span>
        </label>
      </div>
    </div>
    <?php
};
?>

<?php if ($canCreate): ?>
  <div class="dialog" id="dialog-user-new" data-state="closed" hidden role="dialog" aria-labelledby="un-t">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <form method="post" action="<?= $this->e($this->url('/admin/users')) ?>">
      <?= $this->csrf() ?>
      <div class="dialog-header">
        <h2 class="dialog-title" id="un-t">Новая учётная запись</h2>
        <p class="dialog-desc">Если пароль оставить пустым — система сгенерирует надёжный и покажет его один раз.</p>
      </div>
      <?php $userForm(null, 'new'); ?>
      <div class="dialog-footer mt-4">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="submit" class="btn btn-default"><?= $this->icon('user-plus') ?> Создать</button>
      </div>
    </form>
  </div>
<?php endif ?>

<?php if ($canEdit): foreach ($items as $row): ?>
  <div class="dialog" id="dialog-user-<?= (int) $row['id'] ?>" data-state="closed" hidden role="dialog" aria-labelledby="ue-t-<?= (int) $row['id'] ?>">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <form method="post" action="<?= $this->e($this->url('/admin/users/' . (int) $row['id'])) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/users')) ?>">
      <?= $this->csrf() ?>
      <div class="dialog-header">
        <h2 class="dialog-title" id="ue-t-<?= (int) $row['id'] ?>">Изменить учётную запись</h2>
        <p class="dialog-desc">@<?= $this->e((string) $row['login']) ?></p>
      </div>
      <?php $userForm($row, (string) $row['id']); ?>
      <div class="dialog-footer mt-4">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="submit" class="btn btn-default"><?= $this->icon('save') ?> Сохранить</button>
      </div>
    </form>
  </div>
<?php endforeach; endif ?>
