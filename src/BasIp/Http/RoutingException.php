<?php

declare(strict_types=1);

namespace BasIp\Http;

use RuntimeException;
use Throwable;

/**
 * HTTP-level control-flow exception (404, 403, 405, 429, 503…).
 * The kernel turns it into a proper status page / JSON error.
 */
final class RoutingException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        string $message,
        public readonly int $statusCode = 404,
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function notFound(string $message = 'Страница не найдена'): self
    {
        return new self($message, 404);
    }

    public static function forbidden(string $message = 'Доступ запрещён'): self
    {
        return new self($message, 403);
    }

    public static function unauthorized(string $message = 'Требуется вход'): self
    {
        return new self($message, 401);
    }

    public static function tooManyRequests(string $message = 'Слишком много запросов, попробуйте позже', int $retryAfter = 60): self
    {
        return new self($message, 429, ['Retry-After' => (string) $retryAfter]);
    }

    public static function unavailable(string $message = 'Сервис временно недоступен'): self
    {
        return new self($message, 503);
    }

    public static function badRequest(string $message = 'Некорректный запрос'): self
    {
        return new self($message, 400);
    }
}
