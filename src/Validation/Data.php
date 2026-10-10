<?php

declare(strict_types=1);

namespace Crab\Validation;

/**
 * The validated data of a request that was described with #[Fields], in
 * place of an object of a class written for it. Its fields are read as
 * properties, and it cannot be changed.
 *
 * Reading a field that was not described is a mistake in the code, not
 * in the request, and throws.
 *
 * @implements \IteratorAggregate<string, mixed>
 */
final readonly class Data implements \JsonSerializable, \IteratorAggregate
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values)
    {
    }

    public function __get(string $name): mixed
    {
        if (!array_key_exists($name, $this->values)) {
            throw new \LogicException("This data has no field \"{$name}\"; it has " . ($this->values === [] ? 'none' : implode(', ', array_keys($this->values))) . '. Describe the field in #[Fields] to take it.');
        }
        return $this->values[$name];
    }

    public function __isset(string $name): bool
    {
        return isset($this->values[$name]);
    }

    public function __set(string $name, mixed $value): void
    {
        throw new \LogicException("The data of a request cannot be changed; \"{$name}\" was assigned to.");
    }

    /**
     * The fields as plain arrays, however deep.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return self::plain($this->values);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->values);
    }

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    private static function plain(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = $value instanceof self ? $value->toArray() : (is_array($value) ? self::plain($value) : $value);
        }
        return $values;
    }
}
