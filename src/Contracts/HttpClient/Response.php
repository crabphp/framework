<?php

declare(strict_types=1);

namespace Crab\Contracts\HttpClient;

use Psr\Http\Message\ResponseInterface;

/**
 * What another server answered: a PSR-7 response with the two questions
 * most code asks of one.
 */
interface Response extends ResponseInterface
{
    /**
     * Whether the status is a success, 200 to 299.
     */
    public function ok(): bool;

    /**
     * Throws when the status is not a success, and otherwise returns the
     * response, so that what follows may count on one:
     *
     *     $user = $http->get($url)->throw()->json();
     *
     * @throws \Psr\Http\Client\ClientExceptionInterface Naming the request and the status; the component's ResponseException, which holds the response.
     */
    public function throw(): static;

    /**
     * The body read as JSON, an object as an array.
     *
     * @throws \JsonException When the body is not JSON.
     * @throws \LengthException When a JSON object has more than 1000 members.
     */
    public function json(): mixed;

    /**
     * The body line by line, each without its line break, as it arrives
     * when the answer is streamed: newline-delimited JSON, a log.
     *
     * @return iterable<int, string>
     */
    public function lines(): iterable;

    /**
     * The body as server-sent events, each as soon as it has arrived when
     * the answer is streamed. A comment, which keeps a quiet connection
     * open, is no event.
     *
     *     foreach ($http->stream('POST', $url, json: $prompt)->throw()->events() as $event) {
     *         if ($event->data === '[DONE]') {
     *             break;
     *         }
     *         yield ['token' => $event->json()['choices'][0]['delta']['content'] ?? ''];
     *     }
     *
     * @return iterable<int, Event>
     */
    public function events(): iterable;
}
