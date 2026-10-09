<?php

declare(strict_types=1);

namespace BasIp\Device;

use BasIp\Core\Cache;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Crypto;
use BasIp\Core\HttpClient;
use BasIp\Core\Logger;
use BasIp\Db\Database;
use BasIp\Device\Driver\BasIpDriver;
use BasIp\Device\Driver\DryRunDriver;
use BasIp\Device\Driver\HttpRelayDriver;
use BasIp\Domain\Model\Device;
use BasIp\Services\SettingsService;
use Throwable;

/**
 * Builds and caches driver instances for every configured gate.
 *
 * Devices come from two places, merged in this order:
 *   1. `config.device` / `config.device.gates.*`  (deployment-time)
 *   2. the `devices` table                        (editable in the admin UI)
 *
 * The database row wins, and its password is stored encrypted with the
 * application key so a DB dump alone does not give away panel credentials.
 */
final class DeviceManager
{
    /** @var array<string, GateDriver> */
    private array $drivers = [];

    /** @var array<string, Device> */
    private array $devices = [];

    private bool $loaded = false;

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly Clock $clock,
        private readonly HttpClient $http,
        private readonly Crypto $crypto,
        private readonly ?Cache $cache = null,
        private readonly ?SettingsService $settings = null,
    ) {
    }

    /** Load the device list from the database (or synthesise it from config). */
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $rows = [];
        try {
            $rows = $this->db->table('devices')->orderBy('is_default', 'desc')->orderBy('id')->get();
        } catch (Throwable) {
            $rows = [];      // not installed yet
        }

        if ($rows === []) {
            // Single-device mode straight from the config file.
            $slug = (string) ($this->settings?->string('gate.default_device') ?: $this->config->string('device.default', 'main'));
            $this->devices[$slug] = Device::fromRow([
                'id' => 0, 'name' => $this->config->string('app.short_name', 'Шлагбаум'), 'slug' => $slug,
                'driver' => $this->config->string('device.driver', 'basip'),
                'base_url' => $this->config->string('device.base_url'),
                'login' => $this->config->string('device.login'),
                'channel' => $this->config->int('device.lock.channel', 1),
                'is_default' => true, 'is_active' => true,
                'options' => [],
            ]);

            return;
        }

        foreach ($rows as $row) {
            $device = Device::fromRow($row);
            if ($device->slug === '') {
                $device = Device::fromRow($row + ['slug' => 'device-' . $device->id]);
            }
            $this->devices[$device->slug] = $device;
        }
    }

    /** @return array<string, Device> */
    public function all(bool $onlyActive = true): array
    {
        $this->load();
        if (!$onlyActive) {
            return $this->devices;
        }

        return array_filter($this->devices, static fn (Device $d): bool => $d->isActive);
    }

    public function default(): ?Device
    {
        $this->load();
        foreach ($this->devices as $device) {
            if ($device->isDefault) {
                return $device;
            }
        }

        return $this->devices === [] ? null : reset($this->devices);
    }

    public function find(string $slugOrId): ?Device
    {
        $this->load();
        if (isset($this->devices[$slugOrId])) {
            return $this->devices[$slugOrId];
        }
        foreach ($this->devices as $device) {
            if ((string) $device->id === $slugOrId || $device->name === $slugOrId) {
                return $device;
            }
        }

        return null;
    }

    public function resolve(?string $slug = null): ?Device
    {
        return $slug !== null && $slug !== '' ? ($this->find($slug) ?? $this->default()) : $this->default();
    }

    /** Build (and memoise) the driver for a device. */
    public function driver(?Device $device = null): GateDriver
    {
        $device ??= $this->default();
        $key = $device?->slug ?? 'default';

        if (isset($this->drivers[$key])) {
            return $this->drivers[$key];
        }

        $driverName = strtolower((string) ($device?->driver ?? $this->config->string('device.driver', 'basip')));
        $driver = match ($driverName) {
            'dryrun', 'dry-run', 'simulate', 'demo' => new DryRunDriver($this->logger, $key),
            'http-relay', 'relay', 'generic'        => new HttpRelayDriver($this->http, $this->config, $this->logger, $key),
            default                                 => new BasIpDriver($this->http, $this->config, $this->logger, $this->clock, $this->cache, $key, $this->db),
        };

        $driver->configure($this->optionsFor($device));

        return $this->drivers[$key] = $driver;
    }

    /** Effective options for a device: config.device ← config.device.gates.X ← DB row. */
    public function optionsFor(?Device $device): array
    {
        $base = (array) $this->config->section('device');
        unset($base['gates']);

        if ($device !== null && $device->slug !== '' && $device->id === 0) {
            // Config-only device: pull the named gate block when present.
            $extra = (array) $this->config->get('device.gates.' . $device->slug, []);
            $base = array_merge($base, $extra);
        }

        if ($device === null) {
            return $base;
        }

        $merged = array_merge($base, (array) $device->options);
        if ($device->baseUrl !== null && $device->baseUrl !== '') {
            $merged['base_url'] = $device->baseUrl;
        }
        if ($device->login !== null && $device->login !== '') {
            $merged['login'] = $device->login;
        }
        if ($device->passwordEnc !== null && $device->passwordEnc !== '') {
            $plain = $this->crypto->isEncrypted($device->passwordEnc)
                ? $this->crypto->decrypt($device->passwordEnc, 'device:' . $device->slug)
                : $device->passwordEnc;
            if ($plain !== null) {
                $merged['password'] = $plain;
            }
        }
        $merged['lock'] = array_merge((array) ($base['lock'] ?? []), ['channel' => $device->channel ?: 1]);

        return $merged;
    }

    /** Forget memoised drivers (after a settings change). */
    public function flush(): void
    {
        foreach ($this->drivers as $driver) {
            try {
                $driver->disconnect();
            } catch (Throwable) {
            }
        }
        $this->drivers = [];
        $this->devices = [];
        $this->loaded = false;
    }

    /** Create or update a device row. @param array<string, mixed> $input */
    public function save(array $input, ?int $id = null): int
    {
        $slug = trim((string) ($input['slug'] ?? ''));
        if ($slug === '') {
            $slug = \BasIp\Support\Str::slug((string) ($input['name'] ?? 'gate')) ?: ('gate-' . $this->clock->now());
        }
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug)) ?: 'gate';

        $row = [
            'name'     => mb_substr(trim((string) ($input['name'] ?? 'Шлагбаум')), 0, 120),
            'slug'     => mb_substr($slug, 0, 64),
            'driver'   => in_array($input['driver'] ?? 'basip', ['basip', 'http-relay', 'dryrun'], true) ? $input['driver'] : 'basip',
            'base_url' => trim((string) ($input['base_url'] ?? '')) ?: null,
            'login'    => trim((string) ($input['login'] ?? '')) ?: null,
            'channel'  => max(1, (int) ($input['channel'] ?? 1)),
            'location' => trim((string) ($input['location'] ?? '')) ?: null,
            'is_active' => (int) \BasIp\Support\Str::toBoolSafe($input['is_active'] ?? true),
            'notes'    => trim((string) ($input['notes'] ?? '')) ?: null,
            'updated_at' => $this->clock->format(),
        ];
        if (isset($input['options']) && is_array($input['options'])) {
            $row['options'] = (string) json_encode($input['options'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (!empty($input['password'])) {
            $row['password_enc'] = $this->crypto->encrypt((string) $input['password'], 'device:' . $row['slug']);
        }
        if (!empty($input['is_default'])) {
            $this->db->table('devices')->update(['is_default' => 0]);
            $row['is_default'] = 1;
        }

        if ($id !== null && $id > 0) {
            $this->db->table('devices')->where('id', '=', $id)->update($row);
            $this->flush();

            return $id;
        }

        $row['created_at'] = $this->clock->format();
        $newId = $this->db->table('devices')->insertGetId($row);
        $this->flush();

        return $newId;
    }

    public function delete(int $id): bool
    {
        $ok = $this->db->table('devices')->where('id', '=', $id)->delete() > 0;
        $this->flush();

        return $ok;
    }

    /** Update the health snapshot shown on the dashboard. */
    public function recordStatus(Device $device, GateResult $result): void
    {
        if ($device->id <= 0) {
            return;
        }
        try {
            $this->db->table('devices')->where('id', '=', $device->id)->update([
                'last_seen_at' => $this->clock->format(),
                'last_status'  => (string) json_encode([
                    'ok' => $result->ok, 'message' => $result->message,
                    'data' => array_slice($result->data, 0, 20),
                    'ms' => round($result->durationMs, 1),
                    'at' => $this->clock->format(),
                ], JSON_UNESCAPED_UNICODE),
                'error_count'  => $result->ok ? 0 : min(999, $device->errorCount + 1),
            ]);
        } catch (Throwable) {
            // diagnostics must never break a gate command
        }
    }

    /** Register the default device from config on first run (installer helper). */
    public function seedFromConfig(): int
    {
        if ($this->db->table('devices')->count() > 0) {
            return 0;
        }

        return $this->save([
            'name'      => $this->config->string('app.short_name', 'Шлагбаум') . ' (основной)',
            'slug'      => $this->config->string('device.default', 'main'),
            'driver'    => $this->config->string('device.driver', 'basip'),
            'base_url'  => $this->config->string('device.base_url'),
            'login'     => $this->config->string('device.login'),
            'password'  => $this->config->string('device.password'),
            'channel'   => $this->config->int('device.lock.channel', 1),
            'is_active' => true,
            'is_default' => true,
        ]);
    }
}
