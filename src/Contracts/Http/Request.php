<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

use Crab\Engine\Async\Cancellation;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A PSR-7 server request with the few additions Crab's own handlers use.
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
     * Cancelled when the request completes, times out or loses its
     * connection; pass it into cooperative waits.
     */
    public function cancellation(): Cancellation;
}
