<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Http\Request;
use Crab\Contracts\Http\UsesContainer;
use Crab\Engine\FailureReporter;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Records each request after the response is made, without its query
 * string, which may contain secrets. It is off until added as middleware:
 *
 *     ->withMiddleware(prepend: [RequestId::class, AccessLog::class])
 *
 * Request ids and users are read from the request received here, as in
 * ErrorHandler. Put middleware that supplies them before this one; an
 * immutable request changed inside it cannot pass its attributes back.
 */
final readonly class AccessLog implements Middleware, UsesContainer
{
    /**
     * PSR-3 level names, checked before the middleware serves a request.
     */
    private const LEVELS = [
        LogLevel::DEBUG,
        LogLevel::INFO,
        LogLevel::NOTICE,
        LogLevel::WARNING,
        LogLevel::ERROR,
        LogLevel::CRITICAL,
        LogLevel::ALERT,
        LogLevel::EMERGENCY,
    ];

    /**
     * @param list<string> $skip Exact paths to leave out, such as a health check.
     * @param string $level The PSR-3 level used for every access record.
     * @param LoggerInterface|null $logger Where records go; taken from the container when middleware is given with options.
     */
    public function __construct(
        private array $skip = [],
        private string $level = LogLevel::INFO,
        private ?LoggerInterface $logger = null,
    ) {
        if (!in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException(
                '"'
                . $level
                . '" is not a log level. Set the access log level to one of '
                . implode(', ', self::LEVELS)
                . '.',
            );
        }
    }

    /**
     * Supplies the application's logger while preserving the options.
     */
    public function withContainer(ContainerInterface $container): static
    {
        if ($this->logger !== null) {
            return $this;
        }
        if (!$container->has(LoggerInterface::class)) {
            throw new \LogicException(
                'An access log needs a PSR-3 logger: register a log provider that binds Psr\Log\LoggerInterface.',
            );
        }
        return new self($this->skip, $this->level, $container->get(LoggerInterface::class));
    }

    /**
     * Times the inner handler and records its response, or 500 when an
     * exception escapes. The exception continues to the caller unchanged.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->logger === null) {
            throw new \LogicException(
                'This access log has no logger. Register a log provider that binds Psr\Log\LoggerInterface and put it in withMiddleware(), or give it one: new AccessLog(logger: $logger).',
            );
        }
        try {
            $path = $request->getUri()->getPath();
        } catch (\Throwable $error) {
            FailureReporter::report('HTTP access log failed', $error);
            return $handler->handle($request);
        }
        if (in_array($path, $this->skip, true)) {
            return $handler->handle($request);
        }
        $started = hrtime(true);
        $response = null;
        try {
            $response = $handler->handle($request);
            return $response;
        } finally {
            try {
                $ms = round((hrtime(true) - $started) / 1e6, 1);
                $method = $request->getMethod();
                $status = $response?->getStatusCode() ?? 500;
                $context = [
                    'method' => $method,
                    'path' => $path,
                    'status' => $status,
                    'ms' => $ms,
                    'ip' => $request instanceof Request
                        ? $request->ip()
                        : $request->getServerParams()['REMOTE_ADDR'] ?? '',
                    'bytes' => $response?->getBody()->getSize(),
                ];
                $id = $request->getAttribute(RequestId::ATTRIBUTE);
                if ($id !== null) {
                    $context['request_id'] = $id;
                }
                $user = $request->getAttribute(Identity::ATTRIBUTE);
                if ($user instanceof Identity) {
                    $context['user'] = $user->id;
                }
                $message = "{$method} {$path} {$status} " . number_format($ms, 1, '.', '') . 'ms';
                $this->logger->log($this->level, $message, $context);
            } catch (\Throwable $error) {
                FailureReporter::report('HTTP access log failed', $error);
            }
        }
    }
}
