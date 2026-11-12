<?php

declare(strict_types=1);

namespace Crab\Session;

use Crab\Contracts\Session\Record;
use Crab\Contracts\Session\Session as SessionContract;

/**
 * One request's session. It reads what the driver keeps the first time it is
 * used, so a request that never touches it costs nothing, and it remembers
 * which keys this request changed. When it is saved, those changes are laid
 * over what the driver holds by then: two requests of one browser at once
 * each keep what they changed.
 */
final class Session implements SessionContract
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    /**
     * Keys the request before flashed: readable now, gone after this request.
     *
     * @var array<string, true>
     */
    private array $aging = [];

    /**
     * Keys this request flashed, for the next one.
     *
     * @var array<string, true>
     */
    private array $flashed = [];

    /**
     * @var array<string, true>
     */
    private array $changed = [];

    private bool $loaded = false;
    private bool $existed = false;
    private bool $cleared = false;
    private bool $newId = false;
    private int $expiresAt = 0;

    /**
     * @param \Closure(): ?Record $load Reads the session the request names; null for none.
     */
    public function __construct(
        private readonly \Closure $load,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function has(string $key): bool
    {
        $this->load();
        return array_key_exists($key, $this->values);
    }

    public function put(string $key, mixed $value): void
    {
        self::check($key, $value);
        $this->load();
        $this->values[$key] = $value;
        $this->changed[$key] = true;
        // Put again, it is kept like any other value.
        unset($this->aging[$key], $this->flashed[$key]);
    }

    public function forget(string $key): void
    {
        $this->load();
        unset($this->values[$key], $this->aging[$key], $this->flashed[$key]);
        $this->changed[$key] = true;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    public function all(): array
    {
        $this->load();
        return $this->values;
    }

    public function flash(string $key, mixed $value): void
    {
        $this->put($key, $value);
        $this->flashed[$key] = true;
    }

    public function regenerate(): void
    {
        $this->load();
        $this->newId = true;
    }

    public function invalidate(): void
    {
        $this->load();
        $this->values = [];
        $this->aging = [];
        $this->flashed = [];
        $this->changed = [];
        $this->cleared = true;
        $this->newId = true;
    }

    /**
     * Whether the request used its session at all.
     */
    public function started(): bool
    {
        return $this->loaded;
    }

    /**
     * Whether the session must be kept under a new id.
     */
    public function newId(): bool
    {
        return $this->newId;
    }

    /**
     * Whether there is anything to write: a change, a flash to drop, a new id,
     * or an expiry less than half a lifetime away, which is pushed back.
     */
    public function needsWriting(int $now, int $lifetime): bool
    {
        return (
            $this->changed !== []
            || $this->aging !== []
            || $this->newId
            || $this->existed
            && ($this->expiresAt - $now) < intdiv($lifetime, 2)
        );
    }

    /**
     * What to keep: this request's changes laid over the latest record, with
     * the flashes this request has seen dropped. Null when nothing is left.
     */
    public function record(?Record $latest, int $expiresAt): ?Record
    {
        $values = $this->cleared || $latest === null ? [] : $latest->values;
        foreach (array_keys($this->changed) as $key) {
            if (array_key_exists($key, $this->values)) {
                $values[$key] = $this->values[$key];
            } else {
                unset($values[$key]);
            }
        }
        if ($latest === null && !$this->cleared) {
            // The record was gone by the time of writing (it expired, or there never was one).
            $values += array_diff_key($this->values, $this->aging);
        }
        foreach (array_keys($this->aging) as $key) {
            unset($values[$key]);
        }
        $flashed = $this->flashed;
        // What another request flashed meanwhile is still to be seen.
        foreach ($this->cleared || $latest === null ? [] : $latest->flashed as $key) {
            if (isset($this->aging[$key]) || isset($this->changed[$key]) || !array_key_exists($key, $values)) {
                continue;
            }
            $flashed[$key] = true;
        }
        if ($values === []) {
            return null;
        }
        return new Record($values, array_keys($flashed), $expiresAt);
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $record = ($this->load)();
        if ($record === null) {
            return;
        }
        $this->existed = true;
        $this->values = $record->values;
        $this->expiresAt = $record->expiresAt;
        foreach ($record->flashed as $key) {
            if (!array_key_exists($key, $this->values)) {
                continue;
            }
            $this->aging[$key] = true;
        }
    }

    /**
     * A session keeps JSON values, so that what is read is what was put.
     */
    private static function check(string $key, mixed $value): void
    {
        if ($key === '') {
            throw new \InvalidArgumentException('A session key cannot be empty.');
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }
        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new \InvalidArgumentException(
                    "The session value for \"{$key}\" is not UTF-8 text: keep binary data base64-encoded.",
                );
            }
            return;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException("The session value for \"{$key}\" is not a finite number.");
            }
            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::check($key, $item);
            }
            return;
        }
        throw new \InvalidArgumentException(
            "The session keeps JSON values, and \"{$key}\" was given "
            . get_debug_type($value)
            . ': keep its id, or an array of its fields, instead.',
        );
    }
}
