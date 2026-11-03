<?php

declare(strict_types=1);

namespace Crab\Contracts\Console;

/**
 * The words given after a command's name.
 */
interface Input
{
    /**
     * @return list<string> The positional arguments.
     */
    public function arguments(): array;

    /**
     * @return array<string, string|true> Options by name; true when given without a value.
     */
    public function options(): array;

    public function argument(int $index, ?string $default = null): ?string;

    /**
     * The option's value, true when it was given without one, or the default.
     */
    public function option(string $name, string|bool|null $default = null): string|bool|null;

    /**
     * The value of an option that takes one, or null when it was not
     * given. Given without a value, as `--name` alone, it is refused with
     * InvalidArgumentException naming the option and how to give it:
     * `--name needs a value; use --name=$example.`
     *
     * @throws \InvalidArgumentException
     */
    public function value(string $name, string $example = 'value'): ?string;

    /**
     * Whether an option is switched on: present and not "false", "0" or "no".
     */
    public function flag(string $name): bool;
}
