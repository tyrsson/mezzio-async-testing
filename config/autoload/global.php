<?php

declare(strict_types=1);

use Axleus\Log\ConfigProvider as LogConfigProvider;

return [
    'view_manager' => [
        'base_path' => '/',
    ],
    LogConfigProvider::class => [
        'log_errors' => true,
    ],
];
