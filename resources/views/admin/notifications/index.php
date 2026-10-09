<?php
/**
 * Mass mailings: compose, monitor batches, test channels, control the queue.
 *
 * @var BasIp\Support\Paginator $batches
 * @var BasIp\Support\Paginator $outbox
 * @var array $stats
 * @var array $channels
 * @var array $templates
 * @var array $placeholders
 * @var array $query
 * @var bool $canSend
 * @var bool $canManage
 * @var array $residentsCount
 * @var array $audiences
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$batchItems = $batches->items();
$outboxItems = $outbox->items();
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Рассылки и уведомления</h2>
    <p class="page-desc">
      Очередь доставки: <b class="text-foreground"><?= (int) ($stats['pending'] ?? 0) ?></b> в ожидании,
      <b class="text-foreground"><?= (int) ($stats['sent'] ?? 0) ?></b> отправлено,
      <b class="<?= (int) ($stats['failed'] ?? 0) > 0 ? 'text-destructive' : 'text-foreground' ?>"><?= (int) ($stats['failed'] ?? 0) ?></b> с ошибкой.
    </p>
  </div>
  <div class="page-actions">
    <?php if ($canManage && (int) ($stats['pending'] ?? 0) > 0): ?>
      <form method="post" action="<?= $this->e($this->url('/admin/notifications/queue/run')) ?>" data-ajax class="inline">
        <?= $this->csrf() ?>
        <button class="btn btn-outline btn-sm" type="submit"><?= $this->icon('play', 'h-3.5 w-3.5') ?> Разобрать очередь</button>
      </form>
    <?php endif ?>
    <?php if (($stats['failed'] ?? 0) > 0 && $canSend): ?>
      <form method="post" action="<?= $this->e($this->url('/admin/notifications/batches/0/retry')) ?>" data-ajax class="inline">
        <?= $this->csrf() ?>
        <button class="btn btn-outline btn-sm" type="submit"><?= $this->icon('refresh-cw', 'h-3.5 w-3.5') ?> Повторить ошибки</button>
      </form>
    <?php endif ?>
    <?php if ($canSend): ?>
      <button class="btn btn-default btn-sm" type="button" data-dialog="#dialog-compose"><?= $this->icon('send', 'h-3.5 w-3.5') ?> Новая рассылка</button>
    <?php endif ?>
  </div>
</div>

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
  <?= $this->include('components/stat', ['label' => 'В очереди', 'value' => (string) (int) ($stats['pending'] ?? 0), 'icon' => 'inbox', 'accent' => (int) ($stats['pending'] ?? 0) > 0 ? 'info' : '']) ?>
  <?= $this->include('components/stat', ['label' => 'Отправлено', 'value' => (string) (int) ($stats['sent'] ?? 0), 'icon' => 'check-circle', 'accent' => 'success']) ?>
  <?= $this->include('components/stat', ['label' => 'С ошибками', 'value' => (string) (int) ($stats['failed'] ?? 0), 'icon' => 'alert-circle', 'accent' => (int) ($stats['failed'] ?? 0) > 0 ? 'destructive' : 'success']) ?>
  <?= $this->include('components/stat', ['label' => 'Получателей с контактами', 'value' => (string) (int) ($residentsCount['notify'] ?? 0), 'icon' => 'users', 'hint' => 'e-mail: ' . (int) ($residentsCount['with_email'] ?? 0) . ' · тел: ' . (int) ($residentsCount['with_phone'] ?? 0) . ' · tg: ' . (int) ($residentsCount['with_telegram'] ?? 0)]) ?>
</div>

<div class="grid gap-5 mt-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
  <div class="grid content-start gap-5" data-tabs data-tabs-persist="notifications" data-tabs-hash>
    <div class="tabs-list is-scrollable" role="tablist">
      <button class="tabs-trigger" role="tab" data-tab="batches" data-state="active" aria-selected="true"><?= $this->icon('layers') ?> Рассылки <span class="count"><?= (int) $batches->total() ?></span></button>
      <button class="tabs-trigger" role="tab" data-tab="outbox" data-state="inactive" aria-selected="false"><?= $this->icon('inbox') ?> Очередь</button>
      <button class="tabs-trigger" role="tab" data-tab="channels" data-state="inactive" aria-selected="false"><?= $this->icon('plug') ?> Каналы</button>
      <button class="tabs-trigger" role="tab" data-tab="templates" data-state="inactive" aria-selected="false"><?= $this->icon('file-text') ?> Шаблоны</button>
    </div>

    <section class="tabs-content" role="tabpanel" data-tab-panel="batches">
      <div class="card">
        <div class="card-content p-0">
          <?php if ($batchItems === []): ?>
            <?= $this->include('components/empty', ['title' => 'Рассылок ещё не было', 'description' => 'Создайте первую — например, напоминание об оплате.', 'icon' => 'send']) ?>
          <?php else: ?>
            <div class="table-wrap rounded-none border-0">
              <table class="table">
                <thead><tr><th>Рассылка</th><th>Каналы</th><th class="text-right">Получателей</th><th class="w-44">Прогресс</th><th>Статус</th><th>Создана</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($batchItems as $batch): ?>
                  <?php
                    $recipients = (int) ($batch['recipients'] ?? 0);
                    $sent = (int) ($batch['sent'] ?? 0);
                    $failed = (int) ($batch['failed'] ?? 0);
                    $percent = $recipients > 0 ? round((($sent + $failed) / $recipients) * 100) : 0;
                    $batchChannels = \BasIp\Domain\Model\Entity::decodeJson($batch['channels'] ?? null, []);
                  ?>
                  <tr>
                    <td>
                      <a class="text-sm font-medium hover:underline" href="<?= $this->e($this->url('/admin/notifications/batches/' . (int) $batch['id'])) ?>"><?= $this->e((string) ($batch['name'] ?? 'Без названия')) ?></a>
                      <div class="text-xs text-muted-foreground"><?= $this->e((string) ($batch['template'] ?? '')) ?><?= !empty($batch['charge_id']) ? ' · начисление #' . (int) $batch['charge_id'] : '' ?></div>
                    </td>
                    <td>
                      <div class="flex flex-wrap gap-1">
                        <?php foreach ((array) $batchChannels as $batchChannel): ?><span class="badge badge-outline"><?= $this->e((string) $batchChannel) ?></span><?php endforeach ?>
                      </div>
                    </td>
                    <td class="table-numeric tabular"><?= $recipients ?></td>
                    <td>
                      <div class="progress progress-sm"><div class="progress-bar <?= $failed > 0 ? 'bg-warning' : 'bg-success' ?>" style="width: <?= $percent ?>%"></div></div>
                      <div class="mt-0.5 text-[11px] text-muted-foreground"><?= $sent ?> отправлено<?= $failed > 0 ? ' · ' . $failed . ' ошибок' : '' ?></div>
                    </td>
                    <td>
                      <?php $status = (string) ($batch['status'] ?? 'queued'); ?>
                      <span class="badge badge-soft-<?= ['done' => 'success', 'running' => 'info', 'queued' => 'warning', 'partial' => 'warning', 'failed' => 'destructive', 'cancelled' => 'warning'][$status] ?? 'warning' ?>">
                        <?= $this->e(['done' => 'завершена', 'running' => 'идёт', 'queued' => 'в очереди', 'partial' => 'частично', 'failed' => 'ошибка', 'cancelled' => 'отменена'][$status] ?? $status) ?>
                      </span>
                    </td>
                    <td class="whitespace-nowrap text-xs tabular text-muted-foreground"><?= $this->e($this->datetime($batch['created_at'] ?? null)) ?></td>
                    <td class="text-right">
                      <div class="flex justify-end gap-1">
                        <?php if ($canSend && in_array($status, ['failed', 'partial', 'queued'], true)): ?>
                          <form method="post" action="<?= $this->e($this->url('/admin/notifications/batches/' . (int) $batch['id'] . '/retry')) ?>" data-ajax class="inline">
                            <?= $this->csrf() ?>
                            <button class="btn btn-ghost btn-sm btn-icon" type="submit" aria-label="Повторить" data-tooltip="Повторить ошибки"><?= $this->icon('refresh-cw', 'h-3.5 w-3.5') ?></button>
                          </form>
                          <form method="post" action="<?= $this->e($this->url('/admin/notifications/batches/' . (int) $batch['id'] . '/cancel')) ?>" data-ajax class="inline">
                            <?= $this->csrf() ?>
                            <button class="btn btn-ghost btn-sm btn-icon text-destructive" type="submit" aria-label="Отменить" data-tooltip="Отменить очередь"
                                    data-confirm="Отменить неотправленные сообщения этой рассылки?" data-confirm-label="Отменить" data-confirm-tone="destructive"><?= $this->icon('ban', 'h-3.5 w-3.5') ?></button>
                          </form>
                        <?php endif ?>
                        <a class="btn btn-ghost btn-sm btn-icon" href="<?= $this->e($this->url('/admin/notifications/batches/' . (int) $batch['id'])) ?>" aria-label="Открыть"><?= $this->icon('chevron-right', 'h-3.5 w-3.5') ?></a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach ?>
                </tbody>
              </table>
            </div>
            <div class="p-4"><?= $this->include('components/pagination', ['paginator' => $batches, 'query' => $query, 'target' => '/admin/notifications']) ?></div>
          <?php endif ?>
        </div>
      </div>
    </section>

    <section class="tabs-content" role="tabpanel" data-tab-panel="outbox" hidden>
      <div class="card">
        <div class="table-toolbar">
          <form class="flex flex-wrap items-center gap-2" method="get" action="<?= $this->e($this->url('/admin/notifications')) ?>">
            <select class="select select-sm w-auto" name="status" data-auto-submit>
              <option value="">Все статусы</option>
              <?php foreach (['pending' => 'В очереди', 'processing' => 'Отправляется', 'sent' => 'Отправлено', 'failed' => 'Ошибка', 'skipped' => 'Пропущено', 'cancelled' => 'Отменено'] as $value => $label): ?>
                <option value="<?= $value ?>" <?= $this->selected((string) ($query['status'] ?? ''), $value) ?>><?= $label ?></option>
              <?php endforeach ?>
            </select>
            <select class="select select-sm w-auto" name="channel" data-auto-submit>
              <option value="">Все каналы</option>
              <?php foreach ($channels as $channel): ?>
                <option value="<?= $this->e($channel['name']) ?>" <?= $this->selected((string) ($query['channel'] ?? ''), $channel['name']) ?>><?= $this->e($channel['label']) ?></option>
              <?php endforeach ?>
            </select>
            <input class="input input-sm w-56" type="search" name="q" value="<?= $this->e((string) ($query['q'] ?? '')) ?>" placeholder="Поиск по получателю и тексту">
            <button class="btn btn-outline btn-sm" type="submit"><?= $this->icon('search', 'h-3.5 w-3.5') ?></button>
          </form>
        </div>
        <div class="card-content p-0">
          <?php if ($outboxItems === []): ?>
            <?= $this->include('components/empty', ['title' => 'Очередь пуста', 'icon' => 'inbox']) ?>
          <?php else: ?>
            <div class="table-wrap rounded-none border-0">
              <table class="table">
                <thead><tr><th>Канал</th><th>Получатель</th><th>Житель</th><th>Тема / текст</th><th>Статус</th><th>Попыток</th><th>Когда</th></tr></thead>
                <tbody>
                <?php foreach ($outboxItems as $row): ?>
                  <tr>
                    <td><span class="badge badge-outline"><?= $this->e((string) ($row['channel'] ?? '')) ?></span></td>
                    <td class="max-w-[12rem] truncate font-mono text-xs"><?= $this->e(\BasIp\Support\Str::mask((string) ($row['recipient'] ?? ''))) ?></td>
                    <td class="text-sm">
                      <?php if (!empty($row['resident_name'])): ?>
                        <a class="hover:underline" href="<?= $this->e($this->url('/admin/residents/' . (int) $row['resident_id'])) ?>"><?= $this->e((string) $row['resident_name']) ?></a>
                        <div class="text-xs text-muted-foreground">кв. <?= $this->e((string) ($row['apartment'] ?? '')) ?></div>
                      <?php else: ?><span class="text-muted-foreground">—</span><?php endif ?>
                    </td>
                    <td class="max-w-[24rem]">
                      <div class="truncate text-sm"><?= $this->e((string) ($row['subject'] ?? '')) ?></div>
                      <div class="truncate text-xs text-muted-foreground"><?= $this->e(\BasIp\Support\Str::limit((string) ($row['body'] ?? ''), 90)) ?></div>
                      <?php if (!empty($row['last_error'])): ?><div class="mt-0.5 truncate text-xs text-destructive"><?= $this->e(\BasIp\Support\Str::limit((string) $row['last_error'], 90)) ?></div><?php endif ?>
                    </td>
                    <td>
                      <?php $st = (string) ($row['status'] ?? ''); ?>
                      <span class="badge badge-soft-<?= ['sent' => 'success', 'failed' => 'destructive', 'pending' => 'warning', 'processing' => 'info', 'skipped' => 'warning', 'cancelled' => 'warning'][$st] ?? 'warning' ?>"><?= $this->e($st) ?></span>
                    </td>
                    <td class="table-numeric text-xs tabular"><?= (int) ($row['attempts'] ?? 0) ?>/<?= (int) ($row['max_attempts'] ?? 5) ?></td>
                    <td class="whitespace-nowrap text-xs tabular text-muted-foreground"><?= $this->e($this->datetime($row['sent_at'] ?? $row['created_at'] ?? null, 'd.m H:i')) ?></td>
                  </tr>
                <?php endforeach ?>
                </tbody>
              </table>
            </div>
            <div class="p-4"><?= $this->include('components/pagination', ['paginator' => $outbox, 'query' => $query + ['outbox_page' => null], 'target' => '/admin/notifications']) ?></div>
          <?php endif ?>
        </div>
      </div>
    </section>

    <section class="tabs-content" role="tabpanel" data-tab-panel="channels" hidden>
      <div class="grid gap-4 lg:grid-cols-2">
        <?php foreach ($channels as $channel): ?>
          <div class="card">
            <div class="card-header has-action">
              <div>
                <div class="card-title flex items-center gap-2"><?= $this->icon($channel['icon'] ?? 'send', 'h-4 w-4') ?> <?= $this->e($channel['label']) ?></div>
                <div class="card-desc"><code class="inline-code"><?= $this->e($channel['name']) ?></code></div>
              </div>
              <?= !empty($channel['enabled']) ? '<span class="badge badge-soft-success">включён</span>' : '<span class="badge badge-muted">выключен</span>' ?>
            </div>
            <div class="card-content grid gap-3">
              <?php if (($channel['fields'] ?? []) !== []): ?>
                <dl class="grid gap-1.5 text-xs">
                  <?php foreach ($channel['fields'] as $field): ?>
                    <div class="flex justify-between gap-3">
                      <dt class="text-muted-foreground"><?= $this->e($field['label']) ?></dt>
                      <dd>
                        <?php $key = 'notify.' . $channel['name'] . '.' . $field['key']; ?>
                        <?php $current = $this->setting($key); ?>
                        <?php if (($field['type'] ?? '') === 'secret' || ($field['type'] ?? '') === 'password'): ?>
                          <span class="badge badge-soft-<?= $current !== null && $current !== '' ? 'success' : 'warning' ?>"><?= $current !== null && $current !== '' ? 'задан' : 'не задан' ?></span>
                        <?php elseif ($field['type'] === 'bool'): ?>
                          <?= $current ? '<span class="badge badge-soft-success">да</span>' : '<span class="badge badge-muted">нет</span>' ?>
                        <?php else: ?>
                          <span class="truncate font-mono"><?= $this->e(\BasIp\Support\Str::limit((string) ($current ?? ''), 30) ?: '—') ?></span>
                        <?php endif ?>
                      </dd>
                    </div>
                  <?php endforeach ?>
                </dl>
              <?php endif ?>
              <div class="flex flex-wrap items-end gap-2 border-t pt-3">
                <div class="field flex-1">
                  <label class="label" for="test-to-<?= $this->e($channel['name']) ?>">Проверить доставку на</label>
                  <input class="input input-sm" id="test-to-<?= $this->e($channel['name']) ?>" type="text"
                         data-channel-recipient="<?= $this->e($channel['name']) ?>"
                         placeholder="<?= $channel['name'] === 'mail' ? 'you@example.com' : ($channel['name'] === 'telegram' ? '@username или chat id' : '+79…') ?>">
                </div>
                <button class="btn btn-outline btn-sm" type="button"
                        data-channel-test="<?= $this->e($channel['name']) ?>"
                        data-channel-test-url="<?= $this->e($this->url('/admin/notifications/test')) ?>">
                  <?= $this->icon('send', 'h-3.5 w-3.5') ?> Отправить тест
                </button>
                <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/settings/notify')) ?>"><?= $this->icon('settings', 'h-3.5 w-3.5') ?> Настроить</a>
              </div>
            </div>
          </div>
        <?php endforeach ?>
      </div>
    </section>

    <section class="tabs-content" role="tabpanel" data-tab-panel="templates" hidden>
      <div class="card">
        <div class="card-header">
          <div class="card-title flex items-center gap-2"><?= $this->icon('file-text', 'h-4 w-4') ?> Шаблоны и подстановки</div>
          <div class="card-desc">Шаблоны задаются в <code class="inline-code">config/config.php</code> (секция <code class="inline-code">notify.templates</code>). Подстановки заменяются данными жителя при отправке.</div>
        </div>
        <div class="card-content grid gap-4">
          <div class="grid gap-4 md:grid-cols-2">
            <?php foreach ($templates as $name => $template): ?>
              <div class="rounded-lg border p-3">
                <div class="flex items-center justify-between gap-2">
                  <code class="font-mono text-xs font-semibold"><?= $this->e((string) $name) ?></code>
                  <button class="btn btn-ghost btn-sm" type="button" data-use-template="<?= $this->e((string) $name) ?>" data-dialog="#dialog-compose"><?= $this->icon('pencil', 'h-3 w-3') ?> Использовать</button>
                </div>
                <div class="mt-1.5 text-xs font-medium"><?= $this->e((string) ($template['subject'] ?? '')) ?></div>
                <pre class="mt-1 max-h-24 overflow-auto whitespace-pre-wrap font-sans text-xs text-muted-foreground"><?= $this->e((string) ($template['body'] ?? '')) ?></pre>
              </div>
            <?php endforeach ?>
          </div>

          <div>
            <div class="label mb-2">Доступные подстановки</div>
            <div class="flex flex-wrap gap-1.5">
              <?php foreach ($placeholders as $token => $hint): ?>
                <span class="badge badge-outline" data-tooltip="<?= $this->e($hint) ?>"><code class="font-mono text-[11px]"><?= $this->e($token) ?></code></span>
              <?php endforeach ?>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>

  <aside class="grid content-start gap-5">
    <div class="card">
      <div class="card-header"><div class="card-title">Как это работает</div></div>
      <div class="card-content">
        <ol class="grid gap-2.5 text-sm text-muted-foreground">
          <li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/12 text-[11px] font-semibold text-primary">1</span> Сообщения складываются в таблицу <code class="inline-code">outbox</code> — веб-запрос не ждёт доставки.</li>
          <li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/12 text-[11px] font-semibold text-primary">2</span> Работник (<code class="inline-code">queue:work</code> или <code class="inline-code">schedule:run</code> из cron) разбирает очередь.</li>
          <li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/12 text-[11px] font-semibold text-primary">3</span> При ошибке — повтор с нарастающей паузой (60 с → 3 ч). После исчерпания попыток статус «failed».</li>
          <li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/12 text-[11px] font-semibold text-primary">4</span> Отключённые жители и те, у кого выключены уведомления, в рассылку не попадают.</li>
        </ol>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title">Планировщик</div></div>
      <div class="card-content grid gap-2 text-sm">
        <p class="text-muted-foreground">Добавьте в crontab:</p>
        <pre class="code-block"><code>* * * * * cd <?= $this->e($this->root()) ?> && php bin/bas-ip schedule:run --queue >> storage/logs/cron.log 2>&1</code></pre>
        <p class="text-xs text-muted-foreground">Последний запуск: <b><?= $this->e($this->datetime($this->setting('system.last_cron'))) ?></b></p>
        <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/help/cron')) ?>"><?= $this->icon('book-open', 'h-3.5 w-3.5') ?> Настройка cron и systemd</a>
      </div>
    </div>
  </aside>
</div>

<?php /* ═══════════ диалог: новая рассылка ═══════════ */ ?>
<?php if ($canSend): ?>
  <div class="dialog dialog-xl" id="dialog-compose" data-state="closed" hidden role="dialog" aria-labelledby="cmp-t">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <form method="post" action="<?= $this->e($this->url('/admin/notifications/compose')) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/notifications')) ?>">
      <?= $this->csrf() ?>
      <div class="dialog-header">
        <h2 class="dialog-title" id="cmp-t">Новая рассылка</h2>
        <p class="dialog-desc">Отправится только активным жителям с включёнными уведомлениями. Сначала можно сделать предпросмотр.</p>
      </div>
      <div class="grid gap-4">
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="cmp-name">Название рассылки</label>
            <input class="input" id="cmp-name" name="name" type="text" maxlength="190" value="Рассылка <?= $this->e(date('d.m.Y H:i')) ?>">
          </div>
          <div class="field">
            <label class="label" for="cmp-audience">Кому</label>
            <select class="select" id="cmp-audience" name="audience">
              <?php foreach ($audiences as $value => $label): ?>
                <option value="<?= $this->e($value) ?>"><?= $this->e($label) ?></option>
              <?php endforeach ?>
            </select>
          </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-[10rem_minmax(0,1fr)]">
          <div class="field">
            <label class="label" for="cmp-template">Шаблон</label>
            <select class="select" id="cmp-template" name="template">
              <option value="custom">— свой текст —</option>
              <?php foreach (array_keys($templates) as $name): ?>
                <option value="<?= $this->e($name) ?>"><?= $this->e($name) ?></option>
              <?php endforeach ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="cmp-subject">Тема</label>
            <input class="input" id="cmp-subject" name="subject" type="text" maxlength="255" value="Информация от управляющего шлагбаумом">
          </div>
        </div>

        <div class="field">
          <label class="label" for="cmp-message">Текст сообщения</label>
          <textarea class="textarea font-mono text-[13px] leading-relaxed" id="cmp-message" name="message" rows="8" required
                    placeholder="Здравствуйте, {name}!&#10;&#10;…"></textarea>
          <div class="mt-2">
            <div class="label mb-1.5">Вставить подстановку</div>
            <div class="flex flex-wrap gap-1.5">
              <?php foreach ($placeholders as $token => $hint): ?>
                <button type="button" class="chip" data-placeholder-insert="<?= $this->e($token) ?>" data-placeholder-target="#cmp-message" data-tooltip="<?= $this->e($hint) ?>">
                  <code class="font-mono text-[11px]"><?= $this->e($token) ?></code>
                </button>
              <?php endforeach ?>
            </div>
          </div>
        </div>

        <div class="field">
          <div class="label mb-1.5">Каналы доставки</div>
          <div class="grid gap-1.5 sm:grid-cols-2">
            <?php foreach ($channels as $channel): ?>
              <label class="flex items-center gap-2 rounded-lg border p-2.5 text-sm <?= empty($channel['enabled']) ? 'opacity-60' : '' ?>">
                <input type="checkbox" class="checkbox" name="channels[]" value="<?= $this->e($channel['name']) ?>" <?= empty($channel['enabled']) ? 'disabled' : '' ?> <?= in_array($channel['name'], ['mail', 'telegram'], true) ? 'checked' : '' ?>>
                <?= $this->icon($channel['icon'] ?? 'send', 'h-4 w-4 text-muted-foreground') ?>
                <span class="flex-1"><?= $this->e($channel['label']) ?></span>
                <?php if (empty($channel['enabled'])): ?><span class="badge badge-muted">не настроен</span><?php endif ?>
              </label>
            <?php endforeach ?>
          </div>
        </div>

        <div class="grid gap-3 rounded-lg border bg-muted/25 p-3 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="cmp-schedule">Отложить до</label>
            <input class="input" id="cmp-schedule" name="scheduled_at" type="datetime-local">
            <p class="text-xs text-muted-foreground">Пусто — отправить как можно скорее.</p>
          </div>
          <div class="flex items-end">
            <label class="checkbox-row text-sm">
              <input class="checkbox" type="checkbox" name="dry_run" value="1">
              <span>Только предпросмотр <span class="label-hint">Покажет, сколько получателей и как выглядит письмо.</span></span>
            </label>
          </div>
        </div>
      </div>
      <div class="dialog-footer mt-4">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="submit" class="btn btn-default" data-confirm="Поставить рассылку в очередь?" data-confirm-title="Отправка рассылки" data-confirm-label="Отправить">
          <?= $this->icon('send') ?> Отправить
        </button>
      </div>
    </form>
  </div>
<?php endif ?>

<?php $this->startPush('scripts') ?>
<script nonce="<?= $this->e($this->nonce()) ?>">
(function () {
  var B = window.BasIp;
  if (!B) return;

  // Вставка подстановок в произвольное поле.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-placeholder-target]');
    if (!btn) return;
    e.preventDefault();
    var target = document.querySelector(btn.getAttribute('data-placeholder-target'));
    if (!target) return;
    var token = btn.getAttribute('data-placeholder-insert');
    var pos = target.selectionStart || target.value.length;
    target.value = target.value.slice(0, pos) + token + target.value.slice(pos);
    target.focus();
    target.setSelectionRange(pos + token.length, pos + token.length);
  });

  // Выбор шаблона подставляет тему и текст.
  var tplSelect = document.getElementById('cmp-template');
  if (tplSelect) {
    tplSelect.addEventListener('change', function () {
      var tpl = (window.__BASIP.templates || {})[tplSelect.value];
      if (!tpl) return;
      var subject = document.getElementById('cmp-subject');
      var message = document.getElementById('cmp-message');
      if (subject && (!subject.value || subject.dataset.touched !== '1')) subject.value = tpl.subject || '';
      if (message && (!message.value.trim() || message.dataset.touched !== '1')) message.value = tpl.body || '';
    });
  }
  var message = document.getElementById('cmp-message');
  if (message) message.addEventListener('input', function () { message.dataset.touched = '1'; });
  var subject = document.getElementById('cmp-subject');
  if (subject) subject.addEventListener('input', function () { subject.dataset.touched = '1'; });

  // Кнопка «Использовать» во вкладке шаблонов.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-use-template]');
    if (!btn || !tplSelect) return;
    tplSelect.value = btn.getAttribute('data-use-template');
    tplSelect.dispatchEvent(new Event('change'));
  });
})();
</script>
<?php $this->endPush() ?>
