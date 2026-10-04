<?php

declare(strict_types=1);

use Crab\Config\Env;

if (!function_exists('env')) {
    /**
     * An environment variable, or one from the loaded .env file; see Env::get().
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}
