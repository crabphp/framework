<?php

declare(strict_types=1);

namespace Crab\Database\Mysql;

use Crab\Database\DatabaseException;
use Crab\Database\ManagedConnection;
use Crab\Database\Query;
use Crab\Database\SessionScope;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\EventLoop;
use Crab\Engine\Network\DnsResolver;
use Crab\Engine\Process\Worker;

/**
 * A lazily opened MySQL session, used by one borrower while commands suspend.
 * Transactions, savepoints and commit callbacks follow the PDO connection.
 */
final class Connection implements ManagedConnection
{
    private ?Stream $stream = null;
    private ?Protocol $protocol = null;
    private ?Statements $statements = null;
    private bool $busy = false;
    /**
     * Depth owns managed callbacks; server status includes raw BEGIN and
     * implicit DDL commits. Neither may substitute for the other's state.
     * SessionScope preserves lost callback state after physical depth resets.
     */
    private int $depth = 0;
    private readonly SessionScope $scope;
    /**
     * @var list<array{int, \Closure}>
     */
    private array $afterCommit = [];
    private readonly Settings $settings;

    /**
     * Cancellation may be supplied by a standalone coroutine consumer.
     *
     * @param array<int|string, mixed> $options
     */
    public function __construct(
        string $dsn,
        private readonly string $username = '',
        #[\SensitiveParameter]
        private readonly string $password = '',
        array $options = [],
        private readonly float $timeout = 5.0,
        private readonly ?Worker $worker = null,
        private readonly ?Cancellation $cancellation = null,
        private readonly DnsResolver $resolver = new DnsResolver(fallback: false),
    ) {
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException(
                'Set the MySQL connection timeout to a finite positive number of seconds.',
            );
        }
        $this->settings = new Settings($dsn, $options);
        $this->scope = new SessionScope();
    }

    public function driver(): string
    {
        return 'mysql';
    }

    public function table(string $table): Query
    {
        return new Query($this, $table);
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->statement($sql, $bindings, 'select')['rows'];
    }

    public function first(string $sql, array $bindings = []): ?array
    {
        return $this->statement($sql, $bindings, 'first')['rows'][0] ?? null;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->statement($sql, $bindings, 'execute')['affected'];
    }

    public function insert(string $sql, array $bindings = []): int|string
    {
        return $this->statement($sql, $bindings, 'insert')['id'];
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0 || $this->serverInTransaction();
    }

    public function afterCommit(\Closure $callback): void
    {
        $this->scope->check();
        if ($this->depth === 0) {
            $callback();
        } else {
            $this->afterCommit[] = [$this->depth, $callback];
        }
    }

    public function transaction(\Closure $callback): mixed
    {
        $sql = $this->depth > 0 ? 'SAVEPOINT crab_' . $this->depth : 'BEGIN';
        $this->statement($sql, [], 'execute', managedBegin: $this->depth === 0);
        ++$this->depth;
        $level = $this->depth;
        try {
            $result = $this->scope->transaction(fn(): mixed => $callback($this));
        } catch (\Throwable $error) {
            if ($this->depth === $level) {
                $this->rollBack();
            }
            throw $error;
        }
        if ($this->depth !== $level) {
            throw new DatabaseException(
                'The transaction lost its session; let this callback unwind before using the connection again.',
            );
        }
        $this->commit();
        return $result;
    }

    public function close(): void
    {
        $this->scope->lost();
        $stream = $this->stream;
        $this->stream = null;
        $this->protocol = null;
        $this->statements = null;
        $this->depth = 0;
        $this->afterCommit = [];
        $stream?->close();
    }

    /**
     * Opens one session before a lock scope and never replaces it within it.
     */
    public function withoutReconnect(\Closure $callback): mixed
    {
        $this->assertAvailable();
        $this->busy = true;
        try {
            $this->open();
        } catch (\Throwable $error) {
            $this->close();
            throw $this->failure($error, '');
        } finally {
            $this->busy = false;
        }
        return $this->scope->fixed(fn(): mixed => $callback($this));
    }

    public function isOpen(): bool
    {
        return $this->stream !== null;
    }

    /**
     * A server-side implicit commit cannot satisfy a managed callback's commit.
     * Once confirmed, every promised callback runs even if an earlier one fails.
     */
    private function commit(): void
    {
        $depth = $this->depth - 1;
        try {
            if ($depth === 0 && !$this->serverInTransaction()) {
                throw new DatabaseException(
                    'There is no active transaction; a statement ended it before commit.',
                    'COMMIT',
                );
            }
            $this->execute($depth === 0 ? 'COMMIT' : 'RELEASE SAVEPOINT crab_' . $depth);
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
        $this->depth = $depth;
        if ($depth > 0) {
            return;
        }
        $waiting = $this->afterCommit;
        $this->afterCommit = [];
        $failure = null;
        foreach ($waiting as [, $callback]) {
            try {
                $callback();
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Rollback removes callbacks belonging to this savepoint and its children.
     * A cleanup failure drops the session while preserving the original error.
     */
    private function rollBack(): void
    {
        $depth = $this->depth - 1;
        try {
            if ($depth > 0) {
                $this->execute('ROLLBACK TO SAVEPOINT crab_' . $depth);
            } elseif ($this->serverInTransaction()) {
                $this->execute('ROLLBACK');
            }
            $this->depth = $depth;
            $remaining = [];
            foreach ($this->afterCommit as $waiting) {
                if ($waiting[0] > $depth) {
                    continue;
                }

                $remaining[] = $waiting;
            }
            $this->afterCommit = $remaining;
        } catch (\Throwable) {
            $this->close();
        }
    }

    /**
     * Serializes one exchange. Recovery decisions use state from before loss;
     * disposal and exception mapping are independent of retry eligibility.
     */
    private function statement(string $sql, array $bindings, string $mode, bool $managedBegin = false): array
    {
        $this->assertAvailable($sql);
        $this->busy = true;
        try {
            for ($attempt = 0;; ++$attempt) {
                $sent = false;
                $ready = $this->protocol !== null;
                $inTransaction = $this->inTransaction();
                try {
                    if ($this->stream !== null && !$this->stream->isAlive()) {
                        throw new StreamFailure('The idle MySQL connection was lost before sending the command.');
                    }
                    $this->open();
                    $ready = true;
                    if ($managedBegin && $this->serverInTransaction()) {
                        throw new DatabaseException(
                            'There is already an active transaction; finish the raw transaction before calling transaction().',
                            $sql,
                        );
                    }
                    return $this->statements->run($sql, $bindings, $mode, $sent);
                } catch (\Throwable $error) {
                    if ($error instanceof ServerError && $error->getCode() === 1213) {
                        $this->scope->failed(new DatabaseException($error->getMessage(), $sql, $error));
                    }
                    $retry = $this->canRetry($error, $attempt, $ready, $sent, $inTransaction);
                    if ($this->mustDiscard($error)) {
                        $this->close();
                    }
                    if ($retry) {
                        continue;
                    }
                    throw $this->failure($error, $sql);
                }
            }
        } finally {
            $this->busy = false;
        }
    }

    private function assertAvailable(string $sql = ''): void
    {
        $this->scope->check($sql);
        if ($this->busy) {
            throw new \LogicException(
                'Two fibers are using one MySQL connection at once; borrow a separate connection from the database pool.',
            );
        }
    }

    private function serverInTransaction(): bool
    {
        return (bool) (($this->protocol->status ?? 0) & Protocol::IN_TRANSACTION);
    }

    /**
     * Prepare is effect-free; any sent execute may already have changed data.
     * Fixed lock sessions and active transaction callbacks forbid replacement.
     */
    private function canRetry(\Throwable $error, int $attempt, bool $ready, bool $sent, bool $inTransaction): bool
    {
        if (!$error instanceof StreamFailure || $attempt !== 0 || !$ready || $sent || $inTransaction) {
            return false;
        }
        return $this->scope->canReconnect() && !($this->worker?->isDraining() ?? false);
    }

    /**
     * A complete ordinary server error leaves the protocol aligned.
     * Transport, cancellation and malformed exchanges cannot be pooled.
     */
    private function mustDiscard(\Throwable $error): bool
    {
        if ($error instanceof ServerError) {
            return $this->protocol === null || in_array($error->getCode(), [2006, 2013, 2055], true);
        }
        return !$error instanceof DatabaseException && !$error instanceof \InvalidArgumentException;
    }

    private function failure(\Throwable $error, string $sql): \Throwable
    {
        if (
            $error instanceof DatabaseException
            || $error instanceof \InvalidArgumentException
            || $error instanceof CancelledException
        ) {
            return $error;
        }
        return new DatabaseException($error->getMessage(), $sql, $error);
    }

    private function open(): void
    {
        if ($this->stream !== null) {
            return;
        }
        if ($this->worker?->isDraining()) {
            throw new \RuntimeException('The worker is stopping; do not start a new MySQL connection during drain.');
        }
        $loop = $this->worker->loop ?? EventLoop::current();
        $deadline = (hrtime(true) / 1e9) + $this->timeout;
        $this->stream = new Stream($loop);
        $this->stream->open($this->settings, $this->resolver, $deadline, $this->cancellation);
        $session = Authentication::login(
            $this->stream,
            $this->settings,
            $this->username,
            $this->password,
            $deadline,
            $this->cancellation,
        );
        $this->protocol = new Protocol($this->stream, $this->cancellation);
        $this->protocol->status = $session['status'];
        $this->protocol->serverVersion = $session['version'];
        $this->statements = new Statements($this->protocol);
    }
}
