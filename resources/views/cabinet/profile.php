<?php
/**
 * Resident: self-service editing of contacts and cars.
 *
 * @var BasIp\Domain\Model\Resident|null $resident
 * @var array<int, array<string, mixed>> $contacts
 * @var array<int, array<string, mixed>> $cars
 * @var bool $editable
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div>
    <h2 class="page-title">Мои данные</h2>
    <p class="page-desc">
      <?= $editable
        ? 'Держите контакты и автомобили в актуальном состоянии — от них зависят рассылки и открытие по госномеру.'
        : 'Данные доступны только для просмотра. Для изменений обратитесь к управляющему.' ?>
    </p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline" href="<?= $this->e($this->url('/cabinet')) ?>"><?= $this->icon('arrow-left') ?> В кабинет</a>
  </div>
</div>

<?php if ($resident === null): ?>
  <div class="card"><?= $this->include('components/empty', ['title' => 'Карточка не найдена', 'icon' => 'user-x']) ?></div>
<?php else: ?>

<div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
  <div class="grid content-start gap-5">

    <form class="card" method="post" action="<?= $this->e($this->url('/cabinet/profile')) ?>" data-resident-form
          data-ajax <?= $editable ? '' : 'data-readonly' ?>>
      <?= $this->csrf() ?>
      <?= $this->method('POST') ?>

      <div class="card-header">
        <div class="card-title flex items-center gap-2"><?= $this->icon('at-sign', 'h-4 w-4') ?> Контакты</div>
        <div class="card-desc">Можно добавить несколько телефонов и почт. Отмеченные «уведомлять» используются для рассылок об оплате.</div>
      </div>
      <div class="card-content grid gap-3" data-contacts data-contact-types="phone,email,telegram,whatsapp">
        <div class="grid gap-2" data-contact-list>
          <?php foreach ($contacts as $index => $contact): ?>
            <?php $type = (string) ($contact['type'] ?? 'phone'); ?>
            <?php if (!in_array($type, ['phone', 'email', 'telegram', 'whatsapp'], true)) { continue; } ?>
            <div data-contact-row class="grid gap-2 rounded-lg border bg-card/60 p-3 sm:grid-cols-[7.5rem_1fr_7.5rem_auto] sm:items-center">
              <select class="select select-sm" name="contacts[<?= $index ?>][type]" <?= $editable ? '' : 'disabled' ?>>
                <?php foreach (['phone' => 'Телефон', 'email' => 'E-mail', 'telegram' => 'Telegram', 'whatsapp' => 'WhatsApp'] as $value => $label): ?>
                  <option value="<?= $value ?>" <?= $type === $value ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach ?>
              </select>
              <input class="input input-sm" name="contacts[<?= $index ?>][value]" value="<?= $this->e((string) ($contact['value'] ?? '')) ?>"
                     <?= $type === 'phone' ? 'data-phone inputmode="tel"' : '' ?> required <?= $editable ? '' : 'readonly' ?>>
              <input class="input input-sm" name="contacts[<?= $index ?>][label]" placeholder="Метка" value="<?= $this->e((string) ($contact['label'] ?? '')) ?>" <?= $editable ? '' : 'readonly' ?>>
              <div class="flex items-center gap-3">
                <label class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                  <input type="checkbox" class="checkbox" name="contacts[<?= $index ?>][is_primary]" value="1"
                         data-contact-primary="<?= $this->e($type) ?>" <?= !empty($contact['is_primary']) ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>> осн.
                </label>
                <label class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                  <input type="checkbox" class="checkbox" name="contacts[<?= $index ?>][notify]" value="1" <?= !empty($contact['notify']) ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>> уведомлять
                </label>
                <?php if ($editable): ?>
                  <button type="button" class="btn btn-sm btn-ghost btn-icon" data-contact-remove aria-label="Удалить"><?= $this->icon('trash') ?></button>
                <?php endif ?>
              </div>
            </div>
          <?php endforeach ?>
        </div>
        <?php if ($editable): ?>
          <button type="button" class="btn btn-outline btn-sm justify-self-start" data-contact-add>
            <?= $this->icon('plus', 'h-3.5 w-3.5') ?> Добавить контакт
          </button>
        <?php endif ?>
      </div>

      <div class="card-header border-t">
        <div class="card-title flex items-center gap-2"><?= $this->icon('car', 'h-4 w-4') ?> Автомобили</div>
        <div class="card-desc">Несколько машин на одну квартиру — это нормально. Указывайте номер без пробелов: А123ВС77.</div>
      </div>
      <div class="card-content grid gap-3" data-cars>
        <div class="grid gap-2" data-car-list>
          <?php foreach ($cars as $index => $car): ?>
            <div data-car-row class="grid gap-2 rounded-lg border bg-card/60 p-3 sm:grid-cols-[8rem_7rem_7rem_5rem_auto] sm:items-center">
              <input class="input input-sm input-mono" name="cars[<?= $index ?>][plate]" value="<?= $this->e((string) ($car['plate'] ?? '')) ?>" maxlength="16" placeholder="А123ВС77" data-plate <?= $editable ? '' : 'readonly' ?>>
              <input class="input input-sm" name="cars[<?= $index ?>][brand]" placeholder="Марка" value="<?= $this->e((string) ($car['brand'] ?? '')) ?>" <?= $editable ? '' : 'readonly' ?>>
              <input class="input input-sm" name="cars[<?= $index ?>][model]" placeholder="Модель" value="<?= $this->e((string) ($car['model'] ?? '')) ?>" <?= $editable ? '' : 'readonly' ?>>
              <input class="input input-sm" name="cars[<?= $index ?>][year]" placeholder="Год" inputmode="numeric" maxlength="4" value="<?= $this->e((string) ($car['year'] ?? '')) ?>" <?= $editable ? '' : 'readonly' ?>>
              <div class="flex items-center gap-3">
                <label class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                  <input type="checkbox" class="checkbox" name="cars[<?= $index ?>][is_active]" value="1" <?= !empty($car['is_active']) ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>> активен
                </label>
                <?php if ($editable): ?>
                  <button type="button" class="btn btn-sm btn-ghost btn-icon" data-car-remove aria-label="Удалить"><?= $this->icon('trash') ?></button>
                <?php endif ?>
              </div>
              <?php foreach (['color', 'type', 'rfid_tag', 'notes'] as $hidden): ?>
                <input type="hidden" name="cars[<?= $index ?>][<?= $hidden ?>]" value="<?= $this->e((string) ($car[$hidden] ?? ($hidden === 'type' ? 'car' : ''))) ?>">
              <?php endforeach ?>
            </div>
          <?php endforeach ?>
        </div>
        <?php if ($editable): ?>
          <button type="button" class="btn btn-outline btn-sm justify-self-start" data-car-add>
            <?= $this->icon('plus', 'h-3.5 w-3.5') ?> Добавить автомобиль
          </button>
        <?php endif ?>
      </div>

      <?php if ($editable): ?>
        <div class="card-footer justify-end border-t">
          <button class="btn btn-default" type="submit"><?= $this->icon('save') ?> Сохранить</button>
        </div>
      <?php endif ?>
    </form>
  </div>

  <aside class="grid content-start gap-5">
    <div class="card">
      <div class="card-header"><div class="card-title">Карточка</div></div>
      <div class="card-content grid gap-2 text-sm">
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">ФИО</span><b class="text-right"><?= $this->e($resident->displayName()) ?></b></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Адрес</span><b class="text-right"><?= $this->e($resident->address()) ?></b></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Статус</span><span><?= $this->e($resident->roleLabel()) ?></span></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Доступ</span><?= $resident->accessGranted ? '<span class="badge badge-soft-success">разрешён</span>' : '<span class="badge badge-soft-destructive">приостановлен</span>' ?></div>
        <div class="flex justify-between gap-2"><span class="text-muted-foreground">Запись</span><?= $resident->isActive ? '<span class="badge badge-soft-success">активна</span>' : '<span class="badge badge-muted">отключена</span>' ?></div>
        <?php if ($resident->notes !== null && $resident->notes !== ''): ?>
          <div class="mt-2 rounded-lg border bg-muted/30 p-3">
            <div class="text-xs uppercase tracking-wide text-muted-foreground">Комментарий управляющего</div>
            <p class="mt-1 whitespace-pre-line text-sm"><?= $this->e($resident->notes) ?></p>
          </div>
        <?php endif ?>
      </div>
    </div>

    <div class="alert alert-muted">
      <?= $this->icon('info', 'h-4 w-4') ?>
      <div class="alert-desc">
        ФИО, квартиру и состав семьи изменяет управляющий — напишите ему, если данные устарели.
        Контакты и автомобили вы можете править сами.
      </div>
    </div>
  </aside>
</div>
<?php endif ?>
