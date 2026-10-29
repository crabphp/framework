<?php

declare(strict_types=1);

namespace Crab\Actors;

use Crab\Contracts\Actors\Actors as ActorsContract;
use Crab\Contracts\Actors\Inbox;
use Crab\Contracts\Actors\NoReply;
use Crab\Contracts\Broadcast\Carrier;
use Crab\Contracts\Container\Container;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\FailureReporter;
use Psr\Log\LoggerInterface;

/**
 * Runs actors and carries messages to them.
 *
 * Without a carrier, an actor runs in the process that sends to it. With
 * one, such as Redis's, each key belongs to one of the "actors" server's
 * processes, by a hash of the actor's class and key, and messages and
 * replies travel over the carrier to it and back.
 */
final class Actors implements ActorsContract
{
    private const CHANNEL = 'crab:actors:';

    /**
     * For each actor class already looked at: why it cannot be one, or null.
     *
     * @var array<class-string, string|null>
     */
    private static array $checked = [];

    /**
     * The inboxes of the actors running here, by class and key.
     *
     * @var array<string, Mailbox>
     */
    private array $boxes = [];

    /**
     * @var array<int, Task>
     */
    private array $running = [];

    /**
     * Asks waiting for their reply, by id.
     *
     * @var array<string, \Closure(bool, mixed): void>
     */
    private array $waiting = [];

    /**
     * The shares of the keys this process serves, once serving.
     *
     * @var array<int, true>
     */
    private array $shards = [];

    /**
     * The channel this process's replies come back on, once subscribed.
     */
    private ?string $replies = null;

    private ?Task $subscribing = null;
    private bool $stopping = false;

    /**
     * @param Carrier|null $carrier What carries messages between processes; null to run every actor in the process that sends to it.
     * @param int $workers How many processes the "actors" server has: the keys are shared out among them.
     * @param float $idle Seconds an actor's inbox may be empty before it ends.
     * @param int $maxInbox The most messages an actor's inbox holds; more are refused.
     */
    public function __construct(
        private readonly ?Container $container = null,
        private readonly ?Carrier $carrier = null,
        private readonly int $workers = 1,
        private readonly float $idle = 60.0,
        private readonly int $maxInbox = 1000,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if ($workers < 1 || $idle <= 0 || $maxInbox < 1) {
            throw new \InvalidArgumentException(
                'Actors need at least one worker, an idle time and room for a message.',
            );
        }
    }

    public function tell(string $actor, string $key, mixed $message): void
    {
        $this->check($actor, $key);
        $this->route($actor, $key, self::plain($message), null);
    }

    public function ask(
        string $actor,
        string $key,
        mixed $message,
        float $timeout = 30.0,
        ?Cancellation $cancellation = null,
    ): mixed {
        $this->check($actor, $key);
        $data = self::plain($message);
        $cancellation?->throwIfCancelled();
        if ($this->remote($actor, $key)) {
            $this->listen();
        }
        $loop = EventLoop::current();
        $suspension = $loop->getSuspension();
        $id = bin2hex(random_bytes(8));
        // Settled once, by the reply, the timeout or the cancellation; an outcome that comes before the wait is kept for it.
        $settled = false;
        $waiting = false;
        $early = null;
        $settle = static function (array $outcome) use (&$settled, &$waiting, &$early, $suspension): void {
            if ($settled) {
                return;
            }
            $settled = true;
            if ($waiting) {
                $suspension->resume($outcome);
            } else {
                $early = $outcome;
            }
        };
        $this->waiting[$id] = static function (bool $replied, mixed $value) use ($settle, $actor, $key): void {
            $settle(
                $replied ? [true, $value] : [false, new NoReply("{$actor} for \"{$key}\" did not reply: {$value}")],
            );
        };
        $timer = $loop->delay($timeout, static function () use ($settle, $actor, $key, $timeout): void {
            $settle([false, new NoReply("{$actor} for \"{$key}\" did not reply within {$timeout} seconds.")]);
        });
        $unsubscribe = $cancellation?->subscribe(static fn(CancelledException $error) => $settle([false, $error]));
        try {
            $this->route($actor, $key, $data, $id);
            if ($early === null) {
                $waiting = true;
            }
            [$replied, $value] = $early ?? $suspension->suspend();
        } finally {
            $settled = true;
            unset($this->waiting[$id]);
            $loop->cancel($timer);
            $unsubscribe?->__invoke();
        }
        if (!$replied) {
            throw $value;
        }
        return $value;
    }

    /**
     * Runs the actors whose keys belong to these shares of the "actors"
     * server, one for each of its processes, taking their messages from
     * the carrier, until $cancellation is cancelled; then ends them and
     * waits for them to return.
     *
     * @param list<int> $shards
     */
    public function serve(array $shards, Cancellation $cancellation): void
    {
        if ($this->carrier === null) {
            throw new \LogicException(
                'With the local broadcast carrier, an actor runs in the process that sends to it, and the "actors" server has nothing to do. Set broadcast.carrier to redis to run actors in a server of their own.',
            );
        }
        foreach ($shards as $shard) {
            if ($shard < 0 || $shard >= $this->workers) {
                throw new \InvalidArgumentException(
                    "The \"actors\" server has {$this->workers} processes; there is no process {$shard}.",
                );
            }
        }
        $carrier = $this->carrier;
        try {
            foreach ($shards as $shard) {
                $carrier->subscribe(
                    self::CHANNEL . $shard,
                    fn(string $channel, string $text) => $this->arrive($text),
                    fn() => $this->report(
                        'warning',
                        "Actor messages sent to process {$shard} may have been lost while its connection was down.",
                    ),
                );
                $this->shards[$shard] = true;
            }
            if (!$cancellation->isCancelled()) {
                $suspension = EventLoop::current()->getSuspension();
                $unsubscribe = $cancellation->subscribe(static fn() => $suspension->resume());
                try {
                    $suspension->suspend();
                } finally {
                    $unsubscribe();
                }
            }
        } finally {
            foreach (array_keys($this->shards) as $shard) {
                $carrier->unsubscribe(self::CHANNEL . $shard);
            }
            $this->shards = [];
            $this->stop();
            foreach ($this->running as $task) {
                try {
                    $task->await();
                } catch (\Throwable) {
                    // Reported where the actor ran.
                }
            }
        }
    }

    /**
     * Ends every actor running here, cancelling what they wait on.
     * Messages they had not taken are dropped, and asks refused.
     */
    public function stop(): void
    {
        $this->stopping = true;
        foreach ($this->boxes as $box) {
            $box->end('stopping');
        }
    }

    /**
     * How many actors run in this process.
     */
    public function count(): int
    {
        return count($this->boxes);
    }

    /**
     * @param (\Closure(bool, mixed): void)|null $answer
     */
    private function deliver(string $actor, string $key, mixed $data, ?\Closure $answer): void
    {
        if ($this->stopping) {
            $answer?->__invoke(false, 'the process it runs in is stopping.');
            return;
        }
        $box = $this->boxes[$actor . "\0" . $key] ?? $this->start($actor, $key);
        if ($box->size() >= $this->maxInbox) {
            $this->report(
                'warning',
                "{$actor} for \"{$key}\" has {$this->maxInbox} messages waiting; one more was dropped.",
            );
            $answer?->__invoke(false, 'its inbox is full.');
            return;
        }
        $box->put($data, $answer);
    }

    private function start(string $actor, string $key): Mailbox
    {
        $name = $actor . "\0" . $key;
        $box = null;
        $box = new Mailbox($key, EventLoop::current(), $this->idle, function () use ($name, &$box): void {
            if (($this->boxes[$name] ?? null) === $box) {
                unset($this->boxes[$name]);
            }
        });
        $this->boxes[$name] = $box;
        $task = Task::spawn(EventLoop::current(), fn() => $this->run($actor, $key, $box));
        $this->running[spl_object_id($task)] = $task;
        $task->onComplete(function (Task $task): void {
            unset($this->running[spl_object_id($task)]);
        });
        return $box;
    }

    private function run(string $actor, string $key, Mailbox $box): void
    {
        $why = 'it returned without replying.';
        try {
            $instance = $this->container?->has($actor) ? $this->container->get($actor) : new $actor();
            $instance->handle($box, $key);
        } catch (\Throwable $error) {
            if ($this->stopping && $error instanceof CancelledException) {
                $why = 'the process it runs in is stopping.';
            } else {
                $why = 'it failed: ' . $error->getMessage();
                $this->report('error', "{$actor} for \"{$key}\" failed: " . $error->getMessage(), $error);
            }
        } finally {
            $box->end();
            $box->refuseOpen($why);
            $left = $box->left();
            // Messages it never took go to its next run, unless it took none, which would only fail again.
            $again = !$this->stopping && $box->taken() > 0;
            foreach ($left as [$data, $answer]) {
                if ($again) {
                    $this->deliver($actor, $key, $data, $answer);
                } else {
                    $answer?->__invoke(false, $this->stopping ? 'the process it runs in is stopping.' : $why);
                }
            }
        }
    }

    private function route(string $actor, string $key, mixed $data, ?string $id): void
    {
        $answer = $id === null
            ? null
            : function (bool $replied, mixed $value) use ($id): void {
                ($this->waiting[$id] ?? null)?->__invoke($replied, $value);
            };
        if (!$this->remote($actor, $key)) {
            $this->deliver($actor, $key, $data, $answer);
            return;
        }
        $this->carrier?->publish([self::CHANNEL . $this->shardOf($actor, $key)], json_encode([
            'a' => $actor,
            'k' => $key,
            'd' => $data,
            'r' => $id === null ? null : $this->replies,
            'i' => $id,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Whether the actor for this key runs in another process.
     */
    private function remote(string $actor, string $key): bool
    {
        return $this->carrier !== null && !isset($this->shards[$this->shardOf($actor, $key)]);
    }

    private function shardOf(string $actor, string $key): int
    {
        return crc32($actor . "\0" . $key) % $this->workers;
    }

    /**
     * Subscribes to this process's replies, once.
     */
    private function listen(): void
    {
        if ($this->replies !== null) {
            return;
        }
        // Shared by the asks that come while it subscribes.
        $task =
            $this->subscribing ??= Task::spawn(EventLoop::current(), function (): void {
                $channel = self::CHANNEL . 'reply:' . bin2hex(random_bytes(8));
                $this->carrier?->subscribe(
                    $channel,
                    fn(string $channel, string $text) => $this->answered($text),
                    fn() => $this->report(
                        'warning',
                        'Replies from actors may have been lost while the connection was down.',
                    ),
                );
                $this->replies = $channel;
            });
        try {
            $task->await();
        } finally {
            if ($this->subscribing === $task && $task->isComplete()) {
                $this->subscribing = null;
            }
        }
    }

    /**
     * A message for an actor of this process, from the carrier.
     */
    private function arrive(string $text): void
    {
        $envelope = json_decode($text, true);
        if (!is_array($envelope) || !is_string($envelope['a'] ?? null) || !is_string($envelope['k'] ?? null)) {
            $this->report('warning', 'An actor message that could not be read was dropped.');
            return;
        }
        $to = $envelope['r'] ?? null;
        $id = $envelope['i'] ?? null;
        $answer = is_string($to) && is_string($id)
            ? function (bool $replied, mixed $value) use ($to, $id): void {
                try {
                    $this->carrier?->publish([$to], json_encode([
                        'i' => $id,
                        'ok' => $replied,
                        'v' => $value,
                    ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
                } catch (\Throwable $error) {
                    $this->report('warning', 'A reply from an actor could not be sent: ' . $error->getMessage());
                }
            }
            : null;
        $this->deliver($envelope['a'], $envelope['k'], $envelope['d'] ?? null, $answer);
    }

    /**
     * A reply to one of this process's asks, from the carrier.
     */
    private function answered(string $text): void
    {
        $reply = json_decode($text, true);
        if (is_array($reply) && is_string($reply['i'] ?? null)) {
            ($this->waiting[$reply['i']] ?? null)?->__invoke(($reply['ok'] ?? false) === true, $reply['v'] ?? null);
        }
    }

    private function check(string $actor, string $key): void
    {
        $why =
            self::$checked[$actor] ??= match (true) {
                !class_exists($actor) => "{$actor} is not a class.",
                !method_exists($actor, 'handle')
                    => "{$actor} cannot be an actor: an actor is a class with handle(Inbox \$inbox, string \$key).",
                default => self::signature($actor),
            };
        if ($why !== null) {
            throw new \InvalidArgumentException($why);
        }
        if ($key === '') {
            throw new \InvalidArgumentException("An actor's key cannot be empty.");
        }
    }

    private static function signature(string $actor): ?string
    {
        $parameters = new \ReflectionMethod($actor, 'handle')->getParameters();
        $first = $parameters[0] ?? null;
        $type = $first?->getType();
        if (!$type instanceof \ReflectionNamedType || $type->getName() !== Inbox::class) {
            return (
                "{$actor} cannot be an actor: its handle() must take an " . Inbox::class . ' first, and the key second.'
            );
        }
        foreach (array_slice($parameters, 2) as $parameter) {
            if (!$parameter->isOptional()) {
                return "{$actor} cannot be an actor: its handle() takes the inbox and the key, and nothing else.";
            }
        }
        return null;
    }

    private static function plain(mixed $message): mixed
    {
        try {
            return json_decode(
                json_encode($message, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException(
                'A message to an actor travels as JSON, and this one cannot: ' . $error->getMessage(),
                previous: $error,
            );
        }
    }

    private function report(string $level, string $message, ?\Throwable $error = null): void
    {
        if ($this->logger !== null) {
            $this->logger->log($level, $message, $error === null ? [] : ['exception' => $error]);
        } else {
            FailureReporter::report(strtoupper($level) . ' ' . $message, $error);
        }
    }
}
