<?php
/**
 * Machine-readable API documentation rendered as a page.
 *
 * @var array $docs
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$auth = (array) ($docs['authentication'] ?? []);
$endpoints = (array) ($docs['endpoints'] ?? []);
$methodTone = ['GET' => 'info', 'POST' => 'success', 'DELETE' => 'destructive', 'PUT' => 'warning', 'PATCH' => 'warning'];
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title"><?= $this->e((string) ($docs['title'] ?? 'API')) ?></h2>
    <p class="page-desc">Базовый URL: <code class="inline-code"><?= $this->e((string) ($docs['base_url'] ?? '')) ?></code></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/api-tokens')) ?>"><?= $this->icon('key', 'h-3.5 w-3.5') ?> API-токены</a>
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/help/api')) ?>"><?= $this->icon('book-open', 'h-3.5 w-3.5') ?> Справка</a>
  </div>
</div>

<div class="card mb-5">
  <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('shield-check', 'h-4 w-4') ?> Аутентификация</div></div>
  <div class="card-content grid gap-3 text-sm">
    <p class="text-muted-foreground">Все защищённые методы требуют заголовок:</p>
    <pre class="code-block"><code>Authorization: Bearer basip_…</code></pre>
    <p class="text-muted-foreground">
      Токен создаётся в разделе <a class="text-primary underline underline-offset-4" href="<?= $this->e($this->url('/admin/api-tokens')) ?>">API-токены</a>.
      В базе хранится только SHA-256-хеш, поэтому токен показывается ровно один раз.
      Права задаются областями (scopes), а не ролями — даже утечка токена не даст прав администратора.
    </p>
    <div class="flex flex-wrap gap-1.5">
      <?php foreach ((array) ($auth['scopes'] ?? []) as $scope): ?>
        <span class="badge badge-outline font-mono text-[11px]" data-tooltip="<?= $this->e($this->scopeHint((string) $scope)) ?>"><?= $this->e((string) $scope) ?></span>
      <?php endforeach ?>
    </div>
  </div>
</div>

<div class="card mb-5">
  <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('plug', 'h-4 w-4') ?> Методы</div></div>
  <div class="card-content p-0">
    <div class="table-wrap rounded-none border-0">
      <table class="table">
        <thead><tr><th class="w-20">Метод</th><th>Путь</th><th>Описание</th><th>Области</th></tr></thead>
        <tbody>
        <?php foreach ($endpoints as $endpoint): ?>
          <tr>
            <td><span class="badge badge-soft-<?= $methodTone[(string) ($endpoint['method'] ?? 'GET')] ?? 'info' ?> font-mono text-[11px]"><?= $this->e((string) ($endpoint['method'] ?? '')) ?></span></td>
            <td><code class="font-mono text-xs"><?= $this->e((string) ($endpoint['path'] ?? '')) ?></code></td>
            <td class="text-sm"><?= $this->e((string) ($endpoint['description'] ?? '')) ?>
              <?php foreach (['body', 'query'] as $kind): ?>
                <?php if (!empty($endpoint[$kind])): ?>
                  <div class="mt-1 flex flex-wrap gap-1">
                    <?php foreach ((array) $endpoint[$kind] as $param => $hint): ?>
                      <span class="badge badge-muted font-mono text-[10px]" data-tooltip="<?= $this->e((string) $hint) ?>"><?= $this->e((string) $param) ?></span>
                    <?php endforeach ?>
                  </div>
                <?php endif ?>
              <?php endforeach ?>
            </td>
            <td>
              <div class="flex flex-wrap gap-1">
                <?php if (!empty($endpoint['auth']) === false): ?><span class="badge badge-muted">публичный</span><?php endif ?>
                <?php foreach ((array) ($endpoint['scopes'] ?? []) as $scope): ?>
                  <span class="badge badge-outline font-mono text-[10px]"><?= $this->e((string) $scope) ?></span>
                <?php endforeach ?>
              </div>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="grid gap-5 lg:grid-cols-2">
  <div class="card">
    <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('alert-circle', 'h-4 w-4') ?> Коды ошибок</div></div>
    <div class="card-content p-0">
      <div class="table-wrap rounded-none border-0">
        <table class="table">
          <thead><tr><th class="w-20">Код</th><th>Значение</th></tr></thead>
          <tbody>
          <?php foreach ((array) ($docs['errors'] ?? []) as $code => $description): ?>
            <tr><td><span class="badge badge-outline font-mono"><?= $this->e((string) $code) ?></span></td><td class="text-sm"><?= $this->e((string) $description) ?></td></tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('terminal', 'h-4 w-4') ?> Примеры</div></div>
    <div class="card-content">
      <pre class="code-block"><code><?= $this->e(implode("\n\n", (array) ($docs['examples'] ?? []))) ?></code></pre>
    </div>
  </div>
</div>

<div class="card mt-5">
  <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('gauge', 'h-4 w-4') ?> Лимиты запросов</div></div>
  <div class="card-content">
    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
      <?php foreach ((array) ($docs['rate_limits'] ?? []) as $name => $rule): ?>
        <div class="rounded-lg border p-3">
          <div class="font-mono text-xs font-semibold"><?= $this->e((string) $name) ?></div>
          <div class="mt-0.5 text-sm text-muted-foreground"><?= (int) ($rule['max'] ?? 0) ?> запросов / <?= (int) ($rule['window'] ?? 60) ?> с</div>
        </div>
      <?php endforeach ?>
    </div>
    <p class="mt-3 text-xs text-muted-foreground">
      Текущие лимиты возвращаются в заголовках <code class="inline-code">X-RateLimit-Limit</code>,
      <code class="inline-code">X-RateLimit-Remaining</code>, <code class="inline-code">X-RateLimit-Reset</code>.
      При превышении — <code class="inline-code">429</code> с <code class="inline-code">Retry-After</code>.
    </p>
  </div>
</div>
