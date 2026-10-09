<?php
/**
 * Broadcast announcements shown in the cabinet and on the dashboard.
 *
 * @var BasIp\Support\Paginator $items
 * @var array $levels
 * @var array $audiences
 * @var array $query
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$rows = $items->items();
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Объявления</h2>
    <p class="page-desc">Показываются в личном кабинете жителя и на дашборде сотрудника. Можно запланировать публикацию и срок действия.</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-default btn-sm" type="button" data-dialog="#dialog-announcement-new"><?= $this->icon('megaphone', 'h-3.5 w-3.5') ?> Новое объявление</button>
  </div>
</div>

<div class="card">
  <div class="card-content p-0">
    <?php if ($rows === []): ?>
      <?= $this->include('components/empty', ['title' => 'Объявлений нет', 'description' => 'Создайте первое — например, о плановом ТО шлагбаума.', 'icon' => 'megaphone']) ?>
    <?php else: ?>
      <div class="table-wrap rounded-none border-0">
        <table class="table">
          <thead><tr><th>Объявление</th><th>Уровень</th><th>Аудитория</th><th>Публикация</th><th>Действует до</th><th class="text-right">Просмотров</th><th>Статус</th><th class="text-right">Действия</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <tr class="<?= empty($row['is_published']) ? 'is-inactive' : '' ?>">
              <td>
                <div class="text-sm font-medium"><?= $this->e((string) $row['title']) ?><?= !empty($row['pin']) ? ' <span class="badge badge-soft-info ml-1">закреплено</span>' : '' ?></div>
                <div class="max-w-[26rem] truncate text-xs text-muted-foreground"><?= $this->e(\BasIp\Support\Str::squash((string) ($row['body'] ?? ''))) ?></div>
              </td>
              <td>
                <?php $level = (string) ($row['level'] ?? 'info'); ?>
                <span class="badge badge-soft-<?= ['info' => 'info', 'success' => 'success', 'warning' => 'warning', 'urgent' => 'destructive'][$level] ?? 'info' ?>"><?= $this->e($levels[$level] ?? $level) ?></span>
              </td>
              <td class="text-xs"><?= $this->e($audiences[(string) ($row['audience'] ?? 'active')] ?? (string) ($row['audience'] ?? '')) ?></td>
              <td class="whitespace-nowrap text-xs tabular text-muted-foreground"><?= $this->e($this->datetime($row['publish_at'] ?? null, 'd.m.Y H:i') ?: 'сразу') ?></td>
              <td class="whitespace-nowrap text-xs tabular text-muted-foreground"><?= $this->e($this->datetime($row['expires_at'] ?? null, 'd.m.Y H:i') ?: 'бессрочно') ?></td>
              <td class="table-numeric tabular"><?= (int) ($row['views'] ?? 0) ?></td>
              <td>
                <?php
                  $published = !empty($row['is_published']);
                  $nowTs = time();
                  $scheduled = $row['publish_at'] !== null && strtotime((string) $row['publish_at']) > $nowTs;
                  $expired = $row['expires_at'] !== null && strtotime((string) $row['expires_at']) < $nowTs;
                  $state = !$published ? ['draft', 'черновик', 'muted'] : ($scheduled ? ['scheduled', 'запланировано', 'info'] : ($expired ? ['expired', 'завершено', 'warning'] : ['live', 'опубликовано', 'success']));
                ?>
                <span class="badge badge-soft-<?= $state[2] ?>"><?= $this->e($state[1]) ?></span>
              </td>
              <td class="text-right">
                <div class="flex justify-end gap-1">
                  <button class="btn btn-ghost btn-sm btn-icon" type="button" data-dialog="#dialog-announcement-<?= (int) $row['id'] ?>" aria-label="Изменить"><?= $this->icon('pencil', 'h-3.5 w-3.5') ?></button>
                  <form method="post" action="<?= $this->e($this->url('/admin/announcements/' . (int) $row['id'] . '/delete')) ?>" class="inline">
                    <?= $this->csrf() ?>
                    <button class="btn btn-ghost btn-sm btn-icon text-destructive" type="submit" aria-label="Удалить"
                            data-confirm="Удалить объявление «<?= $this->e((string) $row['title']) ?>»?" data-confirm-label="Удалить" data-confirm-tone="destructive">
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
      <div class="p-4"><?= $this->include('components/pagination', ['paginator' => $items, 'query' => $query, 'target' => '/admin/announcements']) ?></div>
    <?php endif ?>
  </div>
</div>

<?php
$renderForm = function (?array $row) use ($levels, $audiences): void {
    $id = (int) ($row['id'] ?? 0);
    ?>
    <div class="grid gap-3">
      <?php if ($id > 0): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif ?>
      <div class="field">
        <label class="label" for="an-title-<?= $id ?>">Заголовок <span class="text-destructive">*</span></label>
        <input class="input" id="an-title-<?= $id ?>" name="title" type="text" maxlength="190" required value="<?= $this->e((string) ($row['title'] ?? '')) ?>" placeholder="Профилактика шлагбаума">
      </div>
      <div class="field">
        <label class="label" for="an-body-<?= $id ?>">Текст</label>
        <textarea class="textarea" id="an-body-<?= $id ?>" name="body" rows="5" maxlength="8000" placeholder="В субботу с 10:00 до 12:00 проведём ТО привода…"><?= $this->e((string) ($row['body'] ?? '')) ?></textarea>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <div class="field">
          <label class="label" for="an-level-<?= $id ?>">Уровень</label>
          <select class="select" id="an-level-<?= $id ?>" name="level">
            <?php foreach ($levels as $value => $label): ?>
              <option value="<?= $value ?>" <?= $this->selected((string) ($row['level'] ?? 'info'), $value) ?>><?= $label ?></option>
            <?php endforeach ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="an-audience-<?= $id ?>">Кому показывать</label>
          <select class="select" id="an-audience-<?= $id ?>" name="audience">
            <?php foreach ($audiences as $value => $label): ?>
              <option value="<?= $value ?>" <?= $this->selected((string) ($row['audience'] ?? 'active'), $value) ?>><?= $label ?></option>
            <?php endforeach ?>
          </select>
        </div>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <div class="field">
          <label class="label" for="an-publish-<?= $id ?>">Опубликовать с</label>
          <input class="input" id="an-publish-<?= $id ?>" name="publish_at" type="datetime-local"
                 value="<?= $this->e($row !== null && !empty($row['publish_at']) ? date('Y-m-d\TH:i', (int) strtotime((string) $row['publish_at'])) : '') ?>">
        </div>
        <div class="field">
          <label class="label" for="an-expires-<?= $id ?>">Действует до</label>
          <input class="input" id="an-expires-<?= $id ?>" name="expires_at" type="datetime-local"
                 value="<?= $this->e($row !== null && !empty($row['expires_at']) ? date('Y-m-d\TH:i', (int) strtotime((string) $row['expires_at'])) : '') ?>">
        </div>
      </div>
      <div class="grid gap-2">
        <label class="checkbox-row text-sm"><input class="checkbox" type="checkbox" name="is_published" value="1" <?= ($row['is_published'] ?? 1) ? 'checked' : '' ?>><span>Опубликовано</span></label>
        <label class="checkbox-row text-sm"><input class="checkbox" type="checkbox" name="pin" value="1" <?= !empty($row['pin']) ? 'checked' : '' ?>><span>Закрепить сверху</span></label>
      </div>
    </div>
    <?php
};
?>

<div class="dialog" id="dialog-announcement-new" data-state="closed" hidden role="dialog" aria-labelledby="an-new-t">
  <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
  <form method="post" action="<?= $this->e($this->url('/admin/announcements')) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/announcements')) ?>">
    <?= $this->csrf() ?>
    <div class="dialog-header"><h2 class="dialog-title" id="an-new-t">Новое объявление</h2></div>
    <?php $renderForm(null); ?>
    <div class="dialog-footer mt-4">
      <button type="button" class="btn btn-outline" data-close>Отмена</button>
      <button type="submit" class="btn btn-default"><?= $this->icon('megaphone') ?> Опубликовать</button>
    </div>
  </form>
</div>

<?php foreach ($rows as $row): ?>
  <div class="dialog" id="dialog-announcement-<?= (int) $row['id'] ?>" data-state="closed" hidden role="dialog" aria-labelledby="an-t-<?= (int) $row['id'] ?>">
    <button type="button" class="dialog-close" data-close aria-label="Закрыть"><?= $this->icon('x') ?></button>
    <form method="post" action="<?= $this->e($this->url('/admin/announcements')) ?>" data-ajax data-redirect="<?= $this->e($this->url('/admin/announcements')) ?>">
      <?= $this->csrf() ?>
      <div class="dialog-header"><h2 class="dialog-title" id="an-t-<?= (int) $row['id'] ?>">Изменить объявление</h2></div>
      <?php $renderForm($row); ?>
      <div class="dialog-footer mt-4">
        <button type="button" class="btn btn-outline" data-close>Отмена</button>
        <button type="submit" class="btn btn-default"><?= $this->icon('save') ?> Сохранить</button>
      </div>
    </form>
  </div>
<?php endforeach ?>
