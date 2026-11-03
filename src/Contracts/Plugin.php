<?php

declare(strict_types=1);

namespace Crab\Contracts;

use Crab\Contracts\Container\Container;

/**
 * How a package or a part of an application adds its services. Bindings are
 * made on first use, after every plugin has registered, so one plugin's
 * services may depend on another's whatever the order of registration.
 */
interface Plugin
{
    public function register(Container $container): void;
}
