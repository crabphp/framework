<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Engine\Async\CancelledException;
use Crab\Engine\Http\Response as EngineResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * The outermost middleware: turns anything thrown beneath it into a
 * response, as JSON when the client accepts it and plain text otherwise.
 * Failures are logged; an HttpException below 500 is an answer, not a
 * failure. Details of a failure are shown only in debug mode.
 */
final readonly class ErrorHandler implements MiddlewareInterface
{
    public function __construct(private LoggerInterface $logger, private bool $debug = false)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (CancelledException $cancelled) {
            // The request is already over; the engine ends it.
            throw $cancelled;
        } catch (\Throwable $error) {
            $status = $error instanceof HttpException ? $error->status : 500;
            if ($status >= 500) {
                $this->logger->error('{method} {target} failed: {message}', [
                    'method' => $request->getMethod(),
                    'target' => $request->getRequestTarget(),
                    'message' => $error->getMessage(),
                    'exception' => $error,
                ]);
            }
            return $this->render($request, $error, $status);
        }
    }

    private function render(ServerRequestInterface $request, \Throwable $error, int $status): ResponseInterface
    {
        $shown = $error instanceof HttpException || $this->debug;
        $message = $shown && $error->getMessage() !== '' ? $error->getMessage() : EngineResponse::reason($status);
        $headers = $error instanceof HttpException ? $error->headers : [];
        if (str_contains($request->getHeaderLine('Accept'), 'json')) {
            $body = ['error' => ['status' => $status, 'message' => $message]];
            if ($this->debug && $status >= 500) {
                $body['error'] += ['exception' => $error::class, 'file' => $error->getFile(),
                    'line' => $error->getLine(), 'trace' => explode("\n", $error->getTraceAsString())];
            }
            // A failure's own text may not be valid UTF-8.
            return Response::json($body, $status, $headers, JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $body = $status . ' ' . $message . "\n";
        if ($this->debug && $status >= 500) {
            $body .= "\n" . $error . "\n";
        }
        return Response::text($body, $status, $headers);
    }
}
