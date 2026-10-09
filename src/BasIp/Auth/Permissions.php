<?php

declare(strict_types=1);

namespace BasIp\Auth;

/**
 * Role → permission matrix.
 *
 * Three built-in roles:
 *   admin     — полный доступ, включая настройки, обновления и резервные копии
 *   operator  — диспетчер: жители, шлагбаум, оплаты, рассылки (без настроек)
 *   resident  — личный кабинет: открыть шлагбаум, гостевой доступ, свои счета
 *
 * Permissions use `area.action` and support the `*` wildcard.
 */
final class Permissions
{
    public const ALL = [
        // Жители
        'residents.view', 'residents.create', 'residents.edit', 'residents.delete', 'residents.restore',
        // Автомобили и контакты
        'cars.manage', 'contacts.manage',
        // Шлагбаум
        'gate.open', 'gate.close', 'gate.log', 'gate.reboot', 'gate.device.control',
        // Гостевой доступ
        'guest.view', 'guest.create', 'guest.revoke',
        // Деньги
        'billing.view', 'billing.charge.create', 'billing.charge.issue', 'billing.charge.recalc',
        'billing.charge.close', 'billing.invoice.edit', 'billing.invoice.void', 'billing.payment.record',
        'billing.payment.delete',
        // Рассылки
        'notifications.view', 'notifications.send',
        // Данные
        'data.export', 'data.import', 'backup.view', 'backup.create', 'backup.restore', 'backup.delete',
        // Система
        'settings.view', 'settings.edit', 'users.view', 'users.create', 'users.edit', 'users.delete',
        'devices.manage', 'schedules.manage', 'announcements.manage', 'audit.view', 'logs.view',
        'system.view', 'system.manage', 'api.view', 'api.create', 'api.delete',
        'update.check', 'update.run', 'update.rollback',
    ];

    /** @var array<string, array<int, string>> */
    private const ROLES = [
        'admin' => ['*'],
        'operator' => [
            'residents.view', 'residents.create', 'residents.edit',
            'cars.manage', 'contacts.manage',
            'gate.open', 'gate.log',
            'guest.view', 'guest.create', 'guest.revoke',
            'billing.view', 'billing.charge.create', 'billing.charge.issue', 'billing.charge.recalc',
            'billing.invoice.edit', 'billing.payment.record',
            'notifications.view', 'notifications.send',
            'data.export',
            'announcements.manage', 'schedules.manage',
            'audit.view', 'logs.view', 'system.view',
        ],
        'resident' => [
            'gate.open',          // свой шлагбаум
            'guest.create',       // свой гостевой доступ
            'guest.view',
            'billing.view',       // только свои счета
        ],
    ];

    /** @return array<int, string> */
    public static function forRole(string $role): array
    {
        return self::ROLES[$role] ?? [];
    }

    /** @return array<int, string> */
    public static function roles(): array
    {
        return array_keys(self::ROLES);
    }

    public static function isValidRole(string $role): bool
    {
        return isset(self::ROLES[$role]);
    }

    public static function can(string $role, string $permission): bool
    {
        $granted = self::ROLES[$role] ?? [];
        if (in_array('*', $granted, true) || in_array($permission, $granted, true)) {
            return true;
        }
        [$area] = explode('.', $permission, 2);

        return in_array($area . '.*', $granted, true);
    }

    /** Human-readable description for the help screen. @return array<string, string> */
    public static function labels(): array
    {
        return [
            'residents.view' => 'Просмотр жителей',
            'residents.create' => 'Добавление жителей',
            'residents.edit' => 'Редактирование жителей',
            'residents.delete' => 'Удаление жителей',
            'residents.restore' => 'Восстановление удалённых',
            'cars.manage' => 'Управление автомобилями',
            'contacts.manage' => 'Управление контактами',
            'gate.open' => 'Открытие шлагбаума',
            'gate.close' => 'Закрытие шлагбаума',
            'gate.log' => 'Журнал открываний',
            'gate.reboot' => 'Перезагрузка панели',
            'gate.device.control' => 'Управление устройством (SIP и пр.)',
            'guest.view' => 'Просмотр гостевых доступов',
            'guest.create' => 'Создание гостевого доступа',
            'guest.revoke' => 'Отзыв гостевого доступа',
            'billing.view' => 'Просмотр начислений и оплат',
            'billing.charge.create' => 'Создание начисления',
            'billing.charge.issue' => 'Выставление счетов',
            'billing.charge.recalc' => 'Перерасчёт',
            'billing.charge.close' => 'Закрытие периода',
            'billing.invoice.edit' => 'Правка счёта',
            'billing.invoice.void' => 'Аннулирование счёта',
            'billing.payment.record' => 'Проведение оплаты',
            'billing.payment.delete' => 'Удаление оплаты',
            'notifications.view' => 'Просмотр рассылок',
            'notifications.send' => 'Отправка уведомлений',
            'data.export' => 'Выгрузка данных',
            'data.import' => 'Загрузка данных',
            'backup.view' => 'Просмотр резервных копий',
            'backup.create' => 'Создание резервной копии',
            'backup.restore' => 'Восстановление из копии',
            'backup.delete' => 'Удаление резервных копий',
            'settings.view' => 'Просмотр настроек',
            'settings.edit' => 'Изменение настроек',
            'users.view' => 'Просмотр учётных записей',
            'users.create' => 'Создание учётных записей',
            'users.edit' => 'Изменение учётных записей',
            'users.delete' => 'Удаление учётных записей',
            'devices.manage' => 'Управление устройствами',
            'schedules.manage' => 'Расписания доступа',
            'announcements.manage' => 'Объявления',
            'audit.view' => 'Журнал аудита',
            'logs.view' => 'Просмотр логов',
            'system.view' => 'Состояние системы',
            'system.manage' => 'Управление системой (кэш, очередь, обслуживание)',
            'api.view' => 'Просмотр API-токенов',
            'api.create' => 'Создание API-токенов',
            'api.delete' => 'Удаление API-токенов',
            'update.check' => 'Проверка обновлений',
            'update.run' => 'Установка обновлений',
            'update.rollback' => 'Откат версии',
        ];
    }
}
