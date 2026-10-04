<?php

declare(strict_types=1);

namespace Crab\Console;

use Crab\Contracts\Console\Input as InputContract;

/**
 * The words after a command's name. `--name=value` is an option with a
 * value, `--name` and each letter of `-abc` are options that are simply
 * present, and everything else, or anything after `--`, is an argument.
 */
final readonly class Input implements InputContract
{
    /**
     * @var list<string>
     */
    private array $arguments;
    /**
     * @var array<string, string|true>
     */
    private array $options;

    /**
     * @param list<string> $tokens
     */
    public function __construct(array $tokens = [])
    {
        $arguments = [];
        $options = [];
        $literal = false;
        foreach ($tokens as $token) {
            if ($literal || $token === '-' || $token === '' || $token[0] !== '-') {
                $arguments[] = $token;
            } elseif ($token === '--') {
                $literal = true;
            } elseif ($token[1] === '-') {
                [$name, $value] = explode('=', substr($token, 2), 2) + [1 => true];
                $options[$name] = $value;
            } else {
                foreach (str_split(substr($token, 1)) as $letter) {
                    $options[$letter] = true;
                }
            }
        }
        $this->arguments = $arguments;
        $this->options = $options;
    }

    public function arguments(): array
    {
        return $this->arguments;
    }

    public function options(): array
    {
        return $this->options;
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    public function option(string $name, string|bool|null $default = null): string|bool|null
    {
        return $this->options[$name] ?? $default;
    }

    public function flag(string $name): bool
    {
        $value = $this->options[$name] ?? false;
        return $value === true || (is_string($value) && !in_array(strtolower($value), ['false', '0', 'no', ''], true));
    }
}
