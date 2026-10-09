<?php

/**
 * Legacy v1.x command executor (`bas_ip.php?cmd=lock_open`).
 *
 * Retained for backwards compatibility and routed through the modern,
 * authenticated API. New integrations should use `/api/v1/gate/*`.
 *
 * @deprecated use /api/v1/gate/open
 */

declare(strict_types=1);

require __DIR__ . '/index.php';
