<?php
/**
 * HTTP 419 error page.
 *
 * @var string $message
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$status = 419;
echo $this->include('errors/layout', [
    'status'    => 419,
    'message'   => $message ?? null,
    'requestId' => $requestId ?? null,
    'exception' => $exception ?? null,
]);
