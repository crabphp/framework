<?php

declare(strict_types=1);

namespace Crab\Runs;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Runs\Run;
use Psr\Clock\ClockInterface;

/**
 * One try of a run: its handle() from the top, with what earlier tries
 * kept, read once as it begins.
 *
 * @internal
 */
final class Execution implements Run
{
    /**
     * The names used in this try, which may each be used once.
     *
     * @var array<string, true>
     */
    private array $used = [];

    /**
     * @param array<string, array{kind: string, result: ?string, wake_at: ?int}> $kept
     */
    public function __construct(
        private readonly int|string $id,
        private readonly Connection $db,
        private readonly string $table,
        private readonly ?ClockInterface $clock,
        private array $kept,
    ) {}

    public function id(): int|string
    {
        return $this->id;
    }

    public function step(string $name, \Closure $work): mixed
    {
        $kept = $this->use($name, 'step');
        if ($kept !== null) {
            return Runs::decode((string) $kept['result'])['value'];
        }
        $value = Runs::plain($work());
        return $this->keep($name, 'step', ['value' => $value], null)['value'];
    }

    public function sleep(string $name, int $seconds): void
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException("The sleep \"{$name}\" is for {$seconds} seconds; give 0 or more.");
        }
        $kept = $this->use($name, 'sleep');
        $wakeAt = $kept['wake_at'] ?? $this->keep($name, 'sleep', null, $this->now() + $seconds)['wake_at'];
        if ($wakeAt > $this->now()) {
            throw new Suspended($name, (int) $wakeAt);
        }
    }

    public function await(string $name): mixed
    {
        $kept = $this->use($name, 'signal');
        if ($kept === null) {
            // Given since this try began, as it may have been.
            $row = $this->read($name);
            if ($row === null) {
                throw new Suspended($name, null);
            }
            $kept = $row;
        }
        return Runs::decode((string) $kept['result'])['value'];
    }

    /**
     * Whether a signal under the name has been kept, looked for afresh.
     */
    public function signalled(string $name): bool
    {
        return $this->read($name) !== null;
    }

    /**
     * What earlier tries kept under the name, once the name is checked to
     * be used once in this try, and as the same kind as before.
     *
     * @return array{kind: string, result: ?string, wake_at: ?int}|null
     */
    private function use(string $name, string $kind): ?array
    {
        Runs::assertName($name);
        if (isset($this->used[$name])) {
            throw new \LogicException(
                "Run {$this->id} used \"{$name}\" twice. Give each step, sleep and await of a run its own name, such as \"search.{\$i}\" in a loop.",
            );
        }
        $this->used[$name] = true;
        $kept = $this->kept[$name] ?? null;
        if ($kept !== null && $kept['kind'] !== $kind) {
            throw new \LogicException(
                "Run {$this->id} used \"{$name}\" as a {$kind}, and it was kept as a {$kept['kind']}. A run must take the same steps each time it is resumed.",
            );
        }
        return $kept;
    }

    /**
     * Keeps what a step returned or when a sleep ends, and returns what
     * was kept: another try's, should one have kept it first.
     *
     * @return array<string, mixed>
     */
    private function keep(string $name, string $kind, ?array $result, ?int $wakeAt): array
    {
        $this->db
            ->table($this->table)
            ->insertOrIgnore([
                'run_id' => $this->id,
                'name' => $name,
                'kind' => $kind,
                'result' => $result === null ? null : Runs::encode($result),
                'wake_at' => $wakeAt,
                'created_at' => $this->now(),
            ]);
        $kept = $this->read($name) ?? throw new \LogicException(
            "The step \"{$name}\" of run {$this->id} was kept and is gone.",
        );
        $this->kept[$name] = $kept;
        return $kind === 'sleep' ? ['wake_at' => $kept['wake_at']] : Runs::decode((string) $kept['result']);
    }

    /**
     * @return array{kind: string, result: ?string, wake_at: ?int}|null
     */
    private function read(string $name): ?array
    {
        $row = $this->db->table($this->table)->where('run_id', $this->id)->where('name', $name)->first();
        if ($row === null) {
            return null;
        }
        return [
            'kind' => (string) $row['kind'],
            'result' => $row['result'] === null ? null : (string) $row['result'],
            'wake_at' => $row['wake_at'] === null ? null : (int) $row['wake_at'],
        ];
    }

    private function now(): int
    {
        return $this->clock?->now()->getTimestamp() ?? time();
    }
}
