<?php
/**
 * Payment registry (all recorded payments).
 *
 * @var BasIp\Support\Paginator $payments
 * @var array $filters
 * @var array $query
 * @var float $pageSum
 * @var float $periodSum
 * @var array $methods
 * @var string $symbol
 * @var bool $canDelete
 * @var bool $canRecord
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$items = $payments->items();
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Реестр оплат</h2>
    <p class="page-desc">Все проведённые платежи. За выбранный период: <b class="text-foreground"><?= $this->e($this->money($periodSum)) ?></b>.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/data/export/payments', $query)) ?>"><?= $this->icon('download', 'h-3.5 w-3.5') ?> CSV</a>
    <?php if ($canRecord): ?>
      <button class="btn btn-default btn-sm" type="button" data-dialog="#dialog-payment-new"><?= $this->icon('plus', 'h-3.5 w-3.5') ?> Провести оплату</button>
    <?php endif ?>
  </div>
</div>

<div class="card">
  <form class="filter-bar rounded-t-xl" method="get" action="<?= $this->e($this->url('/admin/billing/payments')) ?>">
    <div class="filter-field min-w-[14rem] flex-[2]">
      <label class="label" for="q">Поиск</label>
      <input class="input" id="q" name="q" type="search" value="<?= $this->e((string) ($filters['q'] ?? '')) ?>" placeholder="ФИО, квартира, № платежа, примечание">
    </div>
    <div class="filter-field">
      <label class="label" for="method">Способ</label>
      <select class="select" id="method" name="method">
        <option value="">Любой</option>
        <?php foreach ($methods as $value => $label): ?>
          <option value="<?= $value ?>" <?= $this->selected($filters['method'] ?? '', $value) ?>><?= $label ?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="filter-field">
      <label class="label" for="from">Период с</label>
      <input class="input" id="from" name="from" type="date" value="<?= $this->e((string) ($filters['from'] ?? '')) ?>" data-date-from>
    </div>
    <div class="filter-field">
      <label class="label" for="to">по</label>
      <input class="input" id="to" name="to" type="date" value="<?= $this->e((string) ($filters['to'] ?? '')) ?>" data-date-to>
    </div>
    <div class="filter-field">
      <span class="label">Быстрый выбор</span>
      <div class="flex flex-wrap gap-1.5">
        <?php foreach ([['today', 'Сегодня'], ['week', 'Неделя'], ['month', 'Месяц'], ['quarter', 'Квартал'], ['year', 'Год']] as [$preset, $label]): ?>
          <button type="button" class="chip" data-date-preset="<?= $preset ?>"><?= $label ?></button>
        <?php endforeach ?>
      </div>
    </div>
    <div class="flex items-center gap-2">
      <button class="btn btn-default btn-sm" type="submit"><?= $this->icon('filter', 'h-3.5 w-3.5') ?> Применить</button>
      <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/billing/payments')) ?>"><?= $this->icon('rotate-ccw', 'h-3.5 w-3.5') ?> Сброс</a>
    </div>
  </form>

  <div class="card-content p-0">
    <?php if ($items === []): ?>
      <?= $this->include('components/empty', ['title' => 'Оплат не найдено', 'description' => 'Измените фильтры или проведите первый платёж.', 'icon' => 'banknote']) ?>
    <?php else: ?>
      <div class="table-wrap rounded-none border-0">
        <table class="table">
          <thead><tr><th>Дата</th><th>Житель</th><th class="text-right">Сумма</th><th>Способ</th><th>Счёт</th><th>Период</th><th>№ платежа</th><th>Примечание</th><?php if ($canDelete): ?><th></th><?php endif ?></tr></thead>
          <tbody>
          <?php foreach ($items as $payment): ?>
            <tr>
              <td class="whitespace-nowrap text-xs tabular"><?= $this->e($payment->dateLabel()) ?></td>
              <td>
                <a class="text-sm hover:underline" href="<?= $this->e($this->url('/admin/residents/' . (int) $payment->residentId)) ?>"><?= $this->e($payment->residentName ?? '—') ?></a>
                <div class="text-xs text-muted-foreground">кв. <?= $this->e($payment->apartment ?? '—') ?></div>
              </td>
              <td class="table-numeric font-semibold tabular text-success"><?= $this->e($this->money($payment->amount)) ?></td>
              <td><span class="badge badge-outline"><?= $this->e($payment->methodLabel()) ?></span></td>
              <td class="font-mono text-xs"><?= $this->e($payment->invoiceNumber ?? '—') ?></td>
              <td class="whitespace-nowrap text-xs text-muted-foreground"><?= $this->e($payment->periodLabel ?? '—') ?></td>
              <td class="font-mono text-xs"><?= $this->e($payment->reference ?? '—') ?></td>
              <td class="max-w-[16rem] truncate text-xs text-muted-foreground"><?= $this->e($payment->note ?? '') ?></td>
              <?php if ($canDelete): ?>
                <td class="text-right">
                  <form method="post" action="<?= $this->e($this->url('/admin/billing/payments/' . $payment->id . '/delete')) ?>" class="inline">
                    <?= $this->csrf() ?>
                    <input type="hidden" name="redirect" value="<?= $this->e($this->url('/admin/billing/payments', $query)) ?>">
                    <button class="btn btn-ghost btn-sm btn-icon text-destructive" type="submit" aria-label="Удалить оплату"
                            data-confirm="Удалить оплату <?= $this->e($this->money($payment->amount)) ?>? Счета будут пересчитаны." data-confirm-label="Удалить" data-confirm-tone="destructive">
                      <?= $this->icon('trash', 'h-3.5 w-3.5') ?>
                    </button>
                  </form>
                </td>
              <?php endif ?>
            </tr>
          <?php endforeach ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="2" class="text-right font-medium">Итого на странице</td>
              <td class="table-numeric font-semibold tabular"><?= $this->e($this->money($pageSum)) ?></td>
              <td colspan="<?= $canDelete ? 6 : 5 ?>"></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <div class="p-4"><?= $this->include('components/pagination', ['paginator' => $payments, 'query' => $query, 'target' => '/admin/billing/payments']) ?></div>
    <?php endif ?>
  </div>
</div>

<?php if ($canRecord): ?>
  <div class="dialog" id="dialog-payment-new" data-state="closed" hidden role="dialog" aria-labelledby="pay-new-title">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <form method="post" action="<?= $this->e($this->url('/admin/billing/payments')) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/billing/payments')) ?>" data-payment-form>
      <?= $this->csrf() ?>
      <div class="dialog-header">
        <h2 class="dialog-title" id="pay-new-title">Провести оплату</h2>
        <p class="dialog-desc">Платёж зачтётся в старейший неоплаченный счёт, если не выбрать конкретный.</p>
      </div>
      <div class="grid gap-3">
        <div class="field">
          <label class="label" for="np-resident">Житель <span class="text-destructive">*</span></label>
          <select class="select" id="np-resident" name="resident_id" required data-resident-picker></select>
          <p class="text-xs text-muted-foreground">Начните вводить фамилию или номер квартиры.</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="np-amount">Сумма <span class="text-destructive">*</span></label>
            <div class="input-group">
              <input class="input input-mono" id="np-amount" name="amount" type="text" inputmode="decimal" required placeholder="0.00">
              <span class="addon"><?= $this->e($symbol) ?></span>
            </div>
          </div>
          <div class="field">
            <label class="label" for="np-date">Дата оплаты</label>
            <input class="input" id="np-date" name="paid_at" type="date" value="<?= $this->e(date('Y-m-d')) ?>">
          </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="np-method">Способ</label>
            <select class="select" id="np-method" name="method">
              <?php foreach ($methods as $value => $label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="np-ref">№ платёжного документа</label>
            <input class="input input-mono" id="np-ref" name="reference" type="text" maxlength="120">
          </div>
        </div>
        <div class="field">
          <label class="label" for="np-note">Примечание</label>
          <input class="input" id="np-note" name="note" type="text" maxlength="2000">
        </div>
      </div>
      <div class="dialog-footer mt-4">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="submit" class="btn btn-default"><?= $this->icon('check') ?> Провести</button>
      </div>
    </form>
  </div>
<?php endif ?>

<?php $this->startPush('scripts') ?>
<script nonce="<?= $this->e($this->nonce()) ?>">
(function () {
  var picker = document.querySelector('#dialog-payment-new [data-resident-picker]');
  if (!picker || !window.BasIp) return;
  window.BasIp.get(<?= $this->json($this->url('/api/v1/residents?per_page=500')) ?>).then(function (data) {
    (data.data || []).forEach(function (r) {
      var opt = document.createElement('option');
      opt.value = r.id;
      opt.textContent = 'кв. ' + (r.apartment || '—') + ' · ' + r.full_name;
      picker.appendChild(opt);
    });
  }).catch(function () {});
})();
</script>
<?php $this->endPush() ?>
