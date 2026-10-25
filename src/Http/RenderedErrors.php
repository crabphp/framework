<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\ErrorRenderer;
use Crab\Engine\Async\CancelledException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * In front of the whole middleware stack when the application binds an
 * ErrorRenderer: an error response that still carries its Problem is
 * written again by the renderer, keeping the headers middleware gave it.
 * Being outside every middleware, it also reaches the errors of
 * middleware added in front of the error handler.
 */
final class RenderedErrors implements RequestHandlerInterface
{
    private ?ErrorRenderer $renderer = null;

    public function __construct(
        private readonly RequestHandlerInterface $handler,
        private readonly ContainerInterface $container,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->handler->handle($request);
        $problem = $response instanceof Response ? $response->problem() : null;
        if ($problem === null) {
            return $response;
        }
        try {
            // Made in the worker on first use, not in the parent.
            $this->renderer ??= $this->container->get(ErrorRenderer::class);
            $written = $this->renderer->render($problem, $request);
        } catch (CancelledException $cancelled) {
            throw $cancelled;
        } catch (\Throwable $error) {
            // The client still gets its error, in Crab's own format.
            $this->container->get(LoggerInterface::class)->error('The error renderer failed: {message}', [
                'message' => $error->getMessage(),
                'exception' => $error,
            ]);
            return $response;
        }
        foreach ($response->getHeaders() as $name => $values) {
            // The body, and so its type and length, are the renderer's.
            $own = strcasecmp($name, 'Content-Type') === 0 || strcasecmp($name, 'Content-Length') === 0;
            if (!$own && !$written->hasHeader($name)) {
                $written = $written->withHeader($name, $values);
            }
        }
        return $written;
    }
}
