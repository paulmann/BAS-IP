<?php
/**
 * Resident create/edit form with nested contacts and cars.
 *
 * @var BasIp\Domain\Model\Resident|null $resident
 * @var array<int, array<string, mixed>> $contacts
 * @var array<int, array<string, mixed>> $cars
 * @var array<int, string> $contactTypes
 * @var array<int, string> $carTypes
 * @var array<int, string> $roles
 * @var array<int, string> $buildings
 * @var array<int, string> $existingTags
 * @var array|null $account
 * @var bool $isNew
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$errors = $this->shared['errors'] ?? [];
$old = $this->shared['old'] ?? [];
$value = function (string $key, mixed $fallback = '') use ($resident, $old): mixed {
    if (array_key_exists($key, $old) && $old[$key] !== '') {
        return $old[$key];
    }
    if ($resident === null) {
        return $fallback;
    }
    $map = [
        'last_name' => $resident->lastName, 'first_name' => $resident->firstName,
        'patronymic' => $resident->patronymic, 'full_name' => $resident->fullName,
        'apartment' => $resident->apartment, 'apartment_no' => $resident->apartmentNo,
        'building' => $resident->building, 'entrance' => $resident->entrance, 'floor' => $resident->floor,
        'area' => $resident->area, 'persons' => $resident->persons, 'share' => $resident->share,
        'role_in_home' => $resident->roleInHome, 'phone' => $resident->phone, 'email' => $resident->email,
        'notes' => $resident->notes, 'external_id' => $resident->externalId,
        'access_granted' => $resident->accessGranted, 'is_active' => $resident->isActive,
        'is_billed' => $resident->isBilled, 'allow_guest_pass' => $resident->allowGuestPass,
        'notify_enabled' => $resident->notifyEnabled,
    ];

    return $map[$key] ?? $fallback;
};
$action = $isNew ? $this->url('/admin/residents') : $this->url('/admin/residents/' . $resident->id);
$err = static fn (string $key): ?string => isset($errors[$key]) ? (string) $errors[$key][0] : null;
?>
<?php $this->layout('layouts/app') ?>

<form method="post" action="<?= $this->e($action) ?>" data-resident-form data-dirty-guard class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
  <?= $this->csrf() ?>
  <?php if (!$isNew): ?><?= $this->method('POST') ?><?php endif ?>

  <div class="grid content-start gap-5">

    <?php /* ── личность ───────────────────────────────────── */ ?>
    <section class="card">
      <div class="card-header">
        <div class="card-title flex items-center gap-2"><?= $this->icon('user', 'h-4 w-4') ?> Личные данные</div>
        <div class="card-desc">ФИО можно ввести одной строкой — поля разделятся автоматически.</div>
      </div>
      <div class="card-content grid gap-4">
        <div class="grid gap-4 sm:grid-cols-3">
          <div class="field">
            <label class="label" for="last_name">Фамилия <span class="text-destructive">*</span></label>
            <input class="input" id="last_name" name="last_name" type="text" maxlength="120" required
                   value="<?= $this->e((string) $value('last_name')) ?>" autocomplete="family-name" <?= $err('last_name') ? 'aria-invalid="true"' : '' ?>>
            <?php if ($err('last_name')): ?><p class="form-message"><?= $this->e((string) $err('last_name')) ?></p><?php endif ?>
          </div>
          <div class="field">
            <label class="label" for="first_name">Имя</label>
            <input class="input" id="first_name" name="first_name" type="text" maxlength="120"
                   value="<?= $this->e((string) $value('first_name')) ?>" autocomplete="given-name">
          </div>
          <div class="field">
            <label class="label" for="patronymic">Отчество</label>
            <input class="input" id="patronymic" name="patronymic" type="text" maxlength="120"
                   value="<?= $this->e((string) $value('patronymic')) ?>">
          </div>
        </div>

        <div class="field">
          <label class="label" for="full_name">ФИО полностью</label>
          <input class="input" id="full_name" name="full_name" type="text" maxlength="255"
                 value="<?= $this->e((string) $value('full_name')) ?>">
          <p class="text-xs text-muted-foreground">Заполняется автоматически из фамилии, имени и отчества, пока вы не отредактируете это поле вручную.</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div class="field">
            <label class="label" for="apartment">Квартира <span class="text-destructive">*</span></label>
            <input class="input" id="apartment" name="apartment" type="text" maxlength="24" required
                   value="<?= $this->e((string) $value('apartment')) ?>" placeholder="12 или 12А">
            <?php if ($err('apartment')): ?><p class="form-message"><?= $this->e((string) $err('apartment')) ?></p><?php endif ?>
          </div>
          <div class="field">
            <label class="label" for="apartment_no">№ для сортировки</label>
            <input class="input" id="apartment_no" name="apartment_no" type="number" min="0" step="1"
                   value="<?= $this->e((string) $value('apartment_no')) ?>" placeholder="12">
            <p class="text-xs text-muted-foreground">Числовая часть — по ней работает сортировка.</p>
          </div>
          <div class="field">
            <label class="label" for="building">Корпус</label>
            <input class="input" id="building" name="building" type="text" maxlength="24" list="buildings-list"
                   value="<?= $this->e((string) $value('building')) ?>">
            <datalist id="buildings-list">
              <?php foreach ($buildings as $building): ?><option value="<?= $this->e($building) ?>"></option><?php endforeach ?>
            </datalist>
          </div>
          <div class="field">
            <label class="label" for="entrance">Подъезд</label>
            <input class="input" id="entrance" name="entrance" type="text" maxlength="16" value="<?= $this->e((string) $value('entrance')) ?>">
          </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div class="field">
            <label class="label" for="floor">Этаж</label>
            <input class="input" id="floor" name="floor" type="number" min="-10" max="500" value="<?= $this->e((string) $value('floor')) ?>">
          </div>
          <div class="field">
            <label class="label" for="area">Площадь, м²</label>
            <input class="input input-mono" id="area" name="area" type="number" min="0" step="0.01" value="<?= $this->e((string) $value('area', '0')) ?>">
          </div>
          <div class="field">
            <label class="label" for="persons">Проживает, чел.</label>
            <input class="input" id="persons" name="persons" type="number" min="1" max="50" value="<?= $this->e((string) $value('persons', '1')) ?>">
          </div>
          <div class="field">
            <label class="label" for="share">Доля</label>
            <input class="input input-mono" id="share" name="share" type="number" min="0" step="0.0001" value="<?= $this->e((string) $value('share', '1')) ?>">
            <p class="text-xs text-muted-foreground">Вес при распределении «по доле».</p>
          </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div class="field">
            <label class="label" for="role_in_home">Статус</label>
            <select class="select" id="role_in_home" name="role_in_home">
              <?php foreach ($roles as $role): ?>
                <option value="<?= $this->e($role) ?>" <?= $this->selected($value('role_in_home', 'owner'), $role) ?>>
                  <?= $this->e(['owner' => 'Собственник', 'tenant' => 'Арендатор', 'relative' => 'Родственник', 'manager' => 'Управляющий', 'other' => 'Другое'][$role] ?? $role) ?>
                </option>
              <?php endforeach ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="external_id">Внешний ID</label>
            <input class="input input-mono" id="external_id" name="external_id" type="text" maxlength="64"
                   value="<?= $this->e((string) $value('external_id')) ?>" placeholder="Например, код из 1С">
            <p class="text-xs text-muted-foreground">Используется для сопоставления при импорте.</p>
          </div>
        </div>
      </div>
    </section>

    <?php /* ── контакты ───────────────────────────────────── */ ?>
    <section class="card" data-contacts data-contact-types="<?= $this->e(implode(',', $contactTypes)) ?>">
      <div class="card-header">
        <div class="card-title flex items-center gap-2"><?= $this->icon('at-sign', 'h-4 w-4') ?> Контакты</div>
        <div class="card-desc">Несколько телефонов и почт на одного жителя. Основной контакт подставляется в карточку и используется по умолчанию.</div>
      </div>
      <div class="card-content grid gap-3">
        <div class="grid gap-2" data-contact-list>
          <?php if ($contacts === []): ?>
            <?php /* первая строка создаётся автоматически при пустом списке */ ?>
          <?php endif ?>
          <?php foreach ($contacts as $index => $contact): ?>
            <?php $type = (string) ($contact['type'] ?? 'phone'); ?>
            <div data-contact-row class="grid gap-2 rounded-lg border bg-card/60 p-3 sm:grid-cols-[7.5rem_1fr_7.5rem_auto] sm:items-center">
              <select class="select select-sm" name="contacts[<?= $index ?>][type]">
                <?php foreach ($contactTypes as $option): ?>
                  <option value="<?= $this->e($option) ?>" <?= $type === $option ? 'selected' : '' ?>>
                    <?= $this->e(['phone' => 'Телефон', 'email' => 'E-mail', 'telegram' => 'Telegram', 'whatsapp' => 'WhatsApp', 'viber' => 'Viber', 'sip' => 'SIP', 'other' => 'Другое'][$option] ?? $option) ?>
                  </option>
                <?php endforeach ?>
              </select>
              <input class="input input-sm" name="contacts[<?= $index ?>][value]" value="<?= $this->e((string) ($contact['value'] ?? '')) ?>"
                     placeholder="Значение" required <?= $type === 'phone' ? 'data-phone inputmode="tel"' : '' ?>>
              <input class="input input-sm" name="contacts[<?= $index ?>][label]" placeholder="Метка" value="<?= $this->e((string) ($contact['label'] ?? '')) ?>">
              <div class="flex items-center gap-3">
                <label class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                  <input type="checkbox" class="checkbox" name="contacts[<?= $index ?>][is_primary]" value="1"
                         data-contact-primary="<?= $this->e($type) ?>" <?= !empty($contact['is_primary']) ? 'checked' : '' ?>> осн.
                </label>
                <label class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                  <input type="checkbox" class="checkbox" name="contacts[<?= $index ?>][notify]" value="1" <?= !empty($contact['notify']) ? 'checked' : '' ?>> уведомлять
                </label>
                <button type="button" class="btn btn-sm btn-ghost btn-icon" data-contact-remove aria-label="Удалить контакт"><?= $this->icon('trash') ?></button>
              </div>
            </div>
          <?php endforeach ?>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <button type="button" class="btn btn-outline btn-sm" data-contact-add><?= $this->icon('plus', 'h-3.5 w-3.5') ?> Добавить контакт</button>
          <span class="text-xs text-muted-foreground">Первый телефон и первая почта становятся основными автоматически.</span>
        </div>
      </div>
    </section>

    <?php /* ── автомобили ─────────────────────────────────── */ ?>
    <section class="card" data-cars>
      <div class="card-header">
        <div class="card-title flex items-center gap-2"><?= $this->icon('car', 'h-4 w-4') ?> Автомобили</div>
        <div class="card-desc">Несколько машин на одну квартиру. Госномер используется для открытия по номеру и для поиска.</div>
      </div>
      <div class="card-content grid gap-3">
        <div class="grid gap-2" data-car-list>
          <?php foreach ($cars as $index => $car): ?>
            <div data-car-row class="grid gap-2 rounded-lg border bg-card/60 p-3 sm:grid-cols-[8rem_7rem_7rem_5rem_auto] sm:items-center">
              <input class="input input-sm input-mono" name="cars[<?= $index ?>][plate]" value="<?= $this->e((string) ($car['plate'] ?? '')) ?>" placeholder="А123ВС77" maxlength="16" data-plate>
              <input class="input input-sm" name="cars[<?= $index ?>][brand]" placeholder="Марка" value="<?= $this->e((string) ($car['brand'] ?? '')) ?>">
              <input class="input input-sm" name="cars[<?= $index ?>][model]" placeholder="Модель" value="<?= $this->e((string) ($car['model'] ?? '')) ?>">
              <input class="input input-sm" name="cars[<?= $index ?>][year]" placeholder="Год" inputmode="numeric" maxlength="4" value="<?= $this->e((string) ($car['year'] ?? '')) ?>">
              <div class="flex items-center gap-3">
                <label class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                  <input type="checkbox" class="checkbox" name="cars[<?= $index ?>][is_active]" value="1" <?= !empty($car['is_active']) ? 'checked' : '' ?>> активен
                </label>
                <button type="button" class="btn btn-sm btn-ghost btn-icon" data-car-remove aria-label="Удалить автомобиль"><?= $this->icon('trash') ?></button>
              </div>
              <?php foreach (['color' => '', 'type' => 'car', 'rfid_tag' => '', 'notes' => ''] as $hidden => $default): ?>
                <input type="hidden" name="cars[<?= $index ?>][<?= $hidden ?>]" value="<?= $this->e((string) ($car[$hidden] ?? $default)) ?>">
              <?php endforeach ?>
            </div>
          <?php endforeach ?>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <button type="button" class="btn btn-outline btn-sm" data-car-add><?= $this->icon('plus', 'h-3.5 w-3.5') ?> Добавить автомобиль</button>
          <span class="text-xs text-muted-foreground">Формат номера: буква + 3 цифры + 2 буквы + регион.</span>
        </div>
      </div>
    </section>

    <?php /* ── комментарий и метки ────────────────────────── */ ?>
    <section class="card">
      <div class="card-header">
        <div class="card-title flex items-center gap-2"><?= $this->icon('notebook-pen', 'h-4 w-4') ?> Комментарий и метки</div>
        <div class="card-desc">Заметки видны только сотрудникам. Метки удобны для фильтрации и массовых действий.</div>
      </div>
      <div class="card-content grid gap-4">
        <div class="field">
          <label class="label" for="notes">Комментарий</label>
          <textarea class="textarea" id="notes" name="notes" rows="4" maxlength="8000" data-autosize
                    placeholder="Договорённости, особенности, история обращений…"><?= $this->e((string) $value('notes')) ?></textarea>
        </div>
        <div class="field" data-tag-input
             data-tag-values="<?= $this->e(json_encode($resident?->tags ?? [], JSON_UNESCAPED_UNICODE)) ?>">
          <label class="label" for="tag-input">Метки</label>
          <input class="input" id="tag-input" type="text" data-tag-field list="known-tags" placeholder="Введите метку и нажмите Enter" autocomplete="off">
          <datalist id="known-tags">
            <?php foreach ($existingTags as $tag): ?><option value="<?= $this->e($tag) ?>"></option><?php endforeach ?>
          </datalist>
          <div class="mt-2 flex flex-wrap gap-1.5" data-tag-chips></div>
          <input type="hidden" name="tags" value="">
        </div>
      </div>
    </section>
  </div>

  <?php /* ═══════════ правая колонка ═══════════ */ ?>
  <aside class="grid content-start gap-5">

    <section class="card">
      <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('sliders-horizontal', 'h-4 w-4') ?> Статусы</div></div>
      <div class="card-content grid gap-3">
        <?php
          $flags = [
            ['is_active', 'Включен', 'Участвует в расчётах, рассылках и статистике.'],
            ['access_granted', 'Доступ', 'Может открывать шлагбаум.'],
            ['is_billed', 'Участвует в оплате', 'Включать в деление суммы обслуживания.'],
            ['allow_guest_pass', 'Гостевой доступ', 'Может сам создавать гостевые пропуска.'],
            ['notify_enabled', 'Уведомления', 'Отправлять сообщения об оплате.'],
          ];
        ?>
        <?php foreach ($flags as [$name, $label, $hint]): ?>
          <label class="flex items-start justify-between gap-3 rounded-lg border p-3 transition hover:border-primary/40">
            <span class="min-w-0">
              <span class="block text-sm font-medium"><?= $this->e($label) ?></span>
              <span class="mt-0.5 block text-xs text-muted-foreground"><?= $this->e($hint) ?></span>
            </span>
            <input type="checkbox" class="switch switch-success mt-0.5 shrink-0" name="<?= $name ?>" value="1"
                   <?= $value($name, true) ? 'checked' : '' ?> role="switch" aria-label="<?= $this->e($label) ?>">
          </label>
        <?php endforeach ?>

        <div class="alert alert-warning" data-active-hint <?= $value('is_active', true) ? 'hidden' : '' ?>>
          <?= $this->icon('alert-triangle', 'h-4 w-4') ?>
          <div class="alert-desc">Отключённый житель не участвует в делении суммы, не получает рассылки, а его гостевые доступы отзываются.</div>
        </div>
      </div>
    </section>

    <?php if (!$isNew): ?>
      <section class="card">
        <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('shield', 'h-4 w-4') ?> Учётная запись</div></div>
        <div class="card-content grid gap-3 text-sm">
          <?php if ($account === null): ?>
            <p class="text-muted-foreground">Личный кабинет не создан. Житель не сможет сам открывать шлагбаум и создавать гостевые доступы.</p>
            <form method="post" action="<?= $this->e($this->url('/admin/residents/' . $resident->id . '/account')) ?>">
              <?= $this->csrf() ?>
              <div class="field">
                <label class="label" for="account_login">Логин</label>
                <input class="input input-sm" id="account_login" name="login" type="text" placeholder="Оставьте пустым — подставим e-mail или «фамилия+квартира»">
              </div>
              <button class="btn btn-outline btn-sm btn-block" type="submit"><?= $this->icon('user-plus', 'h-3.5 w-3.5') ?> Создать кабинет</button>
            </form>
          <?php else: ?>
            <div class="flex justify-between gap-2"><span class="text-muted-foreground">Логин</span><b><?= $this->e((string) $account['login']) ?></b></div>
            <div class="flex justify-between gap-2"><span class="text-muted-foreground">Роль</span><span><?= $this->e((string) $account['role']) ?></span></div>
            <div class="flex justify-between gap-2"><span class="text-muted-foreground">Активна</span><?= !empty($account['is_active']) ? '<span class="badge badge-soft-success">да</span>' : '<span class="badge badge-muted">нет</span>' ?></div>
            <div class="flex justify-between gap-2"><span class="text-muted-foreground">2FA</span><?= !empty($account['totp_enabled']) ? '<span class="badge badge-soft-success">включена</span>' : '<span class="badge badge-muted">нет</span>' ?></div>
            <div class="flex justify-between gap-2"><span class="text-muted-foreground">Последний вход</span><span class="text-xs tabular"><?= $this->e($this->datetime($account['last_login_at'] ?? null)) ?></span></div>
            <a class="btn btn-outline btn-sm btn-block" href="<?= $this->e($this->url('/admin/users/' . (int) $account['id'])) ?>"><?= $this->icon('settings', 'h-3.5 w-3.5') ?> Управление доступом</a>
          <?php endif ?>
        </div>
      </section>
    <?php endif ?>

    <section class="card">
      <div class="card-content grid gap-2 pt-5">
        <button class="btn btn-default btn-block" type="submit">
          <?= $this->icon('save') ?> <?= $isNew ? 'Создать жителя' : 'Сохранить изменения' ?>
        </button>
        <?php if ($isNew): ?>
          <label class="checkbox-row text-xs text-muted-foreground">
            <input class="checkbox" type="checkbox" name="create_another" value="1">
            <span>после сохранения создать ещё одного</span>
          </label>
          <label class="checkbox-row text-xs text-muted-foreground">
            <input class="checkbox" type="checkbox" name="create_account" value="1">
            <span>сразу создать личный кабинет</span>
          </label>
        <?php endif ?>
        <a class="btn btn-ghost btn-block text-muted-foreground" href="<?= $this->e($this->url($isNew ? '/admin/residents' : '/admin/residents/' . $resident->id)) ?>">Отмена</a>
      </div>
    </section>

    <?php if (!$isNew): ?>
      <section class="card">
        <div class="card-header"><div class="card-title">Служебное</div></div>
        <div class="card-content grid gap-1.5 text-xs text-muted-foreground">
          <div class="flex justify-between gap-2"><span>ID</span><b class="tabular text-foreground"><?= (int) $resident->id ?></b></div>
          <div class="flex justify-between gap-2"><span>UUID</span><span class="font-mono"><?= $this->e(\BasIp\Support\Str::limit($resident->uuid, 13)) ?></span></div>
          <div class="flex justify-between gap-2"><span>Создан</span><span class="tabular"><?= $this->e($this->datetime($resident->createdAt)) ?></span></div>
          <div class="flex justify-between gap-2"><span>Изменён</span><span class="tabular"><?= $this->e($this->datetime($resident->updatedAt)) ?></span></div>
          <?php if ($resident->lastPaymentAt !== null): ?>
            <div class="flex justify-between gap-2"><span>Последняя оплата</span><span class="tabular"><?= $this->e($this->date($resident->lastPaymentAt)) ?></span></div>
          <?php endif ?>
          <?php if ($resident->archivedAt !== null || $resident->deletedAt !== null): ?>
            <div class="mt-1 rounded-md border border-destructive/30 bg-destructive/8 p-2 text-destructive">
              Запись в архиве с <?= $this->e($this->date($resident->deletedAt ?? $resident->archivedAt)) ?>
            </div>
          <?php endif ?>
        </div>
      </section>
    <?php endif ?>
  </aside>
</form>
