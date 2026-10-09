<?php

/**
 * TEMPORARY local config used only to exercise the CLI during development.
 * Deleted before packaging — the installer generates the real one.
 */

declare(strict_types=1);

return [
    'app' => [
        'name' => 'ЖК «Ромашка»',
        'env' => 'local',
        'debug' => true,
        'url' => 'http://localhost:8080',
        'timezone' => 'Europe/Moscow',
        'key' => 'base64:ZGV2LW9ubHkta2V5LW5vdC1mb3ItcHJvZHVjdGlvbi11c2U=',
    ],
    'db' => [
        'default' => 'mysql',
        'connections' => [
            'mysql' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'basip_dev',
                'username' => 'basip',
                'password' => 'basip_pass',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
        ],
    ],
    'device' => [
        'driver' => 'dryrun',
        'base_url' => null,
    ],
    'notify' => ['default' => ['log']],
    'update' => ['enabled' => false],
];
