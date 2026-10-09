<?php
/**
 * One charge: totals, the invoice list, recalculation and the mailing.
 *
 * @var BasIp\Domain\Model\Charge $charge
 * @var array<int, BasIp\Domain\Model\Invoice> $invoices
 * @var array $stats
 * @var string $symbol
 * @var array $query
 * @var array $channels
 * @var array<int, string> $templates
 * @var bool $canRecalc
 * @var bool $canNotify
 * @var bool $canEdit
 * @var array $payments
 * @var array $history
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$user = $this->user();
$statusFilter = (string) ($query['status'] ?? '');
$collected = $charge->collectedPercent();
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <div class="flex flex-wrap items-center gap-2">
      <h2 class="page-title"><?= $this->e($charge->number) ?></h2>
      <span class="badge badge-soft-<?= $charge->statusTone() === 'muted' ? 'warning' : $charge->statusTone() ?>"><?= $this->e($charge->statusLabel()) ?></span>
      <?php if ($charge->isRecalc()): ?>
        <span class="badge badge-soft-info" data-tooltip="Создано перерасчётом начисления #<?= (int) $charge->recalcOf ?>">
          <?= $this->icon('refresh-cw', 'h-3 w-3') ?> перерасчёт
        </span>
      <?php endif ?>
    </div>
    <p class="page-desc">
      <?= $this->e($charge->title) ?> · <?= $this->e($charge->periodRange()) ?>
      <?php if ($charge->dueDate !== null): ?> · срок до <?= $this->e($this->date($charge->dueDate)) ?><?php endif ?>
    </p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/billing/charges/' . $charge->id . '/export', $query)) ?>">
      <?= $this->icon('download', 'h-3.5 w-3.5') ?> CSV
    </a>
    <?php if ($canNotify && !$charge->isCancelled()): ?>
      <button class="btn btn-outline btn-sm" type="button" data-dialog="#dialog-notify">
        <?= $this->icon('send', 'h-3.5 w-3.5') ?> Рассылка
      </button>
    <?php endif ?>
    <?php if ($canRecalc && !$charge->isCancelled() && !$charge->isClosed()): ?>
      <button class="btn btn-default btn-sm" type="button" data-dialog="#dialog-recalc">
        <?= $this->icon('refresh-cw', 'h-3.5 w-3.5') ?> Перерасчёт
      </button>
    <?php endif ?>
  </div>
</div>

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
  <?= $this->include('components/stat', ['label' => 'Общая сумма', 'value' => $this->money($charge->totalAmount), 'icon' => 'wallet', 'hint' => $charge->allocationLabel()]) ?>
  <?= $this->include('components/stat', ['label' => 'Участников', 'value' => (string) $charge->activeCount, 'icon' => 'users', 'hint' => 'на каждого ' . $this->money($charge->perUnitAmount)]) ?>
  <?= $this->include('components/stat', ['label' => 'Выставлено', 'value' => $this->money($charge->invoicesAmount), 'icon' => 'receipt', 'hint' => $charge->invoicesTotal . ' счетов']) ?>
  <?= $this->include('components/stat', ['label' => 'Собрано', 'value' => $this->money($charge->paidAmount), 'icon' => 'check-circle', 'accent' => $collected >= 90 ? 'success' : ($collected >= 50 ? 'warning' : 'destructive'), 'hint' => $collected . '% · осталось ' . $this->money($charge->outstanding())]) ?>
</div>

<div class="card mt-5">
  <div class="card-header">
    <div class="card-title">Сбор по начислению</div>
    <div class="progress progress-lg mt-2">
      <div class="progress-bar <?= $collected >= 90 ? 'bg-success' : ($collected >= 50 ? 'bg-warning' : 'bg-destructive') ?>" style="width: <?= $collected ?>%"></div>
    </div>
    <div class="card-desc mt-1.5">
      Оплачено <?= (int) $stats['paid_count'] ?> из <?= (int) $stats['count'] ?> счетов ·
      <?= $collected ?>% · осталось собрать <b class="text-foreground"><?= $this->e($this->money($charge->outstanding())) ?></b>
    </div>
  </div>
</div>

<?php if ($charge->message !== null && $charge->message !== ''): ?>
  <div class="card mt-5">
    <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('message-square-text', 'h-4 w-4') ?> Текст рассылки</div></div>
    <div class="card-content">
      <pre class="whitespace-pre-wrap rounded-lg border bg-muted/30 p-3 font-sans text-sm"><?= $this->e($charge->message) ?></pre>
      <?php if ($charge->payLink !== null): ?>
        <p class="mt-2 text-xs text-muted-foreground">Ссылка на оплату: <a class="font-mono text-primary underline underline-offset-4 break-all" href="<?= $this->e($charge->payLink) ?>" target="_blank" rel="noopener noreferrer"><?= $this->e($charge->payLink) ?></a></p>
      <?php endif ?>
    </div>
  </div>
<?php endif ?>

<?php if ($history !== []): ?>
  <div class="card mt-5">
    <div class="card-header">
      <div class="card-title flex items-center gap-2"><?= $this->icon('git-branch', 'h-4 w-4') ?> Перерасчёты этого начисления</div>
      <div class="card-desc">Каждый перерасчёт создаёт дочернее начисление и дополнительные счета — исходные не меняются.</div>
    </div>
    <div class="card-content p-0">
      <div class="table-wrap rounded-none border-0">
        <table class="table">
          <thead><tr><th>Начисление</th><th>Сумма</th><th>Участников</th><th>Причина</th><th>Дата</th></tr></thead>
          <tbody>
          <?php foreach ($history as $item): ?>
            <tr>
              <td><a class="font-mono text-xs text-primary hover:underline" href="<?= $this->e($this->url('/admin/billing/charges/' . (int) $item['id'])) ?>"><?= $this->e((string) $item['number']) ?></a></td>
              <td class="tabular"><?= $this->e($this->money((float) $item['total_amount'])) ?></td>
              <td class="tabular"><?= (int) $item['active_count'] ?></td>
              <td class="max-w-[22rem] truncate text-xs text-muted-foreground"><?= $this->e((string) ($item['recalc_reason'] ?? '')) ?></td>
              <td class="whitespace-nowrap text-xs tabular"><?= $this->e($this->datetime($item['created_at'] ?? null)) ?></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif ?>

<div class="card mt-5">
  <div class="table-toolbar flex-wrap">
    <div class="tabs-list is-scrollable" role="tablist">
      <?php foreach ([['', 'Все'], ['unpaid', 'Не оплачены'], ['paid', 'Оплачены'], ['partial', 'Частично'], ['overdue', 'Просрочены'], ['void', 'Аннулированы']] as [$value, $label]): ?>
        <a class="tabs-trigger" role="tab" href="<?= $this->e($this->url('/admin/billing/charges/' . $charge->id, $value === '' ? [] : ['status' => $value])) ?>"
           data-state="<?= $statusFilter === $value ? 'active' : 'inactive' ?>"><?= $this->e($label) ?></a>
      <?php endforeach ?>
    </div>
    <form class="ml-auto flex items-center gap-2" method="get" action="<?= $this->e($this->url('/admin/billing/charges/' . $charge->id)) ?>">
      <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= $this->e($statusFilter) ?>"><?php endif ?>
      <input class="input input-sm w-56" type="search" name="q" value="<?= $this->e((string) ($query['q'] ?? '')) ?>" placeholder="Поиск по ФИО или квартире">
      <button class="btn btn-outline btn-sm" type="submit"><?= $this->icon('search', 'h-3.5 w-3.5') ?></button>
    </form>
  </div>

  <div class="card-content p-0">
    <?php if ($invoices === []): ?>
      <?= $this->include('components/empty', ['title' => 'Счетов нет', 'description' => $charge->isDraft() ? 'Начисление сохранено как черновик — выставьте счета.' : 'По выбранному фильтру ничего не найдено.', 'icon' => 'receipt']) ?>
    <?php else: ?>
      <div class="table-wrap rounded-none border-0" data-table>
        <table class="table">
          <thead>
            <tr>
              <th class="w-28">Квартира</th><th>Житель</th><th class="w-32">Счёт</th>
              <th class="w-20">Тип</th>
              <th class="w-28 text-right">Начислено</th><th class="w-28 text-right">Оплачено</th>
              <th class="w-28 text-right">Остаток</th><th class="w-24">Срок</th><th class="w-28">Статус</th>
              <th class="w-24 text-right">Действия</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($invoices as $invoice): ?>
            <tr class="<?= $invoice->isVoid() ? 'is-inactive' : '' ?>">
              <td class="font-medium tabular">кв. <?= $this->e($invoice->apartment ?? '—') ?></td>
              <td>
                <a class="hover:underline" href="<?= $this->e($this->url('/admin/residents/' . (int) $invoice->residentId)) ?>"><?= $this->e($invoice->residentName ?? '—') ?></a>
              </td>
              <td class="font-mono text-xs"><?= $this->e($invoice->number) ?></td>
              <td>
                <?php if ($invoice->kind !== 'regular'): ?>
                  <span class="badge badge-soft-<?= $invoice->kind === 'credit' ? 'warning' : 'info' ?>" data-tooltip="<?= $this->e((string) ($invoice->recalcReason ?? '')) ?>"><?= $this->e($invoice->kindLabel()) ?></span>
                <?php else: ?>
                  <span class="text-xs text-muted-foreground">основной</span>
                <?php endif ?>
              </td>
              <td class="table-numeric tabular"><?= $this->e($this->money($invoice->amount)) ?></td>
              <td class="table-numeric tabular text-muted-foreground"><?= $this->e($this->money($invoice->paidAmount)) ?></td>
              <td class="table-numeric font-semibold tabular"><?= $this->e($this->money($invoice->balance)) ?></td>
              <td class="whitespace-nowrap text-xs tabular <?= $invoice->isOverdue() ? 'font-semibold text-destructive' : 'text-muted-foreground' ?>">
                <?= $this->e($this->date($invoice->dueDate)) ?>
                <?php if ($invoice->isOverdue()): ?><div class="text-[11px]"><?= $invoice->daysOverdue() ?> дн.</div><?php endif ?>
              </td>
              <td><span class="badge badge-soft-<?= $invoice->statusTone() === 'muted' ? 'warning' : $invoice->statusTone() ?>"><?= $this->e($invoice->statusLabel()) ?></span></td>
              <td class="text-right">
                <div class="flex justify-end gap-1">
                  <?php if ($user !== null && $user->can('billing.payment.record') && $invoice->isPending()): ?>
                    <button class="btn btn-ghost btn-sm btn-icon" type="button" data-dialog="#dialog-payment"
                            data-dialog-payment-resident="<?= (int) $invoice->residentId ?>"
                            data-dialog-payment-invoice="<?= (int) $invoice->id ?>"
                            data-dialog-payment-amount="<?= $invoice->balance ?>"
                            data-tooltip="Принять оплату" aria-label="Принять оплату">
                      <?= $this->icon('coins', 'h-3.5 w-3.5') ?>
                    </button>
                  <?php endif ?>
                  <?php if ($user !== null && $user->can('billing.invoice.void') && $invoice->isPending() && $invoice->paidAmount < 0.005): ?>
                    <form method="post" action="<?= $this->e($this->url('/admin/billing/invoices/' . $invoice->id . '/void')) ?>" class="inline">
                      <?= $this->csrf() ?>
                      <input type="hidden" name="reason" value="Аннулировано из карточки начисления">
                      <input type="hidden" name="redirect" value="<?= $this->e($this->url('/admin/billing/charges/' . $charge->id)) ?>">
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
              <td colspan="4" class="text-right font-medium">Итого по <?= count($invoices) ?> счетам</td>
              <td class="table-numeric font-semibold tabular"><?= $this->e($this->money(array_sum(array_map(static fn ($i) => $i->amount, $invoices)))) ?></td>
              <td class="table-numeric font-semibold tabular text-success"><?= $this->e($this->money(array_sum(array_map(static fn ($i) => $i->paidAmount, $invoices)))) ?></td>
              <td class="table-numeric font-semibold tabular"><?= $this->e($this->money(array_sum(array_map(static fn ($i) => $i->balance, $invoices)))) ?></td>
              <td colspan="3"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endif ?>
  </div>
</div>

<?php if ($payments !== []): ?>
  <div class="card mt-5">
    <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('banknote', 'h-4 w-4') ?> Оплаты по начислению</div></div>
    <div class="card-content p-0">
      <div class="table-wrap rounded-none border-0">
        <table class="table">
          <thead><tr><th>Дата</th><th>Житель</th><th class="text-right">Сумма</th><th>Способ</th><th>№ платежа</th></tr></thead>
          <tbody>
          <?php foreach ($payments as $payment): ?>
            <tr>
              <td class="whitespace-nowrap text-xs tabular"><?= $this->e($this->date($payment['paid_at'] ?? null)) ?></td>
              <td class="text-sm"><?= $this->e((string) ($payment['resident_name'] ?? '—')) ?> <span class="text-xs text-muted-foreground">кв. <?= $this->e((string) ($payment['apartment'] ?? '')) ?></span></td>
              <td class="table-numeric font-semibold tabular text-success"><?= $this->e($this->money((float) ($payment['amount'] ?? 0))) ?></td>
              <td class="text-xs"><?= $this->e((string) ($payment['method'] ?? '')) ?></td>
              <td class="font-mono text-xs"><?= $this->e((string) ($payment['reference'] ?? '—')) ?></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif ?>

<?php if ($canEdit && !$charge->isClosed() && !$charge->isCancelled()): ?>
  <div class="mt-5 flex flex-wrap gap-2">
    <button class="btn btn-outline btn-sm" type="button" data-dialog="#dialog-edit"><?= $this->icon('pencil', 'h-3.5 w-3.5') ?> Изменить параметры</button>
    <form method="post" action="<?= $this->e($this->url('/admin/billing/charges/' . $charge->id . '/close')) ?>">
      <?= $this->csrf() ?>
      <button class="btn btn-outline btn-sm" type="submit" data-confirm="Закрыть период? После закрытия перерасчёт будет недоступен." data-confirm-label="Закрыть период">
        <?= $this->icon('lock', 'h-3.5 w-3.5') ?> Закрыть период
      </button>
    </form>
    <form method="post" action="<?= $this->e($this->url('/admin/billing/charges/' . $charge->id . '/cancel')) ?>">
      <?= $this->csrf() ?>
      <input type="hidden" name="reason" value="Отменено администратором">
      <button class="btn btn-outline btn-sm text-destructive" type="submit"
              data-confirm="Отменить начисление и аннулировать все счета?" data-confirm-label="Отменить" data-confirm-tone="destructive">
        <?= $this->icon('trash', 'h-3.5 w-3.5') ?> Отменить начисление
      </button>
    </form>
  </div>
<?php endif ?>

<?php /* ═══════════ диалог: перерасчёт ═══════════ */ ?>
<?php if ($canRecalc && !$charge->isCancelled() && !$charge->isClosed()): ?>
  <div class="dialog dialog-lg" id="dialog-recalc" data-state="closed" hidden role="dialog" aria-labelledby="recalc-title">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <div class="dialog-header">
      <h2 class="dialog-title" id="recalc-title">Перерасчёт начисления</h2>
      <p class="dialog-desc">
        Изменилась сумма или число участников? Перерасчёт создаст <b>дополнительные счета</b> и <b>сторно</b> —
        уже выставленные счета не меняются, поэтому история начислений остаётся полной.
      </p>
    </div>

    <form method="post" action="<?= $this->e($this->url('/admin/billing/charges/' . $charge->id . '/recalc')) ?>" data-recalc-form>
      <?= $this->csrf() ?>
      <div class="grid gap-4">
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="recalc-total">Новая общая сумма</label>
            <div class="input-group">
              <input class="input input-mono" id="recalc-total" name="total_amount" type="text" inputmode="decimal"
                     value="<?= number_format($charge->totalAmount, 2, '.', '') ?>">
              <span class="addon"><?= $this->e($symbol) ?></span>
            </div>
            <p class="text-xs text-muted-foreground">Сейчас: <?= $this->e($this->money($charge->totalAmount)) ?> на <?= (int) $charge->activeCount ?> участников.</p>
          </div>
          <div class="field">
            <label class="label" for="recalc-allocation">Способ распределения</label>
            <select class="select" id="recalc-allocation" name="allocation">
              <?php foreach (['equal' => 'Поровну', 'by_persons' => 'По числу проживающих', 'by_area' => 'По площади', 'by_share' => 'По доле'] as $value => $label): ?>
                <option value="<?= $value ?>" <?= $this->selected($charge->allocation, $value) ?>><?= $label ?></option>
              <?php endforeach ?>
            </select>
          </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="recalc-due">Новый срок оплаты</label>
            <input class="input" id="recalc-due" name="due_date" type="date" value="<?= $this->e((string) ($charge->dueDate ?? date('Y-m-d', strtotime('+14 days')))) ?>">
          </div>
          <div class="field">
            <label class="label" for="recalc-reason">Причина (попадёт в счета и в письмо)</label>
            <input class="input" id="recalc-reason" name="reason" type="text" maxlength="255" placeholder="Оставьте пустым — сформулируем автоматически">
          </div>
        </div>

        <div class="grid gap-2 rounded-lg border bg-muted/25 p-3">
          <label class="checkbox-row text-sm">
            <input class="checkbox" type="checkbox" name="include_new" value="1" checked>
            <span>Выставить счета новым активным жителям <span class="label-hint">Тем, кто добавился после выставления.</span></span>
          </label>
          <label class="checkbox-row text-sm">
            <input class="checkbox" type="checkbox" name="exclude_inactive" value="1" checked>
            <span>Сторнировать счета отключённых <span class="label-hint">Если житель выключен — его счёт обнуляется сторно.</span></span>
          </label>
          <label class="checkbox-row text-sm">
            <input class="checkbox" type="checkbox" name="notify_now" value="1">
            <span>Отправить уведомления о перерасчёте</span>
          </label>
        </div>

        <div data-recalc-result></div>
      </div>

      <div class="dialog-footer mt-4">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="button" class="btn btn-secondary" data-recalc-preview><?= $this->icon('eye') ?> Показать, что изменится</button>
        <button type="submit" class="btn btn-default"
                data-confirm="Применить перерасчёт и создать дополнительные счета?" data-confirm-label="Применить">
          <?= $this->icon('refresh-cw') ?> Применить перерасчёт
        </button>
      </div>
    </form>
  </div>
<?php endif ?>

<?php /* ═══════════ диалог: рассылка ═══════════ */ ?>
<?php if ($canNotify && !$charge->isCancelled()): ?>
  <div class="dialog dialog-lg" id="dialog-notify" data-state="closed" hidden role="dialog" aria-labelledby="notify-title">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <form method="post" action="<?= $this->e($this->url('/admin/billing/charges/' . $charge->id . '/notify')) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/notifications')) ?>">
      <?= $this->csrf() ?>
      <div class="dialog-header">
        <h2 class="dialog-title" id="notify-title">Рассылка по начислению</h2>
        <p class="dialog-desc">Сообщения получат только <b>активные</b> жители этого начисления. Отключённые и те, у кого выключены уведомления, будут пропущены.</p>
      </div>
      <div class="grid gap-4">
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="notify-template">Шаблон</label>
            <select class="select" id="notify-template" name="template">
              <?php foreach ($templates as $template): ?>
                <option value="<?= $this->e($template) ?>" <?= $template === 'payment_new' ? 'selected' : '' ?>><?= $this->e($template) ?></option>
              <?php endforeach ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="notify-subject">Тема (необязательно)</label>
            <input class="input" id="notify-subject" name="subject" type="text" maxlength="255" placeholder="Из шаблона">
          </div>
        </div>

        <div class="field">
          <label class="label" for="notify-message">Свой текст (переопределит шаблон)</label>
          <textarea class="textarea font-mono text-[13px]" id="notify-message" name="message" rows="7"
                    placeholder="<?= $this->e((string) ($charge->message ?? '')) ?>"><?= $this->e((string) ($charge->message ?? '')) ?></textarea>
        </div>

        <div class="field">
          <div class="label mb-1.5">Каналы</div>
          <div class="flex flex-wrap gap-2">
            <?php foreach ($channels as $channel): ?>
              <label class="chip <?= empty($channel['enabled']) ? 'opacity-50' : '' ?>">
                <input type="checkbox" name="channels[]" value="<?= $this->e($channel['name']) ?>" <?= empty($channel['enabled']) ? 'disabled' : '' ?> <?= $channel['name'] === 'mail' || $channel['name'] === 'telegram' ? 'checked' : '' ?>>
                <?= $this->e($channel['label']) ?>
              </label>
            <?php endforeach ?>
          </div>
        </div>

        <div class="alert alert-muted">
          <?= $this->icon('info', 'h-4 w-4') ?>
          <div class="alert-desc">Сообщения уходят в очередь и доставляются работником (<code class="inline-code">bin/bas-ip queue:run</code> или cron). Статус доставки виден в разделе «Рассылки».</div>
        </div>
      </div>
      <div class="dialog-footer mt-4">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="submit" class="btn btn-default" data-confirm="Поставить рассылку в очередь?" data-confirm-label="Отправить">
          <?= $this->icon('send') ?> Отправить
        </button>
      </div>
    </form>
  </div>
<?php endif ?>

<?php /* ═══════════ диалог: параметры ═══════════ */ ?>
<?php if ($canEdit && !$charge->isClosed() && !$charge->isCancelled()): ?>
  <div class="dialog" id="dialog-edit" data-state="closed" hidden role="dialog" aria-labelledby="edit-title">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <form method="post" action="<?= $this->e($this->url('/admin/billing/charges/' . $charge->id . '/update')) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/billing/charges/' . $charge->id)) ?>">
      <?= $this->csrf() ?>
      <div class="dialog-header">
        <h2 class="dialog-title" id="edit-title">Параметры начисления</h2>
        <p class="dialog-desc">Сумму и состав участников меняет только перерасчёт — здесь правятся текст, сроки и ссылка.</p>
      </div>
      <div class="grid gap-3">
        <div class="field">
          <label class="label" for="edit-title-input">Название</label>
          <input class="input" id="edit-title-input" name="title" type="text" maxlength="190" value="<?= $this->e($charge->title) ?>">
        </div>
        <div class="field">
          <label class="label" for="edit-desc">Что входит в сумму</label>
          <textarea class="textarea" id="edit-desc" name="description" rows="2"><?= $this->e((string) ($charge->description ?? '')) ?></textarea>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="edit-due">Срок оплаты</label>
            <input class="input" id="edit-due" name="due_date" type="date" value="<?= $this->e((string) ($charge->dueDate ?? '')) ?>">
            <p class="text-xs text-muted-foreground">Применится ко всем неоплаченным счетам начисления.</p>
          </div>
          <div class="field">
            <label class="label" for="edit-link">Ссылка на оплату</label>
            <input class="input input-mono" id="edit-link" name="pay_link" type="url" maxlength="512" value="<?= $this->e((string) ($charge->payLink ?? '')) ?>">
          </div>
        </div>
        <div class="field">
          <label class="label" for="edit-message">Текст сообщения</label>
          <textarea class="textarea font-mono text-[13px]" id="edit-message" name="message" rows="6"><?= $this->e((string) ($charge->message ?? '')) ?></textarea>
        </div>
      </div>
      <div class="dialog-footer mt-4">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="submit" class="btn btn-default"><?= $this->icon('save') ?> Сохранить</button>
      </div>
    </form>
  </div>
<?php endif ?>

<?php /* ═══════════ диалог: приём оплаты ═══════════ */ ?>
<?php if ($user !== null && $user->can('billing.payment.record')): ?>
  <div class="dialog dialog-sm" id="dialog-payment" data-state="closed" hidden role="dialog" aria-labelledby="cpay-title">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <form method="post" action="<?= $this->e($this->url('/admin/billing/payments')) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/billing/charges/' . $charge->id)) ?>">
      <?= $this->csrf() ?>
      <input type="hidden" name="charge_id" value="<?= (int) $charge->id ?>">
      <input type="hidden" name="invoice_id" id="cpay-invoice" value="">
      <div class="dialog-header">
        <h2 class="dialog-title" id="cpay-title">Принять оплату</h2>
        <p class="dialog-desc">Платёж зачтётся в выбранный счёт.</p>
      </div>
      <div class="grid gap-3">
        <div class="field">
          <label class="label" for="cpay-resident">Житель</label>
          <select class="select" id="cpay-resident" name="resident_id" required>
            <?php foreach ($invoices as $invoice): ?>
              <option value="<?= (int) $invoice->residentId ?>" data-invoice="<?= (int) $invoice->id ?>" data-amount="<?= $invoice->balance ?>">
                кв. <?= $this->e($invoice->apartment ?? '—') ?> · <?= $this->e($invoice->residentName ?? '') ?>
              </option>
            <?php endforeach ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="cpay-amount">Сумма</label>
          <div class="input-group">
            <input class="input input-mono" id="cpay-amount" name="amount" type="text" inputmode="decimal" required placeholder="0.00">
            <span class="addon"><?= $this->e($symbol) ?></span>
          </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="cpay-date">Дата</label>
            <input class="input" id="cpay-date" name="paid_at" type="date" value="<?= $this->e(date('Y-m-d')) ?>">
          </div>
          <div class="field">
            <label class="label" for="cpay-method">Способ</label>
            <select class="select" id="cpay-method" name="method">
              <?php foreach (['transfer' => 'Перевод', 'cash' => 'Наличные', 'card' => 'Карта', 'sbp' => 'СБП', 'online' => 'Онлайн'] as $value => $label): ?>
                <option value="<?= $value ?>"><?= $label ?></option>
              <?php endforeach ?>
            </select>
          </div>
        </div>
        <div class="field">
          <label class="label" for="cpay-ref">№ платежа</label>
          <input class="input input-mono" id="cpay-ref" name="reference" type="text" maxlength="120">
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
  // Кнопка «Принять оплату» в строке счёта подставляет жителя, счёт и сумму.
  document.addEventListener('basip:opened', function (e) {
    if (e.target.id !== 'dialog-payment') return;
    var opener = document.querySelector('[data-dialog-payment-resident].last-used');
    void opener;
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-dialog-payment-resident]');
    if (!btn) return;
    btn.classList.add('last-used');
    var resident = document.getElementById('cpay-resident');
    var invoice = document.getElementById('cpay-invoice');
    var amount = document.getElementById('cpay-amount');
    if (resident) resident.value = btn.getAttribute('data-dialog-payment-resident');
    if (invoice) invoice.value = btn.getAttribute('data-dialog-payment-invoice');
    if (amount) amount.value = btn.getAttribute('data-dialog-payment-amount');
  });

  // Выбор жителя в диалоге оплаты синхронизирует счёт и сумму.
  var residentSelect = document.getElementById('cpay-resident');
  if (residentSelect) {
    residentSelect.addEventListener('change', function () {
      var option = residentSelect.options[residentSelect.selectedIndex];
      if (!option) return;
      var invoice = document.getElementById('cpay-invoice');
      var amount = document.getElementById('cpay-amount');
      if (invoice) invoice.value = option.getAttribute('data-invoice') || '';
      if (amount) amount.value = option.getAttribute('data-amount') || '';
    });
  }
})();
</script>
<?php $this->endPush() ?>
