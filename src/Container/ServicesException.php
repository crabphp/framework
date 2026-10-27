<?php

declare(strict_types=1);

namespace Crab\Container;

use Crab\Contracts\Container\MissingServices;

/**
 * Services the application names that the container cannot make, every
 * one of them at once, found before a worker starts and not by the first
 * request that needs one.
 */
final class ServicesException extends \LogicException implements MissingServices
{
    /**
     * @param list<string> $problems One sentence each, as App::unmade() gives them.
     */
    public function __construct(
        private readonly array $problems,
    ) {
        $heading = count($problems) === 1
            ? 'A service cannot be made:'
            : count($problems) . ' services cannot be made:';
        parent::__construct(
            $heading . "\n"
                . implode("\n", array_map(static fn(string $problem): string => '  ' . $problem, $problems)),
        );
    }

    public function problems(): array
    {
        return $this->problems;
    }
}
