<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\ErrorRenderer;
use Crab\Contracts\Http\Handler;
use Crab\Contracts\Http\Problem;
use Crab\Contracts\Http\Representations as RepresentationsContract;
use Crab\Contracts\Http\Request;
use Crab\Contracts\Routing\Entered;
use Crab\Engine\Async\CancelledException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * In front of the whole middleware stack when the application binds an
 * ErrorRenderer or a plugin adds a Representation: an error response
 * that still carries its Problem is written again, keeping the headers
 * middleware gave it. A representation whose protocol the request speaks
 * writes it first; any other error is the application's renderer's, or
 * stays in Crab's own format. Being outside every middleware, it also
 * reaches the errors of middleware added in front of the error handler.
 *
 * When a representation may write the error, the request is given an
 * Entered to carry, which the router marks as it calls the action: the
 * representation is handed the request with it, and can say whether the
 * error came before the action or after it had begun.
 */
final class RenderedErrors implements Handler
{
    private ?ErrorRenderer $renderer = null;

    public function __construct(
        private readonly Handler $handler,
        private readonly ContainerInterface $container,
        private readonly ?RepresentationsContract $representations = null,
    ) {}

    public function handle(Request $request): ResponseInterface
    {
        if ($this->representations !== null && $this->representations->all() !== []) {
            // For a representation to say truthfully whether the action was called; see Entered.
            $request = $request->withAttribute(Entered::ATTRIBUTE, new Entered());
        }
        $response = $this->handler->handle($request);
        $problem = $response instanceof Response ? $response->problem() : null;
        if ($problem === null) {
            return $response;
        }
        try {
            $written = $this->written($problem, $request);
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
        if ($written === null) {
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

    /**
     * The error as the first representation that takes the request
     * writes it, else as the application's renderer does; null when
     * neither does.
     */
    private function written(Problem $problem, Request $request): ?ResponseInterface
    {
        foreach ($this->representations?->all() ?? [] as $representation) {
            $written = $representation->render($problem, $request);
            if ($written !== null) {
                return $written;
            }
        }
        if (!$this->container->has(ErrorRenderer::class)) {
            return null;
        }
        // Made in the worker on first use, not in the parent.
        $this->renderer ??= $this->container->get(ErrorRenderer::class);
        return $this->renderer->render($problem, $request);
    }
}
