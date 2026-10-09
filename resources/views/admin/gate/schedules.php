<?php
/**
 * Access schedules + quiet hours.
 *
 * @var array $schedules
 * @var array $residents
 * @var array $days
 * @var array $quietHours
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Расписания доступа</h2>
    <p class="page-desc">Окна, когда открытие разрешено или запрещено — глобально или для конкретного жителя.</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-default btn-sm" type="button" data-dialog="#dialog-schedule-new"><?= $this->icon('plus', 'h-3.5 w-3.5') ?> Новое расписание</button>
  </div>
</div>

<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
  <div class="grid content-start gap-5">
    <section class="card">
      <div class="card-header">
        <div class="card-title flex items-center gap-2"><?= $this->icon('clock', 'h-4 w-4') ?> Действующие правила</div>
        <div class="card-desc">Правила с большим приоритетом применяются первыми. «Запрет» перекрывает «Разрешение».</div>
      </div>
      <div class="card-content p-0">
        <?php if ($schedules === []): ?>
          <?= $this->include('components/empty', ['title' => 'Расписаний нет', 'description' => 'По умолчанию шлагбаум открывается в любое время для всех, у кого есть доступ.', 'icon' => 'clock']) ?>
        <?php else: ?>
          <div class="table-wrap rounded-none border-0">
            <table class="table">
              <thead><tr><th>Название</th><th>Кому</th><th>Дни</th><th>Окно</th><th>Эффект</th><th class="text-right">Приоритет</th><th>Статус</th><th></th></tr></thead>
              <tbody>
              <?php foreach ($schedules as $schedule): ?>
                <tr class="<?= empty($schedule['is_active']) ? 'is-inactive' : '' ?>">
                  <td>
                    <div class="text-sm font-medium"><?= $this->e((string) $schedule['name']) ?></div>
                    <?php if (!empty($schedule['notes'])): ?><div class="text-xs text-muted-foreground"><?= $this->e((string) $schedule['notes']) ?></div><?php endif ?>
                  </td>
                  <td class="text-sm">
                    <?php if ($schedule['resident_id'] === null): ?>
                      <span class="badge badge-outline">все</span>
                    <?php else: ?>
                      <?php $found = null; foreach ($residents as $r) { if ((int) $r['id'] === (int) $schedule['resident_id']) { $found = $r; break; } } ?>
                      <a class="hover:underline" href="<?= $this->e($this->url('/admin/residents/' . (int) $schedule['resident_id'])) ?>">
                        <?= $this->e($found !== null ? $found['full_name'] : '#' . $schedule['resident_id']) ?>
                      </a>
                    <?php endif ?>
                  </td>
                  <td class="text-xs">
                    <?php $ruleDays = array_map('intval', array_filter(explode(',', (string) $schedule['days']))); ?>
                    <?php if (count($ruleDays) >= 7): ?><span class="badge badge-muted">ежедневно</span>
                    <?php else: ?><?= $this->e(implode(' ', array_map(static fn (int $d): string => $days[$d] ?? (string) $d, $ruleDays))) ?><?php endif ?>
                  </td>
                  <td class="font-mono text-xs tabular"><?= $this->e((string) $schedule['start_time']) ?>–<?= $this->e((string) $schedule['end_time']) ?></td>
                  <td>
                    <span class="badge badge-soft-<?= ($schedule['effect'] ?? 'allow') === 'deny' ? 'destructive' : 'success' ?>">
                      <?= ($schedule['effect'] ?? 'allow') === 'deny' ? 'запрет' : 'разрешение' ?>
                    </span>
                  </td>
                  <td class="table-numeric tabular text-xs"><?= (int) $schedule['priority'] ?></td>
                  <td><?= !empty($schedule['is_active']) ? '<span class="badge badge-soft-success">включено</span>' : '<span class="badge badge-muted">выключено</span>' ?></td>
                  <td class="text-right">
                    <div class="flex justify-end gap-1">
                      <button class="btn btn-ghost btn-sm btn-icon" type="button" data-dialog="#dialog-schedule-<?= (int) $schedule['id'] ?>" aria-label="Изменить"><?= $this->icon('pencil', 'h-3.5 w-3.5') ?></button>
                      <form method="post" action="<?= $this->e($this->url('/admin/gate/schedules/' . (int) $schedule['id'] . '/delete')) ?>" class="inline">
                        <?= $this->csrf() ?>
                        <button class="btn btn-ghost btn-sm btn-icon text-destructive" type="submit" aria-label="Удалить"
                                data-confirm="Удалить расписание «<?= $this->e((string) $schedule['name']) ?>»?" data-confirm-label="Удалить" data-confirm-tone="destructive">
                          <?= $this->icon('trash', 'h-3.5 w-3.5') ?>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach ?>
              </tbody>
            </table>
          </div>
        <?php endif ?>
      </div>
    </section>
  </div>

  <aside class="grid content-start gap-5">
    <form class="card" method="post" action="<?= $this->e($this->url('/admin/gate/schedules')) ?>">
      <?= $this->csrf() ?>
      <div class="card-header">
        <div class="card-title flex items-center gap-2"><?= $this->icon('moon', 'h-4 w-4') ?> Тихие часы</div>
        <div class="card-desc">Ночью шлагбаум открывает только диспетчер. Сейчас: <?= !empty($quietHours['active']) ? '<b class="text-warning">идут тихие часы</b>' : '<b class="text-success">обычный режим</b>' ?></div>
      </div>
      <div class="card-content grid gap-3">
        <label class="checkbox-row text-sm">
          <input class="checkbox" type="checkbox" name="quiet_enabled" value="1" <?= !empty($quietHours['enabled']) ? 'checked' : '' ?>>
          <span>Включить тихие часы</span>
        </label>
        <div class="grid grid-cols-2 gap-3">
          <div class="field">
            <label class="label" for="quiet_start">Начало</label>
            <input class="input" id="quiet_start" name="quiet_start" type="time" value="<?= $this->e((string) $quietHours['start']) ?>">
          </div>
          <div class="field">
            <label class="label" for="quiet_end">Конец</label>
            <input class="input" id="quiet_end" name="quiet_end" type="time" value="<?= $this->e((string) $quietHours['end']) ?>">
          </div>
        </div>
        <label class="checkbox-row text-sm">
          <input class="checkbox" type="checkbox" name="quiet_allow_guest" value="1" <?= $this->setting('gate.allow_guest_at_night', true) ? 'checked' : '' ?>>
          <span>Разрешать гостевой доступ ночью <span class="label-hint">Курьеры и скорая смогут въехать.</span></span>
        </label>
        <button class="btn btn-outline btn-block" type="submit"><?= $this->icon('save', 'h-4 w-4') ?> Сохранить тихие часы</button>
      </div>
    </form>

    <div class="alert alert-muted">
      <?= $this->icon('info', 'h-4 w-4') ?>
      <div>
        <div class="alert-title">Порядок проверок</div>
        <div class="alert-desc">
          При каждом открытии система проверяет: права → гостевой пропуск → флаги жителя → паузу между открытиями → лимит запросов →
          тихие часы → расписания → (опционально) задолженность. Отказ записывается в журнал с причиной.
        </div>
      </div>
    </div>
  </aside>
</div>

<?php /* ── диалоги создания/правки ─────────────────────────── */ ?>
<?php
$renderForm = function (?array $schedule) use ($days, $residents): void {
    $id = (int) ($schedule['id'] ?? 0);
    $ruleDays = $schedule !== null ? array_map('intval', array_filter(explode(',', (string) $schedule['days']))) : [1, 2, 3, 4, 5, 6, 7];
    ?>
    <div class="grid gap-3">
      <?php if ($id > 0): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif ?>
      <div class="field">
        <label class="label" for="sch-name-<?= $id ?>">Название</label>
        <input class="input" id="sch-name-<?= $id ?>" name="name" type="text" maxlength="120" required value="<?= $this->e((string) ($schedule['name'] ?? '')) ?>" placeholder="Ночной запрет">
      </div>
      <div class="field">
        <label class="label" for="sch-resident-<?= $id ?>">Для кого</label>
        <select class="select" id="sch-resident-<?= $id ?>" name="resident_id">
          <option value="">Всех жителей</option>
          <?php foreach ($residents as $resident): ?>
            <option value="<?= (int) $resident['id'] ?>" <?= (int) ($schedule['resident_id'] ?? 0) === (int) $resident['id'] ? 'selected' : '' ?>>
              кв. <?= $this->e((string) $resident['apartment']) ?> · <?= $this->e((string) $resident['full_name']) ?>
            </option>
          <?php endforeach ?>
        </select>
      </div>
      <div class="field">
        <div class="label mb-1.5">Дни недели</div>
        <div class="flex flex-wrap gap-1.5">
          <?php foreach ($days as $num => $label): ?>
            <label class="chip <?= in_array((int) $num, $ruleDays, true) ? 'is-active' : '' ?>">
              <input type="checkbox" class="sr-only" name="days[]" value="<?= (int) $num ?>" <?= in_array((int) $num, $ruleDays, true) ? 'checked' : '' ?>> <?= $this->e($label) ?>
            </label>
          <?php endforeach ?>
        </div>
      </div>
      <div class="grid gap-3 sm:grid-cols-3">
        <div class="field">
          <label class="label" for="sch-start-<?= $id ?>">С</label>
          <input class="input" id="sch-start-<?= $id ?>" name="start_time" type="time" value="<?= $this->e((string) ($schedule['start_time'] ?? '23:00')) ?>">
        </div>
        <div class="field">
          <label class="label" for="sch-end-<?= $id ?>">До</label>
          <input class="input" id="sch-end-<?= $id ?>" name="end_time" type="time" value="<?= $this->e((string) ($schedule['end_time'] ?? '07:00')) ?>">
        </div>
        <div class="field">
          <label class="label" for="sch-effect-<?= $id ?>">Эффект</label>
          <select class="select" id="sch-effect-<?= $id ?>" name="effect">
            <option value="allow" <?= ($schedule['effect'] ?? 'allow') === 'allow' ? 'selected' : '' ?>>Разрешить</option>
            <option value="deny" <?= ($schedule['effect'] ?? '') === 'deny' ? 'selected' : '' ?>>Запретить</option>
          </select>
        </div>
      </div>
      <div class="field">
        <label class="label" for="sch-priority-<?= $id ?>">Приоритет</label>
        <input class="input" id="sch-priority-<?= $id ?>" name="priority" type="number" min="-100" max="100" value="<?= (int) ($schedule['priority'] ?? 0) ?>">
        <p class="text-xs text-muted-foreground">Больше число — выше приоритет.</p>
      </div>
      <div class="field">
        <label class="label" for="sch-notes-<?= $id ?>">Заметка</label>
        <input class="input" id="sch-notes-<?= $id ?>" name="notes" type="text" maxlength="2000" value="<?= $this->e((string) ($schedule['notes'] ?? '')) ?>">
      </div>
      <label class="checkbox-row text-sm">
        <input class="checkbox" type="checkbox" name="is_active" value="1" <?= ($schedule['is_active'] ?? 1) ? 'checked' : '' ?>>
        <span>Расписание активно</span>
      </label>
    </div>
    <?php
};
?>

<div class="dialog" id="dialog-schedule-new" data-state="closed" hidden role="dialog" aria-labelledby="sch-new-t">
  <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
  <form method="post" action="<?= $this->e($this->url('/admin/gate/schedules')) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/gate/schedules')) ?>">
    <?= $this->csrf() ?>
    <div class="dialog-header"><h2 class="dialog-title" id="sch-new-t">Новое расписание</h2></div>
    <?php $renderForm(null); ?>
    <div class="dialog-footer mt-4">
      <button type="button" class="btn btn-outline" data-close>Отмена</button>
      <button type="submit" class="btn btn-default"><?= $this->icon('plus') ?> Создать</button>
    </div>
  </form>
</div>

<?php foreach ($schedules as $schedule): ?>
  <div class="dialog" id="dialog-schedule-<?= (int) $schedule['id'] ?>" data-state="closed" hidden role="dialog" aria-labelledby="sch-t-<?= (int) $schedule['id'] ?>">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <form method="post" action="<?= $this->e($this->url('/admin/gate/schedules')) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/gate/schedules')) ?>">
      <?= $this->csrf() ?>
      <div class="dialog-header"><h2 class="dialog-title" id="sch-t-<?= (int) $schedule['id'] ?>">Изменить расписание</h2></div>
      <?php $renderForm($schedule); ?>
      <div class="dialog-footer mt-4">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="submit" class="btn btn-default"><?= $this->icon('save') ?> Сохранить</button>
      </div>
    </form>
  </div>
<?php endforeach ?>
