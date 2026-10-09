<?php
/**
 * Web installer.
 *
 * @var array<int, array<string, mixed>> $requirements
 * @var string $step
 * @var string $localFile
 * @var string $version
 * @var string $suggestedAdmin
 * @var string|null $error
 * @var array $log
 * @var array<string, mixed>|null $report
 * @var string|null $reportText
 * @var array<string, mixed> $old
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$error = $error ?? null;
$log = $log ?? [];
$report = $report ?? null;
$reportText = $reportText ?? null;
$old = $old ?? [];
$failed = array_filter($requirements, static fn (array $r): bool => empty($r['ok']) && ($r['required'] ?? true));
?>
<?php $this->layout('layouts/auth') ?>

<div class="grid gap-5" data-tabs data-tabs-persist="install">
  <div class="tabs-list is-scrollable" role="tablist">
    <button class="tabs-trigger" role="tab" data-tab="check" data-state="active" aria-selected="true"><?= $this->icon('heart-pulse') ?> Проверка</button>
    <button class="tabs-trigger" role="tab" data-tab="db" data-state="inactive" aria-selected="false"><?= $this->icon('database') ?> База</button>
    <button class="tabs-trigger" role="tab" data-tab="app" data-state="inactive" aria-selected="false"><?= $this->icon('sliders') ?> Приложение</button>
    <button class="tabs-trigger" role="tab" data-tab="device" data-state="inactive" aria-selected="false"><?= $this->icon('cpu') ?> Панель</button>
    <button class="tabs-trigger" role="tab" data-tab="admin" data-state="inactive" aria-selected="false"><?= $this->icon('shield') ?> Админ</button>
  </div>

  <?php if ($error !== null): ?>
    <div class="alert alert-destructive">
      <?= $this->icon('alert-circle', 'h-4 w-4') ?>
      <div>
        <div class="alert-title">Установка не завершена</div>
        <div class="alert-desc"><?= $this->e($error) ?></div>
        <?php if ($report !== null): ?>
          <div class="alert-desc mt-1">
            Фаза: <code class="inline-code"><?= $this->e((string) ($report['app']['phase'] ?? '?')) ?></code>.
            <?= $this->e((string) ($report['hint'] ?? '')) ?>
          </div>
        <?php endif ?>
      </div>
    </div>
  <?php endif ?>

  <?php if ($reportText !== null): ?>
    <details class="rounded-lg border border-destructive/40 bg-destructive/5 p-3" open>
      <summary class="cursor-pointer text-sm font-medium text-destructive">
        Подробный отчёт об ошибке — для обращения в поддержку или анализа ИИ
      </summary>
      <pre id="install-report" class="mt-2 max-h-96 overflow-auto rounded-lg bg-zinc-950 p-3 font-mono text-[11px] leading-relaxed text-zinc-100"><?= $this->e($reportText) ?></pre>
      <div class="mt-2 flex flex-wrap gap-2">
        <button type="button" class="btn btn-outline btn-sm" data-copy-report>
          <?= $this->icon('copy', 'h-3.5 w-3.5') ?> Скопировать отчёт
        </button>
        <button type="button" class="btn btn-outline btn-sm" data-download-report>
          <?= $this->icon('download', 'h-3.5 w-3.5') ?> Скачать .txt
        </button>
      </div>
      <p class="mt-2 text-xs text-muted-foreground">
        Пароли и ключи в отчёте замаскированы. Отчёт также доступен в JSON при запросе
        с <code class="inline-code">Accept: application/json</code>.
      </p>
    </details>
  <?php endif ?>

  <?php if ($log !== []): ?>
    <details class="rounded-lg border bg-muted/30 p-3">
      <summary class="cursor-pointer text-sm font-medium">Выполненные шаги (<?= count($log) ?>)</summary>
      <ul class="mt-2 grid gap-1 text-xs text-muted-foreground">
        <?php foreach ($log as $line): ?><li>· <?= $this->e($line) ?></li><?php endforeach ?>
      </ul>
    </details>
  <?php endif ?>

  <form method="post" action="<?= $this->e($this->url('/install')) ?>" class="grid gap-5" id="install-form">
    <?= $this->csrf() ?>

    <?php /* ── проверка окружения ───────────────────────────── */ ?>
    <section class="tabs-content" role="tabpanel" data-tab-panel="check">
      <div class="rounded-lg border">
        <div class="flex items-center justify-between gap-2 border-b px-4 py-2.5">
          <div class="text-sm font-semibold">Требования к окружению</div>
          <?php if ($failed === []): ?>
            <span class="badge badge-soft-success"><?= $this->icon('check-circle', 'h-3 w-3') ?> всё готово</span>
          <?php else: ?>
            <span class="badge badge-soft-destructive"><?= count($failed) ?> проблем</span>
          <?php endif ?>
        </div>
        <ul class="divide-y">
          <?php foreach ($requirements as $requirement): ?>
            <li class="flex items-start gap-2.5 px-4 py-2">
              <span class="health-dot mt-1.5 <?= !empty($requirement['ok']) ? 'ok' : (($requirement['required'] ?? true) ? 'fail' : 'warn') ?>"></span>
              <span class="min-w-0 flex-1">
                <span class="block text-sm"><?= $this->e((string) $requirement['name']) ?></span>
                <?php if (isset($requirement['why'])): ?><span class="block text-xs text-muted-foreground"><?= $this->e((string) $requirement['why']) ?></span><?php endif ?>
              </span>
              <span class="shrink-0 font-mono text-xs text-muted-foreground"><?= $this->e((string) ($requirement['value'] ?? '')) ?></span>
            </li>
          <?php endforeach ?>
        </ul>
      </div>

      <div class="alert alert-muted mt-4">
        <?= $this->icon('info', 'h-4 w-4') ?>
        <div class="alert-desc">
          Мастер запишет локальный конфиг <code class="inline-code"><?= $this->e($localFile) ?></code> (chmod 600, в .gitignore),
          сгенерирует <code class="inline-code">app.key</code>, применит миграции и создаст администратора.
          Тот же сценарий доступен из консоли: <code class="inline-code">php bin/bas-ip install</code>.
        </div>
      </div>

      <div class="flex justify-end">
        <button type="button" class="btn btn-default" data-wizard-step="db">Далее: база данных <?= $this->icon('arrow-right', 'h-4 w-4') ?></button>
      </div>
    </section>

    <?php /* ── база данных ──────────────────────────────────── */ ?>
    <section class="tabs-content" role="tabpanel" data-tab-panel="db" hidden>
      <div class="grid gap-4">
        <div class="field">
          <div class="label mb-1.5">Тип базы данных</div>
          <div class="grid grid-cols-3 gap-2">
            <?php foreach ([['mysql', 'MySQL / MariaDB', 'Рекомендуется', 'database'], ['pgsql', 'PostgreSQL', 'Полная поддержка', 'database'], ['sqlite', 'SQLite', 'Демо и тесты', 'hard-drive']] as [$value, $label, $hint, $icon]): ?>
              <label class="flex cursor-pointer flex-col gap-1 rounded-lg border p-3 transition hover:border-primary/50 has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                <span class="flex items-center gap-2">
                  <input type="radio" class="radio" name="db_driver" value="<?= $value ?>" <?= $value === 'mysql' ? 'checked' : '' ?>>
                  <span class="text-sm font-medium"><?= $this->icon($icon, 'h-4 w-4') ?> <?= $label ?></span>
                </span>
                <span class="text-xs text-muted-foreground"><?= $hint ?></span>
              </label>
            <?php endforeach ?>
          </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2" data-db-server>
          <div class="field">
            <label class="label" for="db_host">Хост</label>
            <input class="input input-mono" id="db_host" name="db_host" type="text" value="127.0.0.1" autocomplete="off">
          </div>
          <div class="field">
            <label class="label" for="db_port">Порт</label>
            <input class="input input-mono" id="db_port" name="db_port" type="number" value="3306">
          </div>
        </div>

        <div class="field">
          <label class="label" for="db_database">Имя базы (или путь к файлу для SQLite)</label>
          <input class="input input-mono" id="db_database" name="db_database" type="text" value="bas_ip" required>
        </div>

        <div class="grid gap-4 sm:grid-cols-2" data-db-credentials>
          <div class="field">
            <label class="label" for="db_username">Пользователь</label>
            <input class="input input-mono" id="db_username" name="db_username" type="text" value="bas_ip" autocomplete="off">
          </div>
          <div class="field">
            <label class="label" for="db_password">Пароль</label>
            <input class="input input-mono" id="db_password" name="db_password" type="password" autocomplete="new-password">
          </div>
        </div>

        <details class="rounded-lg border bg-muted/20 p-3">
          <summary class="cursor-pointer text-sm font-medium">Дополнительно (префикс таблиц, unix-сокет)</summary>
          <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <div class="field">
              <label class="label" for="db_prefix">Префикс таблиц</label>
              <input class="input input-mono" id="db_prefix" name="db_prefix" type="text" placeholder="basip_" autocomplete="off">
              <p class="text-xs text-muted-foreground">Если база общая с другим приложением.</p>
            </div>
            <div class="field">
              <label class="label" for="db_socket">Unix-сокет (MySQL)</label>
              <input class="input input-mono" id="db_socket" name="db_socket" type="text" placeholder="/run/mysqld/mysqld.sock" autocomplete="off">
            </div>
          </div>
        </details>

        <div class="flex flex-wrap items-center gap-3">
          <button type="button" class="btn btn-outline" data-test-db>
            <?= $this->icon('plug', 'h-4 w-4') ?> Проверить соединение
          </button>
          <span class="text-sm text-muted-foreground" data-test-db-result aria-live="polite"></span>
        </div>

        <div class="alert alert-info">
          <?= $this->icon('info', 'h-4 w-4') ?>
          <div class="alert-desc">
            База должна быть создана заранее. Для MySQL/MariaDB:
            <code class="inline-code">CREATE DATABASE bas_ip CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;</code>
          </div>
        </div>

        <div class="flex justify-between">
          <button type="button" class="btn btn-ghost" data-wizard-step="check"><?= $this->icon('arrow-left', 'h-4 w-4') ?> Назад</button>
          <button type="button" class="btn btn-default" data-wizard-step="app">Далее <?= $this->icon('arrow-right', 'h-4 w-4') ?></button>
        </div>
      </div>
    </section>

    <?php /* ── приложение ───────────────────────────────────── */ ?>
    <section class="tabs-content" role="tabpanel" data-tab-panel="app" hidden>
      <div class="grid gap-4">
        <div class="field">
          <label class="label" for="app_name">Название (ЖК / организация)</label>
          <input class="input" id="app_name" name="app_name" type="text" maxlength="120" value="Шлагбаум" required>
          <p class="text-xs text-muted-foreground">Показывается в шапке, письмах и уведомлениях.</p>
        </div>
        <div class="field">
          <label class="label" for="app_url">Публичный URL</label>
          <input class="input input-mono" id="app_url" name="app_url" type="url" placeholder="https://gate.example.com">
          <p class="text-xs text-muted-foreground">Нужен для гостевых ссылок и писем. Оставьте пустым — определим автоматически.</p>
        </div>
        <div class="field">
          <label class="label" for="timezone">Часовой пояс</label>
          <select class="select" id="timezone" name="timezone">
            <?php foreach (['Europe/Moscow', 'Europe/Kaliningrad', 'Europe/Samara', 'Asia/Yekaterinburg', 'Asia/Omsk', 'Asia/Krasnoyarsk', 'Asia/Irkutsk', 'Asia/Yakutsk', 'Asia/Vladivostok', 'Europe/Kiev', 'Europe/Minsk', 'Asia/Almaty', 'Asia/Tashkent', 'UTC'] as $tz): ?>
              <option value="<?= $tz ?>" <?= $tz === 'Europe/Moscow' ? 'selected' : '' ?>><?= $tz ?></option>
            <?php endforeach ?>
          </select>
        </div>
        <div class="grid gap-2 rounded-lg border bg-muted/25 p-3 sm:grid-cols-2">
          <label class="checkbox-row text-sm"><input class="checkbox" type="checkbox" name="seed_demo" value="1"><span>Загрузить демо-данные <span class="label-hint">24 жителя, начисления, оплаты, журнал — для знакомства.</span></span></label>
          <label class="checkbox-row text-sm"><input class="checkbox" type="checkbox" name="debug" value="1"><span>Режим отладки <span class="label-hint">Только для разработки!</span></span></label>
        </div>
        <input type="hidden" name="env" value="production">
        <div class="flex justify-between">
          <button type="button" class="btn btn-ghost" data-wizard-step="db"><?= $this->icon('arrow-left', 'h-4 w-4') ?> Назад</button>
          <button type="button" class="btn btn-default" data-wizard-step="device">Далее <?= $this->icon('arrow-right', 'h-4 w-4') ?></button>
        </div>
      </div>
    </section>

    <?php /* ── панель ───────────────────────────────────────── */ ?>
    <section class="tabs-content" role="tabpanel" data-tab-panel="device" hidden>
      <div class="grid gap-4">
        <div class="field">
          <label class="label" for="device_base_url">Адрес панели BAS-IP</label>
          <input class="input input-mono" id="device_base_url" name="device_base_url" type="text" placeholder="https://192.168.1.50/api/v0">
          <p class="text-xs text-muted-foreground">Оставьте пустым, чтобы настроить позже в разделе «Устройства». Для проверки без железа выберите драйвер-эмулятор.</p>
        </div>
        <div class="field">
          <label class="label" for="device_driver">Драйвер</label>
          <select class="select" id="device_driver" name="device_driver">
            <option value="basip">BAS-IP Camdroid (/api/v0)</option>
            <option value="http-relay">Универсальное HTTP-реле</option>
            <option value="dryrun">Эмулятор (без железа)</option>
          </select>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
          <div class="field">
            <label class="label" for="device_login">Логин панели</label>
            <input class="input input-mono" id="device_login" name="device_login" type="text" value="admin" autocomplete="off">
          </div>
          <div class="field">
            <label class="label" for="device_password">Пароль панели</label>
            <input class="input input-mono" id="device_password" name="device_password" type="password" autocomplete="new-password">
          </div>
          <div class="field">
            <label class="label" for="device_channel">Канал реле</label>
            <input class="input" id="device_channel" name="device_channel" type="number" min="1" max="16" value="1">
          </div>
        </div>
        <div class="alert alert-muted">
          <?= $this->icon('shield-check', 'h-4 w-4') ?>
          <div class="alert-desc">Пароль панели сохраняется в зашифрованном хранилище секретов (<code class="inline-code">storage/app/.secrets.php</code>), а в конфиге остаётся ссылка <code class="inline-code">${secret:DEVICE_PASSWORD}</code>.</div>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <button type="button" class="btn btn-outline" data-test-device>
            <?= $this->icon('zap', 'h-4 w-4') ?> Проверить панель
          </button>
          <span class="text-sm text-muted-foreground" data-test-device-result aria-live="polite"></span>
        </div>
        <div class="flex justify-between">
          <button type="button" class="btn btn-ghost" data-wizard-step="app"><?= $this->icon('arrow-left', 'h-4 w-4') ?> Назад</button>
          <button type="button" class="btn btn-default" data-wizard-step="admin">Далее <?= $this->icon('arrow-right', 'h-4 w-4') ?></button>
        </div>
      </div>
    </section>

    <?php /* ── администратор ────────────────────────────────── */ ?>
    <section class="tabs-content" role="tabpanel" data-tab-panel="admin" hidden>
      <div class="grid gap-4">
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="admin_login">Логин администратора <span class="text-destructive">*</span></label>
            <input class="input input-mono" id="admin_login" name="admin_login" type="text" value="<?= $this->e($suggestedAdmin) ?>" required autocomplete="off" pattern="[A-Za-z0-9._@-]{3,64}">
          </div>
          <div class="field">
            <label class="label" for="admin_name">ФИО</label>
            <input class="input" id="admin_name" name="admin_name" type="text" value="Администратор" maxlength="190">
          </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="admin_email">E-mail</label>
            <input class="input" id="admin_email" name="admin_email" type="email" placeholder="admin@example.com">
            <p class="text-xs text-muted-foreground">На него придёт ссылка восстановления пароля.</p>
          </div>
          <div class="field">
            <label class="label" for="admin_phone">Телефон</label>
            <input class="input input-mono" id="admin_phone" name="admin_phone" type="tel" maxlength="32" placeholder="+7 900 000-00-00" autocomplete="off">
            <p class="text-xs text-muted-foreground">Контактный телефон администратора — для уведомлений и связи.</p>
          </div>
        </div>
        <div class="field">
          <label class="label" for="admin_password">Пароль</label>
          <div class="relative">
            <input class="input input-mono pr-24" id="admin_password" name="admin_password" type="password" minlength="10" autocomplete="new-password" placeholder="оставьте пустым — сгенерируем">
            <div class="absolute inset-y-0 right-1.5 my-1.5 flex items-center gap-1">
              <button type="button" class="btn btn-ghost btn-sm h-7 px-2 text-xs" data-generate-password>Сгенерировать</button>
              <button type="button" class="btn btn-ghost btn-sm btn-icon h-7 w-7" data-secret-toggle="#admin_password" aria-label="Показать пароль"><?= $this->icon('eye', 'h-3.5 w-3.5') ?></button>
            </div>
          </div>
          <div class="mt-1.5" data-password-strength></div>
          <p class="text-xs text-muted-foreground">
            Надёжный пароль генерируется автоматически при открытии этого шага — сохраните или замените его.
            Если оставить поле пустым, сервер сгенерирует пароль сам и покажет его один раз после установки.
            Требования при вводе вручную: минимум 10 символов, строчные и прописные буквы, хотя бы одна цифра.
          </p>
        </div>

        <div class="alert alert-warning">
          <?= $this->icon('alert-triangle', 'h-4 w-4') ?>
          <div>
            <div class="alert-title">После установки</div>
            <div class="alert-desc">
              Направьте веб-сервер на каталог <code class="inline-code">public/</code>, включите 2FA в профиле и создайте резервную копию.
              Файл <code class="inline-code">public/install.lock</code> закроет этот мастер.
            </div>
          </div>
        </div>

        <div class="flex justify-between">
          <button type="button" class="btn btn-ghost" data-wizard-step="device"><?= $this->icon('arrow-left', 'h-4 w-4') ?> Назад</button>
          <button class="btn btn-success" type="submit" <?= $failed !== [] ? 'disabled' : '' ?>>
            <?= $this->icon('rocket') ?> Установить
          </button>
        </div>
        <?php if ($failed !== []): ?>
          <p class="text-right text-xs text-destructive">Сначала устраните критичные проблемы на шаге «Проверка».</p>
        <?php endif ?>
      </div>
    </section>
  </form>
</div>

<?php $this->startPush('scripts') ?>
<script nonce="<?= $this->e($this->nonce()) ?>">
(function () {
  // Значения предыдущей попытки (пароли НЕ возвращаем в разметку).
  var OLD = <?= json_encode(array_diff_key($old, array_flip(['admin_password', 'db_password', 'device_password', '_token'])), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}' ?>;
  Object.keys(OLD).forEach(function (key) {
    var field = document.querySelector('[name="' + key + '"]');
    if (!field || field.type === 'checkbox' || field.type === 'radio' || field.type === 'password') return;
    if (OLD[key] !== null && OLD[key] !== '' && field.value === (field.defaultValue || '')) field.value = OLD[key];
  });

  // Переключение шагов мастера установки.
  document.querySelectorAll('[data-wizard-step]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = btn.getAttribute('data-wizard-step');
      document.querySelectorAll('[data-tab-panel]').forEach(function (panel) {
        panel.hidden = panel.getAttribute('data-tab-panel') !== target;
      });
      document.querySelectorAll('.tabs-trigger').forEach(function (trigger, index) {
        var panels = ['check', 'db', 'app', 'device', 'admin'];
        var active = panels[index] === target;
        trigger.setAttribute('data-state', active ? 'active' : 'inactive');
        trigger.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  });

  // SQLite не требует хоста и учётных данных.
  var driver = document.querySelector('[name="db_driver"]:checked');
  function syncDriver() {
    var isSqlite = driver && driver.value === 'sqlite';
    document.querySelectorAll('[data-db-server] .field, [data-db-credentials] .field').forEach(function (field) {
      field.style.opacity = isSqlite ? '.45' : '1';
      field.querySelectorAll('input').forEach(function (input) {
        input.disabled = isSqlite;
        if (isSqlite && input.id === 'db_database') { input.disabled = false; input.value = 'storage/app/bas-ip.sqlite'; }
      });
    });
    var port = document.getElementById('db_port');
    if (port && !isSqlite) port.value = driver.value === 'pgsql' ? '5432' : '3306';
  }
  document.querySelectorAll('[name="db_driver"]').forEach(function (radio) {
    radio.addEventListener('change', function () { driver = radio; syncDriver(); });
  });
  syncDriver();

  /* ── Проверка соединения с БД и панели (до установки) ─────────────── */
  var form = document.getElementById('install-form');
  function csrfToken() {
    var input = form && form.querySelector('input[name="_token"]');
    return input ? input.value : '';
  }
  function fieldValue(name) {
    var field = document.querySelector('[name="' + name + '"]');
    if (!field) return '';
    if (field.type === 'radio') {
      var checked = document.querySelector('[name="' + name + '"]:checked');
      return checked ? checked.value : '';
    }
    return field.value;
  }
  function postTest(url, fields, btn, resultEl) {
    var body = new URLSearchParams({ _token: csrfToken() });
    fields.forEach(function (name) { body.set(name, fieldValue(name)); });
    var original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Проверяем…';
    resultEl.textContent = '';
    resultEl.className = 'text-sm text-muted-foreground';
    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken(), 'Accept': 'application/json' },
      body: body.toString(),
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).then(function (data) {
      btn.disabled = false;
      btn.innerHTML = original;
      if (data.ok) {
        resultEl.className = 'text-sm text-success';
        resultEl.textContent = '✔ ' + (data.message || 'OK') + (data.server ? ' · ' + data.server : '') + (data.ms ? ' · ' + data.ms + ' мс' : '');
      } else {
        resultEl.className = 'text-sm text-destructive';
        resultEl.textContent = '✖ ' + (data.error || 'Не удалось проверить');
      }
    }).catch(function (e) {
      btn.disabled = false;
      btn.innerHTML = original;
      resultEl.className = 'text-sm text-destructive';
      resultEl.textContent = '✖ Ошибка запроса: ' + e;
    });
  }
  var testDb = document.querySelector('[data-test-db]');
  if (testDb) {
    testDb.addEventListener('click', function () {
      postTest(form.getAttribute('action').replace(/\/install$/, '/install/test-db'),
        ['db_driver', 'db_host', 'db_port', 'db_database', 'db_username', 'db_password', 'db_socket'],
        testDb, document.querySelector('[data-test-db-result]'));
    });
  }
  var testDevice = document.querySelector('[data-test-device]');
  if (testDevice) {
    testDevice.addEventListener('click', function () {
      postTest(form.getAttribute('action').replace(/\/install$/, '/install/test-device'),
        ['device_driver', 'device_base_url', 'device_login', 'device_password'],
        testDevice, document.querySelector('[data-test-device-result]'));
    });
  }

  /* ── Генератор пароля ──────────────────────────────────────────────── */
  var generate = document.querySelector('[data-generate-password]');
  var pass = document.getElementById('admin_password');
  function generatePassword() {
    var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*';
    var out = '';
    var random = new Uint32Array(20);
    (window.crypto || window.msCrypto).getRandomValues(random);
    for (var i = 0; i < 20; i++) out += alphabet[random[i] % alphabet.length];
    pass.value = out;
    pass.dispatchEvent(new Event('input'));
  }
  if (generate && pass) {
    generate.addEventListener('click', function () {
      generatePassword();
      pass.type = 'text';
    });
    // Автогенерация при первом открытии мастера: пароль можно заменить или
    // скопировать (кнопка «глаз»), а пустое поле сервер заполнит сам.
    if (pass.value === '' && !OLD.admin_password) generatePassword();
  }

  // Индикатор надёжности пароля.
  if (pass) {
    var host = document.querySelector('[data-password-strength]');
    var labels = ['очень слабый', 'слабый', 'средний', 'хороший', 'отличный'];
    pass.addEventListener('input', function () {
      var v = pass.value, score = 0;
      if (v.length >= 10) score++;
      if (v.length >= 16) score++;
      if (/[a-zа-я]/.test(v) && /[A-ZА-Я]/.test(v)) score++;
      if (/\d/.test(v)) score++;
      if (/[^A-Za-zА-Яа-я0-9]/.test(v)) score++;
      score = Math.min(4, score);
      if (!host) return;
      if (v === '') { host.innerHTML = ''; return; }
      host.innerHTML = '<div class="flex gap-1">' + [0,1,2,3,4].map(function (i) {
        var color = score <= 1 ? 'hsl(var(--destructive))' : (score === 2 ? 'hsl(var(--warning))' : 'hsl(var(--success))');
        return '<span style="height:4px;flex:1;border-radius:9999px;background:' + (i <= score ? color : 'hsl(var(--muted))') + '"></span>';
      }).join('') + '</div><p class="mt-1 text-xs text-muted-foreground">Надёжность: ' + labels[score] + '</p>';
    });
    if (pass.value !== '') pass.dispatchEvent(new Event('input'));
  }

  /* ── Отчёт об ошибке: копирование и скачивание ─────────────────────── */
  var reportEl = document.getElementById('install-report');
  var copyBtn = document.querySelector('[data-copy-report]');
  if (copyBtn && reportEl) {
    copyBtn.addEventListener('click', function () {
      var text = reportEl.textContent;
      function done() { copyBtn.textContent = 'Скопировано ✓'; setTimeout(function () { copyBtn.innerHTML = '<?= $this->icon('copy', 'h-3.5 w-3.5') ?> Скопировать отчёт'; }, 2000); }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, done);
      } else {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta); done();
      }
    });
  }
  var downloadBtn = document.querySelector('[data-download-report]');
  if (downloadBtn && reportEl) {
    downloadBtn.addEventListener('click', function () {
      var blob = new Blob([reportEl.textContent], { type: 'text/plain;charset=utf-8' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'bas-ip-install-report-<?= date('Ymd-His') ?>.txt';
      document.body.appendChild(a); a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    });
  }
})();
</script>
<?php $this->endPush() ?>
