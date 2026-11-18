<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\DecodePolicies as DecodePoliciesContract;
use Crab\Contracts\Http\Handler;
use Crab\Contracts\Http\Request as RequestContract;
use Psr\Http\Message\ResponseInterface;

/**
 * In front of the middleware stack when a plugin has added a decode
 * policy: asks each, in order, how the request is to be read, and gives
 * the stack the request so marked. It reads nothing itself; the request
 * is still read on first use, and a refusal is thrown where it is read,
 * beneath the error handler.
 */
final readonly class SelectDecoding implements Handler
{
    public function __construct(
        private Handler $handler,
        private DecodePoliciesContract $policies,
    ) {}

    public function handle(RequestContract $request): ResponseInterface
    {
        if ($request instanceof Request) {
            foreach ($this->policies->all() as $policy) {
                $decoding = $policy->select($request);
                if ($decoding !== null) {
                    $request = $request->withDecoding($decoding);
                    break;
                }
            }
        }
        return $this->handler->handle($request);
    }
}
