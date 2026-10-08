<?php

declare(strict_types=1);

namespace Crab\Contracts\HttpClient;

use Crab\Engine\Async\Cancellation;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Sends HTTP requests to other servers. While one waits for its answer
 * the worker serves other requests, so a handler may call another API
 * without holding everyone else up.
 *
 *     $user = $http->get('https://api.example.com/users/7')->json();
 *     $made = $http->post('https://api.example.com/users', json: ['name' => 'Ada']);
 *
 * It is PSR-18's client under Crab's name, with shorter ways to say the
 * usual requests. An answer with an error status, 404 or 500, is an
 * answer and is returned; only a request that got no answer throws, a
 * Psr\Http\Client\ClientExceptionInterface.
 */
interface Client extends ClientInterface
{
    /**
     * @param string $url A whole URL, or a path when the client was made with a base URL.
     * @param array<string, mixed> $query Added to the URL's query string.
     * @param array<string, string|list<string>> $headers
     * @param mixed $json Sent as the body, as JSON.
     * @param array<string, mixed> $form Sent as the body, as a form (application/x-www-form-urlencoded).
     * @param string|StreamInterface|null $body Sent as the body as it is; say its Content-Type in the headers.
     * @param float|null $timeout Seconds the whole exchange may take; the client's own when not given.
     * @param Cancellation|null $cancellation Gives the request up when cancelled: a handler passes its request's cancellation() so that a caller who has gone is not worked for.
     */
    public function request(
        string $method,
        string $url,
        array $query = [],
        array $headers = [],
        mixed $json = null,
        array $form = [],
        string|StreamInterface|null $body = null,
        ?float $timeout = null,
        ?Cancellation $cancellation = null,
    ): Response;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string|list<string>> $headers
     */
    public function get(string $url, array $query = [], array $headers = []): Response;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function post(string $url, mixed $json = null, array $headers = []): Response;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function put(string $url, mixed $json = null, array $headers = []): Response;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function patch(string $url, mixed $json = null, array $headers = []): Response;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string|list<string>> $headers
     */
    public function delete(string $url, array $query = [], array $headers = []): Response;
}
