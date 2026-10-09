<?php
/**
 * Resident: invoices, payments and balance history.
 *
 * @var BasIp\Domain\Model\Resident|null $resident
 * @var BasIp\Support\Paginator|null $invoices
 * @var array<int, BasIp\Domain\Model\Payment> $payments
 * @var array|null $statement
 * @var array $history
 * @var string $payLink
 * @var string $currency
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$debt = (float) ($statement['debt'] ?? 0);
$overpay = (float) ($statement['overpay'] ?? 0);
$items = $invoices !== null ? $invoices->items() : [];
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div>
    <h2 class="page-title">Мои оплаты</h2>
    <p class="page-desc">Начисления за обслуживание шлагбаума и история платежей.</p>
  </div>
  <?php if ($debt > 0.005 && $payLink !== ''): ?>
    <div class="page-actions">
      <a class="btn btn-success" target="_blank" rel="noopener noreferrer"
         href="<?= $this->e(str_replace(['{apartment}', '{amount}'], [rawurlencode((string) ($resident?->apartment ?? '')), number_format($debt, 2, '.', '')], $payLink)) ?>">
        <?= $this->icon('credit-card') ?> Оплатить <?= $this->e($this->money($debt)) ?>
      </a>
    </div>
  <?php endif ?>
</div>

<div class="grid gap-3 sm:grid-cols-3">
  <?= $this->include('components/stat', ['label' => 'Задолженность', 'value' => $this->money($debt), 'icon' => 'alert-triangle', 'accent' => $debt > 0.005 ? 'destructive' : 'success', 'hint' => $debt > 0.005 ? 'Просим оплатить' : 'Долгов нет']) ?>
  <?= $this->include('components/stat', ['label' => 'Переплата', 'value' => $this->money($overpay), 'icon' => 'piggy-bank', 'accent' => $overpay > 0.005 ? 'success' : '', 'hint' => 'Учтётся в следующем периоде']) ?>
  <?= $this->include('components/stat', ['label' => 'Открытых счетов', 'value' => (string) (int) ($statement['invoices'] ?? 0), 'icon' => 'receipt', 'hint' => !empty($statement['oldest_due']) ? 'ближайший срок ' . $this->date((string) $statement['oldest_due']) : '—']) ?>
</div>

<div class="grid gap-5 mt-5 lg:grid-cols-2">
  <section class="card">
    <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('receipt', 'h-4 w-4') ?> Счета</div></div>
    <div class="card-content p-0">
      <?php if ($items === []): ?>
        <?= $this->include('components/empty', ['title' => 'Счетов нет', 'icon' => 'receipt']) ?>
      <?php else: ?>
        <div class="table-wrap rounded-none border-0">
          <table class="table">
            <thead><tr><th>Счёт / период</th><th class="text-right">Начислено</th><th class="text-right">Оплачено</th><th>Срок</th><th>Статус</th></tr></thead>
            <tbody>
            <?php foreach ($items as $invoice): ?>
              <tr class="<?= $invoice->isVoid() ? 'is-inactive' : '' ?>">
                <td>
                  <div class="font-mono text-xs"><?= $this->e($invoice->number) ?></div>
                  <div class="text-xs text-muted-foreground"><?= $this->e($invoice->periodLabel ?: '—') ?><?= $invoice->isAdjustment() ? ' · доп. счёт' : ($invoice->isCredit() ? ' · сторно' : '') ?></div>
                </td>
                <td class="table-numeric tabular"><?= $this->e($this->money($invoice->amount)) ?></td>
                <td class="table-numeric tabular"><?= $this->e($this->money($invoice->paidAmount)) ?></td>
                <td class="whitespace-nowrap text-xs tabular <?= $invoice->isOverdue() ? 'font-semibold text-destructive' : 'text-muted-foreground' ?>">
                  <?= $this->e($this->date($invoice->dueDate)) ?>
                  <?php if ($invoice->isOverdue()): ?><div class="text-[11px]"><?= $invoice->daysOverdue() ?> дн. просрочки</div><?php endif ?>
                </td>
                <td><span class="badge badge-soft-<?= $invoice->statusTone() === 'muted' ? 'warning' : $invoice->statusTone() ?>"><?= $this->e($invoice->statusLabel()) ?></span></td>
              </tr>
            <?php endforeach ?>
            </tbody>
          </table>
        </div>
        <?php if ($invoices !== null && $invoices->hasPages()): ?>
          <div class="p-4"><?= $this->include('components/pagination', ['paginator' => $invoices, 'query' => $this->shared['query'] ?? [], 'target' => '/cabinet/payments']) ?></div>
        <?php endif ?>
      <?php endif ?>
    </div>
  </section>

  <section class="card">
    <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('banknote', 'h-4 w-4') ?> История оплат</div></div>
    <div class="card-content p-0">
      <?php if ($payments === []): ?>
        <?= $this->include('components/empty', ['title' => 'Оплат пока не было', 'icon' => 'banknote']) ?>
      <?php else: ?>
        <ul class="divide-y">
          <?php foreach ($payments as $payment): ?>
            <li class="flex items-start gap-3 px-5 py-3">
              <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-success/12 text-success">
                <?= $this->icon('check', 'h-4 w-4') ?>
              </span>
              <div class="min-w-0 flex-1">
                <div class="flex items-baseline justify-between gap-2">
                  <span class="font-semibold tabular"><?= $this->e($this->money($payment->amount)) ?></span>
                  <span class="shrink-0 text-xs tabular text-muted-foreground"><?= $this->e($payment->dateLabel()) ?></span>
                </div>
                <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                  <span><?= $this->e($payment->methodLabel()) ?></span>
                  <?php if ($payment->reference !== null): ?><span>·</span><span class="font-mono"><?= $this->e($payment->reference) ?></span><?php endif ?>
                  <?php if ($payment->invoiceNumber !== null): ?><span>·</span><span>счёт <?= $this->e($payment->invoiceNumber) ?></span><?php endif ?>
                </div>
              </div>
            </li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
    </div>
  </section>
</div>

<?php if ($history !== []): ?>
  <section class="card mt-5">
    <div class="card-header">
      <div class="card-title flex items-center gap-2"><?= $this->icon('scroll-text', 'h-4 w-4') ?> Движения по счёту</div>
      <div class="card-desc">Каждое начисление, перерасчёт и оплата — с датой и суммой.</div>
    </div>
    <div class="card-content p-0">
      <div class="table-wrap rounded-none border-0">
        <table class="table">
          <thead><tr><th>Дата</th><th>Событие</th><th class="text-right">Сумма</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($history, 0, 40) as $entry): ?>
            <tr>
              <td class="whitespace-nowrap text-xs tabular text-muted-foreground"><?= $this->e($this->datetime($entry['occurred_at'] ?? null)) ?></td>
              <td class="text-sm"><?= $this->e($this->billingActionLabel((string) ($entry['action'] ?? ''))) ?></td>
              <td class="table-numeric tabular font-medium <?= (float) $entry['amount'] < 0 ? 'text-success' : '' ?>"><?= $this->e($this->money((float) $entry['amount'])) ?></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
<?php endif ?>

<div class="alert alert-info mt-5">
  <?= $this->icon('info', 'h-4 w-4') ?>
  <div>
    <div class="alert-title">Как считается сумма</div>
    <div class="alert-desc">
      Общая стоимость обслуживания шлагбаума за период делится на число <b>активных</b> жителей.
      Отключённые записи в расчёте не участвуют. Если число участников или сумма изменились, управляющий делает перерасчёт —
      вам придёт дополнительный счёт или сторно, а уже выставленные счета не меняются.
    </div>
  </div>
</div>
