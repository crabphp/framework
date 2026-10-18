<?php

declare(strict_types=1);

namespace Crab\Engine\EventLoop;

use Crab\Engine\EventLoop;
use Crab\Engine\Suspension;
use Revolt\EventLoop as Revolt;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\Driver\EvDriver;
use Revolt\EventLoop\Driver\EventDriver;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use Revolt\EventLoop\Driver\UvDriver;

/**
 * The engine's loop on a Revolt driver. This folder is the only code that
 * knows Revolt; replacing Revolt means replacing this class.
 */
final class RevoltEventLoop extends EventLoop
{
    /**
     * @param Driver $driver The Revolt driver, for code that must hand it to a library built on Revolt.
     */
    public function __construct(
        public readonly Driver $driver,
    ) {}

    /**
     * A loop on the pure-PHP stream-select backend, or on a native one
     * when its PHP extension is loaded.
     *
     * @param string $backend "select", "ev", "event" or "uv".
     */
    public static function create(string $backend = 'select'): self
    {
        $class = match ($backend) {
            'select' => StreamSelectDriver::class,
            'ev' => EvDriver::class,
            'event' => EventDriver::class,
            'uv' => UvDriver::class,
            default => throw new \InvalidArgumentException('The loop backend is "select", "ev", "event" or "uv".'),
        };
        if ($backend !== 'select' && !$class::isSupported()) {
            throw new \RuntimeException("The {$backend} loop backend needs the {$backend} PHP extension.");
        }
        return new self(new $class());
    }

    public function run(): void
    {
        $this->driver->run();
    }

    public function stop(): void
    {
        $this->driver->stop();
    }

    public function defer(\Closure $callback): string
    {
        return $this->driver->defer($callback);
    }

    public function delay(float $seconds, \Closure $callback): string
    {
        return $this->driver->delay($seconds, $callback);
    }

    public function repeat(float $interval, \Closure $callback): string
    {
        return $this->driver->repeat($interval, $callback);
    }

    public function onReadable(mixed $stream, \Closure $callback): string
    {
        return $this->driver->onReadable($stream, $callback);
    }

    public function onWritable(mixed $stream, \Closure $callback): string
    {
        return $this->driver->onWritable($stream, $callback);
    }

    public function onSignal(int $signal, \Closure $callback): string
    {
        return $this->driver->onSignal($signal, $callback);
    }

    public function enable(string $id): void
    {
        $this->driver->enable($id);
    }

    public function disable(string $id): void
    {
        $this->driver->disable($id);
    }

    public function cancel(string $id): void
    {
        $this->driver->cancel($id);
    }

    public function reference(string $id): void
    {
        $this->driver->reference($id);
    }

    public function unreference(string $id): void
    {
        $this->driver->unreference($id);
    }

    public function isEnabled(string $id): bool
    {
        return $this->driver->isEnabled($id);
    }

    public function getIdentifiers(): array
    {
        return $this->driver->getIdentifiers();
    }

    public function getSuspension(): Suspension
    {
        return new RevoltSuspension($this->driver->getSuspension());
    }

    public function setErrorHandler(?\Closure $handler): void
    {
        $this->driver->setErrorHandler($handler);
    }

    public function activate(): void
    {
        // Libraries built on Revolt find their driver through its static accessor.
        Revolt::setDriver($this->driver);
    }
}
