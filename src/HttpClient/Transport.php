<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Engine\Async\Cancellation;
use Psr\Http\Message\RequestInterface;

/**
 * What carries one request to a server and brings its answer back: the
 * network, or, in a test, replies written in advance.
 *
 * @internal
 */
interface Transport
{
    /**
     * @param float $deadline When to give up, on the clock hrtime() reads, in seconds.
     * @param float $timeout The seconds that deadline was reckoned from, to say so in a failure.
     * @throws ClientException When there is no answer.
     */
    public function exchange(
        RequestInterface $request,
        float $deadline,
        float $timeout,
        ?Cancellation $cancellation,
    ): Response;

    public function close(): void;
}
