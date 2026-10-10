<?php

declare(strict_types=1);

namespace Crab\Schedule;

use Crab\Contracts\Console\Console;
use Crab\Contracts\Queue\Queue;
use Crab\Contracts\Schedule\Schedule as ScheduleContract;
use Psr\Container\ContainerInterface;

/**
 * The list of an application's scheduled tasks. It only holds them;
 * Scheduler runs them. A command or a job is looked for when its task
 * runs, in the container the scheduler was given.
 */
final class Schedule implements ScheduleContract
{
    /**
     * @var list<Task>
     */
    private array $tasks = [];

    public function call(\Closure|string $task): Task
    {
        if ($task instanceof \Closure) {
            $function = new \ReflectionFunction($task);
            $name = 'closure at ' . basename((string) $function->getFileName()) . ':' . $function->getStartLine();
            return $this->tasks[] = new Task($name, static function () use ($task): void {
                $task();
            });
        }
        if (!class_exists($task) || !method_exists($task, 'handle')) {
            throw new \LogicException("{$task} was scheduled, and it is not a class with a handle() method. Give a closure, or a class whose constructor takes services and whose handle() does the work.");
        }
        return $this->tasks[] = new Task($task, static function (?ContainerInterface $container) use ($task): void {
            $object = $container?->has($task) ? $container->get($task) : new $task();
            $object->handle();
        });
    }

    public function command(string $name, string ...$arguments): Task
    {
        $words = [$name, ...array_values($arguments)];
        return $this->tasks[] = new Task('command ' . implode(' ', $words), static function (?ContainerInterface $container) use ($name, $words): void {
            if ($container === null || !$container->has(Console::class)) {
                throw new \LogicException("The scheduled command \"{$name}\" needs the application's console, and the scheduler was given no container that has one.");
            }
            $status = $container->get(Console::class)->run($words);
            if ($status !== 0) {
                throw new \RuntimeException("The scheduled command \"{$name}\" ended with status {$status}.");
            }
        });
    }

    public function job(string $job, mixed ...$data): Task
    {
        if (!class_exists($job) || !method_exists($job, 'handle')) {
            throw new \LogicException("{$job} was scheduled as a job, and it is not a class with a handle() method.");
        }
        return $this->tasks[] = new Task("job {$job}", static function (?ContainerInterface $container) use ($job, $data): void {
            if ($container === null || !$container->has(Queue::class)) {
                throw new \LogicException("The scheduled job {$job} is pushed onto the queue, and there is none: register the queue provider, QueueProvider, or schedule the work with call().");
            }
            $container->get(Queue::class)->push($job, ...$data);
        });
    }

    /**
     * @return list<Task>
     */
    public function tasks(): array
    {
        return $this->tasks;
    }
}
