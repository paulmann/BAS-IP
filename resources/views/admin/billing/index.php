<?php
/**
 * Billing overview: charges list, totals, collection dynamics, debtors.
 *
 * @var BasIp\Support\Paginator $charges
 * @var array $totals
 * @var array $overview
 * @var array $payments
 * @var array $collection
 * @var array $debtors
 * @var array $recent
 * @var string $symbol
 * @var float|null $lastTotal
 * @var int $participants
 * @var bool $canCharge
 * @var bool $canNotify
 * @var array $query
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$items = $charges->items();
$collectionRate = $totals['amount'] > 0 ? round(min(100, ($totals['paid'] / $totals['amount']) * 100), 1) : 0.0;
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Оплаты и начисления</h2>
    <p class="page-desc">Общая смета делится между <b class="text-foreground"><?= (int) $participants ?></b> активными жителями. Отключённые в расчёте не участвуют.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/billing/payments')) ?>"><?= $this->icon('banknote', 'h-3.5 w-3.5') ?> Реестр оплат</a>
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/billing/debtors')) ?>"><?= $this->icon('alert-triangle', 'h-3.5 w-3.5') ?> Должники</a>
    <?php if ($canCharge): ?>
      <a class="btn btn-default btn-sm" href="<?= $this->e($this->url('/admin/billing/wizard')) ?>">
        <?= $this->icon('calculator', 'h-3.5 w-3.5') ?> Начислить оплату
      </a>
    <?php endif ?>
  </div>
</div>

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
  <?= $this->include('components/stat', ['label' => 'Начислено всего', 'value' => $this->money($totals['amount']), 'icon' => 'receipt', 'hint' => $totals['count'] . ' счетов']) ?>
  <?= $this->include('components/stat', ['label' => 'Собрано', 'value' => $this->money($totals['paid']), 'icon' => 'check-circle', 'accent' => 'success', 'hint' => $collectionRate . '% от начисленного']) ?>
  <?= $this->include('components/stat', ['label' => 'К сбору', 'value' => $this->money($totals['outstanding']), 'icon' => 'coins', 'accent' => $totals['outstanding'] > 0.005 ? 'warning' : '']) ?>
  <?= $this->include('components/stat', ['label' => 'Просрочено', 'value' => $this->money($totals['overdue']), 'icon' => 'alert-triangle', 'accent' => $totals['overdue'] > 0.005 ? 'destructive' : 'success', 'hint' => $totals['overdue_count'] . ' счетов', 'href' => '/admin/billing/debtors']) ?>
</div>

<div class="grid gap-5 mt-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
  <div class="grid content-start gap-5">

    <section class="card">
      <div class="card-header has-action">
        <div>
          <div class="card-title flex items-center gap-2"><?= $this->icon('calculator', 'h-4 w-4') ?> Начисления</div>
          <div class="card-desc">Каждая строка — смета периода со снимком суммы и числа участников.</div>
        </div>
        <form method="get" class="flex items-center gap-2">
          <input class="input input-sm w-44" type="search" name="q" value="<?= $this->e((string) ($query['q'] ?? '')) ?>" placeholder="Поиск по номеру">
          <select class="select select-sm w-auto" name="status" data-auto-submit>
            <option value="">Все статусы</option>
            <?php foreach (['draft' => 'Черновик', 'issued' => 'Выставлено', 'closed' => 'Закрыто', 'cancelled' => 'Отменено'] as $value => $label): ?>
              <option value="<?= $value ?>" <?= ($query['status'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach ?>
          </select>
          <button class="btn btn-outline btn-sm" type="submit"><?= $this->icon('search', 'h-3.5 w-3.5') ?></button>
        </form>
      </div>
      <div class="card-content p-0">
        <?php if ($items === []): ?>
          <?= $this->include('components/empty', [
            'title' => 'Начислений пока нет',
            'description' => 'Создайте первое: введите общую сумму на обслуживание шлагбаума — система разделит её между активными жителями и выставит счета.',
            'icon' => 'calculator',
            'actionLabel' => $canCharge ? 'Создать начисление' : null,
            'actionUrl' => $canCharge ? '/admin/billing/wizard' : null,
          ]) ?>
        <?php else: ?>
          <div class="table-wrap rounded-none border-0">
            <table class="table">
              <thead>
                <tr><th>Номер / период</th><th class="text-right">Сумма</th><th class="text-right">Участн.</th><th class="text-right">На 1</th>
                    <th class="w-40">Сбор</th><th class="text-right">Осталось</th><th>Срок</th><th>Статус</th><th></th></tr>
              </thead>
              <tbody>
              <?php foreach ($items as $charge): ?>
                <tr>
                  <td>
                    <a class="font-medium hover:underline" href="<?= $this->e($this->url('/admin/billing/charges/' . $charge->id)) ?>"><?= $this->e($charge->periodLabel ?: $charge->number) ?></a>
                    <div class="font-mono text-xs text-muted-foreground"><?= $this->e($charge->number) ?><?= $charge->isRecalc() ? ' · перерасчёт' : '' ?></div>
                  </td>
                  <td class="table-numeric font-semibold tabular"><?= $this->e($this->money($charge->totalAmount)) ?></td>
                  <td class="table-numeric tabular"><?= (int) $charge->activeCount ?></td>
                  <td class="table-numeric tabular"><?= $this->e($this->money($charge->perUnitAmount)) ?></td>
                  <td>
                    <div class="flex items-center gap-2">
                      <div class="progress progress-sm flex-1">
                        <div class="progress-bar <?= $charge->collectedPercent() >= 90 ? 'bg-success' : ($charge->collectedPercent() >= 50 ? 'bg-warning' : 'bg-destructive') ?>" style="width: <?= $charge->collectedPercent() ?>%"></div>
                      </div>
                      <span class="w-9 shrink-0 text-right text-xs tabular"><?= $charge->collectedPercent() ?>%</span>
                    </div>
                    <div class="mt-0.5 text-[11px] text-muted-foreground"><?= (int) $charge->paidCount ?>/<?= (int) $charge->invoicesTotal ?> счетов</div>
                  </td>
                  <td class="table-numeric tabular <?= $charge->outstanding() > 0.005 ? 'text-destructive' : 'text-muted-foreground' ?>"><?= $this->e($this->money($charge->outstanding())) ?></td>
                  <td class="whitespace-nowrap text-xs tabular <?= $charge->dueDate !== null && strtotime((string) $charge->dueDate) < time() && !$charge->isClosed() ? 'text-destructive' : 'text-muted-foreground' ?>">
                    <?= $this->e($this->date($charge->dueDate)) ?>
                  </td>
                  <td><span class="badge badge-soft-<?= $charge->statusTone() === 'muted' ? 'warning' : $charge->statusTone() ?>"><?= $this->e($charge->statusLabel()) ?></span></td>
                  <td class="text-right">
                    <a class="btn btn-ghost btn-sm btn-icon" href="<?= $this->e($this->url('/admin/billing/charges/' . $charge->id)) ?>" aria-label="Открыть"><?= $this->icon('chevron-right', 'h-4 w-4') ?></a>
                  </td>
                </tr>
              <?php endforeach ?>
              </tbody>
            </table>
          </div>
          <div class="p-4"><?= $this->include('components/pagination', ['paginator' => $charges, 'query' => $query, 'target' => '/admin/billing']) ?></div>
        <?php endif ?>
      </div>
    </section>

    <section class="card">
      <div class="card-header has-action">
        <div>
          <div class="card-title flex items-center gap-2"><?= $this->icon('trending-up', 'h-4 w-4') ?> Начислено и собрано по месяцам</div>
          <div class="card-desc">Процент сбора показывает дисциплину оплат.</div>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/billing/payments')) ?>">Реестр</a>
      </div>
      <div class="card-content">
        <?= $this->include('components/chart', [
          'type' => 'line', 'height' => 130, 'money' => true, 'ariaLabel' => 'Сборы по месяцам',
          'data' => ['labels' => array_column($collection, 'label'), 'values' => array_column($collection, 'paid')],
        ]) ?>
        <div class="mt-3 table-wrap">
          <table class="table">
            <thead><tr><th>Месяц</th><th class="text-right">Начислено</th><th class="text-right">Собрано</th><th class="w-40">Сбор</th><th class="text-right">Платежей</th></tr></thead>
            <tbody>
            <?php foreach (array_slice(array_reverse($collection), 0, 8) as $month): ?>
              <tr>
                <td class="text-sm"><?= $this->e($month['label']) ?></td>
                <td class="table-numeric tabular"><?= $this->e($this->money((float) ($month['billed'] ?? 0))) ?></td>
                <td class="table-numeric font-semibold tabular text-success"><?= $this->e($this->money((float) ($month['paid'] ?? 0))) ?></td>
                <td>
                  <div class="flex items-center gap-2">
                    <div class="progress progress-sm flex-1"><div class="progress-bar <?= ($month['rate'] ?? 0) >= 90 ? 'bg-success' : (($month['rate'] ?? 0) >= 60 ? 'bg-warning' : 'bg-destructive') ?>" style="width: <?= (float) ($month['rate'] ?? 0) ?>%"></div></div>
                    <span class="w-10 text-right text-xs tabular"><?= (float) ($month['rate'] ?? 0) ?>%</span>
                  </div>
                </td>
                <td class="table-numeric tabular"><?= (int) ($month['count'] ?? 0) ?></td>
              </tr>
            <?php endforeach ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>

  <aside class="grid content-start gap-5">
    <section class="card">
      <div class="card-header has-action">
        <div class="card-title flex items-center gap-2"><?= $this->icon('alert-triangle', 'h-4 w-4 text-warning') ?> Должники</div>
        <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/billing/debtors')) ?>">Все</a>
      </div>
      <div class="card-content grid gap-3">
        <?php if ($debtors === []): ?>
          <div class="flex items-center gap-2 rounded-lg border border-success/30 bg-success/8 p-3 text-sm">
            <?= $this->icon('check-circle', 'h-4 w-4 text-success') ?> Задолженностей нет.
          </div>
        <?php else: ?>
          <ul class="grid gap-1.5">
            <?php foreach (array_slice($debtors, 0, 8) as $debtor): ?>
              <li class="flex items-center gap-2 text-sm">
                <a class="min-w-0 flex-1 truncate hover:underline" href="<?= $this->e($this->url('/admin/residents/' . (int) $debtor['resident_id'])) ?>">
                  кв. <?= $this->e((string) $debtor['apartment']) ?> · <?= $this->e(\BasIp\Support\Str::initials((string) ($debtor['name'] ?? ''), 1)) ?>
                </a>
                <?php if ((int) $debtor['days_overdue'] > 30): ?><span class="badge badge-soft-destructive shrink-0"><?= (int) $debtor['days_overdue'] ?> дн.</span><?php endif ?>
                <span class="shrink-0 font-semibold tabular text-destructive"><?= $this->e($this->money((float) $debtor['debt'])) ?></span>
              </li>
            <?php endforeach ?>
          </ul>
          <?php if ($canNotify): ?>
            <form method="post" action="<?= $this->e($this->url('/admin/billing/remind')) ?>" data-ajax>
              <?= $this->csrf() ?>
              <button class="btn btn-outline btn-block btn-sm" type="submit"
                      data-confirm="Отправить напоминания всем активным должникам?" data-confirm-label="Отправить">
                <?= $this->icon('send', 'h-3.5 w-3.5') ?> Напомнить должникам
              </button>
            </form>
          <?php endif ?>
        <?php endif ?>
      </div>
    </section>

    <section class="card">
      <div class="card-header has-action">
        <div class="card-title flex items-center gap-2"><?= $this->icon('banknote', 'h-4 w-4') ?> Последние оплаты</div>
        <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/billing/payments')) ?>">Все</a>
      </div>
      <div class="card-content p-0">
        <?php if ($recent === []): ?>
          <?= $this->include('components/empty', ['title' => 'Оплат пока нет', 'icon' => 'banknote']) ?>
        <?php else: ?>
          <ul class="divide-y">
            <?php foreach (array_slice($recent, 0, 8) as $payment): ?>
              <li class="flex items-center gap-3 px-5 py-2.5">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-success/12 text-success"><?= $this->icon('check', 'h-3.5 w-3.5') ?></span>
                <div class="min-w-0 flex-1">
                  <div class="truncate text-sm"><?= $this->e($payment->residentName ?? '—') ?></div>
                  <div class="text-xs text-muted-foreground"><?= $this->e($payment->dateLabel()) ?> · <?= $this->e($payment->methodLabel()) ?></div>
                </div>
                <span class="shrink-0 font-semibold tabular text-success"><?= $this->e($this->money($payment->amount)) ?></span>
              </li>
            <?php endforeach ?>
          </ul>
        <?php endif ?>
      </div>
    </section>

    <?php if ($canCharge): ?>
      <section class="card">
        <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('zap', 'h-4 w-4') ?> Быстрое начисление</div></div>
        <div class="card-content grid gap-3">
          <form method="post" action="<?= $this->e($this->url('/admin/billing/charges')) ?>">
            <?= $this->csrf() ?>
            <div class="field">
              <label class="label" for="quick-total">Сумма на <?= (int) $participants ?> участников</label>
              <div class="input-group">
                <input class="input input-mono" id="quick-total" name="total_amount" type="text" inputmode="decimal" required
                       value="<?= $lastTotal > 0 ? rtrim(rtrim(number_format((float) $lastTotal, 2, '.', ''), '0'), '.') : '' ?>"
                       data-split-total="#quick-per-unit" data-split-count="<?= (int) $participants ?>">
                <span class="addon"><?= $this->e($symbol) ?></span>
              </div>
              <p class="text-xs text-muted-foreground">На каждого: <b id="quick-per-unit" class="tabular text-foreground"><?= $this->e($this->money($participants > 0 ? (float) $lastTotal / $participants : 0)) ?></b></p>
            </div>
            <button class="btn btn-default btn-block" type="submit"
                    data-confirm="Выставить счета всем активным жителям?" data-confirm-title="Быстрое начисление" data-confirm-label="Выставить">
              <?= $this->icon('calculator') ?> Рассчитать и выставить
            </button>
          </form>
          <a class="btn btn-ghost btn-block btn-sm" href="<?= $this->e($this->url('/admin/billing/wizard')) ?>"><?= $this->icon('sliders', 'h-3.5 w-3.5') ?> Мастер с настройками</a>
        </div>
      </section>
    <?php endif ?>

    <section class="card">
      <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('calendar-check', 'h-4 w-4') ?> Плановые задачи</div></div>
      <div class="card-content grid gap-2">
        <form method="post" action="<?= $this->e($this->url('/admin/billing/overdue')) ?>">
          <?= $this->csrf() ?>
          <button class="btn btn-outline btn-block btn-sm justify-start" type="submit"><?= $this->icon('alert-circle', 'h-3.5 w-3.5') ?> Пометить просроченные счета</button>
        </form>
        <p class="text-xs text-muted-foreground">Обычно это делает cron: <code class="inline-code">bin/bas-ip schedule:run</code> каждую минуту.</p>
      </div>
    </section>
  </aside>
</div>
