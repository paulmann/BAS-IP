<?php
/**
 * Device management: panels / relays, credentials, channels, health.
 *
 * @var array<int, BasIp\Domain\Model\Device> $devices
 * @var array $statuses
 * @var array $drivers
 * @var string|null $defaultSlug
 * @var array $configDevice
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Устройства</h2>
    <p class="page-desc">Панели BAS-IP, универсальные HTTP-реле и эмулятор. Пароли хранятся в базе в зашифрованном виде (ключ — <code class="inline-code">app.key</code>).</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-default btn-sm" type="button" data-dialog="#dialog-device-new"><?= $this->icon('plus', 'h-3.5 w-3.5') ?> Добавить устройство</button>
  </div>
</div>

<?php if ($devices === []): ?>
  <div class="card">
    <?= $this->include('components/empty', [
      'title' => 'Устройства не настроены',
      'description' => 'Добавьте панель BAS-IP (укажите её адрес и учётные данные) или универсальное HTTP-реле. Для демонстрации подойдёт драйвер-эмулятор.',
      'icon' => 'cpu',
    ]) ?>
  </div>
<?php else: ?>
  <div class="grid gap-4 lg:grid-cols-2">
    <?php foreach ($devices as $device): ?>
      <?php $lastStatus = $statuses[$device->slug] ?? []; ?>
      <div class="card">
        <div class="card-header has-action">
          <div class="min-w-0">
            <div class="card-title flex flex-wrap items-center gap-2">
              <?= $this->icon('cpu', 'h-4 w-4') ?>
              <span class="truncate"><?= $this->e($device->label()) ?></span>
              <?php if ($device->isDefault): ?><span class="badge badge-soft-info">по умолчанию</span><?php endif ?>
              <?= $device->isActive ? '' : '<span class="badge badge-muted">выключено</span>' ?>
              <span class="health-dot <?= !empty($lastStatus['ok']) ? 'ok' : ($lastStatus === [] ? 'warn' : 'fail') ?>"></span>
            </div>
            <div class="card-desc"><?= $this->e($device->driverLabel()) ?></div>
          </div>
          <div class="flex shrink-0 gap-1">
            <button class="btn btn-outline btn-sm btn-icon" type="button"
                    data-device-command="test" data-device-url="<?= $this->e($this->url('/admin/gate/devices/' . $device->id . '/test')) ?>"
                    aria-label="Проверить связь" data-tooltip="Проверить связь"><?= $this->icon('wifi', 'h-3.5 w-3.5') ?></button>
            <button class="btn btn-outline btn-sm btn-icon" type="button" data-dialog="#dialog-device-<?= (int) $device->id ?>" aria-label="Настроить" data-tooltip="Настроить"><?= $this->icon('settings', 'h-3.5 w-3.5') ?></button>
          </div>
        </div>
        <div class="card-content grid gap-3">
          <dl class="grid gap-1.5 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Адрес</dt><dd class="truncate font-mono text-xs"><?= $this->e($device->baseUrl ?? 'не задан') ?></dd></div>
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Логин</dt><dd class="font-mono text-xs"><?= $this->e($device->login ?? '—') ?></dd></div>
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Пароль</dt><dd><?= $device->passwordEnc !== null ? '<span class="badge badge-soft-success">задан (зашифрован)</span>' : '<span class="badge badge-muted">не задан</span>' ?></dd></div>
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Канал реле</dt><dd class="tabular"><?= (int) $device->channel ?></dd></div>
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Расположение</dt><dd class="truncate"><?= $this->e($device->location ?? '—') ?></dd></div>
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Slug</dt><dd class="font-mono text-xs"><?= $this->e($device->slug) ?></dd></div>
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Последний опрос</dt><dd class="text-xs tabular"><?= $this->e($this->datetime($device->lastSeenAt)) ?></dd></div>
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Ошибок подряд</dt><dd class="tabular <?= $device->errorCount > 0 ? 'text-destructive' : '' ?>"><?= (int) $device->errorCount ?></dd></div>
          </dl>

          <?php if (!empty($lastStatus)): ?>
            <div class="rounded-lg border bg-muted/25 p-3 text-xs">
              <div class="font-medium">Последний статус</div>
              <pre class="mt-1 max-h-32 overflow-auto whitespace-pre-wrap font-mono text-[11px] text-muted-foreground"><?= $this->e(json_encode($lastStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
            </div>
          <?php endif ?>

          <?php if ($device->notes !== null && $device->notes !== ''): ?>
            <p class="text-xs text-muted-foreground"><?= $this->e($device->notes) ?></p>
          <?php endif ?>

          <div class="flex flex-wrap gap-2 border-t pt-3">
            <button class="btn btn-outline btn-sm" type="button" data-device-command="open"
                    data-device-url="<?= $this->e($this->url('/admin/gate/open')) ?>"
                    data-device-body='{"device":"<?= $this->e($device->slug) ?>"}'
                    data-confirm="Открыть «<?= $this->e($device->label()) ?>»?" data-confirm-label="Открыть">
              <?= $this->icon('door-open', 'h-3.5 w-3.5') ?> Открыть
            </button>
            <button class="btn btn-outline btn-sm" type="button" data-device-command="reboot"
                    data-device-url="<?= $this->e($this->url('/admin/gate/reboot')) ?>"
                    data-device-body='{"device":"<?= $this->e($device->slug) ?>"}'
                    data-confirm="Перезагрузить панель?" data-confirm-label="Перезагрузить" data-confirm-tone="destructive">
              <?= $this->icon('power', 'h-3.5 w-3.5') ?> Перезагрузка
            </button>
            <form method="post" action="<?= $this->e($this->url('/admin/gate/devices/' . $device->id . '/delete')) ?>" class="ml-auto inline">
              <?= $this->csrf() ?>
              <button class="btn btn-ghost btn-sm text-destructive hover:bg-destructive/10" type="submit"
                      data-confirm="Удалить устройство «<?= $this->e($device->label()) ?>»?" data-confirm-label="Удалить" data-confirm-tone="destructive">
                <?= $this->icon('trash', 'h-3.5 w-3.5') ?> Удалить
              </button>
            </form>
          </div>
        </div>
      </div>

      <?php /* диалог редактирования */ ?>
      <div class="dialog" id="dialog-device-<?= (int) $device->id ?>" data-state="closed" hidden role="dialog" aria-labelledby="dev-t-<?= (int) $device->id ?>">
        <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
        <form method="post" action="<?= $this->e($this->url('/admin/gate/devices/' . $device->id)) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/gate/devices')) ?>">
          <?= $this->csrf() ?>
          <div class="dialog-header">
            <h2 class="dialog-title" id="dev-t-<?= (int) $device->id ?>">Настройка устройства</h2>
            <p class="dialog-desc">Пароль можно оставить пустым — тогда сохранится текущий.</p>
          </div>
          <?= $this->include('admin/gate/_device-form', ['device' => $device, 'drivers' => $drivers]) ?>
          <div class="dialog-footer mt-4">
            <button type="button" class="btn btn-outline" data-close>Отмена</button>
            <button type="submit" class="btn btn-default"><?= $this->icon('save') ?> Сохранить</button>
          </div>
        </form>
      </div>
    <?php endforeach ?>
  </div>
<?php endif ?>

<?php /* ── новое устройство ───────────────────────────────── */ ?>
<div class="dialog" id="dialog-device-new" data-state="closed" hidden role="dialog" aria-labelledby="dev-new-t">
  <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
  <form method="post" action="<?= $this->e($this->url('/admin/gate/devices')) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/gate/devices')) ?>">
    <?= $this->csrf() ?>
    <div class="dialog-header">
      <h2 class="dialog-title" id="dev-new-t">Новое устройство</h2>
      <p class="dialog-desc">Панель BAS-IP, универсальное HTTP-реле или эмулятор для проверки без железа.</p>
    </div>
    <?= $this->include('admin/gate/_device-form', ['device' => null, 'drivers' => $drivers]) ?>
    <div class="dialog-footer mt-4">
      <button type="button" class="btn btn-outline" data-close>Отмена</button>
      <button type="submit" class="btn btn-default"><?= $this->icon('plus') ?> Добавить</button>
    </div>
  </form>
</div>

<div class="alert alert-info mt-5">
  <?= $this->icon('info', 'h-4 w-4') ?>
  <div>
    <div class="alert-title">Где взять адрес и пароль панели</div>
    <div class="alert-desc">
      Панель BAS-IP доступна по адресу вида <code class="inline-code">https://192.168.1.50/api/v0</code>.
      Учётные данные — те, что заданы в веб-интерфейсе панели (по умолчанию <code class="inline-code">admin</code>).
      Если сертификат самоподписанный, отключите проверку TLS в дополнительных параметрах (<code class="inline-code">verify_tls: false</code>).
      Проверить связь можно кнопкой <?= $this->icon('wifi', 'h-3.5 w-3.5') ?> или командой <code class="inline-code">bin/bas-ip gate:test</code>.
    </div>
  </div>
</div>
