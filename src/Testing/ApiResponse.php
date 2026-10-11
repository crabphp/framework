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
    public function __construct(public ResponseInterface $response, private string $request = '')
    {
        $this->status = $response->getStatusCode();
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
            throw new \LogicException("{$this->request} answered {$this->status} with a body that is not JSON: " . self::short($this->body()));
        }
        foreach ($path === null || $path === '' ? [] : explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                throw new \LogicException("{$this->request} answered without \"{$path}\": " . self::short($this->body()));
            }
            $value = $value[$key];
        }
        return $value;
    }

    /**
     * Fails the test unless the status is the one expected, saying what
     * was answered instead: with a 422 or a 500, that is the reason.
     */
    public function assertStatus(int $status): static
    {
        $message = "{$this->request} answered {$this->status}, not {$status}: " . self::short($this->body());
        if (class_exists(\PHPUnit\Framework\Assert::class)) {
            \PHPUnit\Framework\Assert::assertSame($status, $this->status, $message);
        } elseif ($this->status !== $status) {
            throw new \LogicException($message);
        }
        return $this;
    }

    private static function short(string $body): string
    {
        return strlen($body) > 600 ? substr($body, 0, 600) . '…' : $body;
    }
}
