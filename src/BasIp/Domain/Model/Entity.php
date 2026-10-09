<?php

declare(strict_types=1);

namespace BasIp\Domain\Model;

use BasIp\Support\Str;

/**
 * Base class for the plain data-transfer objects used across the domain.
 *
 * No ORM, no lazy loading, no magic — a row in, a typed object out. Repositories
 * build them, services consume them, views read them.
 */
abstract class Entity implements \JsonSerializable, \ArrayAccess
{
    /** @var array<string, mixed> Raw row, kept for change tracking and exports. */
    protected array $raw = [];

    /** Columns that must be cast to bool when reading a row. @var array<int, string> */
    protected const BOOLS = [];

    /** Columns that must be cast to int. @var array<int, string> */
    protected const INTS = [];

    /** Columns that must be cast to float. @var array<int, string> */
    protected const FLOATS = [];

    /** Columns holding JSON. @var array<int, string> */
    protected const JSON = [];

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): static
    {
        $row = self::castRow($row, static::BOOLS, static::INTS, static::FLOATS, static::JSON);
        /** @var static $entity */
        $entity = new static();
        $entity->raw = $row;
        $entity->fill($row);

        return $entity;
    }

    abstract protected function fill(array $row): void;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->raw[$key] ?? $default;
    }

    public function offsetExists(mixed $offset): bool { return isset($this->raw[$offset]); }
    public function offsetGet(mixed $offset): mixed   { return $this->raw[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { $this->raw[(string) $offset] = $value; }
    public function offsetUnset(mixed $offset): void   { unset($this->raw[$offset]); }

    /**
     * @param array<int, string> $bools
     * @param array<int, string> $ints
     * @param array<int, string> $floats
     * @param array<int, string> $json
     * @return array<string, mixed>
     */
    public static function castRow(array $row, array $bools = [], array $ints = [], array $floats = [], array $json = []): array
    {
        foreach ($bools as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = self::toBool($row[$key]);
            }
        }
        foreach ($ints as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                $row[$key] = (int) $row[$key];
            } elseif (array_key_exists($key, $row)) {
                $row[$key] = null;
            }
        }
        foreach ($floats as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                $row[$key] = round((float) $row[$key], 6);
            }
        }
        foreach ($json as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = self::decodeJson($row[$key]);
            }
        }

        return $row;
    }

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return false;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'y', 't'], true);
    }

    public static function decodeJson(mixed $value, mixed $default = []): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }
        $decoded = json_decode($value, true);

        return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $default : $decoded;
    }

    public static function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }

    protected static function str(mixed $value): string
    {
        return $value === null ? '' : Str::squash((string) $value);
    }
}
