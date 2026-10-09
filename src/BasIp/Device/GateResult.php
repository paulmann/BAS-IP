<?php

declare(strict_types=1);

namespace BasIp\Device;

/**
 * Uniform result of any device interaction.
 *
 * Keeping the raw HTTP status/body around means the gate log can show exactly
 * what the panel answered — invaluable when the barrier "just doesn't open".
 */
final class GateResult
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message = '',
        public readonly array $data = [],
        public readonly ?int $status = null,
        public readonly array $raw = [],
        public readonly float $durationMs = 0.0,
        public readonly ?string $errorCode = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function success(string $message = 'OK', array $data = [], array $raw = [], float $ms = 0.0, ?int $status = null): self
    {
        return new self(true, $message, $data, $status, $raw, $ms);
    }

    /** @param array<string, mixed> $raw */
    public static function failure(string $message, ?string $errorCode = null, array $raw = [], ?int $status = null, float $ms = 0.0): self
    {
        return new self(false, $message, [], $status, $raw, $ms, $errorCode);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok'         => $this->ok,
            'message'    => $this->message,
            'data'       => $this->data,
            'status'     => $this->status,
            'duration_ms' => round($this->durationMs, 1),
            'error_code' => $this->errorCode,
        ];
    }

    public function isTimeout(): bool
    {
        return in_array($this->errorCode, ['timeout', 'connect_timeout', 'curl28', 'curl7'], true);
    }

    public function isAuthError(): bool
    {
        return in_array($this->errorCode, ['unauthorized', 'forbidden', '401', '403'], true);
    }

    public function isOffline(): bool
    {
        return in_array($this->errorCode, ['offline', 'unreachable', 'circuit-open'], true) || $this->isTimeout();
    }
}
