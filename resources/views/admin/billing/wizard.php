<?php
/**
 * Charge wizard: enter the total maintenance sum → see the exact split across
 * active residents → compose the message → issue and (optionally) notify.
 *
 * @var int    $participants
 * @var int    $activeTotal
 * @var int    $billableTotal
 * @var float  $lastTotal
 * @var string $periodLabel
 * @var string $periodStart
 * @var string $periodEnd
 * @var int    $dueDays
 * @var string|null $payLink
 * @var array  $allocations
 * @var array<int, string> $templates
 * @var array  $channels
 * @var string $symbol
 * @var array|null $previewRows
 * @var array  $messageHelp
 * @var string $defaultMessage
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Новое начисление</h2>
    <p class="page-desc">
      Введите общую сумму на обслуживание шлагбаума — она разделится на <b class="text-foreground"><?= (int) $billableTotal ?></b> активных участников.
    </p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/billing')) ?>"><?= $this->icon('arrow-left', 'h-3.5 w-3.5') ?> К оплатам</a>
    <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/help/billing-charges')) ?>"><?= $this->icon('life-buoy', 'h-3.5 w-3.5') ?> Как это работает</a>
  </div>
</div>

<?php if ($billableTotal === 0): ?>
  <div class="card">
    <?= $this->include('components/empty', [
      'title' => 'Нет участников для расчёта',
      'description' => 'В расчёте участвуют только жители с флагами «Включен» и «Участвует в оплате». Сейчас таких нет.',
      'icon' => 'users',
      'actionLabel' => 'Перейти к жителям',
      'actionUrl' => '/admin/residents',
    ]) ?>
  </div>
<?php else: ?>

<form method="post" action="<?= $this->e($this->url('/admin/billing/charges')) ?>" data-charge-wizard
      data-charge-preview="<?= $this->e($this->url('/admin/billing/preview')) ?>" class="grid gap-5">
  <?= $this->csrf() ?>

  <div class="wizard-steps mb-1" role="tablist">
    <button type="button" class="wizard-step is-active" data-wizard-step="params"><span class="step-num">1</span> Параметры</button>
    <span class="wizard-connector"></span>
    <button type="button" class="wizard-step" data-wizard-step="message"><span class="step-num">2</span> Сообщение</button>
    <span class="wizard-connector"></span>
    <button type="button" class="wizard-step" data-wizard-step="review"><span class="step-num">3</span> Проверка и выставление</button>
  </div>

  <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
    <div class="grid content-start gap-5">

      <?php /* ── шаг 1: параметры ─────────────────────────── */ ?>
      <section class="card" data-wizard-panel="params">
        <div class="card-header">
          <div class="card-title flex items-center gap-2"><?= $this->icon('calculator', 'h-4 w-4') ?> Сумма и период</div>
          <div class="card-desc">Расчёт обновляется автоматически. Копеечные остатки распределяются так, чтобы сумма счетов точно совпала с общей.</div>
        </div>
        <div class="card-content grid gap-4">
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="field">
              <label class="label" for="total_amount">Общая сумма на обслуживание <span class="text-destructive">*</span></label>
              <div class="input-group">
                <input class="input input-lg input-mono" id="total_amount" name="total_amount" type="text" inputmode="decimal"
                       required placeholder="15000" value="<?= $lastTotal > 0 ? rtrim(rtrim(number_format($lastTotal, 2, '.', ''), '0'), '.') : '' ?>"
                       data-split-total="#per-unit" data-split-count="<?= (int) $billableTotal ?>" autocomplete="off">
                <span class="addon"><?= $this->e($symbol) ?></span>
              </div>
              <p class="text-xs text-muted-foreground">Например: ТО привода + видеонаблюдение + связь за месяц.</p>
            </div>

            <div class="field">
              <label class="label" for="allocation">Способ распределения</label>
              <select class="select" id="allocation" name="allocation">
                <?php foreach ($allocations as $value => $label): ?>
                  <option value="<?= $this->e($value) ?>"><?= $this->e($label) ?></option>
                <?php endforeach ?>
              </select>
              <p class="text-xs text-muted-foreground">
                «Поровну» — сумма ÷ число активных. Остальные варианты используют площадь, число проживающих или долю из карточки жителя.
              </p>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-3">
            <div class="field">
              <label class="label" for="period_label">Период</label>
              <input class="input" id="period_label" name="period_label" type="text" maxlength="64" value="<?= $this->e($periodLabel) ?>" placeholder="Сентябрь 2026">
            </div>
            <div class="field">
              <label class="label" for="period_start">Начало периода</label>
              <input class="input" id="period_start" name="period_start" type="date" value="<?= $this->e($periodStart) ?>">
            </div>
            <div class="field">
              <label class="label" for="period_end">Конец периода</label>
              <input class="input" id="period_end" name="period_end" type="date" value="<?= $this->e($periodEnd) ?>">
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-3">
            <div class="field">
              <label class="label" for="due_days">Срок оплаты, дней</label>
              <input class="input" id="due_days" name="due_days" type="number" min="0" max="365" value="<?= (int) $dueDays ?>">
            </div>
            <div class="field">
              <label class="label" for="due_date">Или точная дата</label>
              <input class="input" id="due_date" name="due_date" type="date" value="<?= $this->e(date('Y-m-d', strtotime('+' . (int) $dueDays . ' days'))) ?>">
            </div>
            <div class="field">
              <label class="label" for="pay_link">Ссылка на оплату</label>
              <input class="input input-mono" id="pay_link" name="pay_link" type="url" maxlength="512"
                     value="<?= $this->e((string) $payLink) ?>" placeholder="https://pay.example.com/?apt={apartment}">
              <p class="text-xs text-muted-foreground">Подстановки: <code class="inline-code">{apartment}</code>, <code class="inline-code">{amount}</code>, <code class="inline-code">{period}</code>.</p>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div class="field">
              <label class="label" for="title">Название начисления</label>
              <input class="input" id="title" name="title" type="text" maxlength="190" value="Обслуживание шлагбаума">
            </div>
            <div class="field">
              <label class="label" for="description">Что входит в сумму</label>
              <input class="input" id="description" name="description" type="text" maxlength="2000" placeholder="ТО привода, видеонаблюдение, связь, резерв">
            </div>
          </div>

          <div class="flex justify-end">
            <button type="button" class="btn btn-outline" data-wizard-step="message">Далее: сообщение <?= $this->icon('arrow-right', 'h-4 w-4') ?></button>
          </div>
        </div>
      </section>

      <?php /* ── шаг 2: сообщение ─────────────────────────── */ ?>
      <section class="card" data-wizard-panel="message" hidden>
        <div class="card-header">
          <div class="card-title flex items-center gap-2"><?= $this->icon('send', 'h-4 w-4') ?> Сообщение жителям</div>
          <div class="card-desc">Текст подставляется в каждый счёт индивидуально — подстановки заменяются данными конкретного жителя.</div>
        </div>
        <div class="card-content grid gap-4">
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="field">
              <label class="label" for="template">Готовый шаблон</label>
              <select class="select" id="template" name="template" data-message-template>
                <?php foreach ($templates as $template): ?>
                  <option value="<?= $this->e($template) ?>" <?= $template === 'payment_new' ? 'selected' : '' ?>><?= $this->e($template) ?></option>
                <?php endforeach ?>
                <option value="">— свой текст —</option>
              </select>
            </div>
            <div class="field">
              <label class="label" for="subject">Тема письма / заголовок</label>
              <input class="input" id="subject" name="subject" type="text" maxlength="255" placeholder="Оплата обслуживания шлагбаума — {period}">
            </div>
          </div>

          <div class="field">
            <label class="label" for="message">Текст сообщения</label>
            <textarea class="textarea font-mono text-[13px] leading-relaxed" id="message" name="message" rows="9"
                      placeholder="<?= $this->e($defaultMessage) ?>"></textarea>
            <div class="mt-2">
              <div class="label mb-1.5">Вставить подстановку</div>
              <div class="flex flex-wrap gap-1.5">
                <?php foreach ($messageHelp as $token => $hint): ?>
                  <button type="button" class="chip" data-placeholder-insert="<?= $this->e($token) ?>" data-tooltip="<?= $this->e($hint) ?>">
                    <code class="font-mono text-[11px]"><?= $this->e($token) ?></code>
                  </button>
                <?php endforeach ?>
              </div>
            </div>
          </div>

          <div class="field">
            <div class="label mb-1.5">Предпросмотр</div>
            <div class="rounded-lg border bg-muted/30 p-4">
              <div class="text-sm font-semibold" data-preview-subject>Тема появится здесь</div>
              <pre class="mt-2 whitespace-pre-wrap font-sans text-sm text-muted-foreground" data-preview-body>Нажмите «Обновить предпросмотр», чтобы увидеть текст на данных реального жителя.</pre>
            </div>
            <button type="button" class="btn btn-outline btn-sm mt-2" data-preview-refresh>
              <?= $this->icon('eye', 'h-3.5 w-3.5') ?> Обновить предпросмотр
            </button>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div class="field">
              <div class="label mb-1.5">Каналы рассылки</div>
              <div class="grid gap-1.5">
                <?php foreach ($channels as $channel): ?>
                  <label class="flex items-center gap-2 rounded-lg border p-2.5 text-sm <?= empty($channel['enabled']) ? 'opacity-60' : '' ?>">
                    <input type="checkbox" class="checkbox" name="channels[]" value="<?= $this->e($channel['name']) ?>"
                           <?= in_array($channel['name'], ['log'], true) || !empty($channel['enabled']) ? '' : 'disabled' ?>
                           <?= $channel['name'] === 'log' ? '' : (in_array($channel['name'], ['mail', 'telegram'], true) ? 'checked' : '') ?>>
                    <?= $this->icon($channel['icon'] ?? 'send', 'h-4 w-4 text-muted-foreground') ?>
                    <span class="flex-1"><?= $this->e($channel['label']) ?></span>
                    <?php if (empty($channel['enabled'])): ?><span class="badge badge-muted">не настроен</span><?php endif ?>
                  </label>
                <?php endforeach ?>
              </div>
            </div>
            <div class="field">
              <div class="label mb-1.5">Когда отправить</div>
              <label class="checkbox-row text-sm">
                <input class="checkbox" type="checkbox" name="notify_now" value="1" checked>
                <span>Сразу после выставления счетов</span>
              </label>
              <label class="checkbox-row text-sm">
                <input class="checkbox" type="checkbox" name="issue" value="1" checked>
                <span>Выставить счета (снять — чтобы сохранить черновик)</span>
              </label>
              <p class="text-xs text-muted-foreground">
                Отключённые жители и те, у кого выключены уведомления, в рассылку не попадают — они будут перечислены в отчёте как «пропущено».
              </p>
            </div>
          </div>

          <div class="flex justify-between">
            <button type="button" class="btn btn-ghost" data-wizard-step="params"><?= $this->icon('arrow-left', 'h-4 w-4') ?> Назад</button>
            <button type="button" class="btn btn-outline" data-wizard-step="review">Далее: проверка <?= $this->icon('arrow-right', 'h-4 w-4') ?></button>
          </div>
        </div>
      </section>

      <?php /* ── шаг 3: проверка ──────────────────────────── */ ?>
      <section class="card" data-wizard-panel="review" hidden>
        <div class="card-header">
          <div class="card-title flex items-center gap-2"><?= $this->icon('clipboard-check', 'h-4 w-4') ?> Проверка расчёта</div>
          <div class="card-desc">Убедитесь, что сумма счетов совпадает с общей, и что у всех есть контакты.</div>
        </div>
        <div class="card-content grid gap-4">
          <div data-charge-summary>
            <div class="text-sm text-muted-foreground">Введите сумму — расчёт появится здесь.</div>
          </div>
          <div hidden data-charge-loading class="flex items-center gap-2 text-sm text-muted-foreground">
            <span class="skeleton h-4 w-4 rounded-full"></span> Пересчитываем…
          </div>
          <div data-charge-rows></div>
          <div class="flex flex-wrap justify-between gap-2">
            <button type="button" class="btn btn-ghost" data-wizard-step="message"><?= $this->icon('arrow-left', 'h-4 w-4') ?> Назад</button>
            <button class="btn btn-success" type="submit" data-confirm="Выставить счета всем активным жителям?" data-confirm-title="Выставление счетов" data-confirm-label="Выставить счета">
              <?= $this->icon('check-circle') ?> Выставить счета
            </button>
          </div>
        </div>
      </section>
    </div>

    <?php /* ═══════════ правая колонка: живая сводка ═══════════ */ ?>
    <aside class="grid content-start gap-5">
      <section class="card">
        <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('divide', 'h-4 w-4') ?> Живой расчёт</div></div>
        <div class="card-content grid gap-3">
          <div class="rounded-xl border bg-muted/30 p-4 text-center">
            <div class="text-xs uppercase tracking-wide text-muted-foreground">На каждого</div>
            <div class="mt-1 text-3xl font-semibold tabular" id="per-unit"><?= $this->e($this->money(0)) ?></div>
          </div>
          <div class="grid gap-2 text-sm">
            <div class="flex justify-between gap-2"><span class="text-muted-foreground">Активных участников</span><b class="tabular"><?= (int) $billableTotal ?></b></div>
            <div class="flex justify-between gap-2"><span class="text-muted-foreground">Всего активных</span><b class="tabular"><?= (int) $activeTotal ?></b></div>
            <div class="flex justify-between gap-2"><span class="text-muted-foreground">Всего записей</span><b class="tabular"><?= (int) ($this->shared['counts']['all'] ?? 0) ?></b></div>
          </div>
          <div class="alert alert-muted text-xs">
            <?= $this->icon('info', 'h-3.5 w-3.5') ?>
            <div class="alert-desc">
              В делении участвуют только жители с флагами «Включен» и «Участвует в оплате».
              Отключённые записи не получают ни счетов, ни сообщений.
            </div>
          </div>
        </div>
      </section>

      <section class="card">
        <div class="card-header"><div class="card-title">Что произойдёт</div></div>
        <div class="card-content">
          <ol class="grid gap-2.5 text-sm text-muted-foreground">
            <li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/12 text-[11px] font-semibold text-primary">1</span> Создастся начисление со снимком суммы и числа участников.</li>
            <li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/12 text-[11px] font-semibold text-primary">2</span> Каждому активному жителю выставится отдельный счёт со сроком и ссылкой на оплату.</li>
            <li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/12 text-[11px] font-semibold text-primary">3</span> Сообщения встанут в очередь рассылки и уйдут по выбранным каналам.</li>
            <li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/12 text-[11px] font-semibold text-primary">4</span> При изменении суммы или состава участников перерасчёт создаст <b>дополнительные счета</b>, а уже выставленные останутся нетронутыми.</li>
          </ol>
        </div>
      </section>
    </aside>
  </div>
</form>
<?php endif ?>

<?php $this->startPush('scripts') ?>
<script nonce="<?= $this->e($this->nonce()) ?>">
(function () {
  var B = window.BasIp;
  if (!B) return;
  var form = document.querySelector('[data-charge-wizard]');
  if (!form) return;

  var previewBtn = document.querySelector('[data-preview-refresh]');
  var subjectOut = document.querySelector('[data-preview-subject]');
  var bodyOut = document.querySelector('[data-preview-body]');

  if (previewBtn) {
    previewBtn.addEventListener('click', function () {
      B.busy(previewBtn, true, 'Считаем…');
      B.post(<?= $this->json($this->url('/admin/notifications/preview')) ?>, {
        template: (document.getElementById('template') || {}).value || 'payment_new',
        subject: (document.getElementById('subject') || {}).value || '',
        message: (document.getElementById('message') || {}).value || ''
      }).then(function (data) {
        B.busy(previewBtn, false);
        if (subjectOut) subjectOut.textContent = data.subject || '';
        if (bodyOut) bodyOut.textContent = data.body || '';
        if (data.unknown && data.unknown.length) {
          B.toast.warning('Неизвестные подстановки: ' + data.unknown.join(', '), { duration: 7000 });
        }
      }).catch(function (err) {
        B.busy(previewBtn, false);
        B.toast.error((err && err.message) || 'Не удалось построить предпросмотр');
      });
    });
  }
})();
</script>
<?php $this->endPush() ?>
