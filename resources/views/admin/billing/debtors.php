<?php
/**
 * Debtors list with mass reminders.
 *
 * @var array<int, array<string, mixed>> $debtors
 * @var float $totalDebt
 * @var string $symbol
 * @var bool $onlyActive
 * @var bool $canNotify
 * @var array $channels
 * @var array $query
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$activeCount = count(array_filter($debtors, static fn (array $d): bool => (bool) $d['active']));
$overdue30 = count(array_filter($debtors, static fn (array $d): bool => (int) $d['days_overdue'] >= 30));
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Должники</h2>
    <p class="page-desc">
      <?= $onlyActive ? 'Только активные жители' : 'Все жители, включая отключённых' ?>.
      Отключённые не получают напоминаний и не участвуют в расчётах.
    </p>
  </div>
  <div class="page-actions">
    <form method="get" class="inline">
      <label class="chip <?= $onlyActive ? 'is-active' : '' ?>">
        <input type="checkbox" class="sr-only" name="include_inactive" value="1" <?= $onlyActive ? '' : 'checked' ?> onchange="this.form.submit()">
        показать отключённых
      </label>
    </form>
    <?php if ($canNotify && $activeCount > 0): ?>
      <form method="post" action="<?= $this->e($this->url('/admin/billing/remind')) ?>" data-ajax class="inline">
        <?= $this->csrf() ?>
        <button class="btn btn-default btn-sm" type="submit"
                data-confirm="Отправить напоминания <?= (int) $activeCount ?> активным должникам?" data-confirm-title="Рассылка напоминаний" data-confirm-label="Отправить">
          <?= $this->icon('send', 'h-3.5 w-3.5') ?> Напомнить всем (<?= (int) $activeCount ?>)
        </button>
      </form>
    <?php endif ?>
  </div>
</div>

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
  <?= $this->include('components/stat', ['label' => 'Должников', 'value' => (string) count($debtors), 'icon' => 'users', 'hint' => 'активных: ' . $activeCount]) ?>
  <?= $this->include('components/stat', ['label' => 'Сумма долга', 'value' => $this->money($totalDebt), 'icon' => 'alert-triangle', 'accent' => $totalDebt > 0.005 ? 'destructive' : 'success']) ?>
  <?= $this->include('components/stat', ['label' => 'Просрочка 30+ дней', 'value' => (string) $overdue30, 'icon' => 'calendar-x', 'accent' => $overdue30 > 0 ? 'warning' : '']) ?>
  <?= $this->include('components/stat', ['label' => 'Средний долг', 'value' => $this->money(count($debtors) > 0 ? $totalDebt / count($debtors) : 0), 'icon' => 'calculator']) ?>
</div>

<div class="card mt-5">
  <div class="table-toolbar">
    <div class="flex items-center gap-2">
      <?= $this->icon('search', 'h-4 w-4 text-muted-foreground') ?>
      <input class="input input-sm w-64 border-0 shadow-none focus-visible:ring-0" type="search" data-table-filter placeholder="Фильтр по ФИО или квартире…">
    </div>
    <a class="btn btn-outline btn-sm ml-auto" href="<?= $this->e($this->url('/admin/data/export/payments')) ?>"><?= $this->icon('download', 'h-3.5 w-3.5') ?> Выгрузить оплаты</a>
  </div>

  <div class="card-content p-0">
    <?php if ($debtors === []): ?>
      <?= $this->include('components/empty', [
        'title' => 'Должников нет',
        'description' => 'Все активные жители оплатили выставленные счета.',
        'icon' => 'check-circle',
      ]) ?>
    <?php else: ?>
      <div class="table-wrap rounded-none border-0" data-table>
        <table class="table">
          <thead>
            <tr><th>Квартира</th><th>Житель</th><th>Контакт</th><th class="text-right">Долг</th>
                <th class="text-right">Счетов</th><th>Старейший срок</th><th class="text-right">Просрочка</th><th class="text-right">Действия</th></tr>
          </thead>
          <tbody>
          <?php foreach ($debtors as $debtor): ?>
            <tr class="<?= empty($debtor['active']) ? 'is-inactive' : '' ?>">
              <td class="font-medium tabular">кв. <?= $this->e((string) ($debtor['apartment'] ?? '—')) ?><?php if (!empty($debtor['building'])): ?><div class="text-xs text-muted-foreground">корп. <?= $this->e((string) $debtor['building']) ?></div><?php endif ?></td>
              <td>
                <a class="text-sm hover:underline" href="<?= $this->e($this->url('/admin/residents/' . (int) $debtor['resident_id'])) ?>"><?= $this->e((string) ($debtor['name'] ?? '—')) ?></a>
                <?php if (empty($debtor['active'])): ?> <span class="badge badge-muted">отключён</span><?php endif ?>
              </td>
              <td class="text-xs">
                <?php if (!empty($debtor['phone'])): ?>
                  <a class="inline-flex items-center gap-1 hover:text-primary" href="tel:<?= $this->e(\BasIp\Support\Phone::tel((string) $debtor['phone'])) ?>">
                    <?= $this->icon('phone', 'h-3 w-3 text-muted-foreground') ?> <?= $this->e(\BasIp\Support\Phone::format((string) $debtor['phone'])) ?>
                  </a>
                <?php endif ?>
                <?php if (!empty($debtor['email'])): ?>
                  <a class="mt-0.5 inline-flex max-w-[14rem] items-center gap-1 truncate hover:text-primary" href="mailto:<?= $this->e((string) $debtor['email']) ?>">
                    <?= $this->icon('mail', 'h-3 w-3 shrink-0 text-muted-foreground') ?> <span class="truncate"><?= $this->e((string) $debtor['email']) ?></span>
                  </a>
                <?php endif ?>
                <?php if (empty($debtor['phone']) && empty($debtor['email'])): ?>
                  <span class="badge badge-soft-destructive" data-tooltip="Уведомление не дойдёт — добавьте контакт"><?= $this->icon('alert-triangle', 'h-3 w-3') ?> нет контактов</span>
                <?php endif ?>
              </td>
              <td class="table-numeric font-semibold tabular text-destructive"><?= $this->e($this->money((float) $debtor['debt'])) ?></td>
              <td class="table-numeric tabular"><?= (int) $debtor['invoices'] ?></td>
              <td class="whitespace-nowrap text-xs tabular text-muted-foreground"><?= $this->e($this->date($debtor['oldest_due'] ?? null)) ?></td>
              <td class="text-right">
                <?php if ((int) $debtor['days_overdue'] > 0): ?>
                  <span class="badge badge-soft-<?= (int) $debtor['days_overdue'] >= 30 ? 'destructive' : 'warning' ?>"><?= (int) $debtor['days_overdue'] ?> дн.</span>
                <?php else: ?>
                  <span class="text-xs text-muted-foreground">—</span>
                <?php endif ?>
              </td>
              <td class="text-right">
                <div class="flex justify-end gap-1">
                  <a class="btn btn-ghost btn-sm btn-icon" href="<?= $this->e($this->url('/admin/residents/' . (int) $debtor['resident_id'])) ?>" aria-label="Карточка" data-tooltip="Карточка жителя"><?= $this->icon('eye', 'h-3.5 w-3.5') ?></a>
                  <?php if ($this->user()?->can('billing.payment.record')): ?>
                    <button class="btn btn-ghost btn-sm btn-icon text-success" type="button" data-dialog="#dialog-pay-<?= (int) $debtor['resident_id'] ?>" aria-label="Принять оплату" data-tooltip="Принять оплату"><?= $this->icon('coins', 'h-3.5 w-3.5') ?></button>
                  <?php endif ?>
                </div>
              </td>
            </tr>
          <?php endforeach ?>
          </tbody>
          <tfoot>
            <tr><td colspan="3" class="text-right font-medium">Итого</td><td class="table-numeric font-semibold tabular text-destructive"><?= $this->e($this->money($totalDebt)) ?></td><td colspan="4"></td></tr>
          </tfoot>
        </table>
      </div>
    <?php endif ?>
  </div>
</div>

<?php if ($this->user()?->can('billing.payment.record')): foreach ($debtors as $debtor): ?>
  <div class="dialog dialog-sm" id="dialog-pay-<?= (int) $debtor['resident_id'] ?>" data-state="closed" hidden role="dialog" aria-labelledby="dp-<?= (int) $debtor['resident_id'] ?>">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <form method="post" action="<?= $this->e($this->url('/admin/billing/payments')) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/billing/debtors')) ?>">
      <?= $this->csrf() ?>
      <input type="hidden" name="resident_id" value="<?= (int) $debtor['resident_id'] ?>">
      <div class="dialog-header">
        <h2 class="dialog-title" id="dp-<?= (int) $debtor['resident_id'] ?>">Принять оплату</h2>
        <p class="dialog-desc"><?= $this->e((string) ($debtor['name'] ?? '')) ?> · кв. <?= $this->e((string) ($debtor['apartment'] ?? '')) ?> · долг <?= $this->e($this->money((float) $debtor['debt'])) ?></p>
      </div>
      <div class="grid gap-3">
        <div class="field">
          <label class="label" for="dp-a-<?= (int) $debtor['resident_id'] ?>">Сумма</label>
          <div class="input-group">
            <input class="input input-mono" id="dp-a-<?= (int) $debtor['resident_id'] ?>" name="amount" type="text" inputmode="decimal" required
                   value="<?= number_format((float) $debtor['debt'], 2, '.', '') ?>">
            <span class="addon"><?= $this->e($symbol) ?></span>
          </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="dp-d-<?= (int) $debtor['resident_id'] ?>">Дата</label>
            <input class="input" id="dp-d-<?= (int) $debtor['resident_id'] ?>" name="paid_at" type="date" value="<?= $this->e(date('Y-m-d')) ?>">
          </div>
          <div class="field">
            <label class="label" for="dp-m-<?= (int) $debtor['resident_id'] ?>">Способ</label>
            <select class="select" id="dp-m-<?= (int) $debtor['resident_id'] ?>" name="method">
              <?php foreach (['transfer' => 'Перевод', 'cash' => 'Наличные', 'card' => 'Карта', 'sbp' => 'СБП', 'online' => 'Онлайн', 'other' => 'Другое'] as $value => $label): ?>
                <option value="<?= $value ?>"><?= $label ?></option>
              <?php endforeach ?>
            </select>
          </div>
        </div>
        <div class="field">
          <label class="label" for="dp-r-<?= (int) $debtor['resident_id'] ?>">№ платежа</label>
          <input class="input input-mono" id="dp-r-<?= (int) $debtor['resident_id'] ?>" name="reference" type="text" maxlength="120">
        </div>
        <p class="text-xs text-muted-foreground">Платёж зачтётся в старейший неоплаченный счёт; излишек останется на балансе как переплата.</p>
      </div>
      <div class="dialog-footer mt-4">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="submit" class="btn btn-default"><?= $this->icon('check') ?> Провести</button>
      </div>
    </form>
  </div>
<?php endforeach; endif ?>
