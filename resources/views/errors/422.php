<?php
/**
 * HTTP 422 error page.
 *
 * @var string $message
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$status = 422;
echo $this->include('errors/layout', [
    'status'    => 422,
    'message'   => $message ?? null,
    'requestId' => $requestId ?? null,
    'exception' => $exception ?? null,
]);
