<?php

declare(strict_types=1);

namespace Crab\Storage;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Storage\Disk;
use Crab\Contracts\Storage\Drivers;
use Crab\Contracts\Storage\Storage;

/**
 * The disks of the "storage" configuration section, each made by its
 * driver when first asked for and then kept for the worker's life.
 */
final class StorageManager implements Storage, Drivers
{
    /**
     * @var array<string, array{list<string>, \Closure(array<string, mixed>, string): Disk}>
     */
    private array $drivers = [];

    /**
     * @var array<string, Disk>
     */
    private array $disks = [];

    /**
     * Read when a disk is first asked for, so that a package's provider may
     * add its driver before the settings are final.
     */
    public function __construct(
        private readonly Config $config,
    ) {}

    public function add(string $driver, array $settings, \Closure $make): void
    {
        $this->drivers[$driver] = [$settings, $make];
    }

    public function disk(?string $name = null): Disk
    {
        $name ??= (string) $this->config->get('storage.default');
        if (isset($this->disks[$name])) {
            return $this->disks[$name];
        }
        $this->config->check('storage');
        $disks = $this->disks();
        $settings = $disks[$name] ?? throw new \LogicException(
            "There is no disk named \"{$name}\" in storage.disks; the disks are "
            . ($disks === [] ? 'none' : '"' . implode('", "', array_keys($disks)) . '"')
            . '.',
        );
        $driver = (string) ($settings['driver'] ?? '');
        [$known, $make] = $this->drivers[$driver] ?? throw new \LogicException($this->unknown($name, $driver));
        // Null, as env() gives for a variable that is not set, is the same as leaving the key out.
        $given = array_keys(array_filter($settings, static fn(mixed $value): bool => $value !== null));
        $unknown = array_diff($given, ['driver', ...$known]);
        if ($unknown !== []) {
            throw new \LogicException(
                "The disk \"{$name}\" has settings its driver does not know: \""
                . implode('", "', $unknown)
                . '"; it knows '
                . ($known === [] ? 'none' : '"' . implode('", "', $known) . '"')
                . '.',
            );
        }
        return $this->disks[$name] = $make($settings, $name);
    }

    /**
     * Says every disk whose driver no provider added, and a default that
     * names no disk, without making any disk.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        $disks = $this->disks();
        foreach ($disks as $name => $settings) {
            $driver = (string) ($settings['driver'] ?? '');
            if (!isset($this->drivers[$driver])) {
                $problems[] = $this->unknown((string) $name, $driver);
            }
        }
        $default = (string) $this->config->get('storage.default');
        if (!isset($disks[$default])) {
            $problems[] = "storage.default is \"{$default}\", which is not one of storage.disks.";
        }
        return $problems;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function disks(): array
    {
        return (array) $this->config->get('storage.disks');
    }

    private function unknown(string $name, string $driver): string
    {
        return (
            "The disk \"{$name}\" has the driver \"{$driver}\", and the drivers are \""
            . implode('", "', array_keys($this->drivers))
            . '". For "s3", install crabphp/s3 and register its S3Provider after the framework\'s providers.'
        );
    }
}
