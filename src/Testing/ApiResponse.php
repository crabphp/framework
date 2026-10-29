<?php

declare(strict_types=1);

namespace Crab\Testing;

use Psr\Http\Message\ResponseInterface;

/**
 * What an application answered a test's request with.
 */
final readonly class ApiResponse
{
    public int $status;

    /**
     * @param string $request What was asked, for a failure to say: "POST /orders".
     */
    public ResponseInterface $response;

    public function __construct(
        ResponseInterface $response,
        private string $request = '',
    ) {
        $this->status = $response->getStatusCode();
        if ($response->getBody() instanceof \Crab\Http\ChunkStream) {
            // A streamed body is read once; read it all now, so that every
            // assertion sees the whole of it.
            $this->response = $response->withBody(new \Crab\Http\Stream($response->getBody()->getContents()));
        } else {
            $this->response = $response;
        }
    }

    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function body(): string
    {
        return (string) $this->response->getBody();
    }

    /**
     * The body, decoded from JSON; or one value of it, by a path with
     * dots between the keys: json('data.0.name'), json('error.fields').
     */
    public function json(?string $path = null): mixed
    {
        try {
            $value = json_decode($this->body(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \LogicException(
                "{$this->request} answered {$this->status} with a body that is not JSON: " . self::short($this->body()),
            );
        }
        foreach ($path === null || $path === '' ? [] : explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                throw new \LogicException(
                    "{$this->request} answered without \"{$path}\": " . self::short($this->body()),
                );
            }
            $value = $value[$key];
        }
        return $value;
    }

    /**
     * The server-sent events of a streamed answer, in the order sent, each
     * by its name ("message" when it has none) and its data, decoded from
     * JSON when it is JSON:
     *
     *     [['event' => 'message', 'data' => ['token' => 'Hi']], ['event' => 'done', 'data' => ['id' => 7]]]
     *
     * @return list<array{event: string, data: mixed}>
     */
    public function events(): array
    {
        $events = [];
        foreach (preg_split('/\r\n\r\n|\n\n|\r\r/', $this->body()) ?: [] as $block) {
            $name = 'message';
            $data = null;
            foreach (preg_split('/\r\n|\r|\n/', $block) ?: [] as $line) {
                if ($line === '' || str_starts_with($line, ':')) {
                    continue;
                }
                [$field, $value] = str_contains($line, ':') ? explode(':', $line, 2) : [$line, ''];
                $value = str_starts_with($value, ' ') ? substr($value, 1) : $value;
                if ($field === 'event') {
                    $name = $value;
                } elseif ($field === 'data') {
                    $data = $data === null ? $value : $data . "\n" . $value;
                }
            }
            if ($data === null) {
                continue;
            }
            try {
                $decoded = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = $data;
            }
            $events[] = ['event' => $name, 'data' => $decoded];
        }
        return $events;
    }

    /**
     * Fails the test unless the status is the one expected, saying what
     * was answered instead: with a 422 or a 500, that is the reason.
     */
    public function assertStatus(int $status): static
    {
        $message = "{$this->request} answered {$this->status}, not {$status}: " . self::short($this->body());
        self::assertSame($status, $this->status, $message);
        return $this;
    }

    /**
     * Checks a JSON value by its dot path, including its type. A missing
     * path fails the assertion too, with the request and body as context.
     */
    public function assertJson(string $path, mixed $expected): static
    {
        $message =
            "{$this->request} answered {$this->status}: JSON path \"{$path}\" must be identical to "
            . var_export($expected, true)
            . '. Body: '
            . self::short($this->body());
        try {
            $actual = $this->json($path);
        } catch (\LogicException $error) {
            self::fail($message . ' ' . $error->getMessage());
        }
        self::assertSame($expected, $actual, $message);
        return $this;
    }

    /**
     * Checks a response header and reports the request, status and body
     * when the header is absent or has another value.
     */
    public function assertHeader(string $name, string $expected): static
    {
        $actual = $this->header($name);
        $message =
            "{$this->request} answered {$this->status}: header \"{$name}\" must be "
            . var_export($expected, true)
            . ', but was '
            . var_export($actual, true)
            . '. Body: '
            . self::short($this->body());
        if (!$this->response->hasHeader($name)) {
            self::fail($message . ' The header is not there.');
        }
        self::assertSame($expected, $actual, $message);
        return $this;
    }

    /**
     * Keeps all response comparisons strict, with the same fallback when
     * the testing helpers are used without PHPUnit installed.
     */
    private static function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if (class_exists(\PHPUnit\Framework\Assert::class)) {
            \PHPUnit\Framework\Assert::assertSame($expected, $actual, $message);
        } elseif ($actual !== $expected) {
            throw new \LogicException($message);
        }
    }

    /**
     * Turns an unreadable assertion value into a test failure rather than
     * leaking json()'s LogicException as a test error.
     */
    private static function fail(string $message): never
    {
        if (class_exists(\PHPUnit\Framework\Assert::class)) {
            \PHPUnit\Framework\Assert::fail($message);
        }
        throw new \LogicException($message);
    }

    private static function short(string $body): string
    {
        return strlen($body) > 600 ? substr($body, 0, 600) . '…' : $body;
    }
}
