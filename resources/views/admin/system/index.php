<?php
/**
 * System status: health checks, environment, tables, queue, maintenance.
 *
 * @var array $health
 * @var array $info
 * @var array $tables
 * @var array $queue
 * @var string|null $lastCron
 * @var bool $maintenance
 * @var array $logs
 * @var array $configSources
 * @var bool $canManage
 * @var array $updateInfo
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$checks = (array) ($health['checks'] ?? []);
$failed = array_filter($checks, static fn (array $c): bool => empty($c['ok']));
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Состояние системы</h2>
    <p class="page-desc">
      Статус:
      <span class="badge badge-soft-<?= $health['status'] === 'ok' ? 'success' : ($health['status'] === 'degraded' ? 'warning' : 'destructive') ?>">
        <?= $this->e(strtoupper((string) $health['status'])) ?>
      </span>
      · проверок не пройдено: <b><?= count($failed) ?></b> из <?= count($checks) ?>
    </p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/system/doctor')) ?>"><?= $this->icon('stethoscope', 'h-3.5 w-3.5') ?> Диагностика</a>
    <?php if ($canManage): ?>
      <form method="post" action="<?= $this->e($this->url('/admin/system/cache/clear')) ?>" data-ajax class="inline">
        <?= $this->csrf() ?>
        <button class="btn btn-outline btn-sm" type="submit"><?= $this->icon('eraser', 'h-3.5 w-3.5') ?> Очистить кэш</button>
      </form>
      <form method="post" action="<?= $this->e($this->url('/admin/system/cron')) ?>" data-ajax class="inline">
        <?= $this->csrf() ?>
        <button class="btn btn-outline btn-sm" type="submit"><?= $this->icon('play', 'h-3.5 w-3.5') ?> Запустить задачи cron</button>
      </form>
    <?php endif ?>
  </div>
</div>

<?php if ($maintenance): ?>
  <div class="alert alert-warning mb-5">
    <?= $this->icon('wrench', 'h-4 w-4') ?>
    <div>
      <div class="alert-title">Режим обслуживания включён</div>
      <div class="alert-desc">Веб-интерфейс закрыт для всех, кроме администраторов. Шлагбаум и API продолжают работать.</div>
    </div>
  </div>
<?php endif ?>

<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
  <div class="grid content-start gap-5" data-tabs data-tabs-persist="system" data-tabs-hash>
    <div class="tabs-list is-scrollable" role="tablist">
      <button class="tabs-trigger" role="tab" data-tab="health" data-state="active" aria-selected="true"><?= $this->icon('heart-pulse') ?> Проверки</button>
      <button class="tabs-trigger" role="tab" data-tab="env" data-state="inactive" aria-selected="false"><?= $this->icon('server') ?> Окружение</button>
      <button class="tabs-trigger" role="tab" data-tab="db" data-state="inactive" aria-selected="false"><?= $this->icon('database') ?> База</button>
      <button class="tabs-trigger" role="tab" data-tab="logs" data-state="inactive" aria-selected="false"><?= $this->icon('file-text') ?> Журналы</button>
      <button class="tabs-trigger" role="tab" data-tab="queue" data-state="inactive" aria-selected="false"><?= $this->icon('inbox') ?> Очередь</button>
      <button class="tabs-trigger" role="tab" data-tab="maintenance" data-state="inactive" aria-selected="false"><?= $this->icon('wrench') ?> Обслуживание</button>
    </div>

    <section class="tabs-content" role="tabpanel" data-tab-panel="health">
      <div class="card">
        <div class="card-header">
          <div class="card-title flex items-center gap-2"><?= $this->icon('heart-pulse', 'h-4 w-4') ?> Проверки здоровья</div>
          <div class="card-desc">Та же информация доступна без входа по адресу <a class="text-primary underline underline-offset-4" href="<?= $this->e($this->url('/health')) ?>">/health</a> (для мониторинга) и в формате Prometheus на <a class="text-primary underline underline-offset-4" href="<?= $this->e($this->url('/metrics')) ?>">/metrics</a>.</div>
        </div>
        <div class="card-content p-0">
          <ul class="divide-y">
            <?php foreach ($checks as $name => $check): ?>
              <li class="health-row px-5">
                <span class="health-dot <?= !empty($check['ok']) ? 'ok' : 'fail' ?>"></span>
                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-medium"><?= $this->e((string) $name) ?></span>
                  <?php if (isset($check['message']) && $check['message'] !== null): ?>
                    <span class="block text-xs text-muted-foreground"><?= $this->e((string) $check['message']) ?></span>
                  <?php endif ?>
                </span>
                <span class="shrink-0 font-mono text-xs text-muted-foreground"><?= $this->e(\BasIp\Support\Str::limit((string) ($check['value'] ?? ''), 44)) ?></span>
              </li>
            <?php endforeach ?>
          </ul>
        </div>
      </div>
    </section>

    <section class="tabs-content" role="tabpanel" data-tab-panel="env" hidden>
      <div class="grid gap-5 lg:grid-cols-2">
        <div class="card">
          <div class="card-header"><div class="card-title">Приложение</div></div>
          <div class="card-content grid gap-1.5 text-sm">
            <?php foreach ($info['app'] as $key => $value): ?>
              <div class="flex justify-between gap-3 border-b border-border/40 py-1.5 last:border-0">
                <span class="text-muted-foreground"><?= $this->e($key) ?></span>
                <b class="text-right font-mono text-xs"><?= $this->e($this->describeConfigValue($value)) ?></b>
              </div>
            <?php endforeach ?>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><div class="card-title">PHP</div></div>
          <div class="card-content grid gap-1.5 text-sm">
            <?php foreach (\BasIp\Support\Arr::except($info['php'], ['extensions']) as $key => $value): ?>
              <div class="flex justify-between gap-3 border-b border-border/40 py-1.5 last:border-0">
                <span class="text-muted-foreground"><?= $this->e($key) ?></span>
                <b class="text-right font-mono text-xs"><?= $this->e($this->describeConfigValue($value)) ?></b>
              </div>
            <?php endforeach ?>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><div class="card-title">Сервер</div></div>
          <div class="card-content grid gap-1.5 text-sm">
            <?php foreach ($info['server'] as $key => $value): ?>
              <div class="flex justify-between gap-3 border-b border-border/40 py-1.5 last:border-0">
                <span class="text-muted-foreground"><?= $this->e($key) ?></span>
                <b class="text-right font-mono text-xs"><?= $this->e(is_array($value) ? implode(' ', array_map('strval', $value)) : $this->describeConfigValue($value)) ?></b>
              </div>
            <?php endforeach ?>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><div class="card-title">Расширения PHP</div></div>
          <div class="card-content">
            <div class="flex flex-wrap gap-1.5">
              <?php foreach ($info['php']['extensions'] as $extension): ?>
                <span class="badge badge-outline font-mono text-[11px]"><?= $this->e((string) $extension) ?></span>
              <?php endforeach ?>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="tabs-content" role="tabpanel" data-tab-panel="db" hidden>
      <div class="grid gap-5 lg:grid-cols-2">
        <div class="card">
          <div class="card-header"><div class="card-title">Соединение</div></div>
          <div class="card-content grid gap-1.5 text-sm">
            <?php foreach (\BasIp\Support\Arr::except($info['database'], ['dsn', 'settings']) as $key => $value): ?>
              <div class="flex justify-between gap-3 border-b border-border/40 py-1.5 last:border-0">
                <span class="text-muted-foreground"><?= $this->e($key) ?></span>
                <b class="text-right font-mono text-xs"><?= $this->e($this->describeConfigValue($value)) ?></b>
              </div>
            <?php endforeach ?>
          </div>
        </div>
        <div class="card">
          <div class="card-header has-action">
            <div class="card-title">Таблицы</div>
            <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/system/query-log')) ?>">Запросы</a>
          </div>
          <div class="card-content p-0">
            <div class="table-wrap max-h-96 overflow-auto rounded-none border-0">
              <table class="table">
                <thead><tr><th>Таблица</th><th class="text-right">Строк</th></tr></thead>
                <tbody>
                <?php foreach ($tables as $table => $count): ?>
                  <tr><td class="font-mono text-xs"><?= $this->e((string) $table) ?></td><td class="table-numeric tabular"><?= number_format((int) $count, 0, ',', ' ') ?></td></tr>
                <?php endforeach ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="tabs-content" role="tabpanel" data-tab-panel="logs" hidden>
      <div class="card">
        <div class="card-header has-action">
          <div>
            <div class="card-title flex items-center gap-2"><?= $this->icon('file-text', 'h-4 w-4') ?> Файлы журналов</div>
            <div class="card-desc">storage/logs · формат JSON Lines, удобен для grep и для агрегаторов логов.</div>
          </div>
          <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/system/logs')) ?>">Открыть просмотр</a>
        </div>
        <div class="card-content p-0">
          <?php if ($logs === []): ?>
            <?= $this->include('components/empty', ['title' => 'Журналов пока нет', 'icon' => 'file-text']) ?>
          <?php else: ?>
            <div class="table-wrap rounded-none border-0">
              <table class="table">
                <thead><tr><th>Файл</th><th class="text-right">Размер</th><th class="text-right">Строк</th><th>Изменён</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                  <tr>
                    <td><a class="font-mono text-xs text-primary hover:underline" href="<?= $this->e($this->url('/admin/system/logs/' . rawurlencode((string) $log['name']))) ?>"><?= $this->e((string) $log['name']) ?></a></td>
                    <td class="table-numeric tabular"><?= $this->e(\BasIp\Support\Str::bytes((int) $log['size'])) ?></td>
                    <td class="table-numeric tabular"><?= number_format((int) $log['lines'], 0, ',', ' ') ?></td>
                    <td class="whitespace-nowrap text-xs tabular text-muted-foreground"><?= $this->e($this->datetime((string) $log['mtime'], 'd.m.Y H:i')) ?></td>
                    <td class="text-right">
                      <?php if ($canManage): ?>
                        <form method="post" action="<?= $this->e($this->url('/admin/system/logs/' . rawurlencode((string) $log['name']) . '/truncate')) ?>" class="inline">
                          <?= $this->csrf() ?>
                          <button class="btn btn-ghost btn-sm btn-icon text-destructive" type="submit" aria-label="Очистить"
                                  data-confirm="Очистить журнал <?= $this->e((string) $log['name']) ?>?" data-confirm-label="Очистить" data-confirm-tone="destructive">
                            <?= $this->icon('trash', 'h-3.5 w-3.5') ?>
                          </button>
                        </form>
                      <?php endif ?>
                    </td>
                  </tr>
                <?php endforeach ?>
                </tbody>
              </table>
            </div>
          <?php endif ?>
        </div>
      </div>
    </section>

    <section class="tabs-content" role="tabpanel" data-tab-panel="queue" hidden>
      <div class="card">
        <div class="card-header has-action">
          <div>
            <div class="card-title flex items-center gap-2"><?= $this->icon('inbox', 'h-4 w-4') ?> Очередь уведомлений</div>
            <div class="card-desc">Доставкой занимается работник: <code class="inline-code">bin/bas-ip queue:work</code> (systemd) или <code class="inline-code">schedule:run</code> (cron).</div>
          </div>
          <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/notifications')) ?>">Управление</a>
        </div>
        <div class="card-content grid gap-3">
          <div class="grid gap-3 sm:grid-cols-3">
            <?php foreach (['pending' => 'В очереди', 'processing' => 'Отправляется', 'sent' => 'Отправлено', 'failed' => 'Ошибки', 'skipped' => 'Пропущено', 'cancelled' => 'Отменено'] as $key => $label): ?>
              <div class="rounded-lg border p-3">
                <div class="text-xs text-muted-foreground"><?= $this->e($label) ?></div>
                <div class="mt-0.5 text-xl font-semibold tabular"><?= (int) ($queue[$key] ?? 0) ?></div>
              </div>
            <?php endforeach ?>
          </div>
          <?php if ($canManage): ?>
            <form method="post" action="<?= $this->e($this->url('/admin/notifications/queue/run')) ?>" data-ajax>
              <?= $this->csrf() ?>
              <input type="hidden" name="limit" value="200">
              <button class="btn btn-outline btn-sm" type="submit"><?= $this->icon('play', 'h-3.5 w-3.5') ?> Разобрать до 200 сообщений сейчас</button>
            </form>
          <?php endif ?>
        </div>
      </div>
    </section>

    <section class="tabs-content" role="tabpanel" data-tab-panel="maintenance" hidden>
      <?php if (!$canManage): ?>
        <div class="card"><?= $this->include('components/empty', ['title' => 'Недостаточно прав', 'icon' => 'shield-ban']) ?></div>
      <?php else: ?>
        <div class="grid gap-5 lg:grid-cols-2">
          <div class="card">
            <div class="card-header">
              <div class="card-title flex items-center gap-2"><?= $this->icon('wrench', 'h-4 w-4') ?> Режим обслуживания</div>
              <div class="card-desc">Закрывает веб-интерфейс для всех, кроме администраторов. Шлагбаум и API продолжают работать.</div>
            </div>
            <div class="card-content grid gap-3">
              <form method="post" action="<?= $this->e($this->url('/admin/system/maintenance')) ?>" data-ajax>
                <?= $this->csrf() ?>
                <div class="field">
                  <label class="label" for="maint-message">Сообщение посетителям</label>
                  <input class="input" id="maint-message" name="message" type="text" maxlength="500" placeholder="Ведутся технические работы…">
                </div>
                <div class="mt-3 flex gap-2">
                  <button class="btn btn-warning btn-sm" type="submit" name="on" value="1"><?= $this->icon('pause', 'h-3.5 w-3.5') ?> Включить</button>
                  <button class="btn btn-outline btn-sm" type="submit" name="off" value="1"><?= $this->icon('play', 'h-3.5 w-3.5') ?> Выключить</button>
                </div>
              </form>
              <p class="text-xs text-muted-foreground">Текущее состояние: <?= $maintenance ? '<b class="text-warning">включён</b>' : '<b class="text-success">выключен</b>' ?></p>
            </div>
          </div>

          <div class="card">
            <div class="card-header">
              <div class="card-title flex items-center gap-2"><?= $this->icon('eraser', 'h-4 w-4') ?> Очистка устаревших данных</div>
              <div class="card-desc">Политика хранения. Обычно выполняется автоматически по cron.</div>
            </div>
            <div class="card-content">
              <form method="post" action="<?= $this->e($this->url('/admin/system/prune')) ?>" class="grid gap-3">
                <?= $this->csrf() ?>
                <div class="grid gap-3 sm:grid-cols-2">
                  <?php foreach ([['gate_days', 'Журнал шлагбаума, дней', 730], ['audit_days', 'Аудит, дней', 365], ['login_days', 'Попытки входа, дней', 90], ['outbox_days', 'Отправленные уведомления, дней', 60], ['history_days', 'История начислений, дней', 1825], ['guest_days', 'Закрытые гостевые доступы, дней', 90]] as [$name, $label, $default]): ?>
                    <div class="field">
                      <label class="label" for="prune-<?= $name ?>"><?= $this->e($label) ?></label>
                      <input class="input input-sm" id="prune-<?= $name ?>" name="<?= $name ?>" type="number" min="1" max="3650" value="<?= (int) $default ?>">
                    </div>
                  <?php endforeach ?>
                </div>
                <button class="btn btn-outline btn-sm" type="submit"
                        data-confirm="Удалить устаревшие записи?" data-confirm-label="Очистить" data-confirm-tone="destructive">
                  <?= $this->icon('trash', 'h-3.5 w-3.5') ?> Очистить
                </button>
              </form>
            </div>
          </div>

          <div class="card">
            <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('calendar-check', 'h-4 w-4') ?> Планировщик</div></div>
            <div class="card-content grid gap-3 text-sm">
              <div class="flex justify-between gap-3"><span class="text-muted-foreground">Последний запуск cron</span><b class="tabular"><?= $this->e($this->datetime($lastCron)) ?></b></div>
              <pre class="code-block"><code>* * * * * cd <?= $this->e($this->root()) ?> && php bin/bas-ip schedule:run --queue >> storage/logs/cron.log 2>&1</code></pre>
              <p class="text-xs text-muted-foreground">Задача помечает просроченные счета, отзывает истёкшие гостевые доступы, разбирает очередь уведомлений и чистит временные файлы.</p>
              <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/help/cron')) ?>"><?= $this->icon('book-open', 'h-3.5 w-3.5') ?> Варианты с systemd</a>
            </div>
          </div>

          <div class="card">
            <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('sliders', 'h-4 w-4') ?> Источники конфигурации</div></div>
            <div class="card-content">
              <ol class="grid gap-1 text-xs">
                <?php foreach ($configSources as $index => $source): ?>
                  <li class="flex items-center gap-2 rounded-md border bg-muted/20 px-2.5 py-1.5">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/12 text-[10px] font-semibold text-primary"><?= $index + 1 ?></span>
                    <code class="truncate font-mono"><?= $this->e($source) ?></code>
                  </li>
                <?php endforeach ?>
              </ol>
            </div>
          </div>
        </div>
      <?php endif ?>
    </section>
  </div>

  <aside class="grid content-start gap-5">
    <div class="card">
      <div class="card-header"><div class="card-title">Счётчики</div></div>
      <div class="card-content grid gap-1.5 text-sm">
        <?php foreach ($info['counters'] as $key => $value): ?>
          <div class="flex justify-between gap-3 border-b border-border/40 py-1.5 last:border-0">
            <span class="text-muted-foreground"><?= $this->e($this->counterLabel((string) $key)) ?></span>
            <b class="tabular"><?= number_format((int) $value, 0, ',', ' ') ?></b>
          </div>
        <?php endforeach ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title">Хранилище</div></div>
      <div class="card-content grid gap-1.5 text-sm">
        <?php foreach ($info['storage'] as $key => $value): ?>
          <div class="flex justify-between gap-3 border-b border-border/40 py-1.5 last:border-0">
            <span class="text-muted-foreground"><?= $this->e((string) $key) ?></span><b class="tabular"><?= $this->e((string) $value) ?></b>
          </div>
        <?php endforeach ?>
        <div class="flex justify-between gap-3 pt-1"><span class="text-muted-foreground">Свободно на диске</span><b class="tabular"><?= $this->e($info['server']['disk_free']) ?></b></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('download-cloud', 'h-4 w-4') ?> Версия</div></div>
      <div class="card-content grid gap-2 text-sm">
        <div class="flex justify-between gap-3"><span class="text-muted-foreground">Установлена</span><b><?= $this->e((string) ($updateInfo['current'] ?? '')) ?></b></div>
        <div class="flex justify-between gap-3"><span class="text-muted-foreground">Доступна</span><b><?= $this->e((string) ($updateInfo['latest_version'] ?? '—')) ?></b></div>
        <div class="flex justify-between gap-3"><span class="text-muted-foreground">Проверено</span><b class="text-xs tabular"><?= $this->e($this->datetime($updateInfo['last_check'] ?? null)) ?></b></div>
        <a class="btn btn-outline btn-sm btn-block" href="<?= $this->e($this->url('/admin/updates')) ?>"><?= $this->icon('download-cloud', 'h-3.5 w-3.5') ?> Центр обновлений</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title">Быстрые ссылки</div></div>
      <div class="card-content grid gap-2">
        <a class="btn btn-outline btn-sm justify-start" href="<?= $this->e($this->url('/health?deep=1')) ?>" target="_blank" rel="noopener"><?= $this->icon('heart-pulse', 'h-3.5 w-3.5') ?> /health (с проверкой панели)</a>
        <a class="btn btn-outline btn-sm justify-start" href="<?= $this->e($this->url('/metrics')) ?>" target="_blank" rel="noopener"><?= $this->icon('bar-chart-3', 'h-3.5 w-3.5') ?> /metrics (Prometheus)</a>
        <a class="btn btn-outline btn-sm justify-start" href="<?= $this->e($this->url('/admin/system/phpinfo')) ?>" target="_blank" rel="noopener"><?= $this->icon('info', 'h-3.5 w-3.5') ?> phpinfo()</a>
        <a class="btn btn-outline btn-sm justify-start" href="<?= $this->e($this->url('/api/v1/docs')) ?>" target="_blank" rel="noopener"><?= $this->icon('plug', 'h-3.5 w-3.5') ?> Документация API</a>
      </div>
    </div>
  </aside>
</div>
