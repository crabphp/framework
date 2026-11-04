<?php

declare(strict_types=1);

namespace Crab\Actors;

use Crab\Contracts\Actors\Actors as ActorsContract;
use Crab\Contracts\Actors\Inbox;
use Crab\Contracts\Actors\NoReply;
use Crab\Contracts\Broadcast\Carrier;
use Crab\Contracts\Container\Container;
use Crab\Engine\Async\Async;
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

    /**
     * Messages for other processes not yet published, by channel, in the
     * order they were sent.
     *
     * @var array<string, list<Outgoing>>
     */
    private array $outbox = [];

    /**
     * The channels whose messages are being published.
     *
     * @var array<string, true>
     */
    private array $draining = [];

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
        $data = self::plain($message);
        if (!$this->remote($actor, $key)) {
            $this->local($actor, $key, $data, null);
            return;
        }
        // In line behind whatever was sent before it, an ask's included, and waited for: a failure is told.
        Async::await(EventLoop::current(), function (\Closure $resume, \Closure $fail) use ($actor, $key, $data): null {
            $outgoing = $this->send($actor, $key, $data, null);
            $outgoing->done = static fn(?\Throwable $error) => $error === null ? $resume() : $fail($error);
            $this->ready($outgoing);
            return null;
        });
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
        $loop = EventLoop::current();
        // One deadline for all of it: waiting for the reply channel counts against the timeout too.
        $deadline = microtime(true) + $timeout;
        $id = bin2hex(random_bytes(8));
        $outgoing = null;
        if ($this->remote($actor, $key)) {
            // In line from now, so a tell sent after it is published after it, even while the reply channel
            // is still being made; nothing behind it is published until it is, or it is withdrawn.
            $outgoing = $this->send($actor, $key, $data, $id);
        }
        try {
            if ($outgoing !== null) {
                $this->listen(
                    $loop,
                    $timeout,
                    $cancellation,
                    static fn(): NoReply => new NoReply(
                        "{$actor} for \"{$key}\" did not reply within {$timeout} seconds: the reply channel was not ready.",
                    ),
                );
                $timeout = max(0.0, $deadline - microtime(true));
            }
            // Settled once, by the reply, the timeout or the cancellation; a reply may come while it is still being sent.
            return Async::await(
                $loop,
                function (\Closure $resume, \Closure $fail) use (
                    $loop,
                    $id,
                    $actor,
                    $key,
                    $data,
                    $timeout,
                    $outgoing,
                ): \Closure {
                    $this->waiting[$id] = static function (bool $replied, mixed $value) use (
                        $resume,
                        $fail,
                        $actor,
                        $key,
                    ): void {
                        $replied
                            ? $resume($value)
                            : $fail(new NoReply("{$actor} for \"{$key}\" did not reply: {$value}"));
                    };
                    $timer = $loop->delay($timeout, static function () use ($fail, $actor, $key, $timeout): void {
                        $fail(new NoReply("{$actor} for \"{$key}\" did not reply within {$timeout} seconds."));
                    });
                    $undo = function () use ($loop, $id, $timer, $outgoing): void {
                        unset($this->waiting[$id]);
                        $loop->cancel($timer);
                        if ($outgoing !== null) {
                            $this->withdraw($outgoing);
                        }
                    };
                    if ($outgoing === null) {
                        try {
                            $this->local($actor, $key, $data, $id);
                        } catch (\Throwable $error) {
                            $undo();
                            throw $error;
                        }
                        return $undo;
                    }
                    // Published apart from the wait, which a slow carrier would otherwise hold past its deadline
                    // and cancellation; its reply now has somewhere to go.
                    $outgoing->done = static fn(?\Throwable $error) => $error === null ? null : $fail($error);
                    $this->ready($outgoing);
                    return $undo;
                },
                $cancellation,
            );
        } finally {
            // However it ends, it leaves the line: one never published would hold up all behind it.
            if ($outgoing !== null) {
                $this->withdraw($outgoing);
            }
        }
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

    /**
     * Gives a message to an actor that runs in this process.
     */
    private function local(string $actor, string $key, mixed $data, ?string $id): void
    {
        $answer = $id === null
            ? null
            : function (bool $replied, mixed $value) use ($id): void {
                ($this->waiting[$id] ?? null)?->__invoke($replied, $value);
            };
        $this->deliver($actor, $key, $data, $answer);
    }

    /**
     * Puts a message for another process in line on its channel, to be
     * published once it is ready and those before it have been.
     */
    private function send(string $actor, string $key, mixed $data, ?string $id): Outgoing
    {
        $channel = self::CHANNEL . $this->shardOf($actor, $key);
        $outgoing = new Outgoing(
            $channel,
            fn(): string => json_encode([
                'a' => $actor,
                'k' => $key,
                'd' => $data,
                'r' => $id === null ? null : $this->replies,
                'i' => $id,
            ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            static function (?\Throwable $error): void {},
        );
        $this->outbox[$channel][] = $outgoing;
        return $outgoing;
    }

    private function ready(Outgoing $outgoing): void
    {
        $outgoing->ready = true;
        $this->drain($outgoing->channel);
    }

    /**
     * Takes a message out of line if it has not been published yet.
     */
    private function withdraw(Outgoing $outgoing): void
    {
        if ($outgoing->published || $outgoing->withdrawn) {
            return;
        }
        $outgoing->withdrawn = true;
        $this->drain($outgoing->channel);
    }

    /**
     * Has a channel's messages published one at a time, in the order they
     * were put in line, while the first is ready; by one sender, apart from
     * whoever asks for it.
     */
    private function drain(string $channel): void
    {
        if (isset($this->draining[$channel])) {
            return;
        }
        $this->draining[$channel] = true;
        EventLoop::current()->defer(function () use ($channel): void {
            try {
                while (($outgoing = $this->outbox[$channel][0] ?? null) !== null) {
                    if (!$outgoing->withdrawn && !$outgoing->ready) {
                        return;
                    }
                    array_shift($this->outbox[$channel]);
                    if ($outgoing->withdrawn) {
                        continue;
                    }
                    $outgoing->published = true;
                    try {
                        $this->carrier?->publish([$channel], ($outgoing->text)());
                    } catch (\Throwable $error) {
                        ($outgoing->done)($error);
                        continue;
                    }
                    ($outgoing->done)(null);
                }
                unset($this->outbox[$channel]);
            } finally {
                unset($this->draining[$channel]);
            }
        });
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
    /**
     * Subscribes to this process's reply channel, once, for every ask. A
     * caller waits for it no longer than its own timeout, and its
     * cancellation ends only its own wait: the others still share it.
     *
     * @param \Closure(): \Throwable $late What the caller is told when its time runs out first.
     */
    private function listen(EventLoop $loop, float $timeout, ?Cancellation $cancellation, \Closure $late): void
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
        $wait = new Cancellation();
        $unsubscribe = $cancellation?->subscribe($wait->cancel(...));
        $timer = $loop->delay($timeout, static fn() => $wait->cancel($late()));
        try {
            $task->await($wait);
        } catch (CancelledException $error) {
            // The caller's own cancellation, or the time it was given running out.
            throw $error->getPrevious() instanceof NoReply ? $error->getPrevious() : $error;
        } finally {
            $loop->cancel($timer);
            $unsubscribe?->__invoke();
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
