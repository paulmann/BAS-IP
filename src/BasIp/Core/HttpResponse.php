<?php

declare(strict_types=1);

namespace BasIp\Core;

/**
 * Immutable HTTP response value object.
 */
final class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        public readonly string $url = '',
        public readonly float $durationMs = 0.0,
        public readonly int $attempts = 1,
        public readonly ?string $error = null,
        public readonly int $errno = 0,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300 && $this->error === null;
    }

    public function failed(): bool
    {
        return !$this->ok();
    }

    public function notFound(): bool
    {
        return $this->status === 404;
    }

    public function serverError(): bool
    {
        return $this->status >= 500;
    }

    public function clientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    /** @return array<string, mixed>|null */
    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function header(string $name): ?string
    {
        $name = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return (string) $value;
            }
        }

        return null;
    }

    public function contentType(): string
    {
        return (string) ($this->header('content-type') ?? '');
    }

    public function isJson(): bool
    {
        return str_contains($this->contentType(), 'json');
    }

    public function summary(int $limit = 400): string
    {
        $body = trim($this->body);
        if (mb_strlen($body) > $limit) {
            $body = mb_substr($body, 0, $limit) . '…';
        }

        return sprintf('HTTP %d %s (%.0f ms, %d attempt(s))%s', $this->status, $this->url, $this->durationMs, $this->attempts, $body !== '' ? ' — ' . $body : '');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status'      => $this->status,
            'ok'          => $this->ok(),
            'url'         => $this->url,
            'duration_ms' => round($this->durationMs, 2),
            'attempts'    => $this->attempts,
            'headers'     => $this->headers,
            'body'        => $this->body,
            'error'       => $this->error,
        ];
    }
}
