<?php

declare(strict_types=1);

use Crab\App;

if (!function_exists('config')) {
    /**
     * A value of the current application's configuration by dotted key, or
     * the configuration contract itself when no key is given.
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        $config = App::current()->config;
        return $key === null ? $config : $config->get($key, $default);
    }
}

if (!function_exists('storage_path')) {
    /**
     * A path in the application's storage directory, where it writes:
     * storage_path('database.sqlite'). Configuration files call it while
     * the application is being built.
     */
    function storage_path(string $path = ''): string
    {
        return App::storage($path);
    }
}
