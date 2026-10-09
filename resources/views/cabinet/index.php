<?php
/**
 * Resident cabinet: my gate, my guest passes, my bills, my cars.
 *
 * @var BasIp\Domain\Model\Resident|null $resident
 * @var array|null $statement
 * @var array<int, BasIp\Domain\Model\Invoice> $invoices
 * @var array<int, BasIp\Domain\Model\GuestPass> $passes
 * @var int $passesUsed
 * @var int $passesLimit
 * @var int $dailyLimit
 * @var array<int, BasIp\Domain\Model\GateEvent> $recent
 * @var bool $canOpen
 * @var bool $canGuest
 * @var array $guestPresets
 * @var int $defaultTtl
 * @var bool $requirePlate
 * @var string $currency
 * @var bool $impersonating
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$user = $this->user();
$debt = (float) ($statement['debt'] ?? 0);
$overpay = (float) ($statement['overpay'] ?? 0);
?>
<?php $this->layout('layouts/app') ?>

<?php if (!empty($impersonating)): ?>
  <div class="alert alert-warning mb-5">
    <?= $this->icon('eye', 'h-4 w-4') ?>
    <div>
      <div class="alert-title">Режим просмотра от лица жителя</div>
      <div class="alert-desc">Вы смотрите личный кабинет <?= $this->e($resident?->displayName() ?? '') ?>. Действия записываются в журнал от вашего имени.</div>
    </div>
  </div>
<?php endif ?>

<?php if ($resident === null): ?>
  <div class="card">
    <?= $this->include('components/empty', [
      'title' => 'Карточка жителя не привязана',
      'description' => 'Ваша учётная запись не связана с записью в базе жителей. Обратитесь к управляющему — он привяжет аккаунт в разделе «Пользователи».',
      'icon' => 'user-x',
    ]) ?>
  </div>
<?php else: ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Здравствуйте, <?= $this->e($resident->firstName !== '' ? $resident->firstName : $resident->shortName()) ?>!</h2>
    <p class="page-desc"><?= $this->e($resident->address()) ?><?= $resident->roleLabel() !== '' ? ' · ' . $this->e($resident->roleLabel()) : '' ?></p>
  </div>
  <div class="page-actions">
    <?php if ($resident->accessGranted && $resident->isActive): ?>
      <span class="badge badge-soft-success"><?= $this->icon('check-circle', 'h-3 w-3') ?> доступ активен</span>
    <?php else: ?>
      <span class="badge badge-soft-destructive"><?= $this->icon('ban', 'h-3 w-3') ?> доступ приостановлен</span>
    <?php endif ?>
  </div>
</div>

<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_21rem]">
  <div class="grid content-start gap-5">

    <?php /* ── открыть шлагбаум ─────────────────────────────── */ ?>
    <section class="card">
      <div class="card-content grid gap-4 p-5">
        <button type="button" class="btn btn-gate"
                data-gate-open="<?= $this->e($this->url('/panel/open')) ?>"
                data-gate-resident="<?= (int) $resident->id ?>"
                data-gate-cooldown="<?= (int) $this->setting('gate.open_cooldown', 3) ?>"
                <?= $canOpen ? '' : 'disabled' ?>>
          <?= $this->icon('door-open', 'h-10 w-10') ?>
          <span>Открыть шлагбаум</span>
          <span class="text-xs font-normal opacity-80" data-gate-cooldown-label></span>
        </button>

        <a class="btn btn-outline btn-block" href="<?= $this->e($this->url('/remote')) ?>">
          <?= $this->icon('maximize', 'h-4 w-4') ?>
          Полноэкранный пульт с камерой
        </a>

        <?php if (!$canOpen): ?>
          <div class="alert alert-warning">
            <?= $this->icon('alert-triangle', 'h-4 w-4') ?>
            <div>
              <div class="alert-title">Открытие недоступно</div>
              <div class="alert-desc">
                <?= !$resident->isActive
                  ? 'Ваша запись отключена управляющим.'
                  : 'Доступ к шлагбауму приостановлен. Уточните причину у управляющего.' ?>
              </div>
            </div>
          </div>
        <?php endif ?>

        <div class="gate-visual h-28" aria-hidden="true">
          <div class="gate-bar" data-gate-bar></div>
          <div class="gate-post"></div>
        </div>
      </div>
    </section>

    <?php /* ── оплаты ───────────────────────────────────────── */ ?>
    <section class="card">
      <div class="card-header has-action">
        <div>
          <div class="card-title flex items-center gap-2"><?= $this->icon('wallet', 'h-4 w-4') ?> Начисления и оплаты</div>
          <div class="card-desc">Обслуживание шлагбаума делится поровну между активными жителями.</div>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/cabinet/payments')) ?>">Все счета <?= $this->icon('arrow-right', 'h-3.5 w-3.5') ?></a>
      </div>
      <div class="card-content grid gap-4">
        <div class="grid gap-3 sm:grid-cols-3">
          <?= $this->include('components/stat', [
            'label' => 'К оплате', 'value' => $this->money($debt), 'icon' => 'coins',
            'accent' => $debt > 0.005 ? 'destructive' : 'success',
            'hint' => $debt > 0.005 ? 'Есть открытые счета' : 'Задолженности нет',
          ]) ?>
          <?= $this->include('components/stat', [
            'label' => 'Переплата', 'value' => $this->money($overpay), 'icon' => 'piggy-bank',
            'accent' => $overpay > 0.005 ? 'success' : '',
            'hint' => $overpay > 0.005 ? 'Учтётся в следующем периоде' : '—',
          ]) ?>
          <?= $this->include('components/stat', [
            'label' => 'Открытых счетов', 'value' => (string) (int) ($statement['invoices'] ?? 0), 'icon' => 'receipt',
            'hint' => !empty($statement['oldest_due']) ? 'Ближайший срок: ' . $this->date((string) $statement['oldest_due']) : '—',
          ]) ?>
        </div>

        <?php if ($debt > 0.005): ?>
          <div class="alert alert-destructive">
            <?= $this->icon('alert-triangle', 'h-4 w-4') ?>
            <div>
              <div class="alert-title">Задолженность <?= $this->e($this->money($debt)) ?></div>
              <div class="alert-desc">
                <?php $payLink = $this->setting('billing.pay_link', ''); ?>
                <?php if ($payLink !== ''): ?>
                  <a class="font-medium underline underline-offset-4" href="<?= $this->e(str_replace(['{apartment}', '{amount}'], [rawurlencode($resident->apartment), number_format($debt, 2, '.', '')], (string) $payLink)) ?>" target="_blank" rel="noopener noreferrer">Оплатить онлайн</a>
                  или переведите по реквизитам и сообщите управляющему.
                <?php else: ?>
                  Свяжитесь с управляющим для уточнения способа оплаты.
                <?php endif ?>
              </div>
            </div>
          </div>
        <?php endif ?>

        <?php if ($invoices !== []): ?>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Счёт</th><th>Период</th><th class="text-right">Сумма</th><th>Срок</th><th>Статус</th></tr></thead>
              <tbody>
              <?php foreach (array_slice($invoices, 0, 6) as $invoice): ?>
                <tr>
                  <td class="font-mono text-xs"><?= $this->e($invoice->number) ?></td>
                  <td class="text-sm"><?= $this->e($invoice->periodLabel ?: '—') ?></td>
                  <td class="table-numeric font-semibold tabular"><?= $this->e($this->money($invoice->amount)) ?></td>
                  <td class="whitespace-nowrap text-xs tabular <?= $invoice->isOverdue() ? 'text-destructive' : 'text-muted-foreground' ?>"><?= $this->e($this->date($invoice->dueDate)) ?></td>
                  <td><span class="badge badge-soft-<?= $invoice->statusTone() === 'muted' ? 'warning' : $invoice->statusTone() ?>"><?= $this->e($invoice->statusLabel()) ?></span></td>
                </tr>
              <?php endforeach ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <?= $this->include('components/empty', ['title' => 'Счетов пока нет', 'description' => 'Начисления появятся, когда управляющий выставит оплату за период.', 'icon' => 'receipt']) ?>
        <?php endif ?>
      </div>
    </section>

    <?php /* ── мои данные ───────────────────────────────────── */ ?>
    <section class="card">
      <div class="card-header has-action">
        <div>
          <div class="card-title flex items-center gap-2"><?= $this->icon('car', 'h-4 w-4') ?> Автомобили и контакты</div>
          <div class="card-desc">Держите данные актуальными — по ним работает распознавание номеров и рассылки.</div>
        </div>
        <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/cabinet/profile')) ?>"><?= $this->icon('pencil', 'h-3.5 w-3.5') ?> Изменить</a>
      </div>
      <div class="card-content grid gap-4">
        <?php if ($cars === []): ?>
          <p class="text-sm text-muted-foreground">Автомобили не добавлены. Добавьте номер — диспетчер сможет пускать вас по госномеру.</p>
        <?php else: ?>
          <ul class="grid gap-2 sm:grid-cols-2">
            <?php foreach ($cars as $car): ?>
              <li class="flex items-center gap-3 rounded-lg border p-3 <?= empty($car['is_active']) ? 'opacity-60' : '' ?>">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-muted"><?= $this->icon('car', 'h-4 w-4 text-muted-foreground') ?></span>
                <div class="min-w-0 flex-1">
                  <div class="font-mono text-sm font-semibold"><?= $this->e(\BasIp\Support\Plate::format((string) ($car['plate'] ?? ''))) ?></div>
                  <div class="truncate text-xs text-muted-foreground"><?= $this->e(trim(($car['brand'] ?? '') . ' ' . ($car['model'] ?? '')) ?: '—') ?></div>
                </div>
                <?php if (empty($car['is_active'])): ?><span class="badge badge-muted shrink-0">выкл</span><?php endif ?>
              </li>
            <?php endforeach ?>
          </ul>
        <?php endif ?>

        <?php if ($contacts !== []): ?>
          <div class="border-t pt-4">
            <div class="label mb-2">Контакты</div>
            <ul class="flex flex-wrap gap-2">
              <?php foreach ($contacts as $contact): ?>
                <?php $model = \BasIp\Domain\Model\Contact::fromRow($contact); ?>
                <li>
                  <?php if ($model->href() !== null): ?>
                    <a class="badge badge-outline" href="<?= $this->e((string) $model->href()) ?>" target="_blank" rel="noopener noreferrer">
                      <?= $this->icon($model->icon(), 'h-3 w-3') ?> <?= $this->e($model->display()) ?>
                    </a>
                  <?php else: ?>
                    <span class="badge badge-outline"><?= $this->icon($model->icon(), 'h-3 w-3') ?> <?= $this->e($model->display()) ?></span>
                  <?php endif ?>
                </li>
              <?php endforeach ?>
            </ul>
          </div>
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
          <div class="card-desc">Пропуск на <?= (int) $defaultTtl ?> часов: гость сам откроет шлагбаум по ссылке или коду.</div>
        </div>
        <div class="card-content grid gap-3">
          <div class="flex items-center justify-between text-xs text-muted-foreground">
            <span>Активных: <b class="tabular text-foreground"><?= count($passes) ?></b> из <?= (int) $passesLimit ?></span>
            <span>Создано сегодня: <b class="tabular text-foreground"><?= (int) $passesUsed ?></b> из <?= (int) $dailyLimit ?></span>
          </div>

          <form method="post" action="<?= $this->e($this->url('/cabinet/guest')) ?>" data-guest-form data-ajax>
            <?= $this->csrf() ?>
            <input type="hidden" name="resident_id" value="<?= (int) $resident->id ?>">

            <div class="field">
              <div class="label mb-1.5">Срок действия</div>
              <div class="flex flex-wrap gap-1.5" data-guest-presets>
                <?php foreach (($guestPresets ?: [1, 2, 4, 8, 12, 24, 48]) as $preset): ?>
                  <button type="button" class="chip <?= (int) $preset === (int) $defaultTtl ? 'is-active' : '' ?>" data-guest-preset="<?= (int) $preset ?>"
                          <?= (int) $preset === (int) $defaultTtl ? 'aria-pressed="true"' : '' ?>><?= (int) $preset ?> ч</button>
                <?php endforeach ?>
              </div>
              <input class="input mt-2" type="number" name="ttl_hours" min="1" max="<?= (int) $this->config('guest.max_ttl_hours', 72) ?>"
                     value="<?= (int) $defaultTtl ?>" data-guest-ttl aria-label="Часов">
              <p class="mt-1 text-xs text-muted-foreground">Действует до: <b data-guest-until>—</b></p>
            </div>

            <div class="field">
              <label class="label" for="c_guest_name">Кто приезжает</label>
              <input class="input" id="c_guest_name" name="guest_name" type="text" maxlength="190" placeholder="Курьер, гости, мастер">
            </div>

            <div class="field">
              <label class="label" for="c_car_plate">Номер автомобиля <?= $requirePlate ? '<span class="text-destructive">*</span>' : '' ?></label>
              <input class="input input-mono" id="c_car_plate" name="car_plate" type="text" maxlength="16" placeholder="А123ВС77"
                     autocapitalize="characters" autocomplete="off" <?= $requirePlate ? 'required' : '' ?>>
            </div>

            <label class="checkbox-row text-xs">
              <input class="checkbox" type="checkbox" name="notify" value="1" checked>
              <span>Прислать мне ссылку и код</span>
            </label>

            <button class="btn btn-default btn-block" type="submit">
              <?= $this->icon('user-plus') ?> Создать пропуск
            </button>
          </form>

          <div class="grid gap-3" data-guest-result hidden></div>

          <?php if ($passes !== []): ?>
            <div class="border-t pt-3">
              <div class="label mb-2">Мои активные пропуска</div>
              <ul class="grid gap-2">
                <?php foreach ($passes as $pass): ?>
                  <li class="rounded-lg border p-2.5">
                    <div class="flex items-center gap-2">
                      <span class="font-mono text-sm font-semibold"><?= $this->e($pass->code) ?></span>
                      <span class="ml-auto text-xs tabular text-muted-foreground" data-countdown="<?= $this->e((string) $pass->validUntil) ?>"></span>
                    </div>
                    <div class="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                      <span class="truncate"><?= $this->e($pass->guestName ?? 'гость') ?><?= $pass->carPlate !== null ? ' · ' . $this->e($pass->displayPlate()) : '' ?></span>
                      <span class="ml-auto shrink-0"><?= $this->e($pass->usesLabel()) ?></span>
                    </div>
                    <div class="mt-2 h-1 overflow-hidden rounded-full bg-muted">
                      <div class="h-full rounded-full bg-success" style="width: <?= 100 - (float) $pass->percentElapsed() ?>%"></div>
                    </div>
                    <form method="post" action="<?= $this->e($this->url('/cabinet/guest/' . $pass->id . '/revoke')) ?>" data-ajax class="mt-2">
                      <?= $this->csrf() ?>
                      <button class="btn btn-ghost btn-sm w-full text-destructive hover:bg-destructive/10" type="submit"
                              data-confirm="Отозвать пропуск <?= $this->e($pass->code) ?>?" data-confirm-label="Отозвать" data-confirm-tone="destructive">
                        <?= $this->icon('ban', 'h-3.5 w-3.5') ?> Отозвать
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

    <?php /* ── история открытий ─────────────────────────────── */ ?>
    <section class="card">
      <div class="card-header has-action">
        <div class="card-title flex items-center gap-2"><?= $this->icon('history', 'h-4 w-4') ?> Последние открытия</div>
        <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/cabinet/log')) ?>">Все</a>
      </div>
      <div class="card-content p-0">
        <?php if ($recent === []): ?>
          <?= $this->include('components/empty', ['title' => 'Открытий пока не было', 'icon' => 'history']) ?>
        <?php else: ?>
          <ul class="divide-y">
            <?php foreach (array_slice($recent, 0, 8) as $event): ?>
              <li class="flex items-center gap-3 px-5 py-2.5">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full <?= $event->result === 'success' ? 'bg-success/12 text-success' : 'bg-destructive/12 text-destructive' ?>">
                  <?= $this->icon($event->result === 'success' ? 'check' : 'ban', 'h-3.5 w-3.5') ?>
                </span>
                <div class="min-w-0 flex-1">
                  <div class="truncate text-sm"><?= $this->e($event->accessLabel()) ?></div>
                  <div class="text-xs text-muted-foreground"><span data-time-ago="<?= $this->e((string) $event->occurredAt) ?>"></span> · <?= $this->e($event->sourceLabel()) ?></div>
                </div>
                <span class="shrink-0 text-xs tabular text-muted-foreground"><?= $this->e($event->when('d.m H:i')) ?></span>
              </li>
            <?php endforeach ?>
          </ul>
        <?php endif ?>
      </div>
    </section>

    <section class="card">
      <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('info', 'h-4 w-4') ?> Полезное</div></div>
      <div class="card-content grid gap-2 text-sm text-muted-foreground">
        <a class="btn btn-ghost justify-start -ml-2" href="<?= $this->e($this->url('/help/guest-access')) ?>"><?= $this->icon('book-open', 'h-4 w-4') ?> Как работает гостевой доступ</a>
        <a class="btn btn-ghost justify-start -ml-2" href="<?= $this->e($this->url('/help/payments')) ?>"><?= $this->icon('book-open', 'h-4 w-4') ?> Как считается оплата</a>
        <a class="btn btn-ghost justify-start -ml-2" href="<?= $this->e($this->url('/profile')) ?>"><?= $this->icon('user', 'h-4 w-4') ?> Профиль и пароль</a>
      </div>
    </section>
  </aside>
</div>

<?php if ($canOpen): ?>
  <div class="mobile-action-bar no-print">
    <button type="button" class="btn btn-success btn-lg flex-1" data-gate-open="<?= $this->e($this->url('/panel/open')) ?>"
            data-gate-resident="<?= (int) $resident->id ?>" data-gate-cooldown="<?= (int) $this->setting('gate.open_cooldown', 3) ?>">
      <?= $this->icon('door-open') ?> Открыть шлагбаум
    </button>
    <?php if ($canGuest): ?>
      <a class="btn btn-outline btn-lg btn-icon" href="#guest" aria-label="Гостевой доступ"><?= $this->icon('user-plus') ?></a>
    <?php endif ?>
  </div>
<?php endif ?>

<?php endif ?>
