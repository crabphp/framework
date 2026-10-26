<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\HttpError;
use Crab\Contracts\Http\Middleware;
use Crab\Engine\Async\CancelledException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns anything thrown beneath it into a response; see ErrorResponse.
 * Failures are logged, with the request's id when it has one; an
 * HttpError below 500, such as an HttpException, is an answer, not a failure. Details of a failure
 * are shown only in debug mode.
 */
final readonly class ErrorHandler implements Middleware
{
    public function __construct(
        private LoggerInterface $logger,
        private bool $debug = false,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (CancelledException $cancelled) {
            // The request is already over; the engine ends it.
            throw $cancelled;
        } catch (\Throwable $error) {
            $status = $error instanceof HttpError ? $error->status : 500;
            if ($status >= 500) {
                $context = [
                    'method' => $request->getMethod(),
                    // As AccessLog: without the query string, which may hold a token or a ticket.
                    'path' => $request->getUri()->getPath(),
                    'message' => $error->getMessage(),
                    'exception' => $error,
                ];
                $id = $request->getAttribute(RequestId::ATTRIBUTE);
                if ($id !== null) {
                    $context['request_id'] = $id;
                }
                $this->logger->error('{method} {path} failed: {message}', $context);
            }
            $shown = $error instanceof HttpError || $this->debug;
            return ErrorResponse::make(
                $request,
                $status,
                $shown ? $error->getMessage() : '',
                $error instanceof HttpException ? $error->headers : [],
                cause: $error,
                debug: $this->debug,
            );
        }
    }
}
