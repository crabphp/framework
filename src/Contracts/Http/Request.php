<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

use Crab\Contracts\Auth\Identity;
use Crab\Engine\Async\Cancellation;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A PSR-7 server request with the few additions Crab's actions use.
 * Code that needs only PSR-7 methods should ask for ServerRequestInterface.
 */
interface Request extends ServerRequestInterface
{
    /**
     * One query string parameter, or the default when it is absent.
     */
    public function query(string $name, mixed $default = null): mixed;

    /**
     * One field of an array parsed body, or the default when it is absent.
     */
    public function input(string $name, mixed $default = null): mixed;

    /**
     * One cookie the client sent, or the default when it sent none by
     * that name. Its value is whatever the client chose; check it.
     */
    public function cookie(string $name, ?string $default = null): ?string;

    /**
     * The file uploaded under a top-level field name, or null when there
     * is none or the name holds several files.
     */
    public function file(string $name): ?UploadedFileInterface;

    /**
     * The address of whoever made the request: the other end of the
     * connection, or, behind a proxy the application was told to trust
     * (the TrustedProxies middleware), the client the proxy speaks for.
     * Empty when it is not known, as for a request made in a test.
     */
    public function ip(): string;

    /**
     * The user that authentication middleware, such as TokenAuth or
     * CookieAuth, put on the request, or null when there is none. It is an
     * Identity, so its id is all it is known to have: for the
     * application's own class, typed, use Auth\FromRequest on that class
     * and call User::fromRequest($request). An action takes the user as a
     * parameter instead.
     */
    public function user(): ?Identity;

    /**
     * Cancelled when the request completes, times out or loses its
     * connection; pass it into cooperative waits.
     */
    public function cancellation(): Cancellation;
}
