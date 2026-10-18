<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Engine\Http\Request as EngineRequest;
use Crab\Engine\Http\Response as EngineResponse;
use Crab\Engine\Http\Upgrade;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs a PSR-15 handler as the handler of the engine's HttpServer.
 */
final readonly class EngineHandler
{
    private \Closure $handler;

    /**
     * A closure is handled as a route's closure is; see ClosureHandler.
     */
    public function __construct(RequestHandlerInterface|\Closure $handler)
    {
        $this->handler = ($handler instanceof \Closure ? new ClosureHandler($handler) : $handler)->handle(...);
    }

    public function __invoke(EngineRequest $request): EngineResponse|Upgrade
    {
        return self::toEngine(($this->handler)(Request::fromEngine($request)));
    }

    private static function toEngine(ResponseInterface $response): EngineResponse|Upgrade
    {
        if ($response instanceof UpgradeResponse && $response->getStatusCode() === 101) {
            return $response->toUpgrade();
        }
        $body = $response->getBody();
        $content = $body instanceof Stream ? $body->text() : null;
        $headers = $response->getHeaders();
        if ($content !== null) {
            // Alone and spelled this way, the type matches the fields the
            // engine has already encoded for its own text and JSON responses.
            if (isset($headers['content-type']) && count($headers) === 1 && count($headers['content-type']) === 1) {
                $headers = ['Content-Type' => $headers['content-type'][0]];
            }
            return new EngineResponse($response->getStatusCode(), $headers, $content);
        }
        $size = $body->getSize();
        if ($size === 0) {
            return new EngineResponse($response->getStatusCode(), $headers, '');
        }
        // A length is only certain when the stream is sent from its start.
        if ($size !== null && $body->isSeekable() && !$response->hasHeader('Content-Length')) {
            $headers['Content-Length'] = [(string) $size];
        }
        return new EngineResponse($response->getStatusCode(), $headers, self::chunks($body));
    }

    /**
     * @return \Generator<int, string>
     */
    private static function chunks(StreamInterface $body): \Generator
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            $chunk = $body->read(32_768);
            if ($chunk !== '') {
                yield $chunk;
            }
        }
    }
}
