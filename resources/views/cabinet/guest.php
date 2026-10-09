<?php
/**
 * Resident: full list of guest passes + issuing form.
 *
 * @var BasIp\Domain\Model\Resident|null $resident
 * @var BasIp\Support\Paginator $passes
 * @var int $activeCount
 * @var int $todayCount
 * @var int $limit
 * @var int $dailyLimit
 * @var array $presets
 * @var int $defaultTtl
 * @var bool $requirePlate
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$query = $this->shared['query'] ?? [];
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div>
    <h2 class="page-title">Гостевые доступы</h2>
    <p class="page-desc">Пропуск действует <?= (int) $defaultTtl ?> часов по умолчанию. Все открытия по нему записываются в журнал.</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-default" data-dialog="#dialog-guest-new" data-shortcut="guest">
      <?= $this->icon('user-plus') ?> Создать пропуск
    </button>
  </div>
</div>

<div class="grid gap-3 sm:grid-cols-4">
  <?= $this->include('components/stat', ['label' => 'Активных', 'value' => (string) $activeCount, 'icon' => 'ticket', 'hint' => 'лимит ' . $limit, 'accent' => 'success']) ?>
  <?= $this->include('components/stat', ['label' => 'Создано сегодня', 'value' => (string) $todayCount, 'icon' => 'calendar-check', 'hint' => 'лимит ' . $dailyLimit]) ?>
  <?= $this->include('components/stat', ['label' => 'Всего в списке', 'value' => (string) $passes->total(), 'icon' => 'list']) ?>
  <?= $this->include('components/stat', ['label' => 'Срок по умолчанию', 'value' => $defaultTtl . ' ч', 'icon' => 'timer']) ?>
</div>

<div class="card mt-5">
  <div class="table-toolbar">
    <div class="tabs-list is-scrollable" role="tablist">
      <?php foreach ([['active', 'Активные'], ['expired', 'Истёкшие'], ['revoked', 'Отозванные'], ['all', 'Все']] as [$value, $label]): ?>
        <a class="tabs-trigger <?= ($query['status'] ?? 'active') === $value ? 'bg-background shadow-sm text-foreground' : '' ?>"
           href="<?= $this->e($this->url('/cabinet/guest', array_merge($query, ['status' => $value]))) ?>"><?= $label ?></a>
      <?php endforeach ?>
    </div>
  </div>
  <div class="card-content p-0">
    <?php if ($passes->isEmpty()): ?>
      <?= $this->include('components/empty', [
        'title' => 'Пропусков нет',
        'description' => 'Создайте гостевой доступ — гость получит ссылку и сможет открыть шлагбаум сам.',
        'icon' => 'user-plus',
        'actionLabel' => 'Создать пропуск',
        'actionUrl' => null,
        'actionHtml' => '<button class="btn btn-default" data-dialog="#dialog-guest-new">Создать пропуск</button>',
      ]) ?>
    <?php else: ?>
      <div class="table-wrap rounded-none border-0">
        <table class="table">
          <thead>
            <tr><th>Код</th><th>Гость</th><th>Авто</th><th>Действует</th><th>Использований</th><th>Статус</th><th class="text-right">Действия</th></tr>
          </thead>
          <tbody>
          <?php foreach ($passes->items() as $pass): ?>
            <tr>
              <td>
                <div class="font-mono text-sm font-semibold"><?= $this->e($pass->code) ?></div>
                <?php if ($pass->pin !== null): ?><div class="text-xs text-muted-foreground">PIN <?= $this->e($pass->pin) ?></div><?php endif ?>
              </td>
              <td>
                <div class="text-sm"><?= $this->e($pass->guestName ?? '—') ?></div>
                <div class="text-xs text-muted-foreground"><?= $this->e($pass->purpose ?? '') ?></div>
              </td>
              <td class="font-mono text-xs"><?= $this->e($pass->displayPlate() ?: '—') ?></td>
              <td>
                <div class="whitespace-nowrap text-xs tabular"><?= $this->e($this->datetime($pass->validUntil, 'd.m.Y H:i')) ?></div>
                <div class="mt-1 h-1 w-24 overflow-hidden rounded-full bg-muted">
                  <div class="h-full rounded-full <?= $pass->isActive() ? 'bg-success' : 'bg-muted-foreground/40' ?>" style="width: <?= 100 - (float) $pass->percentElapsed() ?>%"></div>
                </div>
              </td>
              <td class="tabular text-sm"><?= $this->e($pass->usesLabel()) ?></td>
              <td><span class="badge badge-soft-<?= $pass->statusTone() === 'muted' ? 'warning' : $pass->statusTone() ?>"><?= $this->e($pass->statusLabel()) ?></span></td>
              <td class="text-right">
                <div class="flex justify-end gap-1">
                  <button class="btn btn-ghost btn-sm btn-icon" type="button"
                          data-copy="<?= $this->e($this->url('/guest/' . $pass->uuid)) ?>" data-copy-message="Ссылка недоступна после создания — используйте код"
                          data-tooltip="Код: <?= $this->e($pass->code) ?>" aria-label="Показать код">
                    <?= $this->icon('hash', 'h-3.5 w-3.5') ?>
                  </button>
                  <?php if ($pass->isActive()): ?>
                    <form method="post" action="<?= $this->e($this->url('/cabinet/guest/' . $pass->id . '/revoke')) ?>" data-ajax class="inline">
                      <?= $this->csrf() ?>
                      <button class="btn btn-ghost btn-sm btn-icon text-destructive" type="submit" aria-label="Отозвать"
                              data-confirm="Отозвать пропуск <?= $this->e($pass->code) ?>?" data-confirm-label="Отозвать" data-confirm-tone="destructive">
                        <?= $this->icon('ban', 'h-3.5 w-3.5') ?>
                      </button>
                    </form>
                  <?php endif ?>
                </div>
              </td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <div class="p-4">
        <?= $this->include('components/pagination', ['paginator' => $passes, 'query' => $query, 'target' => '/cabinet/guest']) ?>
      </div>
    <?php endif ?>
  </div>
</div>

<?php /* ── диалог создания ───────────────────────────────────── */ ?>
<div class="dialog" id="dialog-guest-new" data-state="closed" hidden role="dialog" aria-labelledby="guest-new-title">
  <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
  <div class="dialog-header">
    <h2 class="dialog-title" id="guest-new-title">Новый гостевой доступ</h2>
    <p class="dialog-desc">Гость получит ссылку и код. Открытие по ним записывается в журнал с указанием, кто выдал пропуск.</p>
  </div>
  <form method="post" action="<?= $this->e($this->url('/cabinet/guest')) ?>" data-guest-form data-ajax data-redirect="<?= $this->e($this->url('/cabinet/guest')) ?>">
    <?= $this->csrf() ?>
    <input type="hidden" name="resident_id" value="<?= (int) ($resident?->id ?? 0) ?>">
    <div class="grid gap-4">
      <div class="field">
        <div class="label mb-1.5">Срок действия</div>
        <div class="flex flex-wrap gap-1.5" data-guest-presets>
          <?php foreach (($presets ?: [1, 2, 4, 8, 12, 24, 48, 72]) as $preset): ?>
            <button type="button" class="chip <?= (int) $preset === (int) $defaultTtl ? 'is-active' : '' ?>" data-guest-preset="<?= (int) $preset ?>"><?= (int) $preset ?> ч</button>
          <?php endforeach ?>
        </div>
        <input class="input mt-2" type="number" name="ttl_hours" min="1" max="<?= (int) $this->config('guest.max_ttl_hours', 72) ?>" value="<?= (int) $defaultTtl ?>" data-guest-ttl>
        <p class="text-xs text-muted-foreground">Действует до: <b data-guest-until>—</b></p>
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="field">
          <label class="label" for="g_name">Кто приезжает</label>
          <input class="input" id="g_name" name="guest_name" type="text" maxlength="190" placeholder="Курьер, гости">
        </div>
        <div class="field">
          <label class="label" for="g_plate">Госномер <?= $requirePlate ? '<span class="text-destructive">*</span>' : '' ?></label>
          <input class="input input-mono" id="g_plate" name="car_plate" type="text" maxlength="16" placeholder="А123ВС77" <?= $requirePlate ? 'required' : '' ?>>
        </div>
      </div>
      <div class="field">
        <label class="label" for="g_phone">Телефон гостя</label>
        <input class="input" id="g_phone" name="guest_phone" type="tel" inputmode="tel" maxlength="32" placeholder="+7…" data-phone>
      </div>
      <div class="field">
        <label class="label" for="g_purpose">Цель визита</label>
        <input class="input" id="g_purpose" name="purpose" type="text" maxlength="190" placeholder="Доставка, ремонт, гости">
      </div>
      <label class="checkbox-row text-sm">
        <input class="checkbox" type="checkbox" name="notify" value="1" checked>
        <span>Прислать мне ссылку и код</span>
      </label>
      <label class="checkbox-row text-sm">
        <input class="checkbox" type="checkbox" name="notify_guest" value="1">
        <span>Отправить SMS гостю <span class="label-hint">Нужен настроенный SMS-шлюз.</span></span>
      </label>
    </div>
    <div class="dialog-footer mt-4">
      <button type="button" class="btn btn-outline" data-close>Отмена</button>
      <button type="submit" class="btn btn-default"><?= $this->icon('user-plus') ?> Создать</button>
    </div>
  </form>
</div>
