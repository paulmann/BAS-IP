<?php
/**
 * HTTP 429 error page.
 *
 * @var string $message
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$status = 429;
echo $this->include('errors/layout', [
    'status'    => 429,
    'message'   => $message ?? null,
    'requestId' => $requestId ?? null,
    'exception' => $exception ?? null,
]);
