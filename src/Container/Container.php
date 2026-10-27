<?php

declare(strict_types=1);

namespace Crab\Container;

use Crab\Contracts\Container\Container as ContainerContract;
use Psr\Container\ContainerInterface;

/**
 * A PSR-11 container with constructor autowiring. A class that has no
 * binding is constructed from its constructor's types and kept, so there is
 * one of it per worker; bind() is for entries that must be new each time.
 * Kept entries are shared by every request a worker serves and must hold no
 * state of a single request.
 */
final class Container implements ContainerContract
{
    /**
     * @var array<string, \Closure|string>
     */
    private array $concretes = [];
    /**
     * Entries that are made anew on every get().
     *
     * @var array<string, true>
     */
    private array $transient = [];
    /**
     * @var array<string, mixed>
     */
    private array $instances = [];
    /**
     * @var array<string, true>
     */
    private array $resolving = [];
    /**
     * Per class: each constructor parameter's type, whether it has a
     * default, and the default.
     *
     * @var array<class-string, list<array{?string, bool, mixed}>>
     */
    private array $plans = [];
    /**
     * Entries problem() found nothing in the way of, until a binding changes.
     *
     * @var array<string, true>
     */
    private array $sound = [];

    public function __construct()
    {
        $this->instances[self::class] = $this;
        $this->instances[ContainerContract::class] = $this;
        $this->instances[ContainerInterface::class] = $this;
    }

    public function bind(string $id, \Closure|string|null $concrete = null): void
    {
        $this->concretes[$id] = $concrete ?? $id;
        $this->transient[$id] = true;
        unset($this->instances[$id]);
        $this->sound = [];
    }

    public function singleton(string $id, \Closure|string|null $concrete = null): void
    {
        $this->concretes[$id] = $concrete ?? $id;
        unset($this->transient[$id], $this->instances[$id]);
        $this->sound = [];
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
        unset($this->concretes[$id], $this->transient[$id]);
        $this->sound = [];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || isset($this->concretes[$id]) || self::instantiable($id);
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id]) || array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->resolving[$id])) {
            throw new ContainerException("{$id} depends on itself.");
        }
        $this->resolving[$id] = true;
        try {
            $concrete = $this->concretes[$id] ?? $id;
            if ($concrete instanceof \Closure) {
                $value = $concrete($this);
            } elseif ($concrete === $id) {
                $value = $this->build($id);
            } elseif (
                isset($this->transient[$id])
                && !isset($this->concretes[$concrete])
                && !array_key_exists($concrete, $this->instances)
            ) {
                // A new one each time, not the kept instance of the class.
                $value = $this->build($concrete);
            } else {
                // Another name for the same thing: asked for each time, so that replacing the thing replaces it here too.
                return $this->get($concrete);
            }
        } finally {
            unset($this->resolving[$id]);
        }
        if (!isset($this->transient[$id])) {
            $this->instances[$id] = $value;
        }
        return $value;
    }

    /**
     * Why an entry could not be made, found without making anything: by
     * following the bindings and the constructors that get() would, to an
     * entry with no binding that is not a class that can be constructed,
     * a parameter that cannot be autowired, or an entry that needs itself.
     * An entry made by a closure, or one already kept, is taken as one that
     * can be made, since only running the closure would tell.
     *
     * @return string|null "App\Import needs App\Scanner, which has no binding and is not a class that can be constructed."; null when nothing is in the way.
     */
    public function problem(string $id): ?string
    {
        $problem = $this->unmade($id, []);
        return $problem === null ? null : "{$id} {$problem}.";
    }

    /**
     * What stands in the way of making an entry, said of it: "needs B,
     * which has no binding ...". The entries being followed to reach it
     * are in $through.
     *
     * @param list<string> $through
     */
    private function unmade(string $id, array $through): ?string
    {
        if (
            isset($this->sound[$id])
            || array_key_exists($id, $this->instances)
            || ($this->concretes[$id] ?? null) instanceof \Closure
        ) {
            return null;
        }
        if (in_array($id, $through, true)) {
            return 'depends on itself';
        }
        $concrete = $this->concretes[$id] ?? $id;
        if ($concrete !== $id) {
            $problem = $this->unmade($concrete, [...$through, $id]);
            if ($problem !== null) {
                return "is bound to {$concrete}, which {$problem}";
            }
        } else {
            if (!self::instantiable($id)) {
                return 'has no binding and is not a class that can be constructed';
            }
            try {
                $plan = $this->plans[$id] ??= self::plan($id);
            } catch (ContainerException $error) {
                // "X takes $dsn, which cannot be autowired; ...", said of X.
                return rtrim(substr($error->getMessage(), strlen($id) + 1), '.');
            }
            foreach ($plan as [$type, $optional]) {
                if ($type === null || $optional && !$this->has($type)) {
                    continue;
                }
                $problem = $this->unmade($type, [...$through, $id]);
                if ($problem !== null) {
                    return "needs {$type}, which {$problem}";
                }
            }
        }
        $this->sound[$id] = true;
        return null;
    }

    private function build(string $class): object
    {
        $arguments = [];
        foreach ($this->plans[$class] ??= self::plan($class) as [$type, $optional, $default]) {
            if ($type === null || $optional && !$this->has($type)) {
                $arguments[] = $default;
                continue;
            }
            try {
                $arguments[] = $this->get($type);
            } catch (NotFoundException $error) {
                throw new ContainerException("{$class} needs {$type}, which has no binding.", previous: $error);
            }
        }
        return new $class(...$arguments);
    }

    /**
     * @return list<array{?string, bool, mixed}>
     */
    private static function plan(string $class): array
    {
        if (!self::instantiable($class)) {
            throw new NotFoundException("{$class} has no binding and is not a class that can be constructed.");
        }
        $plan = [];
        foreach (new \ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }
            $type = $parameter->getType();
            $optional = $parameter->isDefaultValueAvailable();
            $default = $optional ? $parameter->getDefaultValue() : null;
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $plan[] = [$type->getName(), $optional || $type->allowsNull(), $default];
            } elseif ($optional || $type?->allowsNull()) {
                $plan[] = [null, true, $default];
            } else {
                throw new ContainerException(
                    "{$class} takes \${$parameter->getName()}, which cannot be autowired; bind {$class} with a closure.",
                );
            }
        }
        return $plan;
    }

    private static function instantiable(string $class): bool
    {
        return class_exists($class) && new \ReflectionClass($class)->isInstantiable();
    }
}
