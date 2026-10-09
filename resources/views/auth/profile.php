<?php
/**
 * Profile & security: personal data, password, appearance, 2FA, sessions.
 *
 * @var BasIp\Auth\User $user   (shared)
 * @var array           $sessions
 * @var array           $logins
 * @var string          $tab
 * @var bool            $force
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$user = $this->user();
if ($user === null) { return; }
$sessions = $sessions ?? [];
$logins = $logins ?? [];
$force = !empty($force);
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Профиль и безопасность</h2>
    <p class="page-desc">Личные данные, пароль, двухфакторная аутентификация, тема оформления и активные сессии.</p>
  </div>
  <div class="page-actions">
    <span class="badge badge-outline"><?= $this->icon('shield') ?> <?= $this->e($user->roleLabel()) ?></span>
    <?php if ($user->totpEnabled): ?>
      <span class="badge badge-soft-success"><?= $this->icon('shield-check') ?> 2FA включена</span>
    <?php elseif ($twoFactor ?? false): ?>
      <span class="badge badge-soft-warning"><?= $this->icon('shield-alert') ?> 2FA не настроена</span>
    <?php endif ?>
  </div>
</div>

<?php if ($force || $user->mustChangePassword): ?>
  <div class="alert alert-warning mb-5">
    <?= $this->icon('alert-triangle', 'h-4 w-4') ?>
    <div>
      <div class="alert-title">Требуется смена пароля</div>
      <div class="alert-desc">Пароль был выдан автоматически или сброшен администратором. Задайте свой — после этого доступ откроется полностью.</div>
    </div>
  </div>
<?php endif ?>

<div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">

  <div class="grid gap-5" data-tabs data-tabs-persist="profile" data-tabs-hash>
    <div class="tabs-list is-scrollable" role="tablist" aria-label="Разделы профиля">
      <button class="tabs-trigger" role="tab" data-tab="profile" data-state="active" aria-selected="true"><?= $this->icon('user') ?> Данные</button>
      <button class="tabs-trigger" role="tab" data-tab="security" data-state="inactive" aria-selected="false"><?= $this->icon('lock') ?> Безопасность</button>
      <button class="tabs-trigger" role="tab" data-tab="appearance" data-state="inactive" aria-selected="false"><?= $this->icon('palette') ?> Оформление</button>
      <button class="tabs-trigger" role="tab" data-tab="sessions" data-state="inactive" aria-selected="false"><?= $this->icon('monitor') ?> Сессии <span class="count"><?= count($sessions) ?></span></button>
      <button class="tabs-trigger" role="tab" data-tab="activity" data-state="inactive" aria-selected="false"><?= $this->icon('history') ?> Активность</button>
    </div>

    <?php /* ── данные ─────────────────────────────────────────────── */ ?>
    <section class="tabs-content" role="tabpanel" data-tab-panel="profile">
      <form class="card" method="post" action="<?= $this->e($this->url('/profile')) ?>" data-ajax data-reload>
        <?= $this->csrf() ?>
        <div class="card-header">
          <div class="card-title">Личные данные</div>
          <div class="card-desc">Отображается в журнале открываний и в журнале аудита.</div>
        </div>
        <div class="card-content grid gap-4">
          <div class="flex items-center gap-4">
            <span class="avatar avatar-xl"><?= $this->e($user->initials()) ?></span>
            <div class="min-w-0">
              <div class="truncate font-medium"><?= $this->e($user->displayName()) ?></div>
              <div class="truncate text-sm text-muted-foreground">@<?= $this->e($user->login) ?></div>
              <?php if ($user->residentId !== null): ?>
                <a class="text-xs text-primary underline underline-offset-4" href="<?= $this->e($this->url('/cabinet')) ?>">Перейти в личный кабинет</a>
              <?php endif ?>
            </div>
          </div>

          <div class="field">
            <label class="label" for="full_name">ФИО</label>
            <input class="input" id="full_name" name="full_name" type="text" maxlength="190" required value="<?= $this->e($user->fullName) ?>">
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div class="field">
              <label class="label" for="email">E-mail</label>
              <input class="input" id="email" name="email" type="email" maxlength="190" value="<?= $this->e((string) $user->email) ?>" placeholder="name@example.com">
              <p class="text-xs text-muted-foreground">Используется для восстановления доступа и уведомлений.</p>
            </div>
            <div class="field">
              <label class="label" for="locale">Язык интерфейса</label>
              <select class="select" id="locale" name="locale">
                <option value="ru" <?= $this->selected($user->locale ?? 'ru', 'ru') ?>>Русский</option>
                <option value="en" <?= $this->selected($user->locale, 'en') ?>>English</option>
              </select>
            </div>
          </div>

          <div class="field">
            <label class="label" for="login-readonly">Логин</label>
            <input class="input" id="login-readonly" type="text" value="<?= $this->e($user->login) ?>" readonly disabled>
            <p class="text-xs text-muted-foreground">Логин изменяет только администратор.</p>
          </div>
        </div>
        <div class="card-footer justify-end">
          <button class="btn btn-default" type="submit"><?= $this->icon('save') ?> Сохранить</button>
        </div>
      </form>
    </section>

    <?php /* ── безопасность ───────────────────────────────────────── */ ?>
    <section class="tabs-content" role="tabpanel" data-tab-panel="security" hidden>
      <form class="card" method="post" action="<?= $this->e($this->url('/profile/password')) ?>" data-ajax>
        <?= $this->csrf() ?>
        <div class="card-header">
          <div class="card-title">Смена пароля</div>
          <div class="card-desc">После смены все остальные сессии будут завершены.</div>
        </div>
        <div class="card-content grid gap-4">
          <div class="field">
            <label class="label" for="current_password">Текущий пароль</label>
            <input class="input" id="current_password" name="current_password" type="password" required autocomplete="current-password">
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="field">
              <label class="label" for="new_password">Новый пароль</label>
              <input class="input" id="new_password" name="new_password" type="password" required
                     minlength="<?= (int) $this->config('auth.password.min_length', 10) ?>" autocomplete="new-password">
            </div>
            <div class="field">
              <label class="label" for="new_password_confirm">Повторите новый пароль</label>
              <input class="input" id="new_password_confirm" name="new_password_confirm" type="password" required autocomplete="new-password">
            </div>
          </div>
          <label class="checkbox-row text-sm">
            <input class="checkbox" type="checkbox" name="logout_others" value="1" checked>
            <span>Завершить другие сессии <span class="label-hint">Рекомендуется: защищает при утере устройства.</span></span>
          </label>
        </div>
        <div class="card-footer justify-end">
          <button class="btn btn-default" type="submit"><?= $this->icon('key-round') ?> Сменить пароль</button>
        </div>
      </form>

      <?php if (!empty($twoFactor)): ?>
        <div class="card mt-5">
          <div class="card-header">
            <div class="card-title flex items-center gap-2">
              <?= $this->icon('shield-check', 'h-4 w-4 ' . ($user->totpEnabled ? 'text-success' : 'text-warning')) ?>
              Двухфакторная аутентификация
            </div>
            <div class="card-desc">
              <?= $user->totpEnabled
                ? 'Включена. Коды генерируются в приложении на телефоне и работают без интернета.'
                : 'Не настроена. Настоятельно рекомендуем включить — особенно для администраторов.' ?>
            </div>
          </div>
          <div class="card-content">
            <?php if ($user->totpEnabled): ?>
              <div class="flex flex-wrap items-center gap-3">
                <span class="badge badge-soft-success"><?= $this->icon('check-circle') ?> Активна</span>
                <span class="text-sm text-muted-foreground">Алгоритм: TOTP · <?= (int) $this->config('auth.two_factor.digits', 6) ?> цифр · <?= (int) $this->config('auth.two_factor.period', 30) ?> с</span>
                <div class="ml-auto flex gap-2">
                  <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/profile/2fa')) ?>"><?= $this->icon('refresh-cw') ?> Перенастроить</a>
                  <button class="btn btn-outline btn-sm" type="button" data-dialog="#dialog-2fa-off">
                    <?= $this->icon('shield-off') ?> Отключить
                  </button>
                </div>
              </div>
            <?php else: ?>
              <a class="btn btn-default" href="<?= $this->e($this->url('/profile/2fa')) ?>">
                <?= $this->icon('shield-check') ?> Включить 2FA
              </a>
            <?php endif ?>
          </div>
        </div>
      <?php endif ?>

      <div class="card mt-5">
        <div class="card-header">
          <div class="card-title">Ваши права</div>
          <div class="card-desc">Роль «<?= $this->e($user->roleLabel()) ?>» определяет доступные разделы.</div>
        </div>
        <div class="card-content">
          <div class="flex flex-wrap gap-1.5">
            <?php $labels = $permissionLabels ?? []; ?>
            <?php foreach (($permissions ?? []) as $permission): ?>
              <span class="badge badge-outline" data-tooltip="<?= $this->e($permission) ?>"><?= $this->icon('check', 'h-3 w-3') ?> <?= $this->e($labels[$permission] ?? $permission) ?></span>
            <?php endforeach ?>
            <?php if (($permissions ?? []) === []): ?>
              <p class="text-sm text-muted-foreground">Базовые права: открыть шлагбаум, создать гостевой доступ, видеть свои оплаты.</p>
            <?php endif ?>
          </div>
        </div>
      </div>
    </section>

    <?php /* ── оформление ─────────────────────────────────────────── */ ?>
    <section class="tabs-content" role="tabpanel" data-tab-panel="appearance" hidden>
      <div class="card">
        <div class="card-header">
          <div class="card-title">Тема и плотность</div>
          <div class="card-desc">Выбор сохраняется в браузере и в вашем профиле; при первом входе тема определяется по настройкам системы.</div>
        </div>
        <div class="card-content grid gap-5">
          <div>
            <div class="label mb-2">Цветовая схема</div>
            <div class="grid grid-cols-3 gap-2">
              <?php foreach ([['light', 'Светлая', 'sun'], ['dark', 'Тёмная', 'moon'], ['system', 'Системная', 'monitor']] as [$value, $label, $icon]): ?>
                <button type="button" class="flex flex-col items-center gap-2 rounded-lg border p-3 text-sm transition hover:border-primary/50 hover:bg-accent"
                        data-theme-toggle="<?= $value ?>" data-appearance-choice="<?= $value ?>">
                  <?= $this->icon($icon, 'h-5 w-5 text-xl') ?>
                  <span><?= $label ?></span>
                </button>
              <?php endforeach ?>
            </div>
          </div>

          <div>
            <div class="label mb-2">Акцентный цвет</div>
            <div class="flex flex-wrap gap-2">
              <?php foreach (['slate' => '#18181b', 'blue' => '#2563eb', 'green' => '#16a34a', 'violet' => '#7c3aed', 'orange' => '#ea580c', 'rose' => '#e11d48', 'teal' => '#0d9488'] as $name => $color): ?>
                <button type="button" class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition hover:border-primary/50"
                        data-accent-choice="<?= $name ?>">
                  <span class="h-4 w-4 rounded-full border border-border" style="background: <?= $color ?>"></span>
                  <?= $this->e(ucfirst($name)) ?>
                </button>
              <?php endforeach ?>
            </div>
          </div>

          <div>
            <div class="label mb-2">Плотность интерфейса</div>
            <div class="grid grid-cols-2 gap-2">
              <?php foreach ([['comfortable', 'Обычная', 'Просторные строки таблиц'], ['compact', 'Компактная', 'Больше строк на экране']] as [$value, $label, $hint]): ?>
                <button type="button" class="rounded-lg border p-3 text-left transition hover:border-primary/50" data-density-choice="<?= $value ?>">
                  <div class="text-sm font-medium"><?= $label ?></div>
                  <div class="text-xs text-muted-foreground"><?= $hint ?></div>
                </button>
              <?php endforeach ?>
            </div>
          </div>

          <div class="alert alert-muted">
            <?= $this->icon('info', 'h-4 w-4') ?>
            <div class="alert-desc">Горячая клавиша <kbd class="kbd">Shift</kbd> + <kbd class="kbd">T</kbd> переключает тему. Настройки применяются мгновенно, без перезагрузки.</div>
          </div>
        </div>
      </div>
    </section>

    <?php /* ── сессии ─────────────────────────────────────────────── */ ?>
    <section class="tabs-content" role="tabpanel" data-tab-panel="sessions" hidden>
      <div class="card">
        <div class="card-header has-action">
          <div>
            <div class="card-title">Активные сессии</div>
            <div class="card-desc">Устройства, на которых выполнен вход под вашей учётной записью.</div>
          </div>
        </div>
        <div class="card-content p-0">
          <?php if ($sessions === []): ?>
            <?= $this->include('components/empty', ['title' => 'Нет сведений о сессиях', 'description' => 'Информация появится после следующего входа.', 'icon' => 'monitor']) ?>
          <?php else: ?>
            <div class="table-wrap rounded-none border-0">
              <table class="table">
                <thead><tr><th>Устройство</th><th>IP</th><th>Последняя активность</th><th class="text-right">Действие</th></tr></thead>
                <tbody>
                <?php foreach ($sessions as $session): ?>
                  <tr>
                    <td>
                      <div class="flex items-center gap-2">
                        <?= $this->icon(!empty($session['is_current']) ? 'monitor' : 'smartphone', 'h-4 w-4 text-muted-foreground') ?>
                        <div class="min-w-0">
                          <div class="truncate text-sm"><?= $this->e(\BasIp\Support\Str::limit((string) ($session['user_agent'] ?? '—'), 60)) ?></div>
                          <div class="text-xs text-muted-foreground">с <?= $this->e($this->datetime($session['created_at'] ?? null, 'd.m.Y H:i')) ?></div>
                        </div>
                      </div>
                    </td>
                    <td class="font-mono text-xs"><?= $this->e((string) ($session['ip'] ?? '—')) ?></td>
                    <td><span data-time-ago="<?= $this->e((string) ($session['last_used_at'] ?? '')) ?>"></span></td>
                    <td class="text-right">
                      <?php if (!empty($session['is_current'])): ?>
                        <span class="badge badge-soft-success">это устройство</span>
                      <?php else: ?>
                        <form method="post" action="<?= $this->e($this->url('/profile/sessions/' . (int) $session['id'] . '/revoke')) ?>" data-ajax class="inline">
                          <?= $this->csrf() ?>
                          <button class="btn btn-outline btn-sm" type="submit"
                                  data-confirm="Завершить эту сессию?" data-confirm-label="Завершить">
                            <?= $this->icon('log-out', 'h-3.5 w-3.5') ?> Завершить
                          </button>
                        </form>
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
    </section>

    <?php /* ── активность ─────────────────────────────────────────── */ ?>
    <section class="tabs-content" role="tabpanel" data-tab-panel="activity" hidden>
      <div class="card">
        <div class="card-header">
          <div class="card-title">История входов</div>
          <div class="card-desc">Последние <?= count($logins) ?> попыток входа под вашей учётной записью.</div>
        </div>
        <div class="card-content p-0">
          <?php if ($logins === []): ?>
            <?= $this->include('components/empty', ['title' => 'Записей пока нет', 'icon' => 'history']) ?>
          <?php else: ?>
            <div class="table-wrap rounded-none border-0">
              <table class="table">
                <thead><tr><th>Когда</th><th>Результат</th><th>Способ</th><th>IP</th><th>Устройство</th></tr></thead>
                <tbody>
                <?php foreach ($logins as $entry): ?>
                  <tr>
                    <td class="whitespace-nowrap text-xs tabular"><?= $this->e($this->datetime($entry['created_at'] ?? null)) ?></td>
                    <td>
                      <?php if (!empty($entry['successful'])): ?>
                        <span class="badge badge-soft-success"><?= $this->icon('check', 'h-3 w-3') ?> Успешно</span>
                      <?php else: ?>
                        <span class="badge badge-soft-destructive"><?= $this->icon('x', 'h-3 w-3') ?> <?= $this->e($this->loginFailureLabel((string) ($entry['reason'] ?? ''))) ?></span>
                      <?php endif ?>
                    </td>
                    <td class="text-xs"><?= $this->e($this->loginMethodLabel((string) ($entry['method'] ?? 'password'))) ?></td>
                    <td class="font-mono text-xs"><?= $this->e((string) ($entry['ip'] ?? '—')) ?></td>
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
  </div>

  <?php /* ── правая колонка ─────────────────────────────────────── */ ?>
  <aside class="grid content-start gap-5">
    <div class="card">
      <div class="card-content grid gap-3 pt-5">
        <div class="flex items-center gap-3">
          <span class="avatar avatar-lg"><?= $this->e($user->initials()) ?></span>
          <div class="min-w-0">
            <div class="truncate font-semibold"><?= $this->e($user->displayName()) ?></div>
            <div class="truncate text-sm text-muted-foreground">@<?= $this->e($user->login) ?></div>
          </div>
        </div>
        <dl class="grid gap-2 text-sm">
          <div class="flex justify-between gap-2"><dt class="text-muted-foreground">Роль</dt><dd class="font-medium"><?= $this->e($user->roleLabel()) ?></dd></div>
          <div class="flex justify-between gap-2"><dt class="text-muted-foreground">E-mail</dt><dd class="truncate font-medium"><?= $this->e($user->email ?? '—') ?></dd></div>
          <div class="flex justify-between gap-2"><dt class="text-muted-foreground">2FA</dt><dd><?= $user->totpEnabled ? '<span class="badge badge-soft-success">включена</span>' : '<span class="badge badge-soft-warning">выключена</span>' ?></dd></div>
          <div class="flex justify-between gap-2"><dt class="text-muted-foreground">Пароль изменён</dt><dd class="text-xs tabular"><?= $this->e($this->date($user->passwordChangedAt)) ?></dd></div>
          <div class="flex justify-between gap-2"><dt class="text-muted-foreground">Последний вход</dt><dd class="text-xs tabular"><?= $this->e($this->datetime($user->lastLoginAt)) ?></dd></div>
        </dl>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title">Быстрые действия</div></div>
      <div class="card-content grid gap-2">
        <a class="btn btn-outline btn-block justify-start" href="<?= $this->e($this->url('/panel')) ?>"><?= $this->icon('gauge') ?> Панель шлагбаума</a>
        <?php if ($user->residentId !== null): ?>
          <a class="btn btn-outline btn-block justify-start" href="<?= $this->e($this->url('/cabinet/guest')) ?>"><?= $this->icon('user-plus') ?> Гостевой доступ</a>
          <a class="btn btn-outline btn-block justify-start" href="<?= $this->e($this->url('/cabinet/payments')) ?>"><?= $this->icon('wallet') ?> Мои оплаты</a>
        <?php endif ?>
        <?php if ($user->isStaff()): ?>
          <a class="btn btn-outline btn-block justify-start" href="<?= $this->e($this->url('/admin/api-tokens')) ?>"><?= $this->icon('plug') ?> API-токены</a>
          <a class="btn btn-outline btn-block justify-start" href="<?= $this->e($this->url('/help')) ?>"><?= $this->icon('life-buoy') ?> Справка</a>
        <?php endif ?>
        <form method="post" action="<?= $this->e($this->url('/logout')) ?>">
          <?= $this->csrf() ?>
          <button class="btn btn-ghost btn-block justify-start text-destructive hover:bg-destructive/10 hover:text-destructive" type="submit">
            <?= $this->icon('log-out') ?> Выйти
          </button>
        </form>
      </div>
    </div>

    <?php if (!empty($_SESSION['recovery_codes']) || $this->sessionHas('recovery_codes')): ?>
      <div class="card border-warning/40">
        <div class="card-header">
          <div class="card-title flex items-center gap-2"><?= $this->icon('key', 'h-4 w-4 text-warning') ?> Резервные коды</div>
          <div class="card-desc">Показываются один раз. Сохраните в менеджер паролей.</div>
        </div>
        <div class="card-content">
          <ul class="grid grid-cols-2 gap-1.5 font-mono text-sm">
            <?php foreach ((array) $this->sessionPull('recovery_codes') as $code): ?>
              <li class="rounded border bg-muted/40 px-2 py-1"><?= $this->e((string) $code) ?></li>
            <?php endforeach ?>
          </ul>
        </div>
      </div>
    <?php endif ?>
  </aside>
</div>

<?php if (!empty($twoFactor) && $user->totpEnabled): ?>
  <div class="dialog dialog-sm" id="dialog-2fa-off" data-state="closed" hidden role="dialog" aria-labelledby="t2faoff">
    <form method="post" action="<?= $this->e($this->url('/profile/2fa/disable')) ?>" data-ajax>
      <?= $this->csrf() ?>
      <div class="dialog-header">
        <h2 class="dialog-title" id="t2faoff">Отключить 2FA?</h2>
        <p class="dialog-desc">Без двухфакторной аутентификации аккаунт защищён только паролем. Для администраторов 2FA может быть обязательной по политике.</p>
      </div>
      <div class="field">
        <label class="label" for="disable_password">Текущий пароль</label>
        <input class="input" id="disable_password" name="password" type="password" required autocomplete="current-password">
      </div>
      <div class="dialog-footer">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="submit" class="btn btn-destructive">Отключить 2FA</button>
      </div>
    </form>
  </div>
<?php endif ?>

<?php $this->startPush('scripts') ?>
<script nonce="<?= $this->e($this->nonce()) ?>">
document.addEventListener('click', function (e) {
  var accent = e.target.closest('[data-accent-choice]');
  if (accent && window.BasIpTheme) {
    window.BasIpTheme.setAccent(accent.getAttribute('data-accent-choice'));
    window.BasIp.toast.info('Акцент обновлён', { duration: 1500 });
  }
  var density = e.target.closest('[data-density-choice]');
  if (density && window.BasIpTheme) {
    window.BasIpTheme.setDensity(density.getAttribute('data-density-choice'));
    window.BasIp.toast.info('Плотность обновлена', { duration: 1500 });
  }
  var theme = e.target.closest('[data-appearance-choice]');
  if (theme && window.BasIpTheme) window.BasIpTheme.set(theme.getAttribute('data-appearance-choice'));
});
</script>
<?php $this->endPush() ?>
