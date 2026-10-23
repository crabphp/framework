<?php

declare(strict_types=1);

namespace Crab\Config;

use Crab\Contracts\Config\InvalidSettings;

/**
 * Settings that do not fit what they take, every one of them at once, so
 * that they are fixed in one go and not found one start at a time.
 */
final class SettingsException extends \InvalidArgumentException implements InvalidSettings
{
    /**
     * @param list<string> $problems One sentence each, naming the setting and what it takes.
     */
    public function __construct(
        private readonly array $problems,
    ) {
        parent::__construct(self::say($problems));
    }

    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * @param list<string> $problems
     */
    public static function say(array $problems): string
    {
        $heading = count($problems) === 1 ? 'A setting does not fit:' : count($problems) . ' settings do not fit:';
        return $heading
        . "\n"
        . implode("\n", array_map(static fn(string $problem): string => '  ' . $problem, $problems));
    }
}
