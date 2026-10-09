<?php
/**
 * Guest pass administration.
 *
 * @var BasIp\Support\Paginator $passes
 * @var array $filters
 * @var array $query
 * @var array $stats
 * @var array $residents
 * @var array $presets
 * @var int $defaultTtl
 * @var int $maxTtl
 * @var int $limit
 * @var array|null $justCreated
 * @var bool $canCreate
 * @var bool $canRevoke
 * @var bool $canOpen
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$items = $passes->items();
$baseUrl = rtrim((string) $this->config('app.url', ''), '/');
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Гостевые доступы</h2>
    <p class="page-desc">Пропуска на <?= (int) $defaultTtl ?> часов по умолчанию. Ссылка одноразовая, в базе хранится только её хеш.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/admin/guest/export', $query)) ?>"><?= $this->icon('download', 'h-3.5 w-3.5') ?> CSV</a>
    <?php if ($canCreate): ?>
      <button class="btn btn-default btn-sm" type="button" data-dialog="#dialog-guest-new" data-shortcut="guest">
        <?= $this->icon('user-plus', 'h-3.5 w-3.5') ?> Создать пропуск
      </button>
    <?php endif ?>
  </div>
</div>

<?php if (!empty($justCreated)): ?>
  <div class="card mb-5 border-success/40">
    <div class="card-header">
      <div class="card-title flex items-center gap-2 text-success"><?= $this->icon('ticket', 'h-4 w-4') ?> Пропуск создан</div>
      <div class="card-desc">Ссылка и PIN показываются один раз — скопируйте или отправьте гостю сейчас.</div>
    </div>
    <div class="card-content grid gap-4 sm:grid-cols-[auto_minmax(0,1fr)]">
      <div class="qr-frame mx-auto w-40" data-qr="<?= $this->e($baseUrl . $this->url('/guest/' . rawurlencode((string) ($justCreated['link'] ?? '')))) ?>" data-qr-size="160"></div>
      <div class="grid gap-2.5">
        <?php if (!empty($justCreated['resident'])): ?>
          <div class="text-sm text-muted-foreground">Для: <b class="text-foreground"><?= $this->e((string) $justCreated['resident']) ?></b></div>
        <?php endif ?>
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-xs uppercase tracking-wide text-muted-foreground">Код</span>
          <code class="font-mono text-xl font-semibold tracking-wider"><?= $this->e((string) ($justCreated['code'] ?? '')) ?></code>
          <button class="btn btn-ghost btn-sm btn-icon" type="button" data-copy="<?= $this->e((string) ($justCreated['code'] ?? '')) ?>" data-copy-message="Код скопирован" aria-label="Скопировать код"><?= $this->icon('copy', 'h-3.5 w-3.5') ?></button>
        </div>
        <?php if (!empty($justCreated['pin'])): ?>
          <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs uppercase tracking-wide text-muted-foreground">PIN</span>
            <code class="font-mono text-lg font-semibold tabular"><?= $this->e((string) $justCreated['pin']) ?></code>
            <button class="btn btn-ghost btn-sm btn-icon" type="button" data-copy="<?= $this->e((string) $justCreated['pin']) ?>" data-copy-message="PIN скопирован" aria-label="Скопировать PIN"><?= $this->icon('copy', 'h-3.5 w-3.5') ?></button>
          </div>
        <?php endif ?>
        <div class="grid gap-1.5">
          <span class="text-xs uppercase tracking-wide text-muted-foreground">Ссылка для гостя</span>
          <div class="flex items-center gap-2">
            <code class="input input-mono flex-1 text-xs" id="guest-link" readonly value="<?= $this->e($baseUrl . (string) ($justCreated['link'] ?? '')) ?>">
            <button class="btn btn-outline btn-sm" type="button" data-copy="#guest-link" data-copy-message="Ссылка скопирована"><?= $this->icon('copy', 'h-3.5 w-3.5') ?> Копировать</button>
          </div>
        </div>
        <div class="text-xs text-muted-foreground">Действует до: <b class="text-foreground"><?= $this->e($this->datetime($justCreated['expires_at'] ?? null)) ?></b></div>
      </div>
    </div>
  </div>
<?php endif ?>

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
  <?= $this->include('components/stat', ['label' => 'Активных', 'value' => (string) (int) ($stats['active'] ?? 0), 'icon' => 'ticket', 'accent' => 'success']) ?>
  <?= $this->include('components/stat', ['label' => 'Создано сегодня', 'value' => (string) (int) ($stats['today'] ?? 0), 'icon' => 'calendar-check']) ?>
  <?= $this->include('components/stat', ['label' => 'За ' . (int) ($query['days'] ?? 30) . ' дн.', 'value' => (string) (int) ($stats['period'] ?? 0), 'icon' => 'user-plus', 'hint' => 'использований: ' . (int) ($stats['used'] ?? 0)]) ?>
  <?= $this->include('components/stat', ['label' => 'Отозвано', 'value' => (string) (int) ($stats['revoked'] ?? 0), 'icon' => 'ban', 'accent' => (int) ($stats['revoked'] ?? 0) > 0 ? 'warning' : '']) ?>
  <?= $this->include('components/stat', ['label' => 'Истекло', 'value' => (string) (int) ($stats['expired'] ?? 0), 'icon' => 'timer']) ?>
</div>

<div class="card mt-5">
  <form class="filter-bar rounded-t-xl" method="get" action="<?= $this->e($this->url('/admin/guest')) ?>">
    <div class="filter-field min-w-[14rem] flex-[2]">
      <label class="label" for="q">Поиск</label>
      <input class="input" id="q" name="q" type="search" value="<?= $this->e((string) ($filters['q'] ?? '')) ?>" placeholder="Код, имя гостя, телефон, госномер, житель">
    </div>
    <div class="filter-field">
      <label class="label" for="status">Статус</label>
      <select class="select" id="status" name="status">
        <?php foreach (['active' => 'Активные', 'expired' => 'Истёкшие', 'revoked' => 'Отозванные', 'used' => 'Использованные', 'all' => 'Все'] as $value => $label): ?>
          <option value="<?= $value ?>" <?= $this->selected($filters['status'] ?? 'active', $value) ?>><?= $label ?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="filter-field">
      <label class="label" for="resident_id">Житель</label>
      <select class="select" id="resident_id" name="resident_id">
        <option value="">Все</option>
        <?php foreach ($residents as $resident): ?>
          <option value="<?= (int) $resident['id'] ?>" <?= (string) ($filters['resident_id'] ?? '') === (string) $resident['id'] ? 'selected' : '' ?>>
            кв. <?= $this->e((string) $resident['apartment']) ?> · <?= $this->e(\BasIp\Support\Str::limit((string) $resident['full_name'], 24)) ?>
          </option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="filter-field">
      <label class="label" for="from">Создан с</label>
      <input class="input" id="from" name="from" type="date" value="<?= $this->e((string) ($filters['from'] ?? '')) ?>" data-date-from>
    </div>
    <div class="filter-field">
      <label class="label" for="to">по</label>
      <input class="input" id="to" name="to" type="date" value="<?= $this->e((string) ($filters['to'] ?? '')) ?>" data-date-to>
    </div>
    <div class="flex items-center gap-2">
      <button class="btn btn-default btn-sm" type="submit"><?= $this->icon('filter', 'h-3.5 w-3.5') ?> Применить</button>
      <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/admin/guest')) ?>"><?= $this->icon('rotate-ccw', 'h-3.5 w-3.5') ?> Сброс</a>
    </div>
  </form>

  <div class="card-content p-0">
    <?php if ($items === []): ?>
      <?= $this->include('components/empty', ['title' => 'Пропусков нет', 'description' => 'Создайте гостевой доступ — гость получит ссылку, код и PIN.', 'icon' => 'user-plus']) ?>
    <?php else: ?>
      <div class="table-wrap rounded-none border-0">
        <table class="table">
          <thead>
            <tr><th>Код</th><th>Житель</th><th>Гость</th><th>Авто</th><th>Действует</th><th>Использований</th><th>Статус</th><th>Кем создан</th><th class="text-right">Действия</th></tr>
          </thead>
          <tbody>
          <?php foreach ($items as $pass): ?>
            <tr class="<?= $pass->isActive() ? '' : 'is-inactive' ?>">
              <td>
                <div class="font-mono text-sm font-semibold"><?= $this->e($pass->code) ?></div>
                <?php if ($pass->pin !== null): ?><div class="text-[11px] text-muted-foreground">PIN <?= $this->e($pass->pin) ?></div><?php endif ?>
              </td>
              <td>
                <?php if ($pass->residentId !== null): ?>
                  <a class="text-sm hover:underline" href="<?= $this->e($this->url('/admin/residents/' . (int) $pass->residentId)) ?>"><?= $this->e($pass->residentName ?? '—') ?></a>
                  <div class="text-xs text-muted-foreground">кв. <?= $this->e($pass->apartment ?? '—') ?></div>
                <?php else: ?>
                  <span class="text-xs text-muted-foreground">—</span>
                <?php endif ?>
              </td>
              <td>
                <div class="text-sm"><?= $this->e($pass->guestName ?? '—') ?></div>
                <?php if ($pass->guestPhone !== null): ?><div class="text-xs text-muted-foreground"><?= $this->e(\BasIp\Support\Phone::format($pass->guestPhone)) ?></div><?php endif ?>
                <?php if ($pass->purpose !== null): ?><div class="text-xs text-muted-foreground"><?= $this->e($pass->purpose) ?></div><?php endif ?>
              </td>
              <td class="font-mono text-xs"><?= $this->e($pass->displayPlate() ?: '—') ?></td>
              <td>
                <div class="whitespace-nowrap text-xs tabular"><?= $this->e($this->datetime($pass->validUntil, 'd.m.Y H:i')) ?></div>
                <?php if ($pass->isActive()): ?>
                  <div class="mt-1 h-1 w-24 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-success" style="width: <?= 100 - (float) $pass->percentElapsed() ?>%"></div></div>
                  <div class="text-[11px] text-muted-foreground" data-countdown="<?= $this->e((string) $pass->validUntil) ?>"></div>
                <?php endif ?>
              </td>
              <td class="tabular text-sm"><?= $this->e($pass->usesLabel()) ?><?php if ($pass->lastUsedAt !== null): ?><div class="text-[11px] text-muted-foreground"><?= $this->e($this->datetime($pass->lastUsedAt, 'd.m H:i')) ?></div><?php endif ?></td>
              <td><span class="badge badge-soft-<?= $pass->statusTone() === 'muted' ? 'warning' : $pass->statusTone() ?>"><?= $this->e($pass->statusLabel()) ?></span></td>
              <td class="text-xs text-muted-foreground"><?= $this->e($pass->createdByType === 'staff' ? 'диспетчер' : 'житель') ?></td>
              <td class="text-right">
                <div class="flex justify-end gap-1">
                  <?php if ($canOpen && $pass->isActive()): ?>
                    <button class="btn btn-ghost btn-sm btn-icon text-success" type="button"
                            data-device-command="open" data-device-url="<?= $this->e($this->url('/admin/guest/' . $pass->id . '/open')) ?>"
                            data-tooltip="Открыть по этому пропуску" aria-label="Открыть"><?= $this->icon('door-open', 'h-3.5 w-3.5') ?></button>
                  <?php endif ?>
                  <?php if ($canCreate && $pass->isActive()): ?>
                    <button class="btn btn-ghost btn-sm btn-icon" type="button" data-dialog="#dialog-extend-<?= (int) $pass->id ?>" data-tooltip="Продлить" aria-label="Продлить"><?= $this->icon('timer', 'h-3.5 w-3.5') ?></button>
                  <?php endif ?>
                  <?php if ($canRevoke && !$pass->isRevoked()): ?>
                    <form method="post" action="<?= $this->e($this->url('/admin/guest/' . $pass->id . '/revoke')) ?>" class="inline" data-ajax>
                      <?= $this->csrf() ?>
                      <button class="btn btn-ghost btn-sm btn-icon text-destructive" type="submit" aria-label="Отозвать" data-tooltip="Отозвать"
                              data-confirm="Отозвать пропуск <?= $this->e($pass->code) ?>?" data-confirm-label="Отозвать" data-confirm-tone="destructive">
                        <?= $this->icon('ban', 'h-3.5 w-3.5') ?>
                      </button>
                    </form>
                  <?php endif ?>
                  <a class="btn btn-ghost btn-sm btn-icon" href="<?= $this->e($this->url('/admin/gate?guest_pass_id=' . $pass->id)) ?>" data-tooltip="Открытия по пропуску" aria-label="Журнал"><?= $this->icon('history', 'h-3.5 w-3.5') ?></a>
                </div>
              </td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <div class="p-4"><?= $this->include('components/pagination', ['paginator' => $passes, 'query' => $query, 'target' => '/admin/guest']) ?></div>
    <?php endif ?>
  </div>
</div>

<?php /* ── создание ─────────────────────────────────────────── */ ?>
<?php if ($canCreate): ?>
  <div class="dialog dialog-lg" id="dialog-guest-new" data-state="closed" hidden role="dialog" aria-labelledby="gn-t">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <form method="post" action="<?= $this->e($this->url('/admin/guest')) ?>" data-guest-form data-ajax data-redirect="<?= $this->e($this->url('/admin/guest')) ?>">
      <?= $this->csrf() ?>
      <div class="dialog-header">
        <h2 class="dialog-title" id="gn-t">Новый гостевой доступ</h2>
        <p class="dialog-desc">Выдаётся от имени жителя. Все открытия по пропуску записываются в журнал с пометкой «гостевой».</p>
      </div>
      <div class="grid gap-4">
        <div class="field">
          <label class="label" for="gn-resident">Житель <span class="text-destructive">*</span></label>
          <select class="select" id="gn-resident" name="resident_id" required>
            <option value="">— выберите —</option>
            <?php foreach ($residents as $resident): ?>
              <option value="<?= (int) $resident['id'] ?>">кв. <?= $this->e((string) $resident['apartment']) ?> · <?= $this->e((string) $resident['full_name']) ?></option>
            <?php endforeach ?>
          </select>
        </div>
        <div class="field">
          <div class="label mb-1.5">Срок действия</div>
          <div class="flex flex-wrap gap-1.5" data-guest-presets>
            <?php foreach (($presets ?: [1, 2, 4, 8, 12, 24, 48, 72]) as $preset): ?>
              <button type="button" class="chip <?= (int) $preset === (int) $defaultTtl ? 'is-active' : '' ?>" data-guest-preset="<?= (int) $preset ?>"><?= (int) $preset ?> ч</button>
            <?php endforeach ?>
          </div>
          <input class="input mt-2" type="number" name="ttl_hours" min="1" max="<?= (int) $maxTtl ?>" value="<?= (int) $defaultTtl ?>" data-guest-ttl>
          <p class="text-xs text-muted-foreground">Действует до: <b data-guest-until>—</b></p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="gn-name">Кто приезжает</label>
            <input class="input" id="gn-name" name="guest_name" type="text" maxlength="190" placeholder="Курьер, гости, мастер">
          </div>
          <div class="field">
            <label class="label" for="gn-phone">Телефон гостя</label>
            <input class="input" id="gn-phone" name="guest_phone" type="tel" inputmode="tel" maxlength="32" placeholder="+7…" data-phone>
          </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
          <div class="field">
            <label class="label" for="gn-plate">Госномер</label>
            <input class="input input-mono" id="gn-plate" name="car_plate" type="text" maxlength="16" placeholder="А123ВС77">
          </div>
          <div class="field">
            <label class="label" for="gn-uses">Лимит открытий</label>
            <input class="input" id="gn-uses" name="max_uses" type="number" min="0" value="0" data-tooltip="0 — без ограничений">
          </div>
          <div class="field">
            <label class="label" for="gn-purpose">Цель визита</label>
            <input class="input" id="gn-purpose" name="purpose" type="text" maxlength="190" placeholder="Доставка">
          </div>
        </div>
        <div class="grid gap-2 rounded-lg border bg-muted/25 p-3 sm:grid-cols-2">
          <label class="checkbox-row text-sm"><input class="checkbox" type="checkbox" name="notify" value="1" checked><span>Уведомить жителя</span></label>
          <label class="checkbox-row text-sm"><input class="checkbox" type="checkbox" name="notify_guest" value="1"><span>Отправить SMS гостю</span></label>
          <label class="checkbox-row text-sm"><input class="checkbox" type="checkbox" name="notify_on_use" value="1"><span>Уведомлять жителя при каждом открытии</span></label>
        </div>
      </div>
      <div class="dialog-footer mt-4">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="submit" class="btn btn-default"><?= $this->icon('user-plus') ?> Создать пропуск</button>
      </div>
    </form>
  </div>
<?php endif ?>

<?php /* ── продление ────────────────────────────────────────── */ ?>
<?php foreach ($items as $pass): if (!$pass->isActive()) { continue; } ?>
  <div class="dialog dialog-sm" id="dialog-extend-<?= (int) $pass->id ?>" data-state="closed" hidden role="dialog" aria-labelledby="ex-t-<?= (int) $pass->id ?>">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <form method="post" action="<?= $this->e($this->url('/admin/guest/' . $pass->id . '/extend')) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/guest', $query)) ?>">
      <?= $this->csrf() ?>
      <div class="dialog-header">
        <h2 class="dialog-title" id="ex-t-<?= (int) $pass->id ?>">Продлить пропуск</h2>
        <p class="dialog-desc"><?= $this->e($pass->code) ?> · до <?= $this->e($this->datetime($pass->validUntil, 'd.m.Y H:i')) ?></p>
      </div>
      <div class="field">
        <label class="label" for="ex-h-<?= (int) $pass->id ?>">Продлить на, часов</label>
        <input class="input" id="ex-h-<?= (int) $pass->id ?>" name="hours" type="number" min="1" max="<?= (int) $maxTtl ?>" value="<?= (int) $defaultTtl ?>">
      </div>
      <div class="dialog-footer mt-4">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="submit" class="btn btn-default"><?= $this->icon('timer') ?> Продлить</button>
      </div>
    </form>
  </div>
<?php endforeach ?>
