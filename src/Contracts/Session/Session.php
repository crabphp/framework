<?php

declare(strict_types=1);

namespace Crab\Contracts\Session;

/**
 * What one browser keeps between its requests, for the request in hand.
 *
 * `StartSession` puts a session on each request it runs around, and an
 * action takes it as a parameter, `Session $session`. It is the request's
 * own: two requests at once, from one browser or two, each have theirs.
 * Values are JSON values (null, booleans, numbers, strings and lists or
 * maps of them), so that what is kept reads back as it was put.
 */
interface Session
{
    /**
     * The request attribute the session travels on.
     */
    public const string ATTRIBUTE = 'session';

    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    /**
     * Keeps a value until it is forgotten or the session ends.
     */
    public function put(string $key, mixed $value): void;

    public function forget(string $key): void;

    /**
     * The value, which is then forgotten.
     */
    public function pull(string $key, mixed $default = null): mixed;

    /**
     * @return array<string, mixed>
     */
    public function all(): array;

    /**
     * Keeps a value for the next request that uses the session, and no
     * longer: a message to show after a redirect.
     */
    public function flash(string $key, mixed $value): void;

    /**
     * Gives the session a new id and keeps what it holds. Call it when the
     * user logs in, so that an id someone else knew stops working.
     */
    public function regenerate(): void;

    /**
     * Forgets everything the session holds and gives it a new id. Call it
     * when the user logs out.
     */
    public function invalidate(): void;
}
