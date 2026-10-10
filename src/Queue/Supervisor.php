<?php

declare(strict_types=1);

namespace Crab\Queue;

use Crab\Contracts\Config\Config;

/**
 * One named set of queue workers: the queues they take from and the
 * limits they work within. `queue:work` runs every supervisor the
 * application declares, each as a group of processes of its own, so that
 * a flood of one kind of job cannot take the workers of another.
 *
 * Supervisors are declared under "queue.supervisors", by name:
 *
 *     'supervisors' => [
 *         'mail'    => ['queues' => ['mail'], 'workers' => 2, 'tries' => 5],
 *         'default' => ['queues' => ['high', 'default'], 'workers' => 4, 'concurrency' => 10],
 *     ],
 *
 * What one leaves out is taken from the queue's own settings:
 * "queue.workers", "queue.concurrency", "queue.tries", "queue.backoff",
 * "queue.retry_after" and "queue.sleep".
 */
final readonly class Supervisor
{
    private const KEYS = ['queues', 'workers', 'concurrency', 'tries', 'backoff', 'retry_after', 'sleep'];

    /**
     * @param list<string> $queues Queue names, the most urgent first.
     * @param int $workers Worker processes.
     * @param int $concurrency Jobs one worker runs at a time.
     * @param int $tries How many times a job is started before it is given up on.
     * @param list<int> $backoff Seconds to wait before the second try, the third, and so on.
     * @param int $retryAfter Seconds after which a job that was taken and never finished is given to another worker.
     * @param float $sleep Seconds a worker waits before looking again when no job was due.
     */
    public function __construct(
        public string $name,
        public array $queues,
        public int $workers = 1,
        public int $concurrency = 1,
        public int $tries = 3,
        public array $backoff = [10, 60],
        public int $retryAfter = 90,
        public float $sleep = 1.0,
    ) {
        if ($queues === []) {
            throw new \LogicException("The queue supervisor \"{$name}\" names no queue to take jobs from.");
        }
        foreach (['workers' => $workers, 'concurrency' => $concurrency, 'tries' => $tries, 'retry_after' => $retryAfter] as $setting => $value) {
            if ($value < 1) {
                throw new \LogicException("The queue supervisor \"{$name}\" has \"{$setting}\" set to {$value}; it must be 1 or more.");
            }
        }
    }

    /**
     * The supervisors the configuration declares, by name; none when the
     * application declares none.
     *
     * @return array<string, self>
     */
    public static function all(Config $config): array
    {
        $declared = $config->get('queue.supervisors');
        if ($declared === null || $declared === []) {
            return [];
        }
        if (!is_array($declared)) {
            throw new \LogicException('"queue.supervisors" must be a list of supervisors by name, each with its "queues".');
        }
        $supervisors = [];
        foreach ($declared as $name => $settings) {
            if (!is_string($name) || $name === '' || !is_array($settings)) {
                throw new \LogicException('"queue.supervisors" must be a list of supervisors by name, each with its "queues": [\'mail\' => [\'queues\' => [\'mail\']]].');
            }
            $unknown = array_diff(array_keys($settings), self::KEYS);
            if ($unknown !== []) {
                throw new \LogicException("The queue supervisor \"{$name}\" has the setting \"" . implode('", "', $unknown) . '", which is not one of ' . implode(', ', self::KEYS) . '.');
            }
            $supervisors[$name] = self::from($config, $name, $settings);
        }
        return $supervisors;
    }

    /**
     * A supervisor from the settings given, with the queue's own for the
     * ones left out.
     *
     * @param array<string, mixed> $settings
     */
    public static function from(Config $config, string $name, array $settings = []): self
    {
        $queues = $settings['queues'] ?? [(string) $config->get('queue.queue')];
        return new self(
            $name,
            array_values(array_map(strval(...), (array) $queues)),
            (int) ($settings['workers'] ?? $config->get('queue.workers')),
            (int) ($settings['concurrency'] ?? $config->get('queue.concurrency')),
            (int) ($settings['tries'] ?? $config->get('queue.tries')),
            array_map(intval(...), array_values((array) ($settings['backoff'] ?? $config->get('queue.backoff')))),
            (int) ($settings['retry_after'] ?? $config->get('queue.retry_after')),
            (float) ($settings['sleep'] ?? $config->get('queue.sleep')),
        );
    }
}
