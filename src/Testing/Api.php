<?php

declare(strict_types=1);

namespace Crab\Testing;

use Crab\Contracts\Auth\Identity;
use Crab\Http\Request;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sends requests to an application in a test, as a client would, and
 * gives back answers that are easy to check:
 *
 *     $api = new Api($app);
 *
 *     $api->post('/login', ['email' => 'ada@example.com', 'password' => 'secret'])->assertStatus(200);
 *     $order = $api->actingAs($ada)->post('/orders', ['lines' => [...]])->assertStatus(201)->json();
 *     $api->actingAs($ada)->get('/orders', ['page' => 2])->json('data');
 *
 * A request goes through the application's middleware, router and
 * validation, as $app->handle() does. Data is sent as JSON and JSON is
 * asked for, so an error comes back as JSON too.
 */
final readonly class Api
{
    /**
     * @param RequestHandlerInterface $app The application, or any PSR-15 handler.
     * @param array<string, string> $headers Sent with every request.
     */
    public function __construct(
        private RequestHandlerInterface $app,
        private array $headers = [],
        private ?Identity $user = null,
    ) {
    }

    /**
     * The same, with every request made as this user: the user is put
     * on the request as authentication would, and middleware that
     * authenticates lets it through without a token.
     */
    public function actingAs(Identity $user): self
    {
        return new self($this->app, $this->headers, $user);
    }

    /**
     * The same, with these headers on every request as well.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->app, [...$this->headers, ...$headers], $this->user);
    }

    /**
     * Sends a token on every request when a test exercises authentication
     * itself. An empty scheme sends the token alone in Authorization.
     */
    public function withToken(string $token, string $scheme = 'Bearer'): static
    {
        $value = $scheme === '' ? $token : "{$scheme} {$token}";
        return $this->withHeaders(['Authorization' => $value]);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function get(string $path, array $query = [], array $headers = []): ApiResponse
    {
        return $this->request('GET', self::with($path, $query), headers: $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function post(string $path, mixed $json = null, array $headers = []): ApiResponse
    {
        return $this->request('POST', $path, $json, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function put(string $path, mixed $json = null, array $headers = []): ApiResponse
    {
        return $this->request('PUT', $path, $json, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function patch(string $path, mixed $json = null, array $headers = []): ApiResponse
    {
        return $this->request('PATCH', $path, $json, $headers);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function delete(string $path, array $query = [], array $headers = []): ApiResponse
    {
        return $this->request('DELETE', self::with($path, $query), headers: $headers);
    }

    /**
     * Sends a request with any method.
     *
     * @param mixed $json What to send as the JSON body; nothing when null.
     * @param array<string, string> $headers
     */
    public function request(string $method, string $path, mixed $json = null, array $headers = []): ApiResponse
    {
        $headers = ['Accept' => 'application/json', ...$this->headers, ...$headers];
        $body = '';
        if ($json !== null) {
            $headers = ['Content-Type' => 'application/json', ...$headers];
            $body = json_encode($json, JSON_THROW_ON_ERROR);
        }
        $request = new Request($method, $path, $headers, $body);
        if ($this->user !== null) {
            $request = $request->withAttribute(Identity::ATTRIBUTE, $this->user);
        }
        return new ApiResponse($this->app->handle($request), "{$method} {$path}");
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function with(string $path, array $query): string
    {
        return $query === [] ? $path : $path . (str_contains($path, '?') ? '&' : '?') . http_build_query($query);
    }
}
