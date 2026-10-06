<?php

declare(strict_types=1);

namespace Crab\Console;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Console as ConsoleContract;
use Crab\Contracts\Console\Input as InputContract;
use Crab\Contracts\Console\Output as OutputContract;
use Psr\Container\ContainerInterface;

/**
 * Runs commands by name. A command is a Command, a closure taking the input
 * and output, or a class name; a class is made only when its command runs,
 * by the container when there is one. `<command> --help`, or `-h`, describes
 * a command without running it, as `help <command>` does.
 */
final class Console implements ConsoleContract
{
    /**
     * @var array<string, Command|\Closure|string>
     */
    private array $commands = [];
    /**
     * Descriptions given at registration, used for closures.
     *
     * @var array<string, string>
     */
    private array $descriptions = [];

    /**
     * The title, when given, heads the command list.
     */
    public function __construct(private readonly ?ContainerInterface $container = null, private readonly string $title = '')
    {
    }

    /**
     * A later command replaces an earlier one of the same name.
     */
    public function add(string $name, Command|\Closure|string $command, string $description = ''): static
    {
        if (preg_match('/^[a-z0-9][a-z0-9:_-]*$/D', $name) !== 1 || in_array($name, ['list', 'help'], true)) {
            throw new \InvalidArgumentException("\"{$name}\" cannot be used as a command name.");
        }
        $this->commands[$name] = $command;
        $this->descriptions[$name] = $description;
        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * Without an output, writes to standard output and standard error.
     */
    public function run(array $arguments, ?OutputContract $output = null): int
    {
        $output ??= new Output();
        $name = array_shift($arguments) ?? 'list';
        try {
            if (in_array($name, ['list', '--help', '-h'], true)) {
                return $this->list($output);
            }
            if ($name === 'help') {
                $name = $arguments[0] ?? '';
                if ($name === '') {
                    return $this->list($output);
                }
                if (isset($this->commands[$name])) {
                    $output->line($this->describe($name));
                    return 0;
                }
            }
            if (!isset($this->commands[$name])) {
                $output->error("Unknown command \"{$name}\". Run `list` to see the commands.");
                return 1;
            }
            if (self::asksForHelp($arguments)) {
                $output->line($this->describe($name));
                return 0;
            }
            return $this->resolve($name)->handle(new Input($arguments), $output);
        } catch (\Throwable $error) {
            $output->error($error::class . ': ' . $error->getMessage()
                . ' (' . $error->getFile() . ':' . $error->getLine() . ')');
            return 1;
        }
    }

    /**
     * Whether --help or -h is among a command's words, before any "--"
     * that makes the rest plain arguments. Asking a command for help
     * describes it and does not run it.
     *
     * @param list<string> $arguments
     */
    private static function asksForHelp(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if ($argument === '--') {
                return false;
            }
            if ($argument === '--help' || $argument === '-h') {
                return true;
            }
        }
        return false;
    }

    private function list(OutputContract $output): int
    {
        $names = array_keys($this->commands);
        sort($names);
        $rows = [];
        foreach ($names as $name) {
            try {
                $description = strtok($this->describe((string) $name), "\n") ?: '';
            } catch (\Throwable $error) {
                $description = '(unavailable: ' . $error->getMessage() . ')';
            }
            $rows[] = [(string) $name, $description];
        }
        if ($this->title !== '') {
            $output->line($this->title);
            $output->line();
        }
        $output->line('Commands:');
        $output->table([...$rows, ['help <command>', 'Describe a command; so does <command> --help'], ['list', 'List the commands']], '  ');
        return 0;
    }

    private function describe(string $name): string
    {
        return $this->descriptions[$name] !== '' || $this->commands[$name] instanceof \Closure
            ? $this->descriptions[$name]
            : $this->resolve($name)->description();
    }

    private function resolve(string $name): Command
    {
        $command = $this->commands[$name];
        if ($command instanceof \Closure) {
            $description = $this->descriptions[$name];
            return new class($command, $description) implements Command {
                public function __construct(private readonly \Closure $closure, private readonly string $description)
                {
                }

                public function description(): string
                {
                    return $this->description;
                }

                public function handle(InputContract $input, OutputContract $output): int
                {
                    return (int) ($this->closure)($input, $output);
                }
            };
        }
        if (is_string($command)) {
            $class = $command;
            $command = $this->container?->has($class) ? $this->container->get($class) : (class_exists($class) ? new $class() : null);
            if (!$command instanceof Command) {
                throw new \LogicException("Command \"{$name}\" is {$class}, which must implement " . Command::class . '.');
            }
            $this->commands[$name] = $command;
        }
        return $command;
    }
}
