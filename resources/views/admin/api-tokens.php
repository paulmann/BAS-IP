<?php
/**
 * API tokens.
 *
 * @var array<int, array<string, mixed>> $tokens
 * @var array<int, string> $scopes
 * @var array|null $newToken
 * @var string $baseUrl
 * @var bool $canCreate
 * @var bool $canDelete
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">API-токены</h2>
    <p class="page-desc">Для внешних интеграций: Home Assistant, n8n, Telegram-бот, распознавание номеров, платёжные системы.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/api/v1/docs')) ?>" target="_blank" rel="noopener"><?= $this->icon('book-open', 'h-3.5 w-3.5') ?> Документация API</a>
    <?php if ($canCreate): ?>
      <button class="btn btn-default btn-sm" type="button" data-dialog="#dialog-token-new"><?= $this->icon('plus', 'h-3.5 w-3.5') ?> Создать токен</button>
    <?php endif ?>
  </div>
</div>

<?php if (!empty($newToken)): ?>
  <div class="card mb-5 border-success/40">
    <div class="card-header">
      <div class="card-title flex items-center gap-2 text-success"><?= $this->icon('key', 'h-4 w-4') ?> Токен создан</div>
      <div class="card-desc">Скопируйте сейчас — в базе хранится только хеш, показать его снова невозможно.</div>
    </div>
    <div class="card-content grid gap-3">
      <div class="flex items-center gap-2">
        <code class="input input-mono flex-1 select-all" id="new-token" readonly><?= $this->e((string) ($newToken['token'] ?? '')) ?></code>
        <button class="btn btn-default" type="button" data-copy="#new-token" data-copy-message="Токен скопирован"><?= $this->icon('copy') ?> Копировать</button>
      </div>
      <div class="rounded-lg border bg-muted/30 p-3">
        <div class="label mb-1.5">Пример запроса</div>
        <pre class="code-block"><code>curl -X POST "<?= $this->e($baseUrl) ?>/api/v1/gate/open" \
  -H "Authorization: Bearer <?= $this->e((string) ($newToken['token'] ?? '')) ?>" \
  -H "Content-Type: application/json" \
  -d '{"apartment":"12","note":"курьер"}'</code></pre>
      </div>
    </div>
  </div>
<?php endif ?>

<div class="card">
  <div class="card-content p-0">
    <?php if ($tokens === []): ?>
      <?= $this->include('components/empty', ['title' => 'Токенов нет', 'description' => 'Создайте токен для внешней интеграции и выдайте ему только нужные области доступа.', 'icon' => 'plug']) ?>
    <?php else: ?>
      <div class="table-wrap rounded-none border-0">
        <table class="table">
          <thead><tr><th>Название</th><th>Префикс</th><th>Области доступа</th><th>Статус</th><th>Действует до</th><th>Последний вызов</th><th>Создан</th><th class="text-right">Действия</th></tr></thead>
          <tbody>
          <?php foreach ($tokens as $token): ?>
            <?php $tokenScopes = (array) \BasIp\Domain\Model\Entity::decodeJson($token['scopes'] ?? null, []); ?>
            <tr class="<?= empty($token['is_active']) ? 'is-inactive' : '' ?>">
              <td class="text-sm font-medium"><?= $this->e((string) $token['name']) ?></td>
              <td><code class="font-mono text-xs"><?= $this->e((string) ($token['prefix'] ?? '')) ?>…</code></td>
              <td>
                <div class="flex flex-wrap gap-1">
                  <?php foreach ($tokenScopes as $scope): ?>
                    <span class="badge badge-outline font-mono text-[11px]"><?= $this->e((string) $scope) ?></span>
                  <?php endforeach ?>
                </div>
              </td>
              <td><?= !empty($token['is_active']) ? '<span class="badge badge-soft-success">активен</span>' : '<span class="badge badge-muted">отозван</span>' ?></td>
              <td class="whitespace-nowrap text-xs tabular">
                <?= $token['expires_at'] !== null ? $this->e($this->date((string) $token['expires_at'])) : 'бессрочно' ?>
                <?php if ($token['expires_at'] !== null && strtotime((string) $token['expires_at']) < time()): ?>
                  <span class="badge badge-soft-destructive mt-0.5">истёк</span>
                <?php endif ?>
              </td>
              <td class="whitespace-nowrap text-xs tabular text-muted-foreground">
                <?= $token['last_used_at'] !== null ? $this->e($this->datetime((string) $token['last_used_at'])) : 'не использовался' ?>
                <?php if (!empty($token['last_used_ip'])): ?><div class="font-mono text-[11px]"><?= $this->e(\BasIp\Support\Ip::anonymize((string) $token['last_used_ip'])) ?></div><?php endif ?>
              </td>
              <td class="whitespace-nowrap text-xs tabular text-muted-foreground"><?= $this->e($this->date($token['created_at'] ?? null)) ?></td>
              <td class="text-right">
                <?php if ($canDelete): ?>
                  <div class="flex justify-end gap-1">
                    <?php if (!empty($token['is_active'])): ?>
                      <form method="post" action="<?= $this->e($this->url('/admin/api-tokens/' . (int) $token['id'] . '/revoke')) ?>" class="inline">
                        <?= $this->csrf() ?>
                        <button class="btn btn-ghost btn-sm btn-icon text-warning" type="submit" aria-label="Отозвать" data-tooltip="Отозвать"
                                data-confirm="Отозвать токен «<?= $this->e((string) $token['name']) ?>»? Интеграция перестанет работать." data-confirm-label="Отозвать" data-confirm-tone="destructive">
                          <?= $this->icon('ban', 'h-3.5 w-3.5') ?>
                        </button>
                      </form>
                    <?php endif ?>
                    <form method="post" action="<?= $this->e($this->url('/admin/api-tokens/' . (int) $token['id'] . '/revoke')) ?>" class="inline">
                      <?= $this->csrf() ?>
                      <input type="hidden" name="delete" value="1">
                      <button class="btn btn-ghost btn-sm btn-icon text-destructive" type="submit" aria-label="Удалить" data-tooltip="Удалить"
                              data-confirm="Удалить токен безвозвратно?" data-confirm-label="Удалить" data-confirm-tone="destructive">
                        <?= $this->icon('trash', 'h-3.5 w-3.5') ?>
                      </button>
                    </form>
                  </div>
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

<div class="grid gap-5 mt-5 lg:grid-cols-2">
  <div class="card">
    <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('shield', 'h-4 w-4') ?> Области доступа</div></div>
    <div class="card-content">
      <div class="flex flex-wrap gap-1.5">
        <?php foreach ($scopes as $scope): ?>
          <span class="badge badge-outline font-mono text-[11px]" data-tooltip="<?= $this->e($this->scopeHint((string) $scope)) ?>"><?= $this->e($scope) ?></span>
        <?php endforeach ?>
      </div>
      <p class="mt-3 text-xs text-muted-foreground">
        Выдавайте минимум необходимого: токену для «открыть шлагбаум из Home Assistant» достаточно
        <code class="inline-code">gate.open</code> и <code class="inline-code">gate.status</code>.
        Область <code class="inline-code">*</code> даёт полный доступ — используйте её только для доверенных систем.
      </p>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('terminal', 'h-4 w-4') ?> Примеры</div></div>
    <div class="card-content grid gap-3">
      <pre class="code-block"><code># Статус панели
curl -H "Authorization: Bearer $TOKEN" <?= $this->e($baseUrl) ?>/api/v1/gate/status

# Открыть шлагбаум жителю квартиры 12
curl -X POST -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"apartment":"12"}' \
     <?= $this->e($baseUrl) ?>/api/v1/gate/open

# Создать гостевой доступ на 12 часов
curl -X POST -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"resident_id":42,"ttl_hours":12,"guest_name":"Курьер"}' \
     <?= $this->e($baseUrl) ?>/api/v1/guest/passes

# Провести оплату из платёжной системы
curl -X POST -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"resident_id":42,"amount":5000,"method":"online","external_id":"pay_123"}' \
     <?= $this->e($baseUrl) ?>/api/v1/payments</code></pre>
      <p class="text-xs text-muted-foreground">
        Лимиты запросов задаются в <code class="inline-code">config/config.php</code> → <code class="inline-code">security.rate_limits</code>.
        При превышении API вернёт <code class="inline-code">429</code> с заголовком <code class="inline-code">Retry-After</code>.
      </p>
    </div>
  </div>
</div>

<?php if ($canCreate): ?>
  <div class="dialog" id="dialog-token-new" data-state="closed" hidden role="dialog" aria-labelledby="tk-t">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <form method="post" action="<?= $this->e($this->url('/admin/api-tokens')) ?>">
      <?= $this->csrf() ?>
      <div class="dialog-header">
        <h2 class="dialog-title" id="tk-t">Новый API-токен</h2>
        <p class="dialog-desc">Токен показывается один раз. В базе хранится только SHA-256-хеш.</p>
      </div>
      <div class="grid gap-3">
        <div class="field">
          <label class="label" for="tk-name">Название <span class="text-destructive">*</span></label>
          <input class="input" id="tk-name" name="name" type="text" maxlength="120" required placeholder="Home Assistant">
          <p class="text-xs text-muted-foreground">Что за система будет пользоваться токеном.</p>
        </div>
        <div class="field">
          <label class="label" for="tk-expires">Действует до</label>
          <input class="input" id="tk-expires" name="expires_at" type="date" value="<?= $this->e(date('Y-m-d', strtotime('+1 year'))) ?>">
          <p class="text-xs text-muted-foreground">Пусто — бессрочно (не рекомендуется).</p>
        </div>
        <div class="field">
          <div class="label mb-1.5">Области доступа</div>
          <div class="grid gap-1.5 sm:grid-cols-2">
            <?php foreach ($scopes as $scope): ?>
              <label class="flex items-center gap-2 rounded-lg border p-2.5 text-sm">
                <input type="checkbox" class="checkbox" name="scopes[]" value="<?= $this->e($scope) ?>"
                       <?= in_array($scope, ['gate.open', 'gate.status', 'gate.log'], true) ? 'checked' : '' ?>>
                <span class="min-w-0 flex-1">
                  <code class="font-mono text-xs"><?= $this->e($scope) ?></code>
                  <span class="block text-[11px] text-muted-foreground"><?= $this->e($this->scopeHint((string) $scope)) ?></span>
                </span>
              </label>
            <?php endforeach ?>
          </div>
        </div>
      </div>
      <div class="dialog-footer mt-4">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="submit" class="btn btn-default"><?= $this->icon('key') ?> Создать токен</button>
      </div>
    </form>
  </div>
<?php endif ?>
