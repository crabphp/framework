<?php

declare(strict_types=1);

namespace Crab\Contracts\Auth;

/**
 * Whoever a request is made by. An application's user class implements
 * it by having a public $id, and nothing more:
 *
 *     final readonly class User implements Identity
 *     {
 *         public function __construct(public int $id, public string $email) {}
 *     }
 *
 * Middleware that knows who makes a request puts that user on the
 * request's "user" attribute. A route handler that takes a parameter of
 * such a class receives it, and a request without one is answered with
 * 401.
 */
interface Identity
{
    /**
     * The request attribute the logged-in user travels under.
     */
    public const ATTRIBUTE = 'user';

    /**
     * What tells this user from every other, as tokens are issued for.
     */
    public int|string $id { get; }
}
