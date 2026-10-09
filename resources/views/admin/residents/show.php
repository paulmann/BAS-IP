<?php
/**
 * Resident card: everything about one person on a single screen.
 *
 * @var BasIp\Domain\Model\Resident $resident
 * @var array $contacts
 * @var array $cars
 * @var array $statement
 * @var array<int, BasIp\Domain\Model\Invoice> $invoices
 * @var array<int, BasIp\Domain\Model\Payment> $payments
 * @var array<int, BasIp\Domain\Model\GateEvent> $events
 * @var array<int, BasIp\Domain\Model\GuestPass> $passes
 * @var array $history
 * @var array $audit
 * @var array|null $account
 * @var string $currency
 * @var bool $canEdit
 * @var bool $canGate
 * @var bool $canPayment
 * @var bool $canInvoice
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$debt = (float) ($statement['debt'] ?? 0);
$overpay = (float) ($statement['overpay'] ?? 0);
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="flex min-w-0 items-start gap-3">
    <span class="avatar avatar-xl shrink-0"><?= $this->e($resident->initials()) ?></span>
    <div class="min-w-0">
      <h2 class="page-title truncate"><?= $this->e($resident->displayName()) ?></h2>
      <p class="page-desc">
        <?= $this->e($resident->address()) ?> · <?= $this->e($resident->roleLabel()) ?>
        <?php if ($resident->tags !== []): ?> · <?= $this->e(implode(', ', $resident->tags)) ?><?php endif ?>
      </p>
      <div class="mt-2 flex flex-wrap gap-1.5">
        <?= $resident->isActive ? '<span class="badge badge-soft-success">активен</span>' : '<span class="badge badge-soft-destructive">отключен</span>' ?>
        <?= $resident->accessGranted ? '<span class="badge badge-soft-success">доступ разрешён</span>' : '<span class="badge badge-soft-warning">доступ приостановлен</span>' ?>
        <?= $resident->isBilled ? '<span class="badge badge-outline">участвует в оплате</span>' : '<span class="badge badge-muted">исключён из оплаты</span>' ?>
        <?php if ($debt > 0.005): ?><span class="badge badge-soft-destructive">долг <?= $this->e($this->money($debt)) ?></span><?php endif ?>
        <?php if ($overpay > 0.005): ?><span class="badge badge-soft-success">переплата <?= $this->e($this->money($overpay)) ?></span><?php endif ?>
        <?php if ($resident->deletedAt !== null): ?><span class="badge badge-muted">в архиве</span><?php endif ?>
      </div>
    </div>
  </div>

  <div class="page-actions">
    <?php if ($canGate && $resident->isActive && $resident->accessGranted): ?>
      <button class="btn btn-success btn-sm" type="button"
              data-quick-open="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/open-gate')) ?>"
              data-quick-open-name="<?= $this->e($resident->displayName()) ?>">
        <?= $this->icon('door-open', 'h-3.5 w-3.5') ?> Открыть шлагбаум
      </button>
    <?php endif ?>
    <?php if ($canEdit): ?>
      <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/edit')) ?>">
        <?= $this->icon('pencil', 'h-3.5 w-3.5') ?> Редактировать
      </a>
    <?php endif ?>
    <div class="relative">
      <button class="btn btn-outline btn-sm btn-icon" type="button" data-dropdown aria-haspopup="true" aria-expanded="false" aria-label="Ещё">
        <?= $this->icon('more-horizontal', 'h-4 w-4') ?>
      </button>
      <div class="dropdown right-0 top-full mt-1 w-60" data-dropdown-panel data-state="closed" hidden role="menu">
        <a class="dropdown-item" role="menuitem" href="<?= $this->e($this->url('/admin/guest?resident_id=' . $resident->id)) ?>"><?= $this->icon('user-plus') ?> Гостевые доступы</a>
        <a class="dropdown-item" role="menuitem" href="<?= $this->e($this->url('/admin/gate?resident_id=' . $resident->id)) ?>"><?= $this->icon('history') ?> Журнал открытий</a>
        <a class="dropdown-item" role="menuitem" href="<?= $this->e($this->url('/admin/billing/invoices?resident_id=' . $resident->id)) ?>"><?= $this->icon('receipt') ?> Все счета</a>
        <div class="dropdown-separator"></div>
        <?php if ($canPayment): ?>
          <button class="dropdown-item" role="menuitem" data-dialog="#dialog-payment"><?= $this->icon('coins') ?> Принять оплату</button>
        <?php endif ?>
        <?php if ($canInvoice): ?>
          <button class="dropdown-item" role="menuitem" data-dialog="#dialog-invoice"><?= $this->icon('file-plus') ?> Счёт вручную</button>
        <?php endif ?>
        <div class="dropdown-separator"></div>
        <?php if ($resident->deletedAt === null): ?>
          <form method="post" action="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/delete')) ?>">
            <?= $this->csrf() ?>
            <button class="dropdown-item w-full" role="menuitem" type="submit" data-destructive
                    data-confirm="Переместить «<?= $this->e($resident->displayName()) ?>» в архив?" data-confirm-label="В архив" data-confirm-tone="destructive">
              <?= $this->icon('archive') ?> В архив
            </button>
          </form>
        <?php else: ?>
          <form method="post" action="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/restore')) ?>">
            <?= $this->csrf() ?>
            <button class="dropdown-item w-full" role="menuitem" type="submit"><?= $this->icon('rotate-ccw') ?> Восстановить</button>
          </form>
        <?php endif ?>
      </div>
    </div>
  </div>
</div>

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
  <?= $this->include('components/stat', ['label' => 'Задолженность', 'value' => $this->money($debt), 'icon' => 'alert-triangle', 'accent' => $debt > 0.005 ? 'destructive' : 'success', 'hint' => 'открытых счетов: ' . (int) ($statement['invoices'] ?? 0)]) ?>
  <?= $this->include('components/stat', ['label' => 'Переплата', 'value' => $this->money($overpay), 'icon' => 'piggy-bank', 'accent' => $overpay > 0.005 ? 'success' : '', 'hint' => 'учтётся в следующем периоде']) ?>
  <?= $this->include('components/stat', ['label' => 'Открытий', 'value' => (string) count($events), 'icon' => 'door-open', 'hint' => 'последнее: ' . ($resident->lastGateEventAt !== null ? $this->date($resident->lastGateEventAt, 'd.m.Y') : '—')]) ?>
  <?= $this->include('components/stat', ['label' => 'Гостевых пропусков', 'value' => (string) count($passes), 'icon' => 'user-plus', 'hint' => 'выдано всего: ' . (int) $resident->guestPasses_issued]) ?>
</div>

<div class="grid gap-5 mt-5 xl:grid-cols-[minmax(0,1fr)_22rem]" data-tabs data-tabs-persist="resident" data-tabs-hash>
  <div class="grid content-start gap-5">
    <div class="tabs-list is-scrollable" role="tablist">
      <button class="tabs-trigger" role="tab" data-tab="billing" data-state="active" aria-selected="true"><?= $this->icon('wallet') ?> Оплаты</button>
      <button class="tabs-trigger" role="tab" data-tab="gate" data-state="inactive" aria-selected="false"><?= $this->icon('history') ?> Шлагбаум</button>
      <button class="tabs-trigger" role="tab" data-tab="guest" data-state="inactive" aria-selected="false"><?= $this->icon('user-plus') ?> Гости</button>
      <button class="tabs-trigger" role="tab" data-tab="data" data-state="inactive" aria-selected="false"><?= $this->icon('contact') ?> Данные</button>
      <button class="tabs-trigger" role="tab" data-tab="audit" data-state="inactive" aria-selected="false"><?= $this->icon('scroll-text') ?> История изменений</button>
    </div>

    <?php /* ── оплаты ─────────────────────────────────────── */ ?>
    <section class="tabs-content" role="tabpanel" data-tab-panel="billing">
      <div class="grid gap-5">
        <div class="card">
          <div class="card-header has-action">
            <div>
              <div class="card-title flex items-center gap-2"><?= $this->icon('receipt', 'h-4 w-4') ?> Счета</div>
              <div class="card-desc">Включая дополнительные счета и сторно после перерасчётов.</div>
            </div>
            <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/billing/invoices?resident_id=' . $resident->id)) ?>">Все счета</a>
          </div>
          <div class="card-content p-0">
            <?php if ($invoices === []): ?>
              <?= $this->include('components/empty', ['title' => 'Счетов нет', 'description' => 'Начисления появятся после создания расчёта за период.', 'icon' => 'receipt']) ?>
            <?php else: ?>
              <div class="table-wrap rounded-none border-0">
                <table class="table">
                  <thead><tr><th>Счёт</th><th>Период</th><th>Тип</th><th class="text-right">Начислено</th><th class="text-right">Оплачено</th><th class="text-right">Остаток</th><th>Срок</th><th>Статус</th><th></th></tr></thead>
                  <tbody>
                  <?php foreach ($invoices as $invoice): ?>
                    <tr class="<?= $invoice->isVoid() ? 'is-inactive' : '' ?>">
                      <td class="font-mono text-xs"><?= $this->e($invoice->number) ?></td>
                      <td class="text-xs"><?= $this->e($invoice->periodLabel ?: '—') ?></td>
                      <td><span class="badge badge-<?= $invoice->kind === 'adjustment' ? 'soft-info' : ($invoice->kind === 'credit' ? 'soft-warning' : 'muted') ?>"><?= $this->e($invoice->kindLabel()) ?></span></td>
                      <td class="table-numeric tabular"><?= $this->e($this->money($invoice->amount)) ?></td>
                      <td class="table-numeric tabular"><?= $this->e($this->money($invoice->paidAmount)) ?></td>
                      <td class="table-numeric font-semibold tabular"><?= $this->e($this->money($invoice->balance)) ?></td>
                      <td class="whitespace-nowrap text-xs tabular <?= $invoice->isOverdue() ? 'text-destructive' : 'text-muted-foreground' ?>"><?= $this->e($this->date($invoice->dueDate)) ?></td>
                      <td><span class="badge badge-soft-<?= $invoice->statusTone() === 'muted' ? 'warning' : $invoice->statusTone() ?>"><?= $this->e($invoice->statusLabel()) ?></span></td>
                      <td class="text-right">
                        <?php if ($canPayment && $invoice->isPending()): ?>
                          <button class="btn btn-ghost btn-sm btn-icon" type="button" data-dialog="#dialog-payment" data-tooltip="Принять оплату"
                                  data-payment-target="<?= (int) $invoice->id ?>" data-payment-amount="<?= $invoice->balance ?>">
                            <?= $this->icon('coins', 'h-3.5 w-3.5') ?>
                          </button>
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

        <div class="card">
          <div class="card-header has-action">
            <div class="card-title flex items-center gap-2"><?= $this->icon('banknote', 'h-4 w-4') ?> Оплаты</div>
            <?php if ($canPayment): ?>
              <button class="btn btn-default btn-sm" type="button" data-dialog="#dialog-payment"><?= $this->icon('plus', 'h-3.5 w-3.5') ?> Принять оплату</button>
            <?php endif ?>
          </div>
          <div class="card-content p-0">
            <?php if ($payments === []): ?>
              <?= $this->include('components/empty', ['title' => 'Оплат не было', 'icon' => 'banknote']) ?>
            <?php else: ?>
              <div class="table-wrap rounded-none border-0">
                <table class="table">
                  <thead><tr><th>Дата</th><th class="text-right">Сумма</th><th>Способ</th><th>№ платежа</th><th>Счёт</th><th>Примечание</th></tr></thead>
                  <tbody>
                  <?php foreach (array_slice($payments, 0, 30) as $payment): ?>
                    <tr>
                      <td class="whitespace-nowrap text-xs tabular"><?= $this->e($payment->dateLabel()) ?></td>
                      <td class="table-numeric font-semibold tabular text-success"><?= $this->e($this->money($payment->amount)) ?></td>
                      <td class="text-xs"><?= $this->e($payment->methodLabel()) ?></td>
                      <td class="font-mono text-xs"><?= $this->e($payment->reference ?? '—') ?></td>
                      <td class="font-mono text-xs"><?= $this->e($payment->invoiceNumber ?? '—') ?></td>
                      <td class="max-w-[16rem] truncate text-xs text-muted-foreground"><?= $this->e($payment->note ?? '') ?></td>
                    </tr>
                  <?php endforeach ?>
                  </tbody>
                </table>
              </div>
            <?php endif ?>
          </div>
        </div>
      </div>
    </section>

    <?php /* ── шлагбаум ───────────────────────────────────── */ ?>
    <section class="tabs-content" role="tabpanel" data-tab-panel="gate" hidden>
      <div class="card">
        <div class="card-header has-action">
          <div>
            <div class="card-title flex items-center gap-2"><?= $this->icon('history', 'h-4 w-4') ?> Журнал открытий</div>
            <div class="card-desc">Кто, когда и по какому доступу открывал шлагбаум.</div>
          </div>
          <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/gate?resident_id=' . $resident->id)) ?>">Полный журнал</a>
        </div>
        <div class="card-content p-0">
          <?php if ($events === []): ?>
            <?= $this->include('components/empty', ['title' => 'Открытий не зафиксировано', 'icon' => 'history']) ?>
          <?php else: ?>
            <div class="table-wrap rounded-none border-0">
              <table class="table">
                <thead><tr><th>Когда</th><th>Доступ</th><th>Кто</th><th>Источник</th><th>Результат</th><th>Причина</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($events, 0, 50) as $event): ?>
                  <tr>
                    <td class="whitespace-nowrap text-xs tabular"><?= $this->e($event->when('d.m.Y H:i:s')) ?></td>
                    <td><span class="badge badge-soft-<?= $event->accessTone() === 'muted' ? 'warning' : $event->accessTone() ?>"><?= $this->e($event->accessLabel()) ?></span></td>
                    <td class="max-w-[14rem] truncate text-sm"><?= $this->e($event->actorLabel()) ?></td>
                    <td class="text-xs text-muted-foreground"><?= $this->e($event->sourceLabel()) ?></td>
                    <td><span class="badge badge-soft-<?= $event->resultTone() === 'muted' ? 'warning' : $event->resultTone() ?>"><?= $this->e($event->resultLabel()) ?></span></td>
                    <td class="max-w-[18rem] truncate text-xs text-muted-foreground"><?= $this->e($event->reason ?? '') ?></td>
                  </tr>
                <?php endforeach ?>
                </tbody>
              </table>
            </div>
          <?php endif ?>
        </div>
      </div>
    </section>

    <?php /* ── гости ──────────────────────────────────────── */ ?>
    <section class="tabs-content" role="tabpanel" data-tab-panel="guest" hidden>
      <div class="card">
        <div class="card-header has-action">
          <div class="card-title flex items-center gap-2"><?= $this->icon('user-plus', 'h-4 w-4') ?> Гостевые доступы</div>
          <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/guest?resident_id=' . $resident->id)) ?>">Все</a>
        </div>
        <div class="card-content p-0">
          <?php if ($passes === []): ?>
            <?= $this->include('components/empty', ['title' => 'Гостевых доступов не выдавали', 'icon' => 'user-plus']) ?>
          <?php else: ?>
            <div class="table-wrap rounded-none border-0">
              <table class="table">
                <thead><tr><th>Код</th><th>Гость</th><th>Авто</th><th>Действует до</th><th>Использований</th><th>Статус</th></tr></thead>
                <tbody>
                <?php foreach ($passes as $pass): ?>
                  <tr>
                    <td class="font-mono text-sm font-semibold"><?= $this->e($pass->code) ?></td>
                    <td class="text-sm"><?= $this->e($pass->guestName ?? '—') ?><div class="text-xs text-muted-foreground"><?= $this->e($pass->purpose ?? '') ?></div></td>
                    <td class="font-mono text-xs"><?= $this->e($pass->displayPlate() ?: '—') ?></td>
                    <td class="whitespace-nowrap text-xs tabular"><?= $this->e($this->datetime($pass->validUntil, 'd.m.Y H:i')) ?></td>
                    <td class="tabular text-sm"><?= $this->e($pass->usesLabel()) ?></td>
                    <td><span class="badge badge-soft-<?= $pass->statusTone() === 'muted' ? 'warning' : $pass->statusTone() ?>"><?= $this->e($pass->statusLabel()) ?></span></td>
                  </tr>
                <?php endforeach ?>
                </tbody>
              </table>
            </div>
          <?php endif ?>
        </div>
      </div>
    </section>

    <?php /* ── данные ─────────────────────────────────────── */ ?>
    <section class="tabs-content" role="tabpanel" data-tab-panel="data" hidden>
      <div class="grid gap-5 lg:grid-cols-2">
        <div class="card">
          <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('at-sign', 'h-4 w-4') ?> Контакты</div></div>
          <div class="card-content grid gap-2">
            <?php if ($contacts === []): ?>
              <p class="text-sm text-muted-foreground">Контактов нет — уведомления этому жителю не дойдут.</p>
            <?php else: ?>
              <?php foreach ($contacts as $contact): ?>
                <?php $model = \BasIp\Domain\Model\Contact::fromRow($contact); ?>
                <div class="flex items-center gap-3 rounded-lg border p-2.5">
                  <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted"><?= $this->icon($model->icon(), 'h-4 w-4 text-muted-foreground') ?></span>
                  <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-medium">
                      <?php if ($model->href() !== null): ?>
                        <a class="hover:underline" href="<?= $this->e((string) $model->href()) ?>" target="_blank" rel="noopener noreferrer"><?= $this->e($model->display()) ?></a>
                      <?php else: ?>
                        <?= $this->e($model->display()) ?>
                      <?php endif ?>
                    </div>
                    <div class="text-xs text-muted-foreground"><?= $this->e($model->typeLabel()) ?><?= $model->label !== null ? ' · ' . $this->e($model->label) : '' ?></div>
                  </div>
                  <div class="flex shrink-0 gap-1">
                    <?php if ($model->isPrimary): ?><span class="badge badge-soft-info">основной</span><?php endif ?>
                    <?= $model->notify ? '<span class="badge badge-muted">уведомлять</span>' : '<span class="badge badge-muted">без уведомлений</span>' ?>
                    <?php if ($model->verified): ?><span class="badge badge-soft-success" data-tooltip="Подтверждён"><?= $this->icon('check', 'h-3 w-3') ?></span><?php endif ?>
                  </div>
                </div>
              <?php endforeach ?>
            <?php endif ?>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('car', 'h-4 w-4') ?> Автомобили</div></div>
          <div class="card-content grid gap-2">
            <?php if ($cars === []): ?>
              <p class="text-sm text-muted-foreground">Автомобили не добавлены.</p>
            <?php else: ?>
              <?php foreach ($cars as $car): ?>
                <?php $model = \BasIp\Domain\Model\Car::fromRow($car); ?>
                <div class="flex items-center gap-3 rounded-lg border p-2.5 <?= $model->isActive ? '' : 'opacity-60' ?>">
                  <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted"><?= $this->icon('car', 'h-4 w-4 text-muted-foreground') ?></span>
                  <div class="min-w-0 flex-1">
                    <div class="font-mono text-sm font-semibold"><?= $this->e($model->displayPlate() ?: 'без номера') ?></div>
                    <div class="truncate text-xs text-muted-foreground">
                      <?= $this->e($model->label() ?: $model->typeLabel()) ?><?= $model->year !== null ? ' · ' . (int) $model->year : '' ?>
                    </div>
                  </div>
                  <div class="flex shrink-0 flex-col items-end gap-1">
                    <?= $model->isActive ? '<span class="badge badge-soft-success">активен</span>' : '<span class="badge badge-muted">выключен</span>' ?>
                    <?php if ($model->rfidTag !== null): ?><span class="badge badge-outline font-mono text-[10px]"><?= $this->e($model->rfidTag) ?></span><?php endif ?>
                  </div>
                </div>
              <?php endforeach ?>
            <?php endif ?>
          </div>
        </div>

        <div class="card lg:col-span-2">
          <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('notebook-pen', 'h-4 w-4') ?> Комментарий и метки</div></div>
          <div class="card-content grid gap-3">
            <?php if ($resident->notes === null || $resident->notes === ''): ?>
              <p class="text-sm text-muted-foreground">Комментариев нет.</p>
            <?php else: ?>
              <p class="whitespace-pre-line rounded-lg border bg-muted/30 p-3 text-sm"><?= $this->e($resident->notes) ?></p>
            <?php endif ?>
            <?php if ($resident->tags !== []): ?>
              <div class="flex flex-wrap gap-1.5">
                <?php foreach ($resident->tags as $tag): ?>
                  <a class="badge badge-outline" href="<?= $this->e($this->url('/admin/residents?tag=' . rawurlencode($tag))) ?>"><?= $this->icon('tag', 'h-3 w-3') ?> <?= $this->e($tag) ?></a>
                <?php endforeach ?>
              </div>
            <?php endif ?>
          </div>
        </div>

        <div class="card lg:col-span-2">
          <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('info', 'h-4 w-4') ?> Параметры</div></div>
          <div class="card-content grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <?php
              $fields = [
                'Площадь' => $resident->area > 0 ? $resident->area . ' м²' : '—',
                'Проживает' => $resident->persons . ' чел.',
                'Доля' => (string) $resident->share,
                'Этаж' => $resident->floor !== null ? (string) $resident->floor : '—',
                'Внешний ID' => $resident->externalId ?? '—',
                'Уведомления' => $resident->notifyEnabled ? 'включены' : 'выключены',
                'Гостевой доступ' => $resident->allowGuestPass ? 'разрешён' : 'запрещён',
                'Выдано пропусков' => (string) $resident->guestPasses_issued,
                'Создан' => $this->datetime($resident->createdAt),
                'Изменён' => $this->datetime($resident->updatedAt),
                'Последняя оплата' => $resident->lastPaymentAt !== null ? $this->date($resident->lastPaymentAt) : '—',
                'Последнее открытие' => $resident->lastGateEventAt !== null ? $this->datetime($resident->lastGateEventAt) : '—',
              ];
            ?>
            <?php foreach ($fields as $label => $val): ?>
              <div class="flex justify-between gap-3 border-b border-border/40 py-1.5 last:border-0">
                <span class="text-muted-foreground"><?= $this->e($label) ?></span>
                <b class="text-right font-medium"><?= $this->e((string) $val) ?></b>
              </div>
            <?php endforeach ?>
          </div>
        </div>
      </div>
    </section>

    <?php /* ── аудит ──────────────────────────────────────── */ ?>
    <section class="tabs-content" role="tabpanel" data-tab-panel="audit" hidden>
      <div class="card">
        <div class="card-header">
          <div class="card-title flex items-center gap-2"><?= $this->icon('scroll-text', 'h-4 w-4') ?> История изменений</div>
          <div class="card-desc">Кто и что менял в этой карточке, а также движения по деньгам.</div>
        </div>
        <div class="card-content">
          <?php if ($audit === [] && $history === []): ?>
            <?= $this->include('components/empty', ['title' => 'Записей пока нет', 'icon' => 'scroll-text']) ?>
          <?php else: ?>
            <div class="timeline">
              <?php
                $entries = [];
                foreach ($audit as $entry) {
                    $entries[] = ['at' => $entry['occurred_at'] ?? '', 'title' => (string) $entry['action'], 'desc' => (string) ($entry['actor_name'] ?? '') . ' · ' . (string) ($entry['subject_label'] ?? ''), 'tone' => !empty($entry['is_security']) ? 'warning' : 'info'];
                }
                foreach ($history as $entry) {
                    $entries[] = ['at' => $entry['occurred_at'] ?? '', 'title' => $this->billingActionLabel((string) ($entry['action'] ?? '')), 'desc' => $this->money((float) ($entry['amount'] ?? 0)), 'tone' => (float) ($entry['amount'] ?? 0) >= 0 ? 'success' : 'warning'];
                }
                usort($entries, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));
              ?>
              <?php foreach (array_slice($entries, 0, 60) as $entry): ?>
                <div class="timeline-item is-<?= $this->e($entry['tone']) ?>">
                  <div class="timeline-time"><?= $this->e($this->datetime($entry['at'])) ?></div>
                  <div class="timeline-title"><?= $this->e($entry['title']) ?></div>
                  <?php if ($entry['desc'] !== ''): ?><div class="timeline-desc"><?= $this->e($entry['desc']) ?></div><?php endif ?>
                </div>
              <?php endforeach ?>
            </div>
          <?php endif ?>
        </div>
      </div>
    </section>
  </div>

  <?php /* ═══════════ правая колонка ═══════════ */ ?>
  <aside class="grid content-start gap-5">
    <div class="card">
      <div class="card-header"><div class="card-title">Быстрые действия</div></div>
      <div class="card-content grid gap-2">
        <?php if ($canGate && $resident->isActive && $resident->accessGranted): ?>
          <button class="btn btn-success btn-block justify-start" type="button"
                  data-quick-open="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/open-gate')) ?>"
                  data-quick-open-name="<?= $this->e($resident->displayName()) ?>">
            <?= $this->icon('door-open') ?> Открыть шлагбаум
          </button>
        <?php endif ?>
        <?php if ($canPayment): ?>
          <button class="btn btn-outline btn-block justify-start" type="button" data-dialog="#dialog-payment"><?= $this->icon('coins') ?> Принять оплату</button>
        <?php endif ?>
        <?php if ($canInvoice): ?>
          <button class="btn btn-outline btn-block justify-start" type="button" data-dialog="#dialog-invoice"><?= $this->icon('file-plus') ?> Счёт вручную</button>
        <?php endif ?>
        <a class="btn btn-outline btn-block justify-start" href="<?= $this->e($this->url('/admin/guest?resident_id=' . $resident->id)) ?>"><?= $this->icon('user-plus') ?> Гостевой доступ</a>
        <?php if ($canEdit): ?>
          <a class="btn btn-outline btn-block justify-start" href="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/edit')) ?>"><?= $this->icon('pencil') ?> Редактировать</a>
        <?php endif ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title">Учётная запись</div></div>
      <div class="card-content grid gap-2 text-sm">
        <?php if ($account === null): ?>
          <p class="text-muted-foreground">Личный кабинет не создан.</p>
          <?php if ($this->user()?->can('users.create')): ?>
            <form method="post" action="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/account')) ?>">
              <?= $this->csrf() ?>
              <button class="btn btn-outline btn-sm btn-block" type="submit"><?= $this->icon('user-plus', 'h-3.5 w-3.5') ?> Создать кабинет</button>
            </form>
          <?php endif ?>
        <?php else: ?>
          <div class="flex justify-between gap-2"><span class="text-muted-foreground">Логин</span><b><?= $this->e((string) $account['login']) ?></b></div>
          <div class="flex justify-between gap-2"><span class="text-muted-foreground">Активна</span><?= !empty($account['is_active']) ? '<span class="badge badge-soft-success">да</span>' : '<span class="badge badge-muted">нет</span>' ?></div>
          <div class="flex justify-between gap-2"><span class="text-muted-foreground">2FA</span><?= !empty($account['totp_enabled']) ? '<span class="badge badge-soft-success">вкл</span>' : '<span class="badge badge-muted">нет</span>' ?></div>
          <div class="flex justify-between gap-2"><span class="text-muted-foreground">Вход</span><span class="text-xs tabular"><?= $this->e($this->datetime($account['last_login_at'] ?? null)) ?></span></div>
          <?php if ($this->user()?->can('users.view')): ?>
            <a class="btn btn-ghost btn-sm btn-block justify-start" href="<?= $this->e($this->url('/admin/users/' . (int) $account['id'])) ?>"><?= $this->icon('settings', 'h-3.5 w-3.5') ?> Управление</a>
          <?php endif ?>
        <?php endif ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title">Идентификаторы</div></div>
      <div class="card-content grid gap-2 text-xs">
        <div class="flex items-center justify-between gap-2">
          <span class="text-muted-foreground">ID</span><b class="tabular"><?= (int) $resident->id ?></b>
        </div>
        <div class="flex items-center justify-between gap-2">
          <span class="text-muted-foreground">UUID</span>
          <span class="font-mono copy-target" data-copy="<?= $this->e($resident->uuid) ?>" data-tooltip="Скопировать"><?= $this->e($resident->uuid) ?></span>
        </div>
        <?php if ($resident->externalId !== null): ?>
          <div class="flex items-center justify-between gap-2">
            <span class="text-muted-foreground">Внешний ID</span><span class="font-mono"><?= $this->e($resident->externalId) ?></span>
          </div>
        <?php endif ?>
      </div>
    </div>
  </aside>
</div>

<?php /* ═══════════ диалоги ═══════════ */ ?>
<?php if ($canPayment): ?>
  <div class="dialog dialog-sm" id="dialog-payment" data-state="closed" hidden role="dialog" aria-labelledby="pay-title">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <form method="post" action="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/payment')) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/residents/' . $resident->id)) ?>" data-payment-form>
      <?= $this->csrf() ?>
      <div class="dialog-header">
        <h2 class="dialog-title" id="pay-title">Принять оплату</h2>
        <p class="dialog-desc"><?= $this->e($resident->displayName()) ?> · кв. <?= $this->e($resident->apartment) ?></p>
      </div>
      <div class="grid gap-3">
        <div class="field">
          <label class="label" for="pay-amount">Сумма</label>
          <div class="input-group">
            <input class="input input-mono" id="pay-amount" name="amount" type="text" inputmode="decimal" required placeholder="0.00"
                   value="<?= $debt > 0.005 ? number_format($debt, 2, '.', '') : '' ?>">
            <span class="addon"><?= $this->e($currency) ?></span>
          </div>
          <?php if ($debt > 0.005): ?>
            <div class="mt-1.5 flex flex-wrap gap-1.5">
              <button type="button" class="btn btn-outline btn-sm" data-payment-fill="<?= $debt ?>">Долг: <?= $this->e($this->money($debt)) ?></button>
            </div>
          <?php endif ?>
        </div>

        <div class="field">
          <label class="label" for="pay-invoice">Зачесть в счёт</label>
          <select class="select" id="pay-invoice" name="invoice_id"
                  data-payment-invoices-url="<?= $this->e($this->url('/api/v1/residents/' . $resident->id)) ?>">
            <option value="">Автоматически (старейший неоплаченный)</option>
            <?php foreach ($invoices as $invoice): ?>
              <?php if (!$invoice->isPending()) { continue; } ?>
              <option value="<?= (int) $invoice->id ?>" data-amount="<?= $invoice->balance ?>" data-label="<?= $this->e($invoice->periodLabel) ?>">
                <?= $this->e($invoice->number) ?> · <?= $this->e($invoice->periodLabel ?: '—') ?> · остаток <?= $this->e($this->money($invoice->balance)) ?>
              </option>
            <?php endforeach ?>
          </select>
          <p class="text-xs text-muted-foreground" data-payment-debt></p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="pay-date">Дата оплаты</label>
            <input class="input" id="pay-date" name="paid_at" type="date" value="<?= $this->e(date('Y-m-d')) ?>">
          </div>
          <div class="field">
            <label class="label" for="pay-method">Способ</label>
            <select class="select" id="pay-method" name="method">
              <?php foreach (['transfer' => 'Перевод', 'cash' => 'Наличные', 'card' => 'Карта', 'sbp' => 'СБП', 'online' => 'Онлайн', 'other' => 'Другое'] as $value => $label): ?>
                <option value="<?= $value ?>"><?= $label ?></option>
              <?php endforeach ?>
            </select>
          </div>
        </div>

        <div class="field">
          <label class="label" for="pay-ref">№ платёжного документа</label>
          <input class="input input-mono" id="pay-ref" name="reference" type="text" maxlength="120" placeholder="Например, ПП-1234 от 01.09">
        </div>
        <div class="field">
          <label class="label" for="pay-note">Примечание</label>
          <input class="input" id="pay-note" name="note" type="text" maxlength="2000" placeholder="Комментарий">
        </div>
      </div>
      <div class="dialog-footer mt-4">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="submit" class="btn btn-default"><?= $this->icon('check') ?> Провести оплату</button>
      </div>
    </form>
  </div>
<?php endif ?>

<?php if ($canInvoice): ?>
  <div class="dialog dialog-sm" id="dialog-invoice" data-state="closed" hidden role="dialog" aria-labelledby="inv-title">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <form method="post" action="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/invoice')) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/residents/' . $resident->id)) ?>">
      <?= $this->csrf() ?>
      <div class="dialog-header">
        <h2 class="dialog-title" id="inv-title">Счёт вручную</h2>
        <p class="dialog-desc">Разовое начисление вне общей сметы: ремонт, штраф, перерасчёт. Отрицательная сумма создаст сторно.</p>
      </div>
      <div class="grid gap-3">
        <div class="field">
          <label class="label" for="inv-amount">Сумма</label>
          <div class="input-group">
            <input class="input input-mono" id="inv-amount" name="amount" type="text" inputmode="decimal" required placeholder="0.00">
            <span class="addon"><?= $this->e($currency) ?></span>
          </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="inv-period">Период</label>
            <input class="input" id="inv-period" name="period_label" type="text" maxlength="64" placeholder="<?= $this->e(date('F Y')) ?>">
          </div>
          <div class="field">
            <label class="label" for="inv-due">Срок оплаты</label>
            <input class="input" id="inv-due" name="due_date" type="date" value="<?= $this->e(date('Y-m-d', strtotime('+' . (int) $this->config('billing.due_days', 14) . ' days'))) ?>">
          </div>
        </div>
        <div class="field">
          <label class="label" for="inv-note">Основание</label>
          <input class="input" id="inv-note" name="note" type="text" maxlength="2000" placeholder="Например: замена привода по решению собрания">
        </div>
      </div>
      <div class="dialog-footer mt-4">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="submit" class="btn btn-default"><?= $this->icon('file-plus') ?> Выставить счёт</button>
      </div>
    </form>
  </div>
<?php endif ?>

<?php $this->startPush('scripts') ?>
<script nonce="<?= $this->e($this->nonce()) ?>">
document.addEventListener('click', function (e) {
  var opener = e.target.closest('[data-quick-open]');
  if (!opener) return;
  e.preventDefault();
  var url = opener.getAttribute('data-quick-open');
  var name = opener.getAttribute('data-quick-open-name') || '';
  window.BasIp.confirm({
    title: 'Открыть шлагбаум?',
    message: 'Команда будет отправлена на панель и записана в журнал' + (name ? ': ' + name : '') + '.',
    confirmLabel: 'Открыть',
    tone: 'default'
  }).then(function (ok) {
    if (!ok) return;
    window.BasIp.busy(opener, true);
    window.BasIp.post(url, {}).then(function (data) {
      window.BasIp.busy(opener, false);
      window.BasIp.toast.success(data.message || 'Шлагбаум открыт');
    }).catch(function (err) {
      window.BasIp.busy(opener, false);
      window.BasIp.toast.error((err && err.message) || 'Отказ');
    });
  });
});

// Подстановка суммы при открытии диалога оплаты из строки счёта.
document.querySelectorAll('[data-payment-target]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var select = document.getElementById('pay-invoice');
    var amount = document.getElementById('pay-amount');
    if (select) select.value = btn.getAttribute('data-payment-target');
    if (amount) amount.value = btn.getAttribute('data-payment-amount');
  });
});
</script>
<?php $this->endPush() ?>
