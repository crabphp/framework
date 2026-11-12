<?php

declare(strict_types=1);

namespace Crab\Contracts\Session;

/**
 * Where sessions are kept between requests. A driver is told the value of
 * the session's cookie and answers with what it holds; it either keeps the
 * record itself and puts an id in the cookie, or puts the record in the
 * cookie. Times are Unix timestamps, passed in, never read.
 */
interface Driver
{
    /**
     * The session the cookie's value names, or null when there is none, it
     * expired, or the value is not one this driver gave.
     */
    public function read(string $cookie, int $now): ?Record;

    /**
     * Keeps the record and returns the value for the cookie. $cookie is the
     * value the browser sent, or null for a new session or a new id.
     */
    public function write(?string $cookie, Record $record): string;

    /**
     * Forgets the session the cookie's value names.
     */
    public function destroy(string $cookie): void;

    /**
     * Removes expired sessions and returns how many; zero for a driver whose
     * sessions expire by themselves.
     */
    public function sweep(int $now): int;
}
