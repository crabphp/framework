<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\Problem;
use Crab\Engine\FailureReporter;
use Crab\Engine\Http\Response as EngineResponse;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The one way an error status becomes a response. Crab's own format is
 * JSON when the client accepts it and plain text otherwise; an
 * application that binds an ErrorRenderer writes it in its own instead.
 */
final class ErrorResponse
{
    /**
     * An error response, carrying its Problem for the application's
     * ErrorRenderer to write when it has one.
     *
     * @param string $message Shown to the client; the status's reason phrase when empty.
     * @param array<string, string|list<string>> $headers
     * @param array<string, list<string>> $fields What is wrong with the request's data, by field.
     * @param \Throwable|null $cause The exception the request is answered for.
     * @param bool $debug Whether a failure's cause may be described in full, without its calls' arguments.
     */
    public static function make(
        ServerRequestInterface $request,
        int $status,
        string $message = '',
        array $headers = [],
        array $fields = [],
        ?\Throwable $cause = null,
        bool $debug = false,
    ): Response {
        $problem = new Problem(
            $status,
            $message === '' ? EngineResponse::reason($status) : $message,
            $fields,
            $cause,
            $debug,
        );
        return self::render($problem, $request, $headers)->withProblem($problem);
    }

    /**
     * Crab's own format, which an ErrorRenderer may also use for what it
     * does not write itself. A failure's cause is described only for a
     * status of 500 or above, and only when $problem->debug.
     *
     * @param array<string, string|list<string>> $headers
     */
    public static function render(Problem $problem, ServerRequestInterface $request, array $headers = []): Response
    {
        $status = $problem->status;
        $message = $problem->message;
        $fields = $problem->fields;
        $detail = $problem->debug && $status >= 500 ? $problem->cause : null;
        if (str_contains($request->getHeaderLine('Accept'), 'json')) {
            $body = ['error' => ['status' => $status, 'message' => $message]];
            if ($fields !== []) {
                $body['error']['fields'] = $fields;
            }
            if ($detail !== null) {
                $body['error'] += [
                    'exception' => $detail::class,
                    'file' => $detail->getFile(),
                    'line' => $detail->getLine(),
                    // Without the arguments of each call, which may be a password.
                    'trace' => FailureReporter::frames($detail),
                ];
            }
            // A failure's own text may not be valid UTF-8.
            return Response::json($body, $status, $headers, JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $body = $status . ' ' . $message . "\n";
        foreach ($fields as $field => $problems) {
            $body .= "{$field} " . implode(', ', $problems) . "\n";
        }
        if ($detail !== null) {
            $body .= "\n" . FailureReporter::describe($detail) . "\n";
        }
        return Response::text($body, $status, $headers);
    }
}
