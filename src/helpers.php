<?php

declare(strict_types=1);

use Crab\App;

if (!function_exists('config')) {
    /**
     * A value of the current application's configuration by dotted key, or
     * the configuration itself when no key is given.
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        $config = App::current()->config;
        return $key === null ? $config : $config->get($key, $default);
    }
}
