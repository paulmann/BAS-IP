<?php
/**
 * Invoice registry.
 *
 * @var BasIp\Support\Paginator $invoices
 * @var array $filters
 * @var array $query
 * @var array $totals
 * @var array<int, BasIp\Domain\Model\Charge> $charges
 * @var string $symbol
 * @var bool $canVoid
 * @var bool $canPay
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$items = $invoices->items();
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Счета</h2>
    <p class="page-desc">Все выставленные счета, включая дополнительные после перерасчёта и сторно.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/data/export/invoices', $query)) ?>"><?= $this->icon('download', 'h-3.5 w-3.5') ?> CSV</a>
    <a class="btn btn-default btn-sm" href="<?= $this->e($this->url('/admin/billing/wizard')) ?>"><?= $this->icon('calculator', 'h-3.5 w-3.5') ?> Новое начисление</a>
  </div>
</div>

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
  <?= $this->include('components/stat', ['label' => 'Счетов', 'value' => (string) (int) $totals['count'], 'icon' => 'receipt']) ?>
  <?= $this->include('components/stat', ['label' => 'Начислено', 'value' => $this->money($totals['amount']), 'icon' => 'wallet']) ?>
  <?= $this->include('components/stat', ['label' => 'Собрано', 'value' => $this->money($totals['paid']), 'icon' => 'check-circle', 'accent' => 'success']) ?>
  <?= $this->include('components/stat', ['label' => 'К сбору', 'value' => $this->money($totals['outstanding']), 'icon' => 'coins', 'accent' => $totals['outstanding'] > 0.005 ? 'warning' : '']) ?>
  <?= $this->include('components/stat', ['label' => 'Просрочено', 'value' => $this->money($totals['overdue']), 'icon' => 'alert-triangle', 'accent' => $totals['overdue'] > 0.005 ? 'destructive' : 'success', 'hint' => $totals['overdue_count'] . ' счетов']) ?>
</div>

<div class="card mt-5">
  <form class="filter-bar rounded-t-xl" method="get" action="<?= $this->e($this->url('/admin/billing/invoices')) ?>">
    <div class="filter-field min-w-[14rem] flex-[2]">
      <label class="label" for="q">Поиск</label>
      <input class="input" id="q" name="q" type="search" value="<?= $this->e((string) ($filters['q'] ?? '')) ?>" placeholder="Номер счёта, ФИО, квартира, период">
    </div>
    <div class="filter-field">
      <label class="label" for="status">Статус</label>
      <select class="select" id="status" name="status">
        <option value="">Любой</option>
        <?php foreach (['unpaid' => 'Не оплачен', 'pending' => 'Ожидает', 'partial' => 'Частично', 'overdue' => 'Просрочен', 'paid' => 'Оплачен', 'void' => 'Аннулирован'] as $value => $label): ?>
          <option value="<?= $value ?>" <?= $this->selected($filters['status'] ?? '', $value) ?>><?= $label ?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="filter-field">
      <label class="label" for="kind">Тип</label>
      <select class="select" id="kind" name="kind">
        <option value="">Любой</option>
        <?php foreach (['regular' => 'Основной', 'adjustment' => 'Доп. счёт', 'credit' => 'Сторно', 'manual' => 'Вручную'] as $value => $label): ?>
          <option value="<?= $value ?>" <?= $this->selected($filters['kind'] ?? '', $value) ?>><?= $label ?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="filter-field">
      <label class="label" for="charge_id">Начисление</label>
      <select class="select" id="charge_id" name="charge_id">
        <option value="">Все</option>
        <?php foreach ($charges as $charge): ?>
          <option value="<?= (int) $charge->id ?>" <?= (string) ($filters['charge_id'] ?? '') === (string) $charge->id ? 'selected' : '' ?>>
            <?= $this->e($charge->periodLabel ?: $charge->number) ?>
          </option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="filter-field">
      <label class="label" for="from">Выставлен с</label>
      <input class="input" id="from" name="from" type="date" value="<?= $this->e((string) ($filters['from'] ?? '')) ?>">
    </div>
    <div class="filter-field">
      <label class="label" for="to">по</label>
      <input class="input" id="to" name="to" type="date" value="<?= $this->e((string) ($filters['to'] ?? '')) ?>">
    </div>
    <div class="filter-field">
      <span class="label">Флаги</span>
      <div class="flex flex-wrap gap-1.5">
        <label class="chip <?= !empty($filters['only_active']) ? 'is-active' : '' ?>"><input type="checkbox" class="sr-only" name="only_active" value="1" <?= !empty($filters['only_active']) ? 'checked' : '' ?>> только активные</label>
        <label class="chip <?= !empty($filters['overdue']) ? 'is-active' : '' ?>"><input type="checkbox" class="sr-only" name="overdue" value="1" <?= !empty($filters['overdue']) ? 'checked' : '' ?>> просроченные</label>
      </div>
    </div>
    <div class="flex items-center gap-2">
      <button class="btn btn-default btn-sm" type="submit"><?= $this->icon('filter', 'h-3.5 w-3.5') ?> Применить</button>
      <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/billing/invoices')) ?>"><?= $this->icon('rotate-ccw', 'h-3.5 w-3.5') ?> Сброс</a>
    </div>
  </form>

  <div class="card-content p-0">
    <?php if ($items === []): ?>
      <?= $this->include('components/empty', ['title' => 'Счетов не найдено', 'description' => 'Измените фильтры или создайте начисление.', 'icon' => 'receipt']) ?>
    <?php else: ?>
      <div class="table-wrap rounded-none border-0" data-table>
        <table class="table">
          <thead>
            <tr><th>Счёт</th><th>Житель</th><th>Период</th><th>Тип</th><th class="text-right">Начислено</th>
                <th class="text-right">Оплачено</th><th class="text-right">Остаток</th><th>Срок</th><th>Статус</th><th class="text-right">Действия</th></tr>
          </thead>
          <tbody>
          <?php foreach ($items as $invoice): ?>
            <tr class="<?= $invoice->isVoid() ? 'is-inactive' : '' ?>">
              <td>
                <div class="font-mono text-xs"><?= $this->e($invoice->number) ?></div>
                <?php if ($invoice->chargeNumber !== null): ?><div class="text-[11px] text-muted-foreground"><?= $this->e($invoice->chargeNumber) ?></div><?php endif ?>
              </td>
              <td>
                <a class="text-sm hover:underline" href="<?= $this->e($this->url('/admin/residents/' . (int) $invoice->residentId)) ?>"><?= $this->e($invoice->residentName ?? '—') ?></a>
                <div class="text-xs text-muted-foreground">кв. <?= $this->e($invoice->apartment ?? '—') ?></div>
              </td>
              <td class="whitespace-nowrap text-xs"><?= $this->e($invoice->periodLabel ?: '—') ?></td>
              <td><?php if ($invoice->kind !== 'regular'): ?><span class="badge badge-soft-<?= $invoice->kind === 'credit' ? 'warning' : 'info' ?>" data-tooltip="<?= $this->e((string) ($invoice->recalcReason ?? '')) ?>"><?= $this->e($invoice->kindLabel()) ?></span><?php else: ?><span class="text-xs text-muted-foreground">основной</span><?php endif ?></td>
              <td class="table-numeric tabular"><?= $this->e($this->money($invoice->amount)) ?></td>
              <td class="table-numeric tabular text-muted-foreground"><?= $this->e($this->money($invoice->paidAmount)) ?></td>
              <td class="table-numeric font-semibold tabular <?= $invoice->balance > 0.005 ? '' : 'text-muted-foreground' ?>"><?= $this->e($this->money($invoice->balance)) ?></td>
              <td class="whitespace-nowrap text-xs tabular <?= $invoice->isOverdue() ? 'font-semibold text-destructive' : 'text-muted-foreground' ?>">
                <?= $this->e($this->date($invoice->dueDate)) ?>
                <?php if ($invoice->isOverdue()): ?><div class="text-[11px]"><?= $invoice->daysOverdue() ?> дн.</div><?php endif ?>
              </td>
              <td><span class="badge badge-soft-<?= $invoice->statusTone() === 'muted' ? 'warning' : $invoice->statusTone() ?>"><?= $this->e($invoice->statusLabel()) ?></span></td>
              <td class="text-right">
                <div class="flex justify-end gap-1">
                  <?php if ($canPay && $invoice->isPending()): ?>
                    <button class="btn btn-ghost btn-sm btn-icon" type="button" data-dialog="#dialog-pay-<?= (int) $invoice->id ?>" data-tooltip="Принять оплату" aria-label="Принять оплату"><?= $this->icon('coins', 'h-3.5 w-3.5') ?></button>
                  <?php endif ?>
                  <?php if ($canVoid && $invoice->isPending() && $invoice->paidAmount < 0.005): ?>
                    <form method="post" action="<?= $this->e($this->url('/admin/billing/invoices/' . $invoice->id . '/void')) ?>" class="inline">
                      <?= $this->csrf() ?>
                      <input type="hidden" name="reason" value="Аннулировано из реестра счетов">
                      <input type="hidden" name="redirect" value="<?= $this->e($this->url('/admin/billing/invoices', $query)) ?>">
                      <button class="btn btn-ghost btn-sm btn-icon text-destructive" type="submit" aria-label="Аннулировать"
                              data-confirm="Аннулировать счёт <?= $this->e($invoice->number) ?>?" data-confirm-label="Аннулировать" data-confirm-tone="destructive">
                        <?= $this->icon('ban', 'h-3.5 w-3.5') ?>
                      </button>
                    </form>
                  <?php endif ?>
                </div>
              </td>
            </tr>
          <?php endforeach ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="4" class="text-right font-medium">На странице</td>
              <td class="table-numeric font-semibold tabular"><?= $this->e($this->money(array_sum(array_map(static fn ($i) => $i->amount, $items)))) ?></td>
              <td class="table-numeric font-semibold tabular text-success"><?= $this->e($this->money(array_sum(array_map(static fn ($i) => $i->paidAmount, $items)))) ?></td>
              <td class="table-numeric font-semibold tabular"><?= $this->e($this->money(array_sum(array_map(static fn ($i) => $i->balance, $items)))) ?></td>
              <td colspan="3"></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <div class="p-4"><?= $this->include('components/pagination', ['paginator' => $invoices, 'query' => $query, 'target' => '/admin/billing/invoices']) ?></div>
    <?php endif ?>
  </div>
</div>

<?php if ($canPay): foreach ($items as $invoice): if (!$invoice->isPending()) { continue; } ?>
  <div class="dialog dialog-sm" id="dialog-pay-<?= (int) $invoice->id ?>" data-state="closed" hidden role="dialog" aria-labelledby="pt-<?= (int) $invoice->id ?>">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <form method="post" action="<?= $this->e($this->url('/admin/billing/payments')) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/billing/invoices', $query)) ?>">
      <?= $this->csrf() ?>
      <input type="hidden" name="resident_id" value="<?= (int) $invoice->residentId ?>">
      <input type="hidden" name="invoice_id" value="<?= (int) $invoice->id ?>">
      <div class="dialog-header">
        <h2 class="dialog-title" id="pt-<?= (int) $invoice->id ?>">Оплата по счёту <?= $this->e($invoice->number) ?></h2>
        <p class="dialog-desc"><?= $this->e($invoice->residentName ?? '') ?> · кв. <?= $this->e($invoice->apartment ?? '') ?> · остаток <?= $this->e($this->money($invoice->balance)) ?></p>
      </div>
      <div class="grid gap-3">
        <div class="field">
          <label class="label" for="a-<?= (int) $invoice->id ?>">Сумма</label>
          <div class="input-group">
            <input class="input input-mono" id="a-<?= (int) $invoice->id ?>" name="amount" type="text" inputmode="decimal" required value="<?= number_format($invoice->balance, 2, '.', '') ?>">
            <span class="addon"><?= $this->e($symbol) ?></span>
          </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="d-<?= (int) $invoice->id ?>">Дата</label>
            <input class="input" id="d-<?= (int) $invoice->id ?>" name="paid_at" type="date" value="<?= $this->e(date('Y-m-d')) ?>">
          </div>
          <div class="field">
            <label class="label" for="m-<?= (int) $invoice->id ?>">Способ</label>
            <select class="select" id="m-<?= (int) $invoice->id ?>" name="method">
              <?php foreach (['transfer' => 'Перевод', 'cash' => 'Наличные', 'card' => 'Карта', 'sbp' => 'СБП', 'online' => 'Онлайн', 'other' => 'Другое'] as $value => $label): ?>
                <option value="<?= $value ?>"><?= $label ?></option>
              <?php endforeach ?>
            </select>
          </div>
        </div>
        <div class="field">
          <label class="label" for="r-<?= (int) $invoice->id ?>">№ платежа</label>
          <input class="input input-mono" id="r-<?= (int) $invoice->id ?>" name="reference" type="text" maxlength="120">
        </div>
      </div>
      <div class="dialog-footer mt-4">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="submit" class="btn btn-default"><?= $this->icon('check') ?> Провести</button>
      </div>
    </form>
  </div>
<?php endforeach; endif ?>
