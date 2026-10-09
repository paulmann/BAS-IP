<?php
/**
 * One mailing batch: statistics + per-message delivery log.
 *
 * @var array $batch
 * @var BasIp\Support\Paginator $messages
 * @var array $query
 * @var bool $canSend
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$items = $messages->items();
$statsBatch = (array) ($batch['stats'] ?? []);
$total = max(1, (int) ($batch['recipients'] ?? 1));
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title"><?= $this->e((string) ($batch['name'] ?? 'Рассылка')) ?></h2>
    <p class="page-desc">
      Шаблон: <code class="inline-code"><?= $this->e((string) ($batch['template'] ?? 'custom')) ?></code>
      · создана <?= $this->e($this->datetime($batch['created_at'] ?? null)) ?>
      <?php if (!empty($batch['charge_id'])): ?> · начисление <a class="text-primary underline underline-offset-4" href="<?= $this->e($this->url('/admin/billing/charges/' . (int) $batch['charge_id'])) ?>">#<?= (int) $batch['charge_id'] ?></a><?php endif ?>
    </p>
  </div>
  <div class="page-actions">
    <?php if ($canSend && (int) ($statsBatch['failed'] ?? 0) > 0): ?>
      <form method="post" action="<?= $this->e($this->url('/admin/notifications/batches/' . (int) $batch['id'] . '/retry')) ?>" data-ajax class="inline">
        <?= $this->csrf() ?>
        <button class="btn btn-outline btn-sm" type="submit"><?= $this->icon('refresh-cw', 'h-3.5 w-3.5') ?> Повторить ошибки (<?= (int) $statsBatch['failed'] ?>)</button>
      </form>
    <?php endif ?>
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/notifications')) ?>"><?= $this->icon('arrow-left', 'h-3.5 w-3.5') ?> К рассылкам</a>
  </div>
</div>

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
  <?= $this->include('components/stat', ['label' => 'Получателей', 'value' => (string) (int) ($batch['recipients'] ?? 0), 'icon' => 'users']) ?>
  <?= $this->include('components/stat', ['label' => 'Отправлено', 'value' => (string) (int) ($batch['sent'] ?? 0), 'icon' => 'check-circle', 'accent' => 'success', 'hint' => round(((int) ($batch['sent'] ?? 0) / $total) * 100) . '%']) ?>
  <?= $this->include('components/stat', ['label' => 'Ошибок', 'value' => (string) (int) ($batch['failed'] ?? 0), 'icon' => 'alert-circle', 'accent' => (int) ($batch['failed'] ?? 0) > 0 ? 'destructive' : 'success']) ?>
  <?= $this->include('components/stat', ['label' => 'Пропущено', 'value' => (string) (int) ($batch['skipped'] ?? 0), 'icon' => 'user-x', 'hint' => 'отключены или нет контактов']) ?>
  <?= $this->include('components/stat', ['label' => 'В очереди', 'value' => (string) (int) ($statsBatch['pending'] ?? 0), 'icon' => 'inbox', 'accent' => (int) ($statsBatch['pending'] ?? 0) > 0 ? 'warning' : '']) ?>
</div>

<?php if (!empty($batch['message'])): ?>
  <div class="card mt-5">
    <div class="card-header">
      <div class="card-title flex items-center gap-2"><?= $this->icon('message-square-text', 'h-4 w-4') ?> Текст рассылки</div>
      <?php if (!empty($batch['subject'])): ?><div class="card-desc">Тема: <?= $this->e((string) $batch['subject']) ?></div><?php endif ?>
    </div>
    <div class="card-content">
      <pre class="whitespace-pre-wrap rounded-lg border bg-muted/30 p-3 font-sans text-sm"><?= $this->e((string) $batch['message']) ?></pre>
      <?php if (!empty($batch['pay_link'])): ?>
        <p class="mt-2 text-xs text-muted-foreground">Ссылка на оплату: <a class="font-mono text-primary underline underline-offset-4 break-all" href="<?= $this->e((string) $batch['pay_link']) ?>" target="_blank" rel="noopener noreferrer"><?= $this->e((string) $batch['pay_link']) ?></a></p>
      <?php endif ?>
    </div>
  </div>
<?php endif ?>

<div class="card mt-5">
  <div class="table-toolbar">
    <div class="text-sm font-medium">Доставка по получателям</div>
    <form class="ml-auto flex items-center gap-2" method="get" action="<?= $this->e($this->url('/admin/notifications/batches/' . (int) $batch['id'])) ?>">
      <select class="select select-sm w-auto" name="status" data-auto-submit>
        <option value="">Все статусы</option>
        <?php foreach (['pending' => 'В очереди', 'processing' => 'Отправляется', 'sent' => 'Отправлено', 'failed' => 'Ошибка', 'skipped' => 'Пропущено'] as $value => $label): ?>
          <option value="<?= $value ?>" <?= $this->selected((string) ($query['status'] ?? ''), $value) ?>><?= $label ?></option>
        <?php endforeach ?>
      </select>
      <select class="select select-sm w-auto" name="channel" data-auto-submit>
        <option value="">Все каналы</option>
        <?php foreach (['log', 'mail', 'telegram', 'sms', 'webhook', 'whatsapp'] as $channel): ?>
          <option value="<?= $channel ?>" <?= $this->selected((string) ($query['channel'] ?? ''), $channel) ?>><?= $channel ?></option>
        <?php endforeach ?>
      </select>
    </form>
  </div>
  <div class="card-content p-0">
    <?php if ($items === []): ?>
      <?= $this->include('components/empty', ['title' => 'Сообщений нет', 'icon' => 'inbox']) ?>
    <?php else: ?>
      <div class="table-wrap rounded-none border-0">
        <table class="table">
          <thead><tr><th>Канал</th><th>Получатель</th><th>Житель</th><th>Сообщение</th><th>Статус</th><th>Попыток</th><th>Ошибка / ID</th><th>Когда</th></tr></thead>
          <tbody>
          <?php foreach ($items as $row): ?>
            <tr>
              <td><span class="badge badge-outline"><?= $this->e((string) ($row['channel'] ?? '')) ?></span></td>
              <td class="max-w-[12rem] truncate font-mono text-xs" data-tooltip="<?= $this->e((string) ($row['recipient'] ?? '')) ?>"><?= $this->e((string) ($row['recipient'] ?? '')) ?></td>
              <td class="text-sm">
                <?php if (!empty($row['resident_name'])): ?>
                  <a class="hover:underline" href="<?= $this->e($this->url('/admin/residents/' . (int) $row['resident_id'])) ?>"><?= $this->e((string) $row['resident_name']) ?></a>
                  <div class="text-xs text-muted-foreground">кв. <?= $this->e((string) ($row['apartment'] ?? '')) ?></div>
                <?php else: ?><span class="text-muted-foreground">—</span><?php endif ?>
              </td>
              <td class="max-w-[22rem]">
                <div class="truncate text-sm"><?= $this->e((string) ($row['subject'] ?? '')) ?></div>
                <div class="truncate text-xs text-muted-foreground"><?= $this->e(\BasIp\Support\Str::limit((string) ($row['body'] ?? ''), 80)) ?></div>
              </td>
              <td>
                <?php $st = (string) ($row['status'] ?? ''); ?>
                <span class="badge badge-soft-<?= ['sent' => 'success', 'failed' => 'destructive', 'pending' => 'warning', 'processing' => 'info', 'skipped' => 'warning', 'cancelled' => 'warning'][$st] ?? 'warning' ?>"><?= $this->e($st) ?></span>
              </td>
              <td class="table-numeric text-xs tabular"><?= (int) ($row['attempts'] ?? 0) ?>/<?= (int) ($row['max_attempts'] ?? 5) ?></td>
              <td class="max-w-[16rem] truncate text-xs <?= !empty($row['last_error']) ? 'text-destructive' : 'text-muted-foreground' ?>"><?= $this->e((string) ($row['last_error'] ?? $row['provider_id'] ?? '—')) ?></td>
              <td class="whitespace-nowrap text-xs tabular text-muted-foreground"><?= $this->e($this->datetime($row['sent_at'] ?? $row['created_at'] ?? null, 'd.m H:i:s')) ?></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <div class="p-4"><?= $this->include('components/pagination', ['paginator' => $messages, 'query' => $query, 'target' => '/admin/notifications/batches/' . (int) $batch['id']]) ?></div>
    <?php endif ?>
  </div>
</div>
