<?php
/**
 * HTTP 405 error page.
 *
 * @var string $message
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$status = 405;
echo $this->include('errors/layout', [
    'status'    => 405,
    'message'   => $message ?? null,
    'requestId' => $requestId ?? null,
    'exception' => $exception ?? null,
]);
