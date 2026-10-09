<?php
/**
 * Operator / resident control panel.
 *
 * Mobile-first: the big «Открыть» button is the first thing on screen and stays
 * reachable in a bottom action bar; everything else (status, camera, log,
 * guest passes) stacks below it and expands into a full dashboard on desktop.
 *
 * @var BasIp\Domain\Model\Device|null $device
 * @var array<int, BasIp\Domain\Model\Device> $devices
 * @var BasIp\Device\GateResult $status
 * @var array<int, BasIp\Domain\Model\GateEvent> $recent
 * @var BasIp\Domain\Model\Resident|null $resident
 * @var array<int, BasIp\Domain\Model\GuestPass> $myPasses
 * @var bool $canOpen
 * @var bool $canReboot
 * @var bool $canSip
 * @var bool $canGuest
 * @var bool $cameraEnabled
 * @var int  $cooldown
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$user = $this->user();
$isStaff = $user !== null && $user->isStaff();
$openUrl = $isStaff ? $this->url('/admin/gate/open') : $this->url('/panel/open');
$statusUrl = $this->url('/panel/status');
$snapshotUrl = $this->url('/panel/snapshot');
$deviceSlug = $device?->slug ?? '';
$canOpenNow = ($isStaff && $user->can('gate.open')) || ($resident !== null && $resident->canOpenGate());
?>
<?php $this->layout('layouts/app') ?>

<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">

  <?php /* ═══════════ левая колонка: управление ═══════════ */ ?>
  <div class="grid content-start gap-5">

    <section class="card overflow-hidden">
      <div class="card-header has-action">
        <div class="min-w-0">
          <div class="card-title flex items-center gap-2">
            <?= $this->icon('door-open', 'h-4 w-4') ?>
            <?= $this->e($device?->label() ?? 'Шлагбаум') ?>
            <span class="badge badge-muted" data-gate-state>—</span>
          </div>
          <div class="card-desc">
            <?php if ($device !== null): ?>
              <?= $this->e($device->driverLabel()) ?><?= $device->location !== null ? ' · ' . $this->e($device->location) : '' ?>
            <?php else: ?>
              Устройство не настроено — откройте «Устройства» в админке.
            <?php endif ?>
          </div>
        </div>
        <div class="flex shrink-0 items-center gap-2">
          <?php if (count($devices) > 1): ?>
            <select class="select select-sm" data-device-switch aria-label="Выбор устройства">
              <?php foreach ($devices as $item): ?>
                <option value="<?= $this->e($item->slug) ?>" <?= $item->slug === $deviceSlug ? 'selected' : '' ?>><?= $this->e($item->label()) ?></option>
              <?php endforeach ?>
            </select>
          <?php endif ?>
          <button type="button" class="btn btn-outline btn-sm btn-icon" data-gate-refresh aria-label="Обновить статус" data-tooltip="Обновить статус">
            <?= $this->icon('refresh-cw', 'h-4 w-4') ?>
          </button>
        </div>
      </div>

      <div class="card-content grid gap-4">
        <?php /* Визуализация стрелы шлагбаума */ ?>
        <div class="gate-visual" aria-hidden="true">
          <div class="gate-bar" data-gate-bar></div>
          <div class="gate-post"></div>
          <div class="absolute inset-x-0 bottom-0 h-6 bg-gradient-to-t from-muted/60 to-transparent"></div>
        </div>

        <button type="button"
                class="btn btn-gate"
                data-gate-open="<?= $this->e($openUrl) ?>"
                data-gate-device="<?= $this->e($deviceSlug) ?>"
                data-gate-resident="<?= $resident?->id ?? '' ?>"
                data-gate-cooldown="<?= (int) $cooldown ?>"
                data-shortcut="open"
                <?= $canOpenNow ? '' : 'disabled' ?>
                aria-label="Открыть шлагбаум">
          <?= $this->icon('door-open', 'h-10 w-10') ?>
          <span>Открыть шлагбаум</span>
          <span class="text-xs font-normal opacity-80" data-gate-cooldown-label></span>
        </button>

        <?php if (!$canOpenNow): ?>
          <div class="alert alert-warning">
            <?= $this->icon('alert-triangle', 'h-4 w-4') ?>
            <div>
              <div class="alert-title">Открытие недоступно</div>
              <div class="alert-desc">
                <?php if ($resident === null): ?>
                  Ваша учётная запись не привязана к карточке жителя. Обратитесь к управляющему.
                <?php elseif (!$resident->isActive): ?>
                  Ваша запись отключена администратором.
                <?php elseif (!$resident->accessGranted): ?>
                  Доступ к шлагбауму приостановлен (снята галочка «доступ»).
                <?php else: ?>
                  Недостаточно прав.
                <?php endif ?>
              </div>
            </div>
          </div>
        <?php endif ?>

        <p class="text-center text-xs text-muted-foreground">
          Каждое открытие записывается в журнал: кто, когда и по какому доступу.
          <?php if ($isStaff): ?> Горячая клавиша <kbd class="kbd">Shift</kbd>+<kbd class="kbd">O</kbd>.
          <?php endif ?>
        </p>
      </div>
    </section>

    <?php /* ── состояние устройства ─────────────────────────────── */ ?>
    <section class="card" data-gate-status
             data-gate-status="<?= $this->e($statusUrl) ?>"
             data-gate-status-interval="<?= (int) $this->config('ui.status_refresh_seconds', 300) * 1000 ?>">
      <div class="card-header">
        <div class="card-title flex items-center gap-2">
          <span class="health-dot" data-status-dot></span> Состояние панели
        </div>
        <div class="card-desc">Обновляется автоматически. Последняя проверка: <span data-status-checked>—</span></div>
      </div>
      <div class="card-content grid gap-4">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <?php
            $tiles = [
                ['sip', 'SIP', 'data-status-sip', 'radio'],
                ['firmware', 'Прошивка', 'data-status-firmware', 'cpu'],
                ['uptime', 'Аптайм', 'data-status-uptime', 'timer'],
                ['ms', 'Отклик', 'data-status-ms', 'zap'],
            ];
          ?>
          <?php foreach ($tiles as [$key, $label, $attr, $iconName]): ?>
            <div class="rounded-lg border bg-muted/25 p-3">
              <div class="flex items-center gap-1.5 text-[11px] uppercase tracking-wide text-muted-foreground">
                <?= $this->icon($iconName, 'h-3.5 w-3.5') ?> <?= $label ?>
              </div>
              <div class="mt-1 truncate text-sm font-semibold" <?= $attr ?>>—</div>
            </div>
          <?php endforeach ?>
        </div>

        <div class="rounded-lg border bg-muted/25 p-3">
          <div class="flex items-center gap-1.5 text-[11px] uppercase tracking-wide text-muted-foreground">
            <?= $this->icon('clock', 'h-3.5 w-3.5') ?> Время устройства
          </div>
          <div class="mt-1 flex flex-wrap items-baseline justify-between gap-2">
            <span class="text-sm font-semibold tabular" data-device-time>—</span>
            <span class="tabular text-xs text-muted-foreground" data-device-drift></span>
          </div>
          <p class="mt-1 text-xs text-muted-foreground">
            Расхождение больше 2 минут ломает расписания и журналы панели — сверьте время в настройках устройства.
          </p>
        </div>

        <?php if (!$status->ok): ?>
          <div class="alert alert-destructive">
            <?= $this->icon('wifi-off', 'h-4 w-4') ?>
            <div>
              <div class="alert-title">Панель не отвечает</div>
              <div class="alert-desc"><?= $this->e($status->message) ?></div>
            </div>
          </div>
        <?php endif ?>

        <?php if ($isStaff && ($canReboot || $canSip)): ?>
          <div class="flex flex-wrap gap-2 border-t pt-4">
            <?php if ($canSip): ?>
              <button type="button" class="btn btn-outline btn-sm"
                      data-device-command="sip_enable"
                      data-device-url="<?= $this->e($this->url('/admin/gate/sip')) ?>"
                      data-device-body='{"enable":true}'>
                <?= $this->icon('radio', 'h-3.5 w-3.5') ?> Включить SIP
              </button>
              <button type="button" class="btn btn-outline btn-sm"
                      data-device-command="sip_disable"
                      data-device-url="<?= $this->e($this->url('/admin/gate/sip')) ?>"
                      data-device-body='{"enable":false}'>
                <?= $this->icon('radio', 'h-3.5 w-3.5') ?> Выключить SIP
              </button>
            <?php endif ?>
            <?php if ($canReboot): ?>
              <button type="button" class="btn btn-outline btn-sm text-destructive hover:bg-destructive/10"
                      data-device-command="reboot"
                      data-device-url="<?= $this->e($this->url('/admin/gate/reboot')) ?>"
                      data-confirm="Перезагрузить панель? На время перезагрузки открытие через веб-интерфейс будет недоступно."
                      data-confirm-title="Перезагрузка панели"
                      data-confirm-label="Перезагрузить"
                      data-confirm-tone="destructive">
                <?= $this->icon('power', 'h-3.5 w-3.5') ?> Перезагрузка
              </button>
            <?php endif ?>
          </div>
        <?php endif ?>
      </div>
    </section>

    <?php /* ── камера ───────────────────────────────────────────── */ ?>
    <?php if ($cameraEnabled): ?>
      <section class="card">
        <div class="card-header has-action">
          <div>
            <div class="card-title flex items-center gap-2"><?= $this->icon('video', 'h-4 w-4') ?> Камера</div>
            <div class="card-desc">Кадр обновляется каждые <?= (int) $this->config('ui.camera_refresh_seconds', 5) ?> с. Обработка идёт через сервер — учётные данные панели не видны браузеру.</div>
          </div>
          <button type="button" class="btn btn-outline btn-sm" data-camera-toggle>Остановить</button>
        </div>
        <div class="card-content">
          <div class="camera-frame" data-camera="<?= $this->e($snapshotUrl) ?>" data-camera-interval="<?= (int) $this->config('ui.camera_refresh_seconds', 5) * 1000 ?>">
            <img src="<?= $this->e($this->asset('img/placeholder.svg')) ?>" alt="Кадр с камеры шлагбаума" loading="lazy">
            <span class="camera-live">live</span>
            <div class="absolute bottom-2 right-2 rounded bg-black/60 px-2 py-1 text-[10px] font-medium text-white tabular" data-camera-stamp></div>
            <div class="absolute inset-0 hidden items-center justify-center bg-background/80 p-4 text-center text-sm text-muted-foreground" data-camera-error>
              Камера недоступна. Проверьте настройки устройства и сеть.
            </div>
          </div>
        </div>
      </section>
    <?php endif ?>

    <?php /* ── последние события ────────────────────────────────── */ ?>
    <section class="card" data-gate-feed data-gate-feed="<?= $isStaff ? $this->e($this->url('/admin/gate?partial=feed')) : '' ?>">
      <div class="card-header has-action">
        <div>
          <div class="card-title flex items-center gap-2"><?= $this->icon('history', 'h-4 w-4') ?> Последние события</div>
          <div class="card-desc"><?= $isStaff ? 'Все открывания и отказы по всем жителям.' : 'Ваши открытия и гостевые доступы.' ?></div>
        </div>
        <?php if ($isStaff): ?>
          <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/gate')) ?>">Весь журнал <?= $this->icon('arrow-right', 'h-3.5 w-3.5') ?></a>
        <?php else: ?>
          <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/cabinet/log')) ?>">История <?= $this->icon('arrow-right', 'h-3.5 w-3.5') ?></a>
        <?php endif ?>
      </div>
      <div class="card-content p-0">
        <?php if ($recent === []): ?>
          <?= $this->include('components/empty', ['title' => 'Событий пока нет', 'description' => 'Как только шлагбаум откроют — запись появится здесь.', 'icon' => 'history']) ?>
        <?php else: ?>
          <ul class="divide-y">
            <?php foreach ($recent as $event): ?>
              <li class="flex items-start gap-3 px-5 py-3">
                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full
                  <?= $event->result === 'success' ? 'bg-success/12 text-success' : ($event->result === 'denied' ? 'bg-warning/15 text-warning' : 'bg-destructive/12 text-destructive') ?>">
                  <?= $this->icon($event->result === 'success' ? ($event->isGuest() ? 'user-plus' : 'check') : 'ban', 'h-4 w-4') ?>
                </span>
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <span class="truncate text-sm font-medium"><?= $this->e($event->actorLabel()) ?></span>
                    <span class="badge badge-soft-<?= $event->accessType === 'guest' ? 'warning' : ($event->result === 'success' ? 'success' : 'destructive') ?>"><?= $this->e($event->accessLabel()) ?></span>
                  </div>
                  <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                    <span class="tabular" data-time-ago="<?= $this->e((string) $event->occurredAt) ?>"></span>
                    <span>·</span><span><?= $this->e($event->sourceLabel()) ?></span>
                    <?php if ($event->reason !== null && $event->reason !== ''): ?>
                      <span>·</span><span class="truncate"><?= $this->e($event->reason) ?></span>
                    <?php endif ?>
                  </div>
                </div>
                <span class="shrink-0 text-xs tabular text-muted-foreground"><?= $this->e($event->when('H:i:s')) ?></span>
              </li>
            <?php endforeach ?>
          </ul>
        <?php endif ?>
      </div>
    </section>
  </div>

  <?php /* ═══════════ правая колонка ═══════════ */ ?>
  <aside class="grid content-start gap-5">

    <?php if ($canGuest): ?>
      <section class="card" id="guest">
        <div class="card-header">
          <div class="card-title flex items-center gap-2"><?= $this->icon('user-plus', 'h-4 w-4') ?> Гостевой доступ</div>
          <div class="card-desc">
            Создайте пропуск на <?= (int) $defaultTtl ?> часов — гость сам откроет шлагбаум по ссылке или коду.
          </div>
        </div>
        <div class="card-content grid gap-3">
          <form method="post" action="<?= $this->e($this->url($isStaff ? '/admin/guest' : '/cabinet/guest')) ?>" data-guest-form data-ajax>
            <?= $this->csrf() ?>
            <?php if ($isStaff && $resident === null): ?>
              <div class="field">
                <label class="label" for="guest_resident">Житель</label>
                <select class="select" id="guest_resident" name="resident_id" required data-resident-picker></select>
                <p class="text-xs text-muted-foreground">Начните вводить фамилию или номер квартиры.</p>
              </div>
            <?php endif ?>

            <div>
              <div class="label mb-1.5">Срок действия</div>
              <div class="flex flex-wrap gap-1.5" data-guest-presets>
                <?php foreach (($guestPresets ?: [1, 2, 4, 8, 12, 24, 48, 72]) as $preset): ?>
                  <button type="button" class="chip <?= (int) $preset === (int) $defaultTtl ? 'is-active' : '' ?>"
                          data-guest-preset="<?= (int) $preset ?>" <?= (int) $preset === (int) $defaultTtl ? 'aria-pressed="true"' : '' ?>>
                    <?= (int) $preset ?> ч
                  </button>
                <?php endforeach ?>
              </div>
              <input class="input mt-2" type="number" name="ttl_hours" min="1" max="<?= (int) $this->config('guest.max_ttl_hours', 72) ?>"
                     step="1" value="<?= (int) $defaultTtl ?>" data-guest-ttl aria-label="Срок действия в часах">
              <p class="mt-1 text-xs text-muted-foreground">Действует до: <b data-guest-until>—</b></p>
            </div>

            <div class="grid gap-3">
              <div class="field">
                <label class="label" for="guest_name">Кто приезжает <span class="font-normal text-muted-foreground">(необязательно)</span></label>
                <input class="input" id="guest_name" name="guest_name" type="text" maxlength="190" placeholder="Курьер, гости, мастер">
              </div>
              <div class="field">
                <label class="label" for="car_plate">Номер автомобиля</label>
                <input class="input input-mono" id="car_plate" name="car_plate" type="text" maxlength="16" placeholder="А123ВС77"
                       autocomplete="off" autocapitalize="characters">
              </div>
              <div class="field">
                <label class="label" for="guest_phone">Телефон гостя <span class="font-normal text-muted-foreground">(для SMS)</span></label>
                <input class="input" id="guest_phone" name="guest_phone" type="tel" inputmode="tel" maxlength="32" placeholder="+7…" data-phone>
              </div>
            </div>

            <div class="flex flex-wrap gap-3">
              <label class="checkbox-row text-xs">
                <input class="checkbox" type="checkbox" name="notify" value="1" checked>
                <span>Прислать мне ссылку и код</span>
              </label>
              <label class="checkbox-row text-xs">
                <input class="checkbox" type="checkbox" name="notify_guest" value="1">
                <span>Отправить SMS гостю</span>
              </label>
            </div>

            <button class="btn btn-default btn-block" type="submit">
              <?= $this->icon('user-plus') ?> Создать гостевой доступ
            </button>
          </form>

          <div class="grid gap-3" data-guest-result hidden></div>

          <?php if ($myPasses !== []): ?>
            <div class="border-t pt-3">
              <div class="label mb-2">Активные пропуска</div>
              <ul class="grid gap-2">
                <?php foreach ($myPasses as $pass): ?>
                  <li class="flex items-center gap-2 rounded-lg border bg-muted/25 p-2.5">
                    <div class="min-w-0 flex-1">
                      <div class="truncate font-mono text-sm font-semibold"><?= $this->e($pass->code) ?></div>
                      <div class="truncate text-xs text-muted-foreground">
                        до <?= $this->e($this->datetime($pass->validUntil, 'd.m H:i')) ?> ·
                        <span data-countdown="<?= $this->e((string) $pass->validUntil) ?>"></span>
                      </div>
                    </div>
                    <form method="post" action="<?= $this->e($this->url(($isStaff ? '/admin/guest/' : '/cabinet/guest/') . $pass->id . '/revoke')) ?>" data-ajax class="shrink-0">
                      <?= $this->csrf() ?>
                      <button class="btn btn-ghost btn-sm btn-icon text-destructive" type="submit" aria-label="Отозвать"
                              data-confirm="Отозвать гостевой доступ <?= $this->e($pass->code) ?>?" data-confirm-label="Отозвать" data-confirm-tone="destructive">
                        <?= $this->icon('ban', 'h-4 w-4') ?>
                      </button>
                    </form>
                  </li>
                <?php endforeach ?>
              </ul>
            </div>
          <?php endif ?>
        </div>
      </section>
    <?php endif ?>

    <?php /* ── сводка ─────────────────────────────────────────── */ ?>
    <?php if ($isStaff): ?>
      <section class="card">
        <div class="card-header"><div class="card-title">Быстрая сводка</div></div>
        <div class="card-content grid gap-2.5 text-sm" data-panel-summary
             data-panel-summary-url="<?= $this->e($this->url('/api/v1/stats')) ?>">
          <div class="flex items-center justify-between gap-2"><span class="text-muted-foreground">Активных жителей</span><b class="tabular" data-count="active">—</b></div>
          <div class="flex items-center justify-between gap-2"><span class="text-muted-foreground">Открытий сегодня</span><b class="tabular" data-count="today">—</b></div>
          <div class="flex items-center justify-between gap-2"><span class="text-muted-foreground">Отказов за месяц</span><b class="tabular" data-count="denied">—</b></div>
          <div class="flex items-center justify-between gap-2"><span class="text-muted-foreground">Гостевых активных</span><b class="tabular" data-count="guests">—</b></div>
          <div class="flex items-center justify-between gap-2"><span class="text-muted-foreground">Просрочено</span><b class="tabular" data-count="overdue">—</b></div>
        </div>
        <div class="card-footer flex-wrap gap-2">
          <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin')) ?>"><?= $this->icon('layout-dashboard', 'h-3.5 w-3.5') ?> Дашборд</a>
          <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/gate')) ?>"><?= $this->icon('history', 'h-3.5 w-3.5') ?> Журнал</a>
          <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/residents')) ?>"><?= $this->icon('users', 'h-3.5 w-3.5') ?> Жители</a>
        </div>
      </section>
    <?php elseif ($resident !== null): ?>
      <section class="card">
        <div class="card-header"><div class="card-title">Моя карточка</div></div>
        <div class="card-content grid gap-2.5 text-sm">
          <div class="flex items-center justify-between gap-2"><span class="text-muted-foreground">Квартира</span><b><?= $this->e($resident->address()) ?></b></div>
          <div class="flex items-center justify-between gap-2"><span class="text-muted-foreground">Доступ</span>
            <?= $resident->accessGranted ? '<span class="badge badge-soft-success">разрешён</span>' : '<span class="badge badge-soft-destructive">приостановлен</span>' ?>
          </div>
          <div class="flex items-center justify-between gap-2"><span class="text-muted-foreground">Автомобилей</span><b class="tabular"><?= count($resident->cars) ?></b></div>
          <div class="flex items-center justify-between gap-2"><span class="text-muted-foreground">Баланс</span>
            <b class="tabular <?= $resident->balance < 0 ? 'text-destructive' : '' ?>"><?= $this->e($this->money($resident->balance)) ?></b>
          </div>
          <div class="flex items-center justify-between gap-2"><span class="text-muted-foreground">Последнее открытие</span>
            <span class="text-xs" data-time-ago="<?= $this->e((string) ($resident->lastGateEventAt ?? '')) ?>">—</span>
          </div>
        </div>
        <div class="card-footer flex-wrap gap-2">
          <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/cabinet/payments')) ?>"><?= $this->icon('wallet', 'h-3.5 w-3.5') ?> Мои оплаты</a>
          <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/cabinet/profile')) ?>"><?= $this->icon('car', 'h-3.5 w-3.5') ?> Мои данные</a>
        </div>
      </section>
    <?php endif ?>

    <?php /* ── расписание/тихие часы ───────────────────────────── */ ?>
    <?php if (!empty($quietHours)): ?>
      <section class="card">
        <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('moon', 'h-4 w-4') ?> Тихие часы</div></div>
        <div class="card-content grid gap-2 text-sm">
          <div class="flex items-center justify-between gap-2">
            <span class="text-muted-foreground">Окно</span>
            <b class="tabular"><?= $this->e($quietHours['start']) ?> – <?= $this->e($quietHours['end']) ?></b>
          </div>
          <div class="flex items-center justify-between gap-2">
            <span class="text-muted-foreground">Сейчас</span>
            <?= !empty($quietHours['now']) ? '<span class="badge badge-soft-warning">тихие часы</span>' : '<span class="badge badge-soft-success">обычный режим</span>' ?>
          </div>
          <p class="text-xs text-muted-foreground">В это время шлагбаум открывает только диспетчер. Гостевой доступ <?= $this->config('guest.allow_self_service') ? 'разрешён' : 'ограничен' ?>.</p>
        </div>
      </section>
    <?php endif ?>

    <?php /* ── справка ────────────────────────────────────────── */ ?>
    <section class="card">
      <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('life-buoy', 'h-4 w-4') ?> Как это работает</div></div>
      <div class="card-content grid gap-2.5 text-sm text-muted-foreground">
        <p class="flex gap-2"><span class="text-foreground">1.</span> Кнопка «Открыть» отправляет команду на панель BAS-IP и сразу пишет запись в журнал.</p>
        <p class="flex gap-2"><span class="text-foreground">2.</span> Гостевой доступ — это одноразовая ссылка и код. Срок по умолчанию <?= (int) $defaultTtl ?> часов.</p>
        <p class="flex gap-2"><span class="text-foreground">3.</span> Все отказы тоже логируются: видно, почему шлагбаум не открылся.</p>
        <a class="btn btn-ghost btn-sm justify-start -ml-2 mt-1" href="<?= $this->e($this->url('/help/panel')) ?>">
          <?= $this->icon('book-open', 'h-3.5 w-3.5') ?> Подробно в справке
        </a>
      </div>
    </section>
  </aside>
</div>

<?php /* Мобильная панель: главная кнопка всегда под пальцем. */ ?>
<?php if ($canOpenNow): ?>
  <div class="mobile-action-bar no-print">
    <button type="button" class="btn btn-success btn-lg flex-1" data-gate-open="<?= $this->e($openUrl) ?>" data-gate-device="<?= $this->e($deviceSlug) ?>"
            data-gate-resident="<?= $resident?->id ?? '' ?>" data-gate-cooldown="<?= (int) $cooldown ?>">
      <?= $this->icon('door-open') ?> Открыть
    </button>
    <?php if ($canGuest): ?>
      <a class="btn btn-outline btn-lg btn-icon" href="#guest" aria-label="Гостевой доступ"><?= $this->icon('user-plus') ?></a>
    <?php endif ?>
  </div>
<?php endif ?>

<?php $this->startPush('scripts') ?>
<script nonce="<?= $this->e($this->nonce()) ?>">
(function () {
  var B = window.BasIp;
  if (!B) return;

  // Переключение устройства перезагружает панель с новым slug.
  var switcher = document.querySelector('[data-device-switch]');
  if (switcher) {
    switcher.addEventListener('change', function () {
      var url = new URL(window.location.href);
      url.searchParams.set('device', switcher.value);
      window.location.href = url.toString();
    });
  }

  // Ручное обновление статуса.
  var refresh = document.querySelector('[data-gate-refresh]');
  if (refresh) {
    refresh.addEventListener('click', function () {
      refresh.classList.add('animate-spin-slow');
      if (window.refreshStatus) window.refreshStatus(true);
      setTimeout(function () { refresh.classList.remove('animate-spin-slow'); }, 900);
    });
  }

  // Сводка для сотрудников.
  var summary = document.querySelector('[data-panel-summary]');
  if (summary) {
    var load = function () {
      B.get(summary.getAttribute('data-panel-summary-url')).then(function (data) {
        var o = (data && data.overview) || data || {};
        var set = function (key, value) {
          var el = summary.querySelector('[data-count="' + key + '"]');
          if (el) el.textContent = value === undefined || value === null ? '—' : String(value);
        };
        set('active', (o.residents || {}).active);
        set('today', (o.gate || {}).today);
        set('denied', (o.gate || {}).denied);
        set('guests', (o.guest || {}).active);
        set('overdue', (o.billing || {}).overdue ? B.money(o.billing.overdue) : '0');
        var navGuests = document.querySelector('[data-count="guests"]');
        if (navGuests && (o.guest || {}).active) navGuests.textContent = o.guest.active;
        var navResidents = document.querySelector('[data-count="residents"]');
        if (navResidents && (o.residents || {}).active) navResidents.textContent = o.residents.active;
      }).catch(function () {});
    };
    load();
    setInterval(load, 60000);
  }

  // Выбор жителя для диспетчера (поиск по фамилии/квартире).
  var picker = document.querySelector('[data-resident-picker]');
  if (picker) {
    var datalist = document.createElement('datalist');
    datalist.id = 'residents-datalist';
    picker.setAttribute('list', datalist.id);
    picker.parentNode.appendChild(datalist);
    B.get(<?= $this->json($this->url('/api/v1/residents?per_page=500&fields=id,full_name,apartment')) ?>)
      .then(function (data) {
        (data.data || []).forEach(function (r) {
          var opt = document.createElement('option');
          opt.value = r.id;
          opt.label = 'кв. ' + (r.apartment || '—') + ' · ' + r.full_name;
          datalist.appendChild(opt);
        });
      }).catch(function () {
        picker.insertAdjacentHTML('afterend', '<p class="form-message">Не удалось загрузить список жителей.</p>');
      });
  }
})();
</script>
<?php $this->endPush() ?>
