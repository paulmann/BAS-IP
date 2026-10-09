<?php
/**
 * HTTP 500 error page.
 *
 * @var string $message
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$status = 500;
echo $this->include('errors/layout', [
    'status'    => 500,
    'message'   => $message ?? null,
    'requestId' => $requestId ?? null,
    'exception' => $exception ?? null,
]);
