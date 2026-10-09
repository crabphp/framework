<?php

declare(strict_types=1);

namespace Crab\Contracts\HttpClient;

use Crab\Engine\Async\Cancellation;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Sends HTTP requests to other servers. While one waits for its answer
 * the worker serves other requests, so a handler may call another API
 * without holding everyone else up.
 *
 *     $user = $http->get('https://api.example.com/users/7')->json();
 *     $made = $http->post('https://api.example.com/users', json: ['name' => 'Ada'])->throw();
 *     $fast = $http->timeout(2.0)->get('https://api.example.com/status');
 *     $sure = $http->retry(3)->get('https://api.example.com/report');
 *
 * It is PSR-18's client under Crab's name, with shorter ways to say the
 * usual requests. An answer with an error status, 404 or 500, is an
 * answer and is returned, and throw() on it turns it into an exception;
 * a request that got no answer always throws. Either way it is a
 * Psr\Http\Client\ClientExceptionInterface.
 */
interface Client extends ClientInterface
{
    /**
     * @param string $url A whole URL, or a path when the client was made with a base URL.
     * @param array<string, mixed> $query Added to the URL's query string.
     * @param array<string, string|list<string>> $headers
     * @param mixed $json Sent as the body, as JSON.
     * @param array<string, mixed> $form Sent as the body, as a form; together with files, as that form's other fields.
     * @param array<string, File|UploadedFileInterface|list<File|UploadedFileInterface>> $files Sent as the body, as a form's file fields (multipart/form-data). An uploaded file received in a request may be passed on as it is.
     * @param string|StreamInterface|null $body Sent as the body as it is; say its Content-Type in the headers.
     * @param Cancellation|null $cancellation Gives the request up when cancelled: a handler passes its request's cancellation() so that a caller who has gone is not worked for.
     */
    public function request(
        string $method,
        string $url,
        array $query = [],
        array $headers = [],
        mixed $json = null,
        array $form = [],
        array $files = [],
        string|StreamInterface|null $body = null,
        ?Cancellation $cancellation = null,
    ): Response;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string|list<string>> $headers
     */
    public function get(string $url, array $query = [], array $headers = []): Response;

    /**
     * @param array<string, mixed> $form
     * @param array<string, File|UploadedFileInterface|list<File|UploadedFileInterface>> $files
     * @param array<string, string|list<string>> $headers
     */
    public function post(string $url, mixed $json = null, array $form = [], array $files = [], array $headers = []): Response;

    /**
     * @param array<string, mixed> $form
     * @param array<string, File|UploadedFileInterface|list<File|UploadedFileInterface>> $files
     * @param array<string, string|list<string>> $headers
     */
    public function put(string $url, mixed $json = null, array $form = [], array $files = [], array $headers = []): Response;

    /**
     * @param array<string, mixed> $form
     * @param array<string, File|UploadedFileInterface|list<File|UploadedFileInterface>> $files
     * @param array<string, string|list<string>> $headers
     */
    public function patch(string $url, mixed $json = null, array $form = [], array $files = [], array $headers = []): Response;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string|list<string>> $headers
     */
    public function delete(string $url, array $query = [], array $headers = []): Response;

    /**
     * A client that allows each exchange this many seconds, redirects
     * included, and is otherwise this one: it uses the same connections.
     * This client is left as it was.
     */
    public function timeout(float $seconds): static;

    /**
     * A client that tries a request again, up to this many more times,
     * when it could not be made or the server asked for it to be made
     * later, and is otherwise this one. This client is left as it was.
     *
     * A request that may safely be made twice (GET, HEAD, OPTIONS, PUT,
     * DELETE) is tried again when it got no answer, or 429, 502, 503 or
     * 504. Any other, a POST or a PATCH, only when none of it was sent,
     * or when it was answered 429 or 503, with which a server says it
     * did not act on it.
     *
     * @param float $delay Seconds to wait before the second try; each later wait is twice the one before. A Retry-After the server sent is waited instead when it is longer.
     */
    public function retry(int $times, float $delay = 0.2): static;

    /**
     * A client that keeps and sends cookies with this jar, and is
     * otherwise this one. This client is left as it was.
     */
    public function cookies(CookieJar $jar): static;
}
