<?php

declare(strict_types=1);

namespace Crab\Testing;

use Crab\Contracts\Auth\Identity;
use Crab\Contracts\HttpClient\File;
use Crab\Http\Multipart;
use Crab\Http\Request;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
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
 *     $api->actingAs($ada)->post('/me/avatar', files: ['avatar' => File::fromPath($picture)]);
 *     $api->put('/saves/1', body: $bytes, headers: ['Content-Type' => 'application/octet-stream']);
 *
 * A request goes through the application's middleware, router and
 * validation, as $app->handle() does, and its body through the same
 * parsing a request from a client goes through. Data is sent as JSON
 * unless a form, files or a body is given, and JSON is asked for, so an
 * error comes back as JSON too.
 */
final readonly class Api
{
    /**
     * @param RequestHandlerInterface $app The application, or any PSR-15 handler.
     * @param array<string, string> $headers Sent with every request.
     */
    private SocketFixture $sockets;

    public function __construct(
        private RequestHandlerInterface $app,
        private array $headers = [],
        private ?Identity $user = null,
        ?SocketFixture $sockets = null,
    ) {
        $this->sockets = $sockets ?? new SocketFixture();
    }

    /**
     * The same, with every request made as this user: the user is put
     * on the request as authentication would, and middleware that
     * authenticates lets it through without a token.
     */
    public function actingAs(Identity $user): self
    {
        return new self($this->app, $this->headers, $user, $this->sockets);
    }

    /**
     * The same, with these headers on every request as well.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->app, [...$this->headers, ...$headers], $this->user, $this->sockets);
    }

    /**
     * Sends a token on every request when a test exercises authentication
     * itself. An empty scheme sends the token alone in Authorization.
     */
    public function withToken(#[\SensitiveParameter] string $token, string $scheme = 'Bearer'): static
    {
        $value = $scheme === '' ? $token : "{$scheme} {$token}";
        return $this->withHeaders(['Authorization' => $value]);
    }

    /**
     * Opens the real engine session over a socket pair. A refusal throws
     * WebSocketRefused carrying the HTTP ApiResponse for assertions.
     *
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function websocket(string $path, array $query = [], array $headers = []): TestSocket
    {
        $request = new Request('GET', self::with($path, $query), [
            'Host' => 'localhost',
            'Accept' => 'application/json',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
            ...$this->headers,
            ...$headers,
        ]);
        if ($this->user !== null) {
            $request = $request->withAttribute(Identity::ATTRIBUTE, $this->user);
        }
        return $this->sockets->own(TestSocket::connect($this->app, $request));
    }

    /**
     * Ends every socket opened by this fixture and its header/user variants.
     * Api also does this when the fixture leaves scope.
     */
    public function closeSockets(): void
    {
        $this->sockets->close();
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
     * @param array<string, mixed> $form
     * @param array<string, File|UploadedFileInterface|list<File|UploadedFileInterface>> $files
     */
    public function post(
        string $path,
        mixed $json = null,
        array $headers = [],
        array $form = [],
        array $files = [],
        string|StreamInterface|null $body = null,
    ): ApiResponse {
        return $this->request('POST', $path, $json, $headers, $form, $files, $body);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $form
     * @param array<string, File|UploadedFileInterface|list<File|UploadedFileInterface>> $files
     */
    public function put(
        string $path,
        mixed $json = null,
        array $headers = [],
        array $form = [],
        array $files = [],
        string|StreamInterface|null $body = null,
    ): ApiResponse {
        return $this->request('PUT', $path, $json, $headers, $form, $files, $body);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $form
     * @param array<string, File|UploadedFileInterface|list<File|UploadedFileInterface>> $files
     */
    public function patch(
        string $path,
        mixed $json = null,
        array $headers = [],
        array $form = [],
        array $files = [],
        string|StreamInterface|null $body = null,
    ): ApiResponse {
        return $this->request('PATCH', $path, $json, $headers, $form, $files, $body);
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
     * Sends a request with any method. It has one body: JSON, a form
     * (form: and files:, which make it multipart/form-data) or the bytes
     * of body:, as the HTTP client's request() takes them.
     *
     * @param mixed $json What to send as the JSON body; nothing when null.
     * @param array<string, string> $headers
     * @param array<string, mixed> $form Sent as a form; together with files, as that form's other fields.
     * @param array<string, File|UploadedFileInterface|list<File|UploadedFileInterface>> $files Sent as the form's file fields.
     * @param string|StreamInterface|null $body Sent as it is; say its Content-Type in $headers.
     */
    public function request(
        string $method,
        string $path,
        mixed $json = null,
        array $headers = [],
        array $form = [],
        array $files = [],
        string|StreamInterface|null $body = null,
    ): ApiResponse {
        if ((($json !== null) + ($form !== [] || $files !== []) + ($body !== null)) > 1) {
            throw new \LogicException(
                'A request has one body: give json:, a form (form: and files:) or body:, not several.',
            );
        }
        $headers = ['Accept' => 'application/json', ...$this->headers, ...$headers];
        if ($json !== null) {
            $headers = ['Content-Type' => 'application/json', ...$headers];
            $body = json_encode($json, JSON_THROW_ON_ERROR);
        } elseif ($files !== []) {
            [$body, $type] = Multipart::write($form, $files);
            // Only this type names the boundary the body was written with.
            $headers = [
                ...array_filter(
                    $headers,
                    static fn(string $name): bool => strtolower($name) !== 'content-type',
                    ARRAY_FILTER_USE_KEY,
                ),
                'Content-Type' => $type,
            ];
        } elseif ($form !== []) {
            $headers = ['Content-Type' => 'application/x-www-form-urlencoded', ...$headers];
            $body = http_build_query($form);
        }
        $request = new Request($method, $path, $headers, $body ?? '');
        if ($this->user !== null) {
            $request = $request->withAttribute(Identity::ATTRIBUTE, $this->user);
        }
        $response = $this->app->handle($request);
        if ($response instanceof \Crab\Http\UpgradeResponse) {
            $response->discard();
            if (\Fiber::getCurrent() === null) {
                $loop = \Crab\Engine\EventLoop::current();
                $loop->defer($loop->stop(...));
                $loop->run();
            }
        }
        return new ApiResponse($response, "{$method} {$path}");
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function with(string $path, array $query): string
    {
        return $query === [] ? $path : $path . (str_contains($path, '?') ? '&' : '?') . http_build_query($query);
    }
}
