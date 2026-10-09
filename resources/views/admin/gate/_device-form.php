<?php
/**
 * Device form fields (shared by the "new" and "edit" dialogs).
 *
 * @var BasIp\Domain\Model\Device|null $device
 * @var array<string, string> $drivers
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$d = $device;
?>
<div class="grid gap-3">
  <div class="grid gap-3 sm:grid-cols-2">
    <div class="field">
      <label class="label" for="dev-name-<?= (int) ($d?->id ?? 0) ?>">Название</label>
      <input class="input" id="dev-name-<?= (int) ($d?->id ?? 0) ?>" name="name" type="text" maxlength="120" required
             value="<?= $this->e($d?->name ?? 'Основной шлагбаум') ?>" placeholder="Основной шлагбаум">
    </div>
    <div class="field">
      <label class="label" for="dev-slug-<?= (int) ($d?->id ?? 0) ?>">Slug</label>
      <input class="input input-mono" id="dev-slug-<?= (int) ($d?->id ?? 0) ?>" name="slug" type="text" maxlength="64"
             value="<?= $this->e($d?->slug ?? 'main') ?>" placeholder="main">
      <p class="text-xs text-muted-foreground">Латиница, цифры, дефис. Используется в URL и в API.</p>
    </div>
  </div>

  <div class="field">
    <label class="label" for="dev-driver-<?= (int) ($d?->id ?? 0) ?>">Драйвер</label>
    <select class="select" id="dev-driver-<?= (int) ($d?->id ?? 0) ?>" name="driver">
      <?php foreach ($drivers as $value => $label): ?>
        <option value="<?= $this->e($value) ?>" <?= $this->selected($d?->driver ?? 'basip', $value) ?>><?= $this->e($label) ?></option>
      <?php endforeach ?>
    </select>
    <p class="text-xs text-muted-foreground">
      <b>basip</b> — панель BAS-IP Camdroid (<code class="inline-code">/api/v0</code>).
      <b>http-relay</b> — любое HTTP-реле (NetPing, Sonoff, ESP32).
      <b>dryrun</b> — эмулятор: команды «выполняются» без железа, удобно для проверки настроек и обучения.
    </p>
  </div>

  <div class="grid gap-3 sm:grid-cols-2">
    <div class="field">
      <label class="label" for="dev-url-<?= (int) ($d?->id ?? 0) ?>">Базовый URL</label>
      <input class="input input-mono" id="dev-url-<?= (int) ($d?->id ?? 0) ?>" name="base_url" type="text" maxlength="255"
             value="<?= $this->e($d?->baseUrl ?? '') ?>" placeholder="https://192.168.1.50/api/v0">
    </div>
    <div class="field">
      <label class="label" for="dev-channel-<?= (int) ($d?->id ?? 0) ?>">Канал реле</label>
      <input class="input" id="dev-channel-<?= (int) ($d?->id ?? 0) ?>" name="channel" type="number" min="1" max="16"
             value="<?= (int) ($d?->channel ?? 1) ?>">
    </div>
  </div>

  <div class="grid gap-3 sm:grid-cols-2">
    <div class="field">
      <label class="label" for="dev-login-<?= (int) ($d?->id ?? 0) ?>">Логин панели</label>
      <input class="input input-mono" id="dev-login-<?= (int) ($d?->id ?? 0) ?>" name="login" type="text" maxlength="120"
             value="<?= $this->e($d?->login ?? 'admin') ?>" autocomplete="off">
    </div>
    <div class="field">
      <label class="label" for="dev-pass-<?= (int) ($d?->id ?? 0) ?>">Пароль панели</label>
      <input class="input input-mono" id="dev-pass-<?= (int) ($d?->id ?? 0) ?>" name="password" type="password" maxlength="255"
             placeholder="<?= $d !== null ? 'оставьте пустым, чтобы не менять' : '' ?>" autocomplete="new-password">
      <p class="text-xs text-muted-foreground">Хранится зашифрованным (AES-256-GCM на ключе приложения).</p>
    </div>
  </div>

  <div class="field">
    <label class="label" for="dev-location-<?= (int) ($d?->id ?? 0) ?>">Расположение</label>
    <input class="input" id="dev-location-<?= (int) ($d?->id ?? 0) ?>" name="location" type="text" maxlength="190"
           value="<?= $this->e($d?->location ?? '') ?>" placeholder="Северный въезд, корпус 1">
  </div>

  <div class="field">
    <label class="label" for="dev-options-<?= (int) ($d?->id ?? 0) ?>">Дополнительные параметры (JSON или key=value построчно)</label>
    <textarea class="textarea font-mono text-[12px]" id="dev-options-<?= (int) ($d?->id ?? 0) ?>" name="options" rows="4"
              placeholder='{"verify_tls": false, "timeout": 8, "lock": {"pulse_ms": 1000}}'><?= $this->e($d !== null && $d->options !== [] ? json_encode($d->options, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '') ?></textarea>
    <p class="text-xs text-muted-foreground">Перекрывают значения из <code class="inline-code">config.device.*</code> для этого устройства.</p>
  </div>

  <div class="grid gap-2 sm:grid-cols-2">
    <label class="checkbox-row text-sm">
      <input class="checkbox" type="checkbox" name="is_active" value="1" <?= ($d?->isActive ?? true) ? 'checked' : '' ?>>
      <span>Устройство активно</span>
    </label>
    <label class="checkbox-row text-sm">
      <input class="checkbox" type="checkbox" name="is_default" value="1" <?= ($d?->isDefault ?? false) ? 'checked' : '' ?>>
      <span>Использовать по умолчанию</span>
    </label>
  </div>

  <?php
  $cam = array_merge(
      ['enabled' => true, 'mode' => 'snapshot', 'endpoint' => 'security/ipcam', 'stream_url' => '', 'cache_ttl' => 2, 'width' => 1280],
      (array) ($d?->options['camera'] ?? []),
  );
  $camEnabled = (bool) $cam['enabled'] && $cam['mode'] !== 'off';
  ?>
  <fieldset class="grid gap-3 rounded-lg border p-3" data-camera-section>
    <legend class="flex items-center gap-2 px-1 text-sm font-semibold">
      <?= $this->icon('cctv', 'h-4 w-4') ?> Камера шлагбаума и видеопоток
    </legend>

    <label class="checkbox-row text-sm">
      <input class="checkbox" type="checkbox" name="camera_enabled" value="1" <?= $camEnabled ? 'checked' : '' ?>>
      <span>Показывать видео у этого шлагбаума <span class="label-hint">камера привязана к устройству и появится в пульте жителя и панели оператора</span></span>
    </label>

    <div class="grid gap-3 sm:grid-cols-2">
      <div class="field">
        <label class="label" for="dev-cam-mode-<?= (int) ($d?->id ?? 0) ?>">Режим потока</label>
        <select class="select" id="dev-cam-mode-<?= (int) ($d?->id ?? 0) ?>" name="camera_mode">
          <option value="snapshot" <?= $this->selected((string) $cam['mode'], 'snapshot') ?>>Кадры через сервер (snapshot) — надёжно, 1 кадр/сек</option>
          <option value="proxy" <?= $this->selected((string) $cam['mode'], 'proxy') ?>>Поток через сервер (proxy MJPEG) — плавно, скрывает панель</option>
          <option value="mjpeg" <?= $this->selected((string) $cam['mode'], 'mjpeg') ?>>Прямой поток (mjpeg) — браузер подключается к камере сам</option>
          <option value="off" <?= $this->selected((string) $cam['mode'], 'off') ?>>Не использовать камеру</option>
        </select>
        <p class="text-xs text-muted-foreground">
          <b>snapshot</b> — приложение само забирает кадры у панели BAS-IP (учётные данные не видны браузеру).
          <b>proxy</b> — непрерывный MJPEG-поток транслируется через сервер.
          <b>mjpeg</b> — браузер открывает поток напрямую (устройство должно быть доступно клиентам по сети).
        </p>
      </div>
      <div class="field">
        <label class="label" for="dev-cam-endpoint-<?= (int) ($d?->id ?? 0) ?>">Snapshot-endpoint панели (BAS-IP)</label>
        <input class="input input-mono" id="dev-cam-endpoint-<?= (int) ($d?->id ?? 0) ?>" name="camera_endpoint" type="text" maxlength="255"
               value="<?= $this->e((string) $cam['endpoint']) ?>" placeholder="security/ipcam">
        <p class="text-xs text-muted-foreground">Относительно базового URL <code class="inline-code">/api/v0/</code>.</p>
      </div>
    </div>

    <div class="field">
      <label class="label" for="dev-cam-stream-<?= (int) ($d?->id ?? 0) ?>">URL видеопотока (MJPEG/HTTP)</label>
      <input class="input input-mono" id="dev-cam-stream-<?= (int) ($d?->id ?? 0) ?>" name="camera_stream_url" type="text" maxlength="512"
             value="<?= $this->e((string) ($cam['stream_url'] ?? '')) ?>" placeholder="http://192.168.1.20/mjpeg.cgi или http://cam.local:8080/stream">
      <p class="text-xs text-muted-foreground">
        Для режимов <b>proxy</b> и <b>mjpeg</b>. Оставьте пустым для BAS-IP в режиме proxy — поток будет синтезирован из snapshot-кадров панели.
      </p>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
      <div class="field">
        <label class="label" for="dev-cam-ttl-<?= (int) ($d?->id ?? 0) ?>">Кэш кадра, сек</label>
        <input class="input" id="dev-cam-ttl-<?= (int) ($d?->id ?? 0) ?>" name="camera_cache_ttl" type="number" min="1" max="60"
               value="<?= (int) $cam['cache_ttl'] ?>">
      </div>
      <div class="field">
        <label class="label" for="dev-cam-width-<?= (int) ($d?->id ?? 0) ?>">Ширина кадра, px</label>
        <input class="input" id="dev-cam-width-<?= (int) ($d?->id ?? 0) ?>" name="camera_width" type="number" min="160" max="3840" step="80"
               value="<?= (int) $cam['width'] ?>">
      </div>
    </div>
  </fieldset>

  <div class="field">
    <label class="label" for="dev-notes-<?= (int) ($d?->id ?? 0) ?>">Заметка</label>
    <input class="input" id="dev-notes-<?= (int) ($d?->id ?? 0) ?>" name="notes" type="text" maxlength="2000" value="<?= $this->e($d?->notes ?? '') ?>">
  </div>
</div>
