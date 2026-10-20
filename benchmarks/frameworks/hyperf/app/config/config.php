<?php

use Hyperf\Contract\StdoutLoggerInterface;
use Psr\Log\LogLevel;

use function Hyperf\Support\env;

return [
    'app_name' => 'benchmark',
    'app_env' => 'prod',
    'scan_cacheable' => env('SCAN_CACHEABLE', true),
    StdoutLoggerInterface::class => ['log_level' => [
        LogLevel::ERROR,
        LogLevel::CRITICAL,
        LogLevel::ALERT,
        LogLevel::EMERGENCY,
    ]],
];
