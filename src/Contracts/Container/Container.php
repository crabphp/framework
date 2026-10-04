<?php

declare(strict_types=1);

namespace Crab\Contracts\Container;

use Psr\Container\ContainerInterface;

/**
 * A PSR-11 container that can also be told how its entries are made.
 * Code that only reads entries should ask for ContainerInterface.
 */
interface Container extends ContainerInterface
{
    /**
     * Makes a new value every time the entry is asked for. The concrete is
     * a closure receiving the container, the name of another entry or class,
     * or null to construct the class the entry is named after.
     */
    public function bind(string $id, \Closure|string|null $concrete = null): void;

    /**
     * Makes the value on first use and keeps it; see bind() for $concrete.
     * Naming another entry or class makes this entry that one's kept value.
     */
    public function singleton(string $id, \Closure|string|null $concrete = null): void;

    /**
     * Stores a value that already exists.
     */
    public function instance(string $id, mixed $value): void;
}
