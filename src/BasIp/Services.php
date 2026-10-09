<?php

declare(strict_types=1);

namespace BasIp;

use BasIp\Auth\AuthService;
use BasIp\Auth\Guard;
use BasIp\Core\Cache;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Container;
use BasIp\Core\Crypto;
use BasIp\Core\Csrf;
use BasIp\Core\Events;
use BasIp\Core\HttpClient;
use BasIp\Core\Logger;
use BasIp\Core\RateLimiter;
use BasIp\Core\Request;
use BasIp\Core\Session;
use BasIp\Core\View;
use BasIp\Db\Database;
use BasIp\Device\DeviceManager;
use BasIp\Domain\Repository\ResidentRepository;
use BasIp\Notify\Channel\LogChannel;
use BasIp\Notify\Channel\MailChannel;
use BasIp\Notify\Channel\SmsChannel;
use BasIp\Notify\Channel\TelegramChannel;
use BasIp\Notify\Channel\WebhookChannel;
use BasIp\Notify\Channel\WhatsAppChannel;
use BasIp\Notify\NotificationService;
use BasIp\Notify\QueueWorker;
use BasIp\Notify\TemplateRenderer;
use BasIp\Services\AnnouncementService;
use BasIp\Services\ApiTokenService;
use BasIp\Services\AuditService;
use BasIp\Services\BackupService;
use BasIp\Services\BillingService;
use BasIp\Services\DataService;
use BasIp\Services\GateService;
use BasIp\Services\GuestPassService;
use BasIp\Services\ImportExportService;
use BasIp\Services\ResidentService;
use BasIp\Services\SettingsService;
use BasIp\Services\StatisticsService;
use BasIp\Services\UserService;
use BasIp\Update\UpdateService;

/**
 * Service wiring.
 *
 * Everything is a lazy closure: a request for `/login` never touches the device
 * driver, the queue worker or the update checker. Services that need the
 * current HTTP request read it from the container (`Request::class`), which the
 * kernel binds before dispatching.
 */
final class Services
{
    public static function register(Container $c): void
    {
        /* ── HTTP-scoped ─────────────────────────────────────────────── */

        $c->bind(Session::class, static fn (Container $c): Session => new Session(
            $c->get(Config::class),
            $c->get(Request::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
        ));

        $c->bind(Events::class, static fn (): Events => new Events());

        /* ── domain repositories ─────────────────────────────────────── */

        $c->bind(ResidentRepository::class, static fn (Container $c): ResidentRepository => new ResidentRepository(
            $c->get(Database::class),
            $c->get(Clock::class),
        ));

        /* ── core services ───────────────────────────────────────────── */

        $c->bind(SettingsService::class, static fn (Container $c): SettingsService => new SettingsService(
            $c->get(Database::class),
            $c->get(Config::class),
            $c->get(Clock::class),
            $c->get(Cache::class),
            $c->get(Crypto::class),
        ));

        $c->bind(AuditService::class, static fn (Container $c): AuditService => new AuditService(
            $c->get(Database::class),
            $c->get(Config::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
        ));

        $c->bind(Guard::class, static fn (Container $c): Guard => new Guard(
            $c->get(Database::class),
            $c->get(Config::class),
            $c->get(Session::class),
            $c->get(Crypto::class),
        ));

        $c->bind(AuthService::class, static fn (Container $c): AuthService => new AuthService(
            $c->get(Database::class),
            $c->get(Config::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
            $c->get(Session::class),
            $c->get(Guard::class),
            $c->get(Crypto::class),
            $c->get(RateLimiter::class),
        ));

        $c->bind(UserService::class, static fn (Container $c): UserService => new UserService(
            $c->get(Database::class),
            $c->get(Config::class),
            $c->get(Clock::class),
            $c->get(Crypto::class),
            $c->get(AuthService::class),
            $c->get(AuditService::class),
            $c->get(Guard::class),
        ));

        $c->bind(DeviceManager::class, static fn (Container $c): DeviceManager => new DeviceManager(
            $c->get(Database::class),
            $c->get(Config::class),
            $c->get(Logger::class),
            $c->get(Clock::class),
            $c->get(HttpClient::class),
            $c->get(Crypto::class),
            $c->get(Cache::class),
            $c->get(SettingsService::class),
        ));

        $c->bind(TemplateRenderer::class, static fn (Container $c): TemplateRenderer => new TemplateRenderer(
            $c->get(Config::class),
        ));

        $c->bind(NotificationService::class, static function (Container $c): NotificationService {
            $service = new NotificationService(
                $c->get(Database::class),
                $c->get(Config::class),
                $c->get(Clock::class),
                $c->get(Logger::class),
                $c->get(SettingsService::class),
                $c->get(ResidentRepository::class),
                $c->get(Crypto::class),
                $c->get(TemplateRenderer::class),
                $c->get(AuditService::class),
            );

            $http = $c->get(HttpClient::class);
            $service->registerChannels([
                new LogChannel($c->get(Config::class), $c->get(SettingsService::class), $c->get(Logger::class), $c->get(Crypto::class)),
                new MailChannel($c->get(Config::class), $c->get(SettingsService::class), $c->get(Logger::class), $c->get(Crypto::class)),
                new TelegramChannel($c->get(Config::class), $c->get(SettingsService::class), $c->get(Logger::class), $c->get(Crypto::class), $http),
                new SmsChannel($c->get(Config::class), $c->get(SettingsService::class), $c->get(Logger::class), $c->get(Crypto::class), $http),
                new WebhookChannel($c->get(Config::class), $c->get(SettingsService::class), $c->get(Logger::class), $c->get(Crypto::class), $http),
                new WhatsAppChannel($c->get(Config::class), $c->get(SettingsService::class), $c->get(Logger::class), $c->get(Crypto::class), $http),
            ]);

            return $service;
        });

        $c->bind(QueueWorker::class, static fn (Container $c): QueueWorker => new QueueWorker(
            $c->get(Database::class),
            $c->get(Config::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
            $c->get(NotificationService::class),
        ));

        $c->bind(BillingService::class, static fn (Container $c): BillingService => new BillingService(
            $c->get(Database::class),
            $c->get(Config::class),
            $c->get(Clock::class),
            $c->get(ResidentRepository::class),
            $c->get(AuditService::class),
            $c->get(SettingsService::class),
            $c->get(NotificationService::class),
            $c->get(TemplateRenderer::class),
        ));

        $c->bind(ResidentService::class, static fn (Container $c): ResidentService => new ResidentService(
            $c->get(Database::class),
            $c->get(Config::class),
            $c->get(Clock::class),
            $c->get(ResidentRepository::class),
            $c->get(AuditService::class),
            $c->get(BillingService::class),
        ));

        $c->bind(GateService::class, static fn (Container $c): GateService => new GateService(
            $c->get(Database::class),
            $c->get(Config::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
            $c->get(DeviceManager::class),
            $c->get(ResidentRepository::class),
            $c->get(AuditService::class),
            $c->get(SettingsService::class),
            $c->get(RateLimiter::class),
            $c->get(Events::class),
            $c->get(Cache::class),
        ));

        $c->bind(GuestPassService::class, static fn (Container $c): GuestPassService => new GuestPassService(
            $c->get(Database::class),
            $c->get(Config::class),
            $c->get(Clock::class),
            $c->get(Crypto::class),
            $c->get(ResidentRepository::class),
            $c->get(AuditService::class),
            $c->get(SettingsService::class),
            $c->get(NotificationService::class),
        ));

        $c->bind(ImportExportService::class, static fn (Container $c): ImportExportService => new ImportExportService(
            $c->get(Database::class),
            $c->get(Config::class),
            $c->get(Clock::class),
            $c->get(ResidentRepository::class),
            $c->get(ResidentService::class),
            $c->get(BillingService::class),
            $c->get(AuditService::class),
        ));

        $c->bind(BackupService::class, static fn (Container $c): BackupService => new BackupService(
            $c->get(Database::class),
            $c->get(Config::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
            $c->get(Crypto::class),
            $c->get(AuditService::class),
        ));

        $c->bind(UpdateService::class, static fn (Container $c): UpdateService => new UpdateService(
            $c->get(Config::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
            $c->get(HttpClient::class),
            $c->get(Database::class),
            $c->get(Cache::class),
            $c->get(SettingsService::class),
            $c->get(AuditService::class),
            $c->get(BackupService::class),
            $c->get(\BasIp\Db\Migrator::class),
        ));

        $c->bind(StatisticsService::class, static fn (Container $c): StatisticsService => new StatisticsService(
            $c->get(Database::class),
            $c->get(Config::class),
            $c->get(Clock::class),
            $c->get(Cache::class),
            $c->get(ResidentRepository::class),
        ));

        $c->bind(AnnouncementService::class, static fn (Container $c): AnnouncementService => new AnnouncementService(
            $c->get(Database::class),
            $c->get(Clock::class),
            $c->get(AuditService::class),
        ));

        $c->bind(ApiTokenService::class, static fn (Container $c): ApiTokenService => new ApiTokenService(
            $c->get(Database::class),
            $c->get(Clock::class),
            $c->get(Crypto::class),
            $c->get(AuditService::class),
        ));

        $c->bind(DataService::class, static fn (Container $c): DataService => new DataService(
            $c->get(Database::class),
            $c->get(Config::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
            $c->get(Cache::class),
            $c->get(SettingsService::class),
            $c->get(ResidentRepository::class),
            $c->get(DeviceManager::class),
            $c->get(AuditService::class),
        ));

        /* ── controllers ─────────────────────────────────────────────── */

        foreach (self::controllers() as $class) {
            $c->bind($class, static function (Container $c) use ($class) {
                return new $class($c);
            });
        }
    }

    /** @return array<int, class-string> */
    public static function controllers(): array
    {
        return [
            \BasIp\Http\Controllers\AuthController::class,
            \BasIp\Http\Controllers\DashboardController::class,
            \BasIp\Http\Controllers\PanelController::class,
            \BasIp\Http\Controllers\CabinetController::class,
            \BasIp\Http\Controllers\GuestController::class,
            \BasIp\Http\Controllers\HelpController::class,
            \BasIp\Http\Controllers\InstallController::class,
            \BasIp\Http\Controllers\SystemController::class,
            \BasIp\Http\Controllers\Admin\DashboardController::class,
            \BasIp\Http\Controllers\Admin\ResidentsController::class,
            \BasIp\Http\Controllers\Admin\BillingController::class,
            \BasIp\Http\Controllers\Admin\GateController::class,
            \BasIp\Http\Controllers\Admin\GuestController::class,
            \BasIp\Http\Controllers\Admin\UsersController::class,
            \BasIp\Http\Controllers\Admin\SettingsController::class,
            \BasIp\Http\Controllers\Admin\DataController::class,
            \BasIp\Http\Controllers\Admin\SystemController::class,
            \BasIp\Http\Controllers\Admin\NotificationsController::class,
            \BasIp\Http\Controllers\Api\GateApiController::class,
            \BasIp\Http\Controllers\Api\MetaApiController::class,
        ];
    }
}
