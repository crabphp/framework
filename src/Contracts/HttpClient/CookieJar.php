<?php

declare(strict_types=1);

namespace Crab\Contracts\HttpClient;

use Psr\Http\Message\UriInterface;

/**
 * Keeps the cookies servers set and gives them back on later requests,
 * as a browser does. A client has none unless it is given one, because a
 * client is shared by every request a worker serves and one jar would
 * hand one caller's cookies to the next.
 */
interface CookieJar
{
    /**
     * Remembers the cookies an answer set.
     *
     * @param UriInterface $from Where the request was sent.
     * @param list<string> $setCookie The answer's Set-Cookie headers.
     */
    public function store(UriInterface $from, array $setCookie): void;

    /**
     * The Cookie header for a request there; empty when no cookie belongs to it.
     */
    public function header(UriInterface $to): string;
}
