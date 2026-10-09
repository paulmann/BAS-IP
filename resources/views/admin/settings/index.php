<?php
/**
 * Settings admin.
 *
 * @var string $group
 * @var array $groups
 * @var array $fields
 * @var array $values
 * @var array $channels
 * @var array $config
 * @var array $sources
 * @var bool $hasLocal
 * @var string $localPath
 * @var array<int, string> $secrets
 * @var bool $secretsEncrypted
 * @var array $definitions
 * @var bool $canEdit
 * @var array $updateInfo
 * @var array $dbInfo
 * @var array $phpInfo
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Настройки</h2>
    <p class="page-desc">
      Параметры, которые можно менять без правки кода. Структурные настройки (доступ к БД, пароль панели) живут в
      <code class="inline-code"><?= $this->e($localPath) ?></code> — он не попадает в git и не теряется при обновлении.
    </p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/help/configuration')) ?>"><?= $this->icon('book-open', 'h-3.5 w-3.5') ?> Справка по настройкам</a>
    <?php if ($canEdit): ?>
      <form method="post" action="<?= $this->e($this->url('/admin/settings/config/download')) ?>" class="inline">
        <?= $this->csrf() ?>
        <button class="btn btn-outline btn-sm" type="submit"><?= $this->icon('file-down', 'h-3.5 w-3.5') ?> Скачать конфиг</button>
      </form>
    <?php endif ?>
  </div>
</div>

<div class="grid gap-5 xl:grid-cols-[15rem_minmax(0,1fr)]">
  <nav class="grid content-start gap-1" aria-label="Разделы настроек">
    <?php foreach ($groups as $key => $meta): ?>
      <a class="nav-item <?= $group === $key ? 'is-active' : '' ?>" href="<?= $this->e($this->url('/admin/settings/' . $key)) ?>">
        <?= $this->icon($meta['icon'] ?? 'sliders', 'h-4 w-4') ?>
        <span class="flex-1"><?= $this->e($meta['label']) ?></span>
      </a>
    <?php endforeach ?>
  </nav>

  <div class="grid content-start gap-5">
    <?php if (!$hasLocal): ?>
      <div class="alert alert-warning">
        <?= $this->icon('alert-triangle', 'h-4 w-4') ?>
        <div>
          <div class="alert-title">Локальный конфиг не создан</div>
          <div class="alert-desc">
            Работают значения по умолчанию из <code class="inline-code">config/config.php</code>.
            Создайте <code class="inline-code"><?= $this->e($localPath) ?></code> (или запустите <code class="inline-code">bin/bas-ip install</code>),
            чтобы хранить ключ приложения, доступ к БД и пароль панели отдельно от кода.
          </div>
        </div>
      </div>
    <?php endif ?>

    <form class="card" method="post" action="<?= $this->e($this->url('/admin/settings/' . $group)) ?>" data-ajax>
      <?= $this->csrf() ?>
      <div class="card-header">
        <div class="card-title flex items-center gap-2">
          <?= $this->icon($groups[$group]['icon'] ?? 'sliders', 'h-4 w-4') ?>
          <?= $this->e($groups[$group]['label'] ?? $group) ?>
        </div>
        <div class="card-desc">Изменения применяются сразу и сохраняются в базе — они переживают обновление кода из GitHub.</div>
      </div>

      <div class="card-content grid gap-4">
        <?php if ($fields === []): ?>
          <p class="text-sm text-muted-foreground">В этом разделе настройки задаются только в конфигурационных файлах.</p>
        <?php endif ?>

        <?php foreach ($fields as $field): ?>
          <?php
            $key = (string) $field['key'];
            $name = str_replace('.', '__', $key);
            $value = $values[$key] ?? null;
            $type = (string) ($field['type'] ?? 'text');
            $id = 'set-' . $name;
          ?>
          <div class="field-row">
            <div class="min-w-0 flex-1">
              <label class="label" for="<?= $this->e($id) ?>"><?= $this->e($field['label'] ?? $key) ?></label>
              <?php if (!empty($field['hint'])): ?><p class="text-xs text-muted-foreground"><?= $this->e($field['hint']) ?></p><?php endif ?>
              <code class="mt-0.5 inline-block font-mono text-[11px] text-muted-foreground/70"><?= $this->e($key) ?></code>
            </div>
            <div class="w-full shrink-0 sm:w-80">
              <?php if ($type === 'bool'): ?>
                <label class="flex items-center justify-end gap-3">
                  <span class="text-xs text-muted-foreground"><?= !empty($value) ? 'включено' : 'выключено' ?></span>
                  <input type="checkbox" class="switch switch-success" id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>" value="1"
                         role="switch" <?= !empty($value) ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                </label>
              <?php elseif ($type === 'select'): ?>
                <select class="select" id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                  <?php foreach ((array) ($field['options'] ?? []) as $optionValue => $optionLabel): ?>
                    <option value="<?= $this->e((string) $optionValue) ?>" <?= $this->selected((string) ($value ?? ''), (string) $optionValue) ?>><?= $this->e((string) $optionLabel) ?></option>
                  <?php endforeach ?>
                </select>
              <?php elseif ($type === 'multiselect'): ?>
                <div class="grid gap-1.5">
                  <?php $selected = is_array($value) ? $value : []; ?>
                  <?php foreach ((array) ($field['options'] ?? []) as $optionValue => $optionLabel): ?>
                    <label class="flex items-center gap-2 text-sm">
                      <input type="checkbox" class="checkbox" name="<?= $this->e($name) ?>[]" value="<?= $this->e((string) $optionValue) ?>"
                             <?= in_array((string) $optionValue, array_map('strval', $selected), true) ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                      <?= $this->e((string) $optionLabel) ?>
                    </label>
                  <?php endforeach ?>
                </div>
              <?php elseif ($type === 'textarea'): ?>
                <textarea class="textarea font-mono text-[13px]" id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>" rows="6" <?= $canEdit ? '' : 'disabled' ?>><?= $this->e((string) ($value ?? '')) ?></textarea>
              <?php elseif ($type === 'number'): ?>
                <input class="input input-mono" id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>" type="number" step="1" value="<?= $this->e((string) ($value ?? '')) ?>" <?= $canEdit ? '' : 'disabled' ?>>
              <?php elseif ($type === 'money'): ?>
                <div class="input-group">
                  <input class="input input-mono" id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>" type="text" inputmode="decimal"
                         value="<?= $value !== null && $value !== '' ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') : '' ?>" <?= $canEdit ? '' : 'disabled' ?>>
                  <span class="addon"><?= $this->e((string) ($values['_currency'] ?? '₽')) ?></span>
                </div>
              <?php elseif ($type === 'time'): ?>
                <input class="input input-mono" id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>" type="time" value="<?= $this->e((string) ($value ?? '00:00')) ?>" <?= $canEdit ? '' : 'disabled' ?>>
              <?php elseif ($type === 'secret'): ?>
                <input class="input input-mono" id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>" type="password" value="" placeholder="не менять" autocomplete="new-password" <?= $canEdit ? '' : 'disabled' ?>>
              <?php else: ?>
                <input class="input" id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>" type="text" value="<?= $this->e((string) ($value ?? '')) ?>" <?= $canEdit ? '' : 'disabled' ?>>
              <?php endif ?>
            </div>
          </div>
        <?php endforeach ?>

        <?php if ($group === 'billing'): ?>
          <div class="rounded-lg border bg-muted/25 p-3 text-sm">
            <div class="font-medium">Сейчас в расчёте</div>
            <p class="mt-1 text-xs text-muted-foreground">
              Активных жителей: <b class="text-foreground"><?= (int) ($values['_active'] ?? 0) ?></b>,
              участвуют в оплате: <b class="text-foreground"><?= (int) ($values['_participants'] ?? 0) ?></b>.
              Сумма делится именно на второе число.
            </p>
            <a class="btn btn-outline btn-sm mt-2" href="<?= $this->e($this->url('/admin/billing/wizard')) ?>"><?= $this->icon('calculator', 'h-3.5 w-3.5') ?> Перейти к начислению</a>
          </div>
        <?php endif ?>

        <?php if ($group === 'notify'): ?>
          <div class="grid gap-3">
            <div class="label">Каналы доставки</div>
            <?php foreach ($channels as $channel): ?>
              <div class="flex flex-wrap items-center gap-3 rounded-lg border p-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-muted"><?= $this->icon($channel['icon'] ?? 'send', 'h-4 w-4 text-muted-foreground') ?></span>
                <div class="min-w-0 flex-1">
                  <div class="text-sm font-medium"><?= $this->e($channel['label']) ?></div>
                  <div class="text-xs text-muted-foreground"><code class="inline-code"><?= $this->e($channel['name']) ?></code></div>
                </div>
                <?= !empty($channel['enabled']) ? '<span class="badge badge-soft-success">настроен</span>' : '<span class="badge badge-muted">не настроен</span>' ?>
                <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/notifications')) ?>#channels">Настроить</a>
              </div>
            <?php endforeach ?>
          </div>
        <?php endif ?>

        <?php if ($group === 'security'): ?>
          <div class="grid gap-3 text-sm">
            <div class="label">Парольная политика (из config/config.php)</div>
            <?php foreach ([
              ['auth.password.min_length', 'Минимальная длина'],
              ['auth.password.require_mixed_case', 'Строчные и прописные'],
              ['auth.password.require_digit', 'Хотя бы одна цифра'],
              ['auth.password.require_symbol', 'Хотя бы один спецсимвол'],
              ['auth.password.breach_check', 'Проверка по базам утечек'],
              ['auth.two_factor.enabled', 'Двухфакторная аутентификация'],
              ['auth.login.max_attempts', 'Попыток до блокировки'],
              ['auth.login.lockout_minutes', 'Блокировка, минут'],
              ['auth.session.lifetime', 'Время жизни сессии, минут'],
              ['security.csrf.enabled', 'Защита от CSRF'],
              ['security.headers.csp', 'Content-Security-Policy'],
            ] as [$key, $label]): ?>
              <div class="flex justify-between gap-3 border-b border-border/40 py-1.5 last:border-0">
                <span class="text-muted-foreground"><?= $this->e($label) ?></span>
                <b class="text-right"><?= $this->e($this->describeConfigValue($this->config($key))) ?></b>
              </div>
            <?php endforeach ?>
            <p class="text-xs text-muted-foreground">Эти значения меняются в конфигурационном файле, а не в базе — чтобы их нельзя было изменить из интерфейса.</p>
          </div>
        <?php endif ?>

        <?php if ($group === 'system'): ?>
          <div class="grid gap-4">
            <div>
              <div class="label mb-2">Секреты</div>
              <p class="text-xs text-muted-foreground">
                Хранятся в <code class="inline-code">storage/app/.secrets.php</code>
                <?= $secretsEncrypted ? '(зашифрованы AES-256-GCM)' : '<b class="text-destructive">(БЕЗ шифрования — задайте app.key!)</b>' ?>.
                В конфиге используются как <code class="inline-code">${secret:NAME}</code>.
              </p>
              <?php if ($secrets === []): ?>
                <p class="mt-2 text-sm text-muted-foreground">Секретов пока нет.</p>
              <?php else: ?>
                <ul class="mt-2 grid gap-1.5">
                  <?php foreach ($secrets as $secret): ?>
                    <li class="flex items-center gap-2 rounded-md border bg-muted/25 px-3 py-2">
                      <code class="flex-1 font-mono text-xs"><?= $this->e($secret) ?></code>
                      <span class="badge badge-soft-<?= $secretsEncrypted ? 'success' : 'destructive' ?>"><?= $secretsEncrypted ? 'зашифрован' : 'открыто' ?></span>
                      <?php if ($canEdit): ?>
                        <form method="post" action="<?= $this->e($this->url('/admin/settings/secrets/' . rawurlencode($secret))) ?>" class="inline">
                          <?= $this->csrf() ?>
                          <input type="hidden" name="remove" value="1">
                          <button class="btn btn-ghost btn-sm btn-icon text-destructive" type="submit" aria-label="Удалить секрет"
                                  data-confirm="Удалить секрет <?= $this->e($secret) ?>?" data-confirm-label="Удалить" data-confirm-tone="destructive">
                            <?= $this->icon('trash', 'h-3.5 w-3.5') ?>
                          </button>
                        </form>
                      <?php endif ?>
                    </li>
                  <?php endforeach ?>
                </ul>
              <?php endif ?>

              <?php if ($canEdit): ?>
                <form method="post" action="<?= $this->e($this->url('/admin/settings/secrets/NEW')) ?>" class="mt-3 grid gap-2 sm:grid-cols-[1fr_1fr_auto]" data-secret-form>
                  <?= $this->csrf() ?>
                  <input class="input input-mono" name="secret_name" type="text" placeholder="ИМЯ_СЕКРЕТА" pattern="[A-Za-z0-9_]+" required>
                  <input class="input input-mono" name="value" type="password" placeholder="Значение" autocomplete="new-password" required>
                  <button class="btn btn-outline" type="submit"><?= $this->icon('plus', 'h-4 w-4') ?> Добавить</button>
                </form>
              <?php endif ?>
            </div>

            <div>
              <div class="label mb-2">Источники конфигурации</div>
              <ol class="grid gap-1 text-xs">
                <?php foreach ($sources as $index => $source): ?>
                  <li class="flex items-center gap-2 rounded-md border bg-muted/20 px-2.5 py-1.5">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/12 text-[10px] font-semibold text-primary"><?= $index + 1 ?></span>
                    <code class="truncate font-mono"><?= $this->e($source) ?></code>
                  </li>
                <?php endforeach ?>
              </ol>
              <p class="mt-2 text-xs text-muted-foreground">Каждый следующий источник перекрывает предыдущий. Переменные окружения <code class="inline-code">BASIP_*</code> имеют наивысший приоритет.</p>
            </div>

            <div>
              <div class="label mb-2">Обновления</div>
              <div class="grid gap-1.5 text-sm">
                <div class="flex justify-between gap-3"><span class="text-muted-foreground">Автообновление</span><b><?= $updateInfo['enabled'] ? 'включено' : 'выключено' ?></b></div>
                <div class="flex justify-between gap-3"><span class="text-muted-foreground">Режим</span><b><?= $this->e($updateInfo['mode']) ?></b></div>
                <div class="flex justify-between gap-3"><span class="text-muted-foreground">Канал</span><b><?= $this->e($updateInfo['channel']) ?></b></div>
                <div class="flex justify-between gap-3"><span class="text-muted-foreground">Репозиторий</span><b class="font-mono text-xs"><?= $this->e($updateInfo['repo']) ?></b></div>
                <div class="flex justify-between gap-3"><span class="text-muted-foreground">Установлено</span><b><?= $this->e($updateInfo['current']) ?></b></div>
                <div class="flex justify-between gap-3"><span class="text-muted-foreground">Последняя проверка</span><b class="text-xs tabular"><?= $this->e($this->datetime($updateInfo['last_check'] ?? null)) ?></b></div>
              </div>
              <a class="btn btn-outline btn-sm mt-2" href="<?= $this->e($this->url('/admin/updates')) ?>"><?= $this->icon('download-cloud', 'h-3.5 w-3.5') ?> Перейти к обновлениям</a>
            </div>

            <div>
              <div class="label mb-2">Окружение</div>
              <div class="grid gap-1.5 text-sm">
                <div class="flex justify-between gap-3"><span class="text-muted-foreground">PHP</span><b><?= $this->e($phpInfo['version']) ?> (<?= $this->e($phpInfo['sapi']) ?>)</b></div>
                <div class="flex justify-between gap-3"><span class="text-muted-foreground">memory_limit</span><b class="font-mono text-xs"><?= $this->e($phpInfo['memory']) ?></b></div>
                <div class="flex justify-between gap-3"><span class="text-muted-foreground">СУБД</span><b><?= $this->e((string) ($dbInfo['pdo_driver'] ?? '?')) ?> <?= $this->e((string) ($dbInfo['version'] ?? '')) ?></b></div>
                <div class="flex justify-between gap-3"><span class="text-muted-foreground">Часовой пояс</span><b><?= $this->e($this->config('app.timezone')) ?></b></div>
                <div class="flex justify-between gap-3"><span class="text-muted-foreground">Окружение</span><b><?= $this->e($this->config('app.env')) ?></b></div>
                <div class="flex justify-between gap-3"><span class="text-muted-foreground">Отладка</span><b class="<?= $this->config('app.debug') ? 'text-destructive' : 'text-success' ?>"><?= $this->config('app.debug') ? 'ВКЛЮЧЕНА — выключите на боевом сервере!' : 'выключена' ?></b></div>
              </div>
            </div>
          </div>
        <?php endif ?>
      </div>

      <?php if ($canEdit && $fields !== []): ?>
        <div class="card-footer justify-end border-t">
          <button class="btn btn-default" type="submit"><?= $this->icon('save') ?> Сохранить настройки</button>
        </div>
      <?php endif ?>
    </form>
  </div>
</div>

<?php $this->startPush('scripts') ?>
<script nonce="<?= $this->e($this->nonce()) ?>">
(function () {
  // Форма добавления секрета подставляет имя в action.
  var form = document.querySelector('[data-secret-form]');
  if (form) {
    form.addEventListener('submit', function (e) {
      var name = form.querySelector('[name="secret_name"]').value.trim().toUpperCase();
      if (!name) { e.preventDefault(); return; }
      var base = form.getAttribute('action').replace(/\/[^/]+$/, '');
      form.setAttribute('action', base + '/' + encodeURIComponent(name));
      form.querySelector('[name="secret_name"]').disabled = true;
    });
  }
})();
</script>
<?php $this->endPush() ?>
