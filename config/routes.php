<?php

/**
 * Route table.
 *
 * `$router->group(['prefix' => …, 'middleware' => …], fn (Router $r) => …)`
 * Route options understood by the middleware stack:
 *   auth        => true | 'api'      require a session / an API token
 *   roles       => ['admin', …]      role gate
 *   permission  => 'residents.edit'  single permission
 *   any         => ['a','b']         any of the permissions
 *   scopes      => ['gate.open']     required API-token scopes
 *   throttle    => 'gate_open' | '30,60'
 *   csrf_except => true
 *   name        => 'admin.residents.index'
 */

declare(strict_types=1);

use BasIp\Core\Container;
use BasIp\Core\Router;
use BasIp\Http\Controllers\Admin;
use BasIp\Http\Controllers\Api;
use BasIp\Http\Controllers\AuthController;
use BasIp\Http\Controllers\CabinetController;
use BasIp\Http\Controllers\DashboardController;
use BasIp\Http\Controllers\GuestController;
use BasIp\Http\Controllers\HelpController;
use BasIp\Http\Controllers\InstallController;
use BasIp\Http\Controllers\PanelController;
use BasIp\Http\Controllers\SystemController;

return static function (Router $r, Container $c): void {

    /* ══════════════════════ public ══════════════════════ */

    $r->get('/', [DashboardController::class, 'home'], ['name' => 'home']);
    $r->get('/health', [SystemController::class, 'health'], ['name' => 'health', 'throttle' => '60,60']);
    $r->get('/health.json', [SystemController::class, 'healthJson'], ['name' => 'health.json']);
    $r->get('/metrics', [SystemController::class, 'metrics'], ['name' => 'metrics', 'throttle' => '60,60']);
    $r->get('/manifest.webmanifest', [SystemController::class, 'manifest'], ['name' => 'manifest']);
    $r->get('/version', [SystemController::class, 'version'], ['name' => 'version']);

    // Web installer (self-disables once public/install.lock exists).
    $r->get('/install', [InstallController::class, 'show'], ['name' => 'install']);
    $r->post('/install', [InstallController::class, 'run'], ['name' => 'install.run', 'throttle' => '10,300']);
    $r->get('/install/config', [InstallController::class, 'downloadConfig'], ['name' => 'install.config']);

    /* ══════════════════════ authentication ══════════════════════ */

    $r->get('/login', [AuthController::class, 'showLogin'], ['name' => 'login']);
    $r->post('/login', [AuthController::class, 'login'], ['name' => 'login.submit', 'throttle' => 'login']);
    $r->get('/two-factor', [AuthController::class, 'showTwoFactor'], ['name' => '2fa']);
    $r->post('/two-factor', [AuthController::class, 'verifyTwoFactor'], ['name' => '2fa.verify', 'throttle' => '10,300']);
    $r->any('/logout', [AuthController::class, 'logout'], ['name' => 'logout']);

    $r->get('/password/forgot', [AuthController::class, 'showForgot'], ['name' => 'password.forgot']);
    $r->post('/password/forgot', [AuthController::class, 'forgot'], ['name' => 'password.forgot.submit', 'throttle' => 'password_reset']);
    $r->get('/password/reset/{token}', [AuthController::class, 'showReset'], ['name' => 'password.reset']);
    $r->post('/password/reset/{token}', [AuthController::class, 'reset'], ['name' => 'password.reset.submit', 'throttle' => 'password_reset']);

    /* ══════════════════════ guest access (public, signed) ══════════════════════ */

    $r->get('/guest', [GuestController::class, 'index'], ['name' => 'guest.landing']);
    $r->get('/guest/{token}', [GuestController::class, 'show'], ['name' => 'guest.show', 'throttle' => 'guest_open']);
    $r->post('/guest/{token}/open', [GuestController::class, 'open'], ['name' => 'guest.open', 'throttle' => 'guest_open', 'csrf_except' => true]);
    $r->post('/guest/code', [GuestController::class, 'byCode'], ['name' => 'guest.code', 'throttle' => 'guest_open', 'csrf_except' => true]);

    /* ══════════════════════ legacy v1.x endpoints ══════════════════════ */

    $r->get('/bas-ip.php', [SystemController::class, 'legacyPanel'], ['name' => 'legacy.panel']);
    $r->get('/bas_ip.php', [SystemController::class, 'legacyApi'], ['name' => 'legacy.api', 'throttle' => 'api']);
    $r->get('/get_last_image.php', [SystemController::class, 'legacyImage'], ['name' => 'legacy.image']);

    /* ══════════════════════ authenticated (any role) ══════════════════════ */

    $r->group(['middleware' => [], 'prefix' => ''], function (Router $r): void {
        $r->get('/dashboard', [DashboardController::class, 'index'], ['name' => 'dashboard', 'auth' => true]);

        // Operator control panel (staff) / resident cabinet share the gate endpoints.
        $r->get('/panel', [PanelController::class, 'index'], ['name' => 'panel', 'auth' => true]);
        $r->post('/panel/open', [PanelController::class, 'open'], ['name' => 'panel.open', 'auth' => true, 'throttle' => 'gate_open', 'any' => ['gate.open']]);
        $r->post('/panel/close', [PanelController::class, 'close'], ['name' => 'panel.close', 'auth' => true, 'throttle' => 'gate_open', 'permission' => 'gate.close']);
        $r->get('/panel/status', [PanelController::class, 'status'], ['name' => 'panel.status', 'auth' => true, 'throttle' => 'status_poll']);
        $r->get('/panel/snapshot', [PanelController::class, 'snapshot'], ['name' => 'panel.snapshot', 'auth' => true, 'throttle' => '120,60']);

        $r->get('/cabinet', [CabinetController::class, 'index'], ['name' => 'cabinet', 'auth' => true, 'roles' => ['resident', 'admin', 'operator']]);
        $r->get('/cabinet/guest', [CabinetController::class, 'guestList'], ['name' => 'cabinet.guest', 'auth' => true]);
        $r->post('/cabinet/guest', [CabinetController::class, 'createGuest'], ['name' => 'cabinet.guest.create', 'auth' => true, 'throttle' => 'guest_create']);
        $r->post('/cabinet/guest/{id:\d+}/revoke', [CabinetController::class, 'revokeGuest'], ['name' => 'cabinet.guest.revoke', 'auth' => true]);
        $r->get('/cabinet/payments', [CabinetController::class, 'payments'], ['name' => 'cabinet.payments', 'auth' => true]);
        $r->get('/cabinet/log', [CabinetController::class, 'log'], ['name' => 'cabinet.log', 'auth' => true]);
        $r->get('/cabinet/profile', [CabinetController::class, 'profile'], ['name' => 'cabinet.profile', 'auth' => true]);

        // Profile & preferences (all roles)
        $r->get('/profile', [AuthController::class, 'showProfile'], ['name' => 'profile', 'auth' => true]);
        $r->post('/profile', [AuthController::class, 'saveProfile'], ['name' => 'profile.save', 'auth' => true]);
        $r->post('/profile/password', [AuthController::class, 'changePassword'], ['name' => 'profile.password', 'auth' => true, 'throttle' => '10,600']);
        $r->post('/profile/theme', [AuthController::class, 'saveTheme'], ['name' => 'profile.theme', 'auth' => true, 'csrf_except' => true]);
        $r->get('/profile/sessions', [AuthController::class, 'sessions'], ['name' => 'profile.sessions', 'auth' => true]);
        $r->post('/profile/sessions/{id}/revoke', [AuthController::class, 'revokeSession'], ['name' => 'profile.sessions.revoke', 'auth' => true]);
        $r->get('/profile/2fa', [AuthController::class, 'twoFactorSetup'], ['name' => 'profile.2fa', 'auth' => true]);
        $r->post('/profile/2fa/start', [AuthController::class, 'twoFactorStart'], ['name' => 'profile.2fa.start', 'auth' => true]);
        $r->post('/profile/2fa/confirm', [AuthController::class, 'twoFactorConfirm'], ['name' => 'profile.2fa.confirm', 'auth' => true]);
        $r->post('/profile/2fa/disable', [AuthController::class, 'twoFactorDisable'], ['name' => 'profile.2fa.disable', 'auth' => true]);

        // In-app help (comprehensive reference)
        $r->get('/help', [HelpController::class, 'index'], ['name' => 'help', 'auth' => true]);
        $r->get('/help/{section}', [HelpController::class, 'section'], ['name' => 'help.section', 'auth' => true]);
        $r->get('/help-search', [HelpController::class, 'search'], ['name' => 'help.search', 'auth' => true]);
    });

    /* ══════════════════════ admin area ══════════════════════ */

    $r->group(['prefix' => '/admin', 'as' => 'admin.'], function (Router $r): void {

        $r->get('/', [Admin\DashboardController::class, 'index'], ['name' => 'dashboard', 'auth' => true, 'roles' => ['admin', 'operator']]);

        /* ── residents ─────────────────────────────────────────── */
        $r->get('/residents', [Admin\ResidentsController::class, 'index'], ['name' => 'residents.index', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'residents.view']);
        $r->get('/residents/create', [Admin\ResidentsController::class, 'create'], ['name' => 'residents.create', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'residents.create']);
        $r->post('/residents', [Admin\ResidentsController::class, 'store'], ['name' => 'residents.store', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'residents.create']);
        $r->get('/residents/{id:\d+}', [Admin\ResidentsController::class, 'show'], ['name' => 'residents.show', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'residents.view']);
        $r->get('/residents/{id:\d+}/edit', [Admin\ResidentsController::class, 'edit'], ['name' => 'residents.edit', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'residents.edit']);
        $r->post('/residents/{id:\d+}', [Admin\ResidentsController::class, 'update'], ['name' => 'residents.update', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'residents.edit']);
        $r->post('/residents/{id:\d+}/toggle', [Admin\ResidentsController::class, 'toggle'], ['name' => 'residents.toggle', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'residents.edit']);
        $r->post('/residents/{id:\d+}/delete', [Admin\ResidentsController::class, 'destroy'], ['name' => 'residents.delete', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'residents.delete']);
        $r->post('/residents/{id:\d+}/restore', [Admin\ResidentsController::class, 'restore'], ['name' => 'residents.restore', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'residents.restore']);
        $r->post('/residents/{id:\d+}/open-gate', [Admin\ResidentsController::class, 'openGate'], ['name' => 'residents.open', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'gate.open', 'throttle' => 'gate_open']);
        $r->post('/residents/{id:\d+}/payment', [Admin\ResidentsController::class, 'recordPayment'], ['name' => 'residents.payment', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.payment.record']);
        $r->post('/residents/{id:\d+}/invoice', [Admin\ResidentsController::class, 'createInvoice'], ['name' => 'residents.invoice', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.invoice.edit']);
        $r->post('/residents/{id:\d+}/account', [Admin\ResidentsController::class, 'ensureAccount'], ['name' => 'residents.account', 'auth' => true, 'roles' => ['admin'], 'permission' => 'users.create']);
        $r->post('/residents/bulk', [Admin\ResidentsController::class, 'bulk'], ['name' => 'residents.bulk', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'residents.edit']);
        $r->get('/residents/export.csv', [Admin\ResidentsController::class, 'exportCsv'], ['name' => 'residents.export', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'data.export']);

        /* ── billing ───────────────────────────────────────────── */
        $r->get('/billing', [Admin\BillingController::class, 'index'], ['name' => 'billing.index', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.view']);
        $r->get('/billing/wizard', [Admin\BillingController::class, 'wizard'], ['name' => 'billing.wizard', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.charge.create']);
        $r->post('/billing/preview', [Admin\BillingController::class, 'preview'], ['name' => 'billing.preview', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.charge.create', 'throttle' => '60,60']);
        $r->post('/billing/charges', [Admin\BillingController::class, 'storeCharge'], ['name' => 'billing.charges.store', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.charge.create']);
        $r->get('/billing/charges/{id:\d+}', [Admin\BillingController::class, 'showCharge'], ['name' => 'billing.charges.show', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.view']);
        $r->post('/billing/charges/{id:\d+}/update', [Admin\BillingController::class, 'updateCharge'], ['name' => 'billing.charges.update', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.charge.issue']);
        $r->post('/billing/charges/{id:\d+}/recalc', [Admin\BillingController::class, 'recalc'], ['name' => 'billing.charges.recalc', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.charge.recalc']);
        $r->post('/billing/charges/{id:\d+}/notify', [Admin\BillingController::class, 'notifyCharge'], ['name' => 'billing.charges.notify', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'notifications.send']);
        $r->post('/billing/charges/{id:\d+}/close', [Admin\BillingController::class, 'closeCharge'], ['name' => 'billing.charges.close', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.charge.close']);
        $r->post('/billing/charges/{id:\d+}/cancel', [Admin\BillingController::class, 'cancelCharge'], ['name' => 'billing.charges.cancel', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.charge.close']);
        $r->get('/billing/charges/{id:\d+}/export', [Admin\BillingController::class, 'exportCharge'], ['name' => 'billing.charges.export', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'data.export']);
        $r->get('/billing/invoices', [Admin\BillingController::class, 'invoices'], ['name' => 'billing.invoices', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.view']);
        $r->post('/billing/invoices/{id:\d+}/void', [Admin\BillingController::class, 'voidInvoice'], ['name' => 'billing.invoices.void', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.invoice.void']);
        $r->get('/billing/payments', [Admin\BillingController::class, 'payments'], ['name' => 'billing.payments', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.view']);
        $r->post('/billing/payments', [Admin\BillingController::class, 'storePayment'], ['name' => 'billing.payments.store', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.payment.record']);
        $r->post('/billing/payments/{id:\d+}/delete', [Admin\BillingController::class, 'deletePayment'], ['name' => 'billing.payments.delete', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.payment.delete']);
        $r->get('/billing/debtors', [Admin\BillingController::class, 'debtors'], ['name' => 'billing.debtors', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.view']);
        $r->post('/billing/remind', [Admin\BillingController::class, 'remind'], ['name' => 'billing.remind', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'notifications.send']);
        $r->post('/billing/overdue', [Admin\BillingController::class, 'markOverdue'], ['name' => 'billing.overdue', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'billing.charge.issue']);

        /* ── gate log ──────────────────────────────────────────── */
        $r->get('/gate', [Admin\GateController::class, 'events'], ['name' => 'gate.events', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'gate.log']);
        $r->get('/gate/export', [Admin\GateController::class, 'export'], ['name' => 'gate.export', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'data.export']);
        $r->get('/gate/stats', [Admin\GateController::class, 'stats'], ['name' => 'gate.stats', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'gate.log']);
        $r->post('/gate/open', [Admin\GateController::class, 'open'], ['name' => 'gate.open', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'gate.open', 'throttle' => 'gate_open']);
        $r->post('/gate/close', [Admin\GateController::class, 'close'], ['name' => 'gate.close', 'auth' => true, 'roles' => ['admin'], 'permission' => 'gate.close', 'throttle' => 'gate_open']);
        $r->post('/gate/reboot', [Admin\GateController::class, 'reboot'], ['name' => 'gate.reboot', 'auth' => true, 'roles' => ['admin'], 'permission' => 'gate.reboot']);
        $r->post('/gate/sip', [Admin\GateController::class, 'sip'], ['name' => 'gate.sip', 'auth' => true, 'roles' => ['admin'], 'permission' => 'gate.device.control']);
        $r->get('/gate/devices', [Admin\GateController::class, 'devices'], ['name' => 'gate.devices', 'auth' => true, 'roles' => ['admin'], 'permission' => 'devices.manage']);
        $r->post('/gate/devices', [Admin\GateController::class, 'storeDevice'], ['name' => 'gate.devices.store', 'auth' => true, 'roles' => ['admin'], 'permission' => 'devices.manage']);
        $r->post('/gate/devices/{id:\d+}', [Admin\GateController::class, 'updateDevice'], ['name' => 'gate.devices.update', 'auth' => true, 'roles' => ['admin'], 'permission' => 'devices.manage']);
        $r->post('/gate/devices/{id:\d+}/delete', [Admin\GateController::class, 'deleteDevice'], ['name' => 'gate.devices.delete', 'auth' => true, 'roles' => ['admin'], 'permission' => 'devices.manage']);
        $r->post('/gate/devices/{id:\d+}/test', [Admin\GateController::class, 'testDevice'], ['name' => 'gate.devices.test', 'auth' => true, 'roles' => ['admin'], 'permission' => 'devices.manage']);
        $r->get('/gate/schedules', [Admin\GateController::class, 'schedules'], ['name' => 'gate.schedules', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'schedules.manage']);
        $r->post('/gate/schedules', [Admin\GateController::class, 'storeSchedule'], ['name' => 'gate.schedules.store', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'schedules.manage']);
        $r->post('/gate/schedules/{id:\d+}/delete', [Admin\GateController::class, 'deleteSchedule'], ['name' => 'gate.schedules.delete', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'schedules.manage']);

        /* ── guest passes ──────────────────────────────────────── */
        $r->get('/guest', [Admin\GuestController::class, 'index'], ['name' => 'guest.index', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'guest.view']);
        $r->post('/guest', [Admin\GuestController::class, 'store'], ['name' => 'guest.store', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'guest.create']);
        $r->post('/guest/{id:\d+}/revoke', [Admin\GuestController::class, 'revoke'], ['name' => 'guest.revoke', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'guest.revoke']);
        $r->post('/guest/{id:\d+}/extend', [Admin\GuestController::class, 'extend'], ['name' => 'guest.extend', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'guest.create']);
        $r->post('/guest/{id:\d+}/open', [Admin\GuestController::class, 'open'], ['name' => 'guest.open', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'gate.open', 'throttle' => 'gate_open']);
        $r->get('/guest/export', [Admin\GuestController::class, 'export'], ['name' => 'guest.export', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'data.export']);

        /* ── users ─────────────────────────────────────────────── */
        $r->get('/users', [Admin\UsersController::class, 'index'], ['name' => 'users.index', 'auth' => true, 'roles' => ['admin'], 'permission' => 'users.view']);
        $r->post('/users', [Admin\UsersController::class, 'store'], ['name' => 'users.store', 'auth' => true, 'roles' => ['admin'], 'permission' => 'users.create']);
        $r->get('/users/{id:\d+}', [Admin\UsersController::class, 'show'], ['name' => 'users.show', 'auth' => true, 'roles' => ['admin'], 'permission' => 'users.view']);
        $r->post('/users/{id:\d+}', [Admin\UsersController::class, 'update'], ['name' => 'users.update', 'auth' => true, 'roles' => ['admin'], 'permission' => 'users.edit']);
        $r->post('/users/{id:\d+}/delete', [Admin\UsersController::class, 'destroy'], ['name' => 'users.delete', 'auth' => true, 'roles' => ['admin'], 'permission' => 'users.delete']);
        $r->post('/users/{id:\d+}/unlock', [Admin\UsersController::class, 'unlock'], ['name' => 'users.unlock', 'auth' => true, 'roles' => ['admin'], 'permission' => 'users.edit']);
        $r->post('/users/{id:\d+}/reset-2fa', [Admin\UsersController::class, 'resetTwoFactor'], ['name' => 'users.2fa.reset', 'auth' => true, 'roles' => ['admin'], 'permission' => 'users.edit']);
        $r->post('/users/{id:\d+}/logout', [Admin\UsersController::class, 'logoutSessions'], ['name' => 'users.logout', 'auth' => true, 'roles' => ['admin'], 'permission' => 'users.edit']);
        $r->get('/users/sessions/all', [Admin\UsersController::class, 'sessions'], ['name' => 'users.sessions', 'auth' => true, 'roles' => ['admin'], 'permission' => 'users.view']);
        $r->get('/users/logins', [Admin\UsersController::class, 'logins'], ['name' => 'users.logins', 'auth' => true, 'roles' => ['admin'], 'permission' => 'users.view']);

        /* ── notifications ─────────────────────────────────────── */
        $r->get('/notifications', [Admin\NotificationsController::class, 'index'], ['name' => 'notifications.index', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'notifications.view']);
        $r->get('/notifications/batches/{id:\d+}', [Admin\NotificationsController::class, 'batch'], ['name' => 'notifications.batch', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'notifications.view']);
        $r->post('/notifications/compose', [Admin\NotificationsController::class, 'compose'], ['name' => 'notifications.compose', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'notifications.send']);
        $r->post('/notifications/preview', [Admin\NotificationsController::class, 'preview'], ['name' => 'notifications.preview', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'notifications.view']);
        $r->post('/notifications/test', [Admin\NotificationsController::class, 'test'], ['name' => 'notifications.test', 'auth' => true, 'roles' => ['admin'], 'permission' => 'settings.edit', 'throttle' => '10,300']);
        $r->post('/notifications/batches/{id:\d+}/retry', [Admin\NotificationsController::class, 'retry'], ['name' => 'notifications.retry', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'notifications.send']);
        $r->post('/notifications/batches/{id:\d+}/cancel', [Admin\NotificationsController::class, 'cancel'], ['name' => 'notifications.cancel', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'notifications.send']);
        $r->post('/notifications/queue/run', [Admin\NotificationsController::class, 'runQueue'], ['name' => 'notifications.queue', 'auth' => true, 'roles' => ['admin'], 'permission' => 'system.manage']);
        $r->get('/notifications/templates', [Admin\NotificationsController::class, 'templates'], ['name' => 'notifications.templates', 'auth' => true, 'roles' => ['admin'], 'permission' => 'settings.view']);

        /* ── announcements ─────────────────────────────────────── */
        $r->get('/announcements', [Admin\SystemController::class, 'announcements'], ['name' => 'announcements.index', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'announcements.manage']);
        $r->post('/announcements', [Admin\SystemController::class, 'storeAnnouncement'], ['name' => 'announcements.store', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'announcements.manage']);
        $r->post('/announcements/{id:\d+}/delete', [Admin\SystemController::class, 'deleteAnnouncement'], ['name' => 'announcements.delete', 'auth' => true, 'roles' => ['admin', 'operator'], 'permission' => 'announcements.manage']);

        /* ── data: import / export / backups ───────────────────── */
        $r->get('/data', [Admin\DataController::class, 'index'], ['name' => 'data.index', 'auth' => true, 'roles' => ['admin'], 'permission' => 'data.export']);
        $r->post('/data/inspect', [Admin\DataController::class, 'inspect'], ['name' => 'data.inspect', 'auth' => true, 'roles' => ['admin'], 'permission' => 'data.import']);
        $r->post('/data/import', [Admin\DataController::class, 'import'], ['name' => 'data.import', 'auth' => true, 'roles' => ['admin'], 'permission' => 'data.import']);
        $r->post('/data/import/payments', [Admin\DataController::class, 'importPayments'], ['name' => 'data.import.payments', 'auth' => true, 'roles' => ['admin'], 'permission' => 'data.import']);
        $r->get('/data/export/{kind}', [Admin\DataController::class, 'export'], ['name' => 'data.export', 'auth' => true, 'roles' => ['admin'], 'permission' => 'data.export']);
        $r->get('/data/sample', [Admin\DataController::class, 'sample'], ['name' => 'data.sample', 'auth' => true, 'roles' => ['admin']]);
        $r->post('/data/backup', [Admin\DataController::class, 'backup'], ['name' => 'data.backup', 'auth' => true, 'roles' => ['admin'], 'permission' => 'backup.create']);
        $r->get('/data/backup/{id:\d+}', [Admin\DataController::class, 'downloadBackup'], ['name' => 'data.backup.download', 'auth' => true, 'roles' => ['admin'], 'permission' => 'backup.view']);
        $r->post('/data/backup/{id:\d+}/restore', [Admin\DataController::class, 'restore'], ['name' => 'data.backup.restore', 'auth' => true, 'roles' => ['admin'], 'permission' => 'backup.restore']);
        $r->post('/data/backup/{id:\d+}/delete', [Admin\DataController::class, 'deleteBackup'], ['name' => 'data.backup.delete', 'auth' => true, 'roles' => ['admin'], 'permission' => 'backup.delete']);

        /* ── settings ──────────────────────────────────────────── */
        $r->get('/settings', [Admin\SettingsController::class, 'index'], ['name' => 'settings.index', 'auth' => true, 'roles' => ['admin'], 'permission' => 'settings.view']);
        $r->get('/settings/{group}', [Admin\SettingsController::class, 'group'], ['name' => 'settings.group', 'auth' => true, 'roles' => ['admin'], 'permission' => 'settings.view']);
        $r->post('/settings/{group}', [Admin\SettingsController::class, 'save'], ['name' => 'settings.save', 'auth' => true, 'roles' => ['admin'], 'permission' => 'settings.edit']);
        $r->post('/settings/secrets/{name}', [Admin\SettingsController::class, 'saveSecret'], ['name' => 'settings.secret', 'auth' => true, 'roles' => ['admin'], 'permission' => 'settings.edit']);
        $r->post('/settings/config/download', [Admin\SettingsController::class, 'downloadLocalConfig'], ['name' => 'settings.config.download', 'auth' => true, 'roles' => ['admin'], 'permission' => 'settings.view']);

        /* ── system ────────────────────────────────────────────── */
        $r->get('/system', [Admin\SystemController::class, 'index'], ['name' => 'system.index', 'auth' => true, 'roles' => ['admin'], 'permission' => 'system.view']);
        $r->get('/system/doctor', [Admin\SystemController::class, 'doctor'], ['name' => 'system.doctor', 'auth' => true, 'roles' => ['admin'], 'permission' => 'system.view']);
        $r->get('/system/logs', [Admin\SystemController::class, 'logs'], ['name' => 'system.logs', 'auth' => true, 'roles' => ['admin'], 'permission' => 'logs.view']);
        $r->get('/system/logs/{channel}', [Admin\SystemController::class, 'logChannel'], ['name' => 'system.log', 'auth' => true, 'roles' => ['admin'], 'permission' => 'logs.view']);
        $r->post('/system/logs/{channel}/truncate', [Admin\SystemController::class, 'truncateLog'], ['name' => 'system.log.truncate', 'auth' => true, 'roles' => ['admin'], 'permission' => 'system.manage']);
        $r->post('/system/cache/clear', [Admin\SystemController::class, 'clearCache'], ['name' => 'system.cache', 'auth' => true, 'roles' => ['admin'], 'permission' => 'system.manage']);
        $r->post('/system/maintenance', [Admin\SystemController::class, 'maintenance'], ['name' => 'system.maintenance', 'auth' => true, 'roles' => ['admin'], 'permission' => 'system.manage']);
        $r->post('/system/cron', [Admin\SystemController::class, 'runCron'], ['name' => 'system.cron', 'auth' => true, 'roles' => ['admin'], 'permission' => 'system.manage']);
        $r->post('/system/prune', [Admin\SystemController::class, 'prune'], ['name' => 'system.prune', 'auth' => true, 'roles' => ['admin'], 'permission' => 'system.manage']);
        $r->get('/system/phpinfo', [Admin\SystemController::class, 'phpinfo'], ['name' => 'system.phpinfo', 'auth' => true, 'roles' => ['admin'], 'permission' => 'system.view']);
        $r->get('/system/query-log', [Admin\SystemController::class, 'queryLog'], ['name' => 'system.queries', 'auth' => true, 'roles' => ['admin'], 'permission' => 'system.view']);

        /* ── audit ─────────────────────────────────────────────── */
        $r->get('/audit', [Admin\SystemController::class, 'audit'], ['name' => 'audit.index', 'auth' => true, 'roles' => ['admin'], 'permission' => 'audit.view']);
        $r->get('/audit/export', [Admin\SystemController::class, 'exportAudit'], ['name' => 'audit.export', 'auth' => true, 'roles' => ['admin'], 'permission' => 'data.export']);

        /* ── API tokens ────────────────────────────────────────── */
        $r->get('/api-tokens', [Admin\SystemController::class, 'apiTokens'], ['name' => 'api.index', 'auth' => true, 'roles' => ['admin'], 'permission' => 'api.view']);
        $r->post('/api-tokens', [Admin\SystemController::class, 'createApiToken'], ['name' => 'api.create', 'auth' => true, 'roles' => ['admin'], 'permission' => 'api.create']);
        $r->post('/api-tokens/{id:\d+}/revoke', [Admin\SystemController::class, 'revokeApiToken'], ['name' => 'api.revoke', 'auth' => true, 'roles' => ['admin'], 'permission' => 'api.delete']);

        /* ── updates ───────────────────────────────────────────── */
        $r->get('/updates', [Admin\SystemController::class, 'updates'], ['name' => 'updates.index', 'auth' => true, 'roles' => ['admin'], 'permission' => 'update.check']);
        $r->post('/updates/check', [Admin\SystemController::class, 'checkUpdate'], ['name' => 'updates.check', 'auth' => true, 'roles' => ['admin'], 'permission' => 'update.check', 'throttle' => '20,600']);
        $r->post('/updates/install', [Admin\SystemController::class, 'installUpdate'], ['name' => 'updates.install', 'auth' => true, 'roles' => ['admin'], 'permission' => 'update.run', 'throttle' => '5,600']);
        $r->post('/updates/rollback', [Admin\SystemController::class, 'rollback'], ['name' => 'updates.rollback', 'auth' => true, 'roles' => ['admin'], 'permission' => 'update.rollback']);
    });

    /* ══════════════════════ public API (token auth) ══════════════════════ */

    $r->group(['prefix' => '/api/v1', 'as' => 'api.'], function (Router $r): void {
        $r->get('/meta', [Api\MetaApiController::class, 'meta'], ['name' => 'meta', 'throttle' => '60,60']);
        $r->get('/health', [Api\MetaApiController::class, 'health'], ['name' => 'health', 'throttle' => '60,60']);

        $r->get('/gate/status', [Api\GateApiController::class, 'status'], ['name' => 'gate.status', 'auth' => 'api', 'scopes' => ['gate.status', 'gate.open', '*'], 'throttle' => 'status_poll']);
        $r->post('/gate/open', [Api\GateApiController::class, 'open'], ['name' => 'gate.open', 'auth' => 'api', 'scopes' => ['gate.open', '*'], 'throttle' => 'gate_open']);
        $r->post('/gate/close', [Api\GateApiController::class, 'close'], ['name' => 'gate.close', 'auth' => 'api', 'scopes' => ['gate.close', '*'], 'throttle' => 'gate_open']);
        $r->get('/gate/events', [Api\GateApiController::class, 'events'], ['name' => 'gate.events', 'auth' => 'api', 'scopes' => ['gate.log', '*'], 'throttle' => 'api']);
        $r->get('/gate/snapshot', [Api\GateApiController::class, 'snapshot'], ['name' => 'gate.snapshot', 'auth' => 'api', 'scopes' => ['gate.status', '*'], 'throttle' => '60,60']);

        $r->post('/guest/passes', [Api\GateApiController::class, 'createGuestPass'], ['name' => 'guest.create', 'auth' => 'api', 'scopes' => ['guest.create', '*'], 'throttle' => 'guest_create']);
        $r->get('/guest/passes', [Api\GateApiController::class, 'listGuestPasses'], ['name' => 'guest.list', 'auth' => 'api', 'scopes' => ['guest.view', '*'], 'throttle' => 'api']);
        $r->delete('/guest/passes/{id:\d+}', [Api\GateApiController::class, 'revokeGuestPass'], ['name' => 'guest.revoke', 'auth' => 'api', 'scopes' => ['guest.revoke', '*'], 'throttle' => 'api']);

        $r->get('/residents', [Api\MetaApiController::class, 'residents'], ['name' => 'residents.index', 'auth' => 'api', 'scopes' => ['residents.view', '*'], 'throttle' => 'api']);
        $r->get('/residents/{id:\d+}', [Api\MetaApiController::class, 'resident'], ['name' => 'residents.show', 'auth' => 'api', 'scopes' => ['residents.view', '*'], 'throttle' => 'api']);
        $r->post('/payments', [Api\MetaApiController::class, 'recordPayment'], ['name' => 'payments.store', 'auth' => 'api', 'scopes' => ['billing.payment.record', '*'], 'throttle' => 'api']);
        $r->get('/stats', [Api\MetaApiController::class, 'stats'], ['name' => 'stats', 'auth' => 'api', 'scopes' => ['*', 'billing.view'], 'throttle' => 'api']);
        $r->post('/notifications', [Api\MetaApiController::class, 'sendNotification'], ['name' => 'notifications.send', 'auth' => 'api', 'scopes' => ['notifications.send', '*'], 'throttle' => 'api']);
        $r->get('/docs', [Api\MetaApiController::class, 'docs'], ['name' => 'docs']);
    });

    // Telegram webhook (secret-protected, no CSRF, no session).
    $r->post('/webhook/telegram', [Api\MetaApiController::class, 'telegramWebhook'], ['name' => 'webhook.telegram', 'csrf_except' => true, 'throttle' => '60,60']);
};
