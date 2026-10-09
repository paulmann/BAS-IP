<?php
/**
 * Admin dashboard.
 *
 * @var array $overview   residents / gate / billing / guest / notify counters
 * @var array $gateByDay
 * @var array $gateByHour
 * @var array $payments
 * @var array $collection
 * @var array $debtors
 * @var array $topResidents
 * @var array<int, BasIp\Domain\Model\GateEvent> $recentEvents
 * @var BasIp\Device\GateResult $gateStatus
 * @var array $guestStats
 * @var array $health
 * @var array $attention
 * @var int   $days
 * @var array $charges
 * @var array $updateInfo
 * @var string $currency
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$residents = (array) ($overview['residents'] ?? []);
$gate = (array) ($overview['gate'] ?? []);
$billing = (array) ($overview['billing'] ?? []);
$guest = (array) ($overview['guest'] ?? []);
$notify = (array) ($overview['notify'] ?? []);
$debt = (float) ($billing['debt'] ?? 0);
$overdue = (float) ($billing['overdue'] ?? 0);
$collected = (float) ($billing['collected_month'] ?? 0);
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Дашборд</h2>
    <p class="page-desc">
      Сводка по <?= (int) $days ?> дням. Активных жителей: <b class="text-foreground"><?= (int) ($residents['active'] ?? 0) ?></b>
      <?php if (($residents['inactive'] ?? 0) > 0): ?> · отключено: <?= (int) $residents['inactive'] ?><?php endif ?>
    </p>
  </div>
  <div class="page-actions">
    <form method="get" class="flex items-center gap-2">
      <label class="sr-only" for="days">Период</label>
      <select class="select select-sm w-auto" id="days" name="days" data-auto-submit>
        <?php foreach ([7 => '7 дней', 30 => '30 дней', 90 => '90 дней', 180 => '6 месяцев', 365 => 'год'] as $value => $label): ?>
          <option value="<?= $value ?>" <?= (int) $days === $value ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach ?>
      </select>
    </form>
    <button type="button" class="btn btn-outline btn-sm" data-device-command="status" data-device-url="<?= $this->e($this->url('/admin/gate/open')) ?>" data-tooltip="Проверить панель">
      <?= $this->icon('activity', 'h-3.5 w-3.5') ?> Панель
    </button>
    <a class="btn btn-success btn-sm" href="<?= $this->e($this->url('/admin/billing/wizard')) ?>">
      <?= $this->icon('calculator', 'h-3.5 w-3.5') ?> Начислить оплату
    </a>
  </div>
</div>

<?php /* ── требующие внимания ─────────────────────────────── */ ?>
<?php if ($attention !== []): ?>
  <section class="mb-5 grid gap-2">
    <?php foreach (array_slice($attention, 0, 4) as $item): ?>
      <div class="alert alert-<?= ($item['level'] ?? 'warning') === 'error' ? 'destructive' : 'warning' ?>">
        <?= $this->icon(($item['level'] ?? 'warning') === 'error' ? 'alert-circle' : 'alert-triangle', 'h-4 w-4') ?>
        <div>
          <div class="alert-title"><?= $this->e($item['title']) ?></div>
          <div class="alert-desc">
            <?= $this->e($item['text']) ?>
            <?php if (!empty($item['link'])): ?>
              <a class="ml-1 font-medium underline underline-offset-4" href="<?= $this->e($this->url((string) $item['link'])) ?>">Открыть</a>
            <?php endif ?>
          </div>
        </div>
      </div>
    <?php endforeach ?>
  </section>
<?php endif ?>

<?php /* ── KPI ─────────────────────────────────────────────── */ ?>
<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
  <?= $this->include('components/stat', [
    'label' => 'Открытий сегодня', 'value' => (string) (int) ($gate['today'] ?? 0), 'icon' => 'door-open',
    'hint' => 'за ' . $days . ' дн.: ' . (int) ($gate['month'] ?? 0), 'accent' => 'success', 'href' => '/admin/gate',
  ]) ?>
  <?= $this->include('components/stat', [
    'label' => 'Гостевых активных', 'value' => (string) (int) ($guest['active'] ?? 0), 'icon' => 'user-plus',
    'hint' => 'создано сегодня: ' . (int) ($guest['today'] ?? 0), 'href' => '/admin/guest',
  ]) ?>
  <?= $this->include('components/stat', [
    'label' => 'Собрано за месяц', 'value' => $this->money($collected), 'icon' => 'wallet',
    'accent' => 'info', 'hint' => 'всего: ' . $this->money((float) ($billing['collected_total'] ?? 0)), 'href' => '/admin/billing/payments',
  ]) ?>
  <?= $this->include('components/stat', [
    'label' => 'Задолженность', 'value' => $this->money($debt), 'icon' => 'alert-triangle',
    'accent' => $debt > 0.005 ? 'destructive' : 'success',
    'hint' => 'просрочено: ' . $this->money($overdue) . ' (' . (int) ($billing['overdue_count'] ?? 0) . ' счетов)',
    'href' => '/admin/billing/debtors',
  ]) ?>
</div>

<div class="grid gap-5 mt-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
  <div class="grid content-start gap-5">

    <?php /* ── график открытий ─────────────────────────────── */ ?>
    <section class="card">
      <div class="card-header has-action">
        <div>
          <div class="card-title flex items-center gap-2"><?= $this->icon('bar-chart-3', 'h-4 w-4') ?> Открытия шлагбаума</div>
          <div class="card-desc">По дням за <?= (int) $days ?> дн. Наведите на столбец, чтобы увидеть значение.</div>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/gate/stats')) ?>">Статистика</a>
      </div>
      <div class="card-content">
        <?= $this->include('components/chart', [
          'type' => 'bars',
          'height' => 150,
          'ariaLabel' => 'Открытия шлагбаума по дням',
          'data' => [
            'labels' => array_column($gateByDay, 'label'),
            'values' => array_column($gateByDay, 'count'),
          ],
        ]) ?>
        <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted-foreground">
          <span class="legend-item"><span class="legend-swatch" style="background:hsl(var(--chart-1))"></span> всего: <b class="tabular text-foreground"><?= (int) ($gate['month'] ?? 0) ?></b></span>
          <span class="legend-item">успешно: <b class="tabular text-foreground"><?= (int) ($gate['success'] ?? 0) ?></b></span>
          <span class="legend-item">отказов: <b class="tabular text-foreground"><?= (int) ($gate['denied'] ?? 0) ?></b></span>
          <span class="legend-item">ошибок устройства: <b class="tabular text-foreground"><?= (int) ($gate['errors'] ?? 0) ?></b></span>
        </div>
      </div>
    </section>

    <?php /* ── сборы ───────────────────────────────────────── */ ?>
    <section class="card">
      <div class="card-header has-action">
        <div>
          <div class="card-title flex items-center gap-2"><?= $this->icon('trending-up', 'h-4 w-4') ?> Начислено и собрано</div>
          <div class="card-desc">По месяцам. Линия — факт оплат, фон — процент сбора.</div>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/billing')) ?>">Оплаты</a>
      </div>
      <div class="card-content">
        <?= $this->include('components/chart', [
          'type' => 'line', 'height' => 140, 'money' => true,
          'ariaLabel' => 'Оплаты по месяцам',
          'data' => ['labels' => array_column($collection, 'label'), 'values' => array_column($collection, 'paid')],
        ]) ?>
        <div class="mt-3 grid gap-2 sm:grid-cols-3">
          <?php foreach (array_slice(array_reverse($collection), 0, 3) as $month): ?>
            <div class="rounded-lg border bg-muted/25 p-2.5">
              <div class="text-xs text-muted-foreground"><?= $this->e($month['label']) ?></div>
              <div class="mt-0.5 text-sm font-semibold tabular"><?= $this->e($this->money((float) $month['paid'])) ?></div>
              <div class="mt-1.5 progress progress-sm">
                <div class="progress-bar <?= ($month['rate'] ?? 0) >= 90 ? 'bg-success' : ((($month['rate'] ?? 0) >= 60 ? 'bg-warning' : 'bg-destructive')) ?>" style="width: <?= (float) ($month['rate'] ?? 0) ?>%"></div>
              </div>
              <div class="mt-1 text-[11px] text-muted-foreground">сбор <?= (float) ($month['rate'] ?? 0) ?>% от <?= $this->e($this->money((float) ($month['billed'] ?? 0))) ?></div>
            </div>
          <?php endforeach ?>
        </div>
      </div>
    </section>

    <?php /* ── последние события ───────────────────────────── */ ?>
    <section class="card">
      <div class="card-header has-action">
        <div>
          <div class="card-title flex items-center gap-2"><?= $this->icon('history', 'h-4 w-4') ?> Последние события</div>
          <div class="card-desc">Журнал обновляется автоматически.</div>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/gate')) ?>">Весь журнал <?= $this->icon('arrow-right', 'h-3.5 w-3.5') ?></a>
      </div>
      <div class="card-content p-0" data-gate-feed data-gate-feed="<?= $this->e($this->url('/admin/gate?partial=feed')) ?>">
        <?php if ($recentEvents === []): ?>
          <?= $this->include('components/empty', ['title' => 'Событий пока нет', 'icon' => 'history']) ?>
        <?php else: ?>
          <ul class="divide-y">
            <?php foreach ($recentEvents as $event): ?>
              <li class="flex items-start gap-3 px-5 py-2.5">
                <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full
                  <?= $event->result === 'success' ? 'bg-success/12 text-success' : ($event->result === 'denied' ? 'bg-warning/15 text-warning' : 'bg-destructive/12 text-destructive') ?>">
                  <?= $this->icon($event->result === 'success' ? ($event->isGuest() ? 'user-plus' : 'check') : 'ban', 'h-3.5 w-3.5') ?>
                </span>
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-baseline gap-x-2">
                    <span class="truncate text-sm font-medium"><?= $this->e($event->actorLabel()) ?></span>
                    <span class="badge badge-soft-<?= $event->accessTone() === 'muted' ? 'warning' : $event->accessTone() ?>"><?= $this->e($event->accessLabel()) ?></span>
                  </div>
                  <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                    <span data-time-ago="<?= $this->e((string) $event->occurredAt) ?>"></span>
                    <span>·</span><span><?= $this->e($event->sourceLabel()) ?></span>
                    <?php if ($event->reason !== null && $event->reason !== ''): ?><span>·</span><span class="truncate"><?= $this->e($event->reason) ?></span><?php endif ?>
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

    <?php /* ── состояние панели ───────────────────────────── */ ?>
    <section class="card">
      <div class="card-header">
        <div class="card-title flex items-center gap-2">
          <span class="health-dot <?= $gateStatus->ok ? 'ok' : 'fail' ?>"></span> Панель
        </div>
        <div class="card-desc truncate"><?= $this->e($gateStatus->ok ? 'На связи' : $gateStatus->message) ?></div>
      </div>
      <div class="card-content grid gap-2 text-sm">
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Отклик</span><b class="tabular"><?= round($gateStatus->durationMs) ?> мс</b></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">SIP</span><b><?= $this->e((string) ($gateStatus->get('sip_status') ?? '—')) ?></b></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Открытий сегодня</span><b class="tabular"><?= (int) ($gate['today'] ?? 0) ?></b></div>
        <div class="mt-1 grid gap-2">
          <a class="btn btn-success btn-block" href="<?= $this->e($this->url('/panel')) ?>"><?= $this->icon('door-open', 'h-4 w-4') ?> Открыть шлагбаум</a>
          <a class="btn btn-outline btn-block" href="<?= $this->e($this->url('/admin/gate/devices')) ?>"><?= $this->icon('cpu', 'h-4 w-4') ?> Устройства</a>
        </div>
      </div>
    </section>

    <?php /* ── должники ───────────────────────────────────── */ ?>
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
            <?php foreach (array_slice($debtors, 0, 6) as $debtor): ?>
              <li class="flex items-center gap-2">
                <a class="min-w-0 flex-1 truncate text-sm hover:underline" href="<?= $this->e($this->url('/admin/residents/' . (int) $debtor['resident_id'])) ?>">
                  кв. <?= $this->e((string) $debtor['apartment']) ?> · <?= $this->e(\BasIp\Support\Str::initials((string) $debtor['full_name'], 1)) ?>
                </a>
                <span class="shrink-0 text-sm font-semibold tabular text-destructive"><?= $this->e($this->money((float) $debtor['debt'])) ?></span>
              </li>
            <?php endforeach ?>
          </ul>
          <form method="post" action="<?= $this->e($this->url('/admin/billing/remind')) ?>" data-ajax>
            <?= $this->csrf() ?>
            <button class="btn btn-outline btn-block btn-sm" type="submit"
                    data-confirm="Отправить напоминания всем активным должникам?" data-confirm-label="Отправить">
              <?= $this->icon('send', 'h-3.5 w-3.5') ?> Напомнить должникам
            </button>
          </form>
        <?php endif ?>
      </div>
    </section>

    <?php /* ── начисления ─────────────────────────────────── */ ?>
    <section class="card">
      <div class="card-header has-action">
        <div class="card-title flex items-center gap-2"><?= $this->icon('calculator', 'h-4 w-4') ?> Начисления</div>
        <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/billing')) ?>">Все</a>
      </div>
      <div class="card-content grid gap-2.5">
        <?php if ($charges === []): ?>
          <p class="text-sm text-muted-foreground">Начислений ещё не было.</p>
          <a class="btn btn-default btn-sm" href="<?= $this->e($this->url('/admin/billing/wizard')) ?>"><?= $this->icon('plus', 'h-3.5 w-3.5') ?> Создать первое</a>
        <?php else: ?>
          <?php foreach (array_slice($charges, 0, 4) as $charge): ?>
            <a class="block rounded-lg border p-3 transition hover:border-primary/40 hover:bg-accent/40"
               href="<?= $this->e($this->url('/admin/billing/charges/' . $charge->id)) ?>">
              <div class="flex items-baseline justify-between gap-2">
                <span class="truncate text-sm font-medium"><?= $this->e($charge->periodLabel ?: $charge->number) ?></span>
                <span class="badge badge-soft-<?= $charge->statusTone() === 'muted' ? 'warning' : $charge->statusTone() ?> shrink-0"><?= $this->e($charge->statusLabel()) ?></span>
              </div>
              <div class="mt-1.5 flex items-center justify-between gap-2 text-xs text-muted-foreground">
                <span><?= $this->e($this->money($charge->totalAmount)) ?> · <?= (int) $charge->activeCount ?> чел.</span>
                <span class="tabular">на 1: <?= $this->e($this->money($charge->perUnitAmount)) ?></span>
              </div>
              <div class="progress progress-sm mt-2">
                <div class="progress-bar <?= $charge->collectedPercent() >= 90 ? 'bg-success' : ($charge->collectedPercent() >= 50 ? 'bg-warning' : 'bg-destructive') ?>"
                     style="width: <?= $charge->collectedPercent() ?>%"></div>
              </div>
              <div class="mt-1 text-[11px] text-muted-foreground">собрано <?= $charge->collectedPercent() ?>% · осталось <?= $this->e($this->money($charge->outstanding())) ?></div>
            </a>
          <?php endforeach ?>
        <?php endif ?>
      </div>
    </section>

    <?php /* ── активность по часам ────────────────────────── */ ?>
    <section class="card">
      <div class="card-header">
        <div class="card-title flex items-center gap-2"><?= $this->icon('clock', 'h-4 w-4') ?> Пик нагрузок</div>
        <div class="card-desc">Распределение открытий по часам за 14 дней.</div>
      </div>
      <div class="card-content">
        <?= $this->include('components/chart', [
          'type' => 'bars', 'height' => 90,
          'ariaLabel' => 'Открытия по часам суток',
          'data' => ['labels' => array_column($gateByHour, 'label'), 'values' => array_column($gateByHour, 'count')],
        ]) ?>
      </div>
    </section>

    <?php /* ── база ───────────────────────────────────────── */ ?>
    <section class="card">
      <div class="card-header has-action">
        <div class="card-title flex items-center gap-2"><?= $this->icon('users', 'h-4 w-4') ?> База жителей</div>
        <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/residents')) ?>">Открыть</a>
      </div>
      <div class="card-content grid gap-2 text-sm">
        <?php
          $rows = [
            ['Активных', (int) ($residents['active'] ?? 0), 'success'],
            ['Участвуют в оплате', (int) ($residents['billable'] ?? 0), ''],
            ['Отключено', (int) ($residents['inactive'] ?? 0), 'muted'],
            ['Без галочки «доступ»', (int) ($residents['no_access'] ?? 0), 'warning'],
            ['Автомобилей', (int) ($residents['cars'] ?? 0), ''],
            ['В архиве', (int) ($residents['deleted'] ?? 0), 'muted'],
          ];
        ?>
        <?php foreach ($rows as [$label, $value, $tone]): ?>
          <div class="flex items-center justify-between gap-2">
            <span class="text-muted-foreground"><?= $this->e($label) ?></span>
            <b class="tabular <?= $tone === 'success' ? 'text-success' : ($tone === 'warning' ? 'text-warning' : ($tone === 'muted' ? 'text-muted-foreground' : '')) ?>"><?= (int) $value ?></b>
          </div>
        <?php endforeach ?>
      </div>
    </section>

    <?php /* ── очередь уведомлений ────────────────────────── */ ?>
    <?php if (($notify['pending'] ?? 0) > 0 || ($notify['failed'] ?? 0) > 0): ?>
      <section class="card">
        <div class="card-header has-action">
          <div class="card-title flex items-center gap-2"><?= $this->icon('send', 'h-4 w-4') ?> Рассылки</div>
          <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/notifications')) ?>">Открыть</a>
        </div>
        <div class="card-content grid gap-2 text-sm">
          <div class="flex justify-between gap-2"><span class="text-muted-foreground">В очереди</span><b class="tabular"><?= (int) ($notify['pending'] ?? 0) ?></b></div>
          <?php if (($notify['failed'] ?? 0) > 0): ?>
            <div class="flex justify-between gap-2"><span class="text-muted-foreground">Ошибок</span><b class="tabular text-destructive"><?= (int) $notify['failed'] ?></b></div>
          <?php endif ?>
          <div class="flex justify-between gap-2"><span class="text-muted-foreground">Отправлено за месяц</span><b class="tabular"><?= (int) ($notify['sent_month'] ?? 0) ?></b></div>
        </div>
      </section>
    <?php endif ?>

    <?php /* ── обновления и здоровье ──────────────────────── */ ?>
    <section class="card">
      <div class="card-header">
        <div class="card-title flex items-center gap-2"><?= $this->icon('activity', 'h-4 w-4') ?> Система</div>
        <div class="card-desc">
          v<?= $this->e($this->shared['version'] ?? '') ?> · <?= $this->e(ucfirst((string) ($health['status'] ?? 'unknown'))) ?>
        </div>
      </div>
      <div class="card-content grid gap-2 text-sm">
        <?php $failedChecks = 0; foreach (($health['checks'] ?? []) as $name => $check) { if (empty($check['ok'])) { $failedChecks++; } } ?>
        <div class="flex justify-between gap-2">
          <span class="text-muted-foreground">Проверок пройдено</span>
          <b class="tabular <?= $failedChecks > 0 ? 'text-warning' : 'text-success' ?>">
            <?= count($health['checks'] ?? []) - $failedChecks ?>/<?= count($health['checks'] ?? []) ?>
          </b>
        </div>
        <?php if (!empty($updateInfo['available'])): ?>
          <div class="alert alert-info -mx-1 px-3 py-2">
            <?= $this->icon('download-cloud', 'h-4 w-4') ?>
            <div class="alert-desc">
              Доступна версия <b><?= $this->e((string) $updateInfo['latest']) ?></b>.
              <a class="font-medium underline underline-offset-4" href="<?= $this->e($this->url('/admin/updates')) ?>">Обновить</a>
            </div>
          </div>
        <?php endif ?>
        <div class="mt-1 grid gap-2">
          <a class="btn btn-outline btn-block btn-sm" href="<?= $this->e($this->url('/admin/system/doctor')) ?>"><?= $this->icon('stethoscope', 'h-3.5 w-3.5') ?> Диагностика</a>
          <a class="btn btn-outline btn-block btn-sm" href="<?= $this->e($this->url('/admin/data')) ?>"><?= $this->icon('database', 'h-3.5 w-3.5') ?> Резервная копия</a>
        </div>
      </div>
    </section>

    <?php /* ── топ по открытиям ───────────────────────────── */ ?>
    <?php if ($topResidents !== []): ?>
      <section class="card">
        <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('trending-up', 'h-4 w-4') ?> Чаще всех открывают</div></div>
        <div class="card-content">
          <?= $this->include('components/chart', [
            'type' => 'ranks',
            'data' => ['items' => array_map(static fn (array $r): array => [
              'label' => 'кв. ' . ($r['apartment'] ?? '—') . ' · ' . \BasIp\Support\Str::initials((string) ($r['full_name'] ?? ''), 1),
              'value' => (int) $r['cnt'],
            ], array_slice($topResidents, 0, 6))],
          ]) ?>
        </div>
      </section>
    <?php endif ?>
  </aside>
</div>
