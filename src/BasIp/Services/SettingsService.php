<?php

declare(strict_types=1);

namespace BasIp\Services;

use BasIp\Core\Cache;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Crypto;
use BasIp\Db\Database;
use BasIp\Domain\Model\Entity;

/**
 * Runtime settings stored in the database.
 *
 * Configuration files describe *how the app is deployed*; settings describe
 * *how the building is run* — the current maintenance total, the payment link,
 * message templates, quiet hours. They are editable from the admin UI, survive
 * updates and are cached in memory for the duration of a request.
 *
 * Values marked `is_secret` are encrypted with the application key.
 */
final class SettingsService
{
    private const CACHE_KEY = 'settings.all';

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    /**
     * Defaults that are *not* in config/config.php because they are pure data.
     *
     * @var array<string, array{value: mixed, type: string, group: string, secret?: bool, public?: bool}>
     */
    private const DEFAULTS = [
        'billing.default_total'   => ['value' => null, 'type' => 'float', 'group' => 'billing'],
        'billing.last_total'      => ['value' => null, 'type' => 'float', 'group' => 'billing'],
        'billing.last_period'     => ['value' => null, 'type' => 'string', 'group' => 'billing'],
        'billing.last_allocation' => ['value' => 'equal', 'type' => 'string', 'group' => 'billing'],
        'billing.pay_link'        => ['value' => '', 'type' => 'string', 'group' => 'billing'],
        'billing.invoice_counter' => ['value' => 0, 'type' => 'int', 'group' => 'billing'],
        'billing.charge_counter'  => ['value' => 0, 'type' => 'int', 'group' => 'billing'],
        'billing.message_default' => ['value' => '', 'type' => 'string', 'group' => 'billing'],
        'billing.auto_suspend_debtors' => ['value' => false, 'type' => 'bool', 'group' => 'billing'],
        'billing.suspend_after_days'   => ['value' => 60, 'type' => 'int', 'group' => 'billing'],

        'gate.default_device'     => ['value' => 'main', 'type' => 'string', 'group' => 'gate'],
        'gate.open_cooldown'      => ['value' => 3, 'type' => 'int', 'group' => 'gate'],
        'gate.require_access_flag' => ['value' => true, 'type' => 'bool', 'group' => 'gate'],
        'gate.block_debtors'      => ['value' => false, 'type' => 'bool', 'group' => 'gate'],
        'gate.debt_threshold'     => ['value' => 0, 'type' => 'float', 'group' => 'gate'],
        'gate.quiet_hours_enabled' => ['value' => false, 'type' => 'bool', 'group' => 'gate'],
        'gate.quiet_hours_start'  => ['value' => '23:00', 'type' => 'string', 'group' => 'gate'],
        'gate.quiet_hours_end'    => ['value' => '07:00', 'type' => 'string', 'group' => 'gate'],
        'gate.allow_guest_at_night' => ['value' => true, 'type' => 'bool', 'group' => 'gate'],

        'guest.default_ttl_hours' => ['value' => 12, 'type' => 'int', 'group' => 'guest'],
        'guest.max_per_resident'  => ['value' => 5, 'type' => 'int', 'group' => 'guest'],
        'guest.allow_self_service' => ['value' => true, 'type' => 'bool', 'group' => 'guest'],
        'guest.require_plate'     => ['value' => false, 'type' => 'bool', 'group' => 'guest'],
        'guest.link_base'         => ['value' => '', 'type' => 'string', 'group' => 'guest'],

        'ui.theme'                => ['value' => 'system', 'type' => 'string', 'group' => 'ui', 'public' => true],
        'ui.accent'               => ['value' => 'slate', 'type' => 'string', 'group' => 'ui', 'public' => true],
        'ui.density'              => ['value' => 'comfortable', 'type' => 'string', 'group' => 'ui', 'public' => true],
        'ui.dashboard_refresh'    => ['value' => 30, 'type' => 'int', 'group' => 'ui', 'public' => true],
        'ui.camera_enabled'       => ['value' => true, 'type' => 'bool', 'group' => 'ui', 'public' => true],
        'ui.organisation_name'    => ['value' => '', 'type' => 'string', 'group' => 'ui', 'public' => true],
        'ui.support_phone'        => ['value' => '', 'type' => 'string', 'group' => 'ui', 'public' => true],
        'ui.support_email'        => ['value' => '', 'type' => 'string', 'group' => 'ui', 'public' => true],

        'notify.enabled'          => ['value' => true, 'type' => 'bool', 'group' => 'notify'],
        'notify.channels_default' => ['value' => ['log'], 'type' => 'json', 'group' => 'notify'],
        'notify.telegram_bot_token' => ['value' => '', 'type' => 'string', 'group' => 'notify', 'secret' => true],
        'notify.telegram_chat_id' => ['value' => '', 'type' => 'string', 'group' => 'notify'],
        'notify.smtp_password'    => ['value' => '', 'type' => 'string', 'group' => 'notify', 'secret' => true],

        'update.last_check'       => ['value' => null, 'type' => 'string', 'group' => 'system'],
        'update.latest_version'   => ['value' => null, 'type' => 'string', 'group' => 'system'],
        'update.latest_notes'     => ['value' => null, 'type' => 'string', 'group' => 'system'],
        'update.available'        => ['value' => false, 'type' => 'bool', 'group' => 'system'],
        'update.auto'             => ['value' => false, 'type' => 'bool', 'group' => 'system'],

        'system.installed_at'     => ['value' => null, 'type' => 'string', 'group' => 'system'],
        'system.installed_version' => ['value' => null, 'type' => 'string', 'group' => 'system'],
        'system.maintenance_mode' => ['value' => false, 'type' => 'bool', 'group' => 'system'],
        'system.readonly_mode'    => ['value' => false, 'type' => 'bool', 'group' => 'system'],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Cache $cache,
        private readonly Crypto $crypto,
    ) {
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $values = [];
        foreach (self::DEFAULTS as $key => $definition) {
            $values[$key] = $definition['value'];
        }
        foreach ((array) $this->config->get('settings.defaults', []) as $key => $value) {
            $values[(string) $key] = $value;
        }

        try {
            foreach ($this->db->table('settings')->get() as $row) {
                $key = (string) $row['key'];
                $values[$key] = $this->cast($row['value'] ?? null, (string) ($row['type'] ?? 'string'), !empty($row['is_secret']));
            }
        } catch (\Throwable) {
            // Table missing (fresh install) — fall back to defaults.
        }

        return $this->memo = $values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        if (!array_key_exists($key, $all) || $all[$key] === null || $all[$key] === '') {
            $configDefault = $this->configDefault($key);

            return $configDefault ?? ($all[$key] ?? $default);
        }

        return $all[$key];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->get($key, $default);

        return is_scalar($v) ? (string) $v : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key, $default);

        return is_numeric($v) ? (int) $v : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $v = $this->get($key, $default);

        return is_numeric($v) ? (float) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return Entity::toBool($this->get($key, $default));
    }

    /** @return array<int|string, mixed> */
    public function json(string $key, array $default = []): array
    {
        $v = $this->get($key, $default);

        return is_array($v) ? $v : $default;
    }

    public function set(string $key, mixed $value, ?string $actor = null): void
    {
        $definition = self::DEFAULTS[$key] ?? ['type' => $this->guessType($value), 'group' => 'general'];
        $type   = (string) ($definition['type'] ?? 'string');
        $group  = (string) ($definition['group'] ?? 'general');
        $secret = (bool) ($definition['secret'] ?? false);

        $stored = $this->serialize($value, $type, $secret);

        $this->db->table('settings')->upsert([
            'key'        => $key,
            'value'      => $stored,
            'type'       => $type,
            'group'      => $group,
            'is_secret'  => $secret ? 1 : 0,
            'is_public'  => !empty($definition['public']) ? 1 : 0,
            'updated_by' => $actor,
            'updated_at' => $this->clock->format(),
        ], ['key']);

        $this->memo = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /** @param array<string, mixed> $values */
    public function setMany(array $values, ?string $actor = null): int
    {
        $count = 0;
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $actor);
            $count++;
        }

        return $count;
    }

    public function forget(string $key): void
    {
        $this->db->table('settings')->where('key', '=', $key)->delete();
        $this->memo = null;
    }

    /** @return array<int, string> */
    public function groups(): array
    {
        $groups = [];
        foreach (self::DEFAULTS as $definition) {
            $groups[(string) $definition['group']] = true;
        }
        foreach ($this->db->table('settings')->select('group')->distinct()->get() as $row) {
            $groups[(string) ($row['group'] ?? 'general')] = true;
        }
        $out = array_keys($groups);
        sort($out);

        return $out;
    }

    /** @return array<string, mixed> */
    public function group(string $group): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $definition) {
            if (($definition['group'] ?? '') === $group) {
                $out[$key] = $this->get($key);
            }
        }
        foreach ($this->db->table('settings')->where('group', '=', $group)->get() as $row) {
            $out[(string) $row['key']] = $this->cast($row['value'] ?? null, (string) ($row['type'] ?? 'string'), !empty($row['is_secret']));
        }

        return $out;
    }

    /** Values safe to expose to the browser (public settings + resolved config). */
    public function publicPayload(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $definition) {
            if (!empty($definition['public'])) {
                $out[$key] = $this->get($key);
            }
        }
        $out['billing.currency_symbol'] = $this->config->string('billing.currency_symbol', '₽');
        $out['billing.precision'] = $this->config->int('billing.precision', 2);
        $out['app.name'] = $this->config->string('app.name', 'BAS-IP');
        $out['app.version'] = $this->config->string('app.version', '2.0.0');
        $out['ui.language'] = $this->config->string('ui.language', 'ru');

        return $out;
    }

    /** @return array<string, array{type: string, group: string, secret: bool}> */
    public function definitions(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $definition) {
            $out[$key] = [
                'type'   => (string) $definition['type'],
                'group'  => (string) $definition['group'],
                'secret' => (bool) ($definition['secret'] ?? false),
            ];
        }

        return $out;
    }

    public function flushCache(): void
    {
        $this->memo = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /* ─────────────────────────── internals ─────────────────────────── */

    private function configDefault(string $key): mixed
    {
        // billing.due_days → config billing.due_days, guest.default_ttl_hours → config guest.default_ttl_hours …
        $mapped = [
            'billing.pay_link'        => 'billing.pay_link',
            'billing.default_total'   => 'billing.default_total',
            'guest.default_ttl_hours' => 'guest.default_ttl_hours',
            'guest.max_per_resident'  => 'guest.max_active_per_resident',
            'guest.allow_self_service' => 'guest.allow_self_service',
            'guest.require_plate'     => 'guest.require_plate',
            'gate.open_cooldown'      => 'device.lock.cooldown_seconds',
            'ui.camera_enabled'       => 'ui.show_camera',
            'ui.dashboard_refresh'    => 'ui.events_refresh_seconds',
        ];

        return isset($mapped[$key]) ? $this->config->get($mapped[$key]) : null;
    }

    private function cast(?string $raw, string $type, bool $secret): mixed
    {
        if ($raw === null) {
            return null;
        }
        if ($secret && $this->crypto->available() && $this->crypto->isEncrypted($raw)) {
            $raw = $this->crypto->decrypt($raw, 'setting') ?? '';
        }

        return match ($type) {
            'int'   => (int) $raw,
            'float' => (float) $raw,
            'bool'  => Entity::toBool($raw),
            'json'  => Entity::decodeJson($raw, []),
            default => $raw,
        };
    }

    private function serialize(mixed $value, string $type, bool $secret): ?string
    {
        if ($value === null) {
            return null;
        }
        $stored = match ($type) {
            'bool'  => Entity::toBool($value) ? '1' : '0',
            'json'  => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'int'   => (string) (int) $value,
            'float' => (string) (float) $value,
            default => (string) $value,
        };
        if ($secret && $stored !== '' && $this->crypto->available()) {
            $stored = $this->crypto->encrypt($stored, 'setting');
        }

        return $stored;
    }

    private function guessType(mixed $value): string
    {
        return match (true) {
            is_bool($value)  => 'bool',
            is_int($value)   => 'int',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default          => 'string',
        };
    }
}
