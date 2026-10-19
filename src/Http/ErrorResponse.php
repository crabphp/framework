<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Engine\FailureReporter;
use Crab\Engine\Http\Response as EngineResponse;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The one way an error status becomes a response: JSON when the client
 * accepts it, plain text otherwise.
 */
final class ErrorResponse
{
    /**
     * @param string $message Shown to the client; the status's reason phrase when empty.
     * @param array<string, string|list<string>> $headers
     * @param \Throwable|null $detail A failure to describe in full, but without its calls' arguments, for debugging only.
     * @param array<string, list<string>> $fields What is wrong with the request's data, by field.
     */
    public static function make(
        ServerRequestInterface $request,
        int $status,
        string $message = '',
        array $headers = [],
        ?\Throwable $detail = null,
        array $fields = [],
    ): Response {
        if ($message === '') {
            $message = EngineResponse::reason($status);
        }
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
