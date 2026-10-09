<?php

declare(strict_types=1);

namespace BasIp\Core;

use RuntimeException;

/**
 * Thrown when an outbound HTTP call fails at the transport level
 * (DNS, TCP, TLS, timeout) — as opposed to a non-2xx status code, which is
 * returned as a normal {@see HttpResponse}.
 */
final class HttpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $curlErrno = 0,
        public readonly string $url = '',
        public readonly ?HttpResponse $response = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $curlErrno, $previous);
    }

    public function isTimeout(): bool
    {
        return in_array($this->curlErrno, [CURLE_OPERATION_TIMEOUTED, CURLE_OPERATION_TIMEDOUT], true);
    }

    public function isConnectionError(): bool
    {
        return in_array($this->curlErrno, [CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_RESOLVE_PROXY], true);
    }

    public function isTlsError(): bool
    {
        return in_array($this->curlErrno, [CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CIPHER], true);
    }
}
