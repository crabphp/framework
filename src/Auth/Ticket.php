<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Auth\Tickets;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Http\UsesContainer;
use Crab\Http\ErrorResponse;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authenticates a browser's single-use ticket, or delegates to TokenAuth
 * when there is no ticket parameter. A present invalid ticket never
 * falls back to a different credential or an existing request identity.
 */
final readonly class Ticket implements Middleware, UsesContainer
{
    /**
     * @param list<string> $origins Exact allowed Origin values; an empty list disables the check.
     * @param Tickets|null $tickets Taken from the container when given as route options.
     * @param TokenAuth|null $tokens Header authentication for clients without a ticket.
     */
    public function __construct(
        private array $origins = [],
        private ?Tickets $tickets = null,
        private ?TokenAuth $tokens = null,
    ) {
    }

    public function withContainer(ContainerInterface $container): static
    {
        return new self(
            $this->origins,
            $this->tickets ?? $container->get(Tickets::class),
            $this->tokens ?? $container->get(TokenAuth::class),
        );
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->origins !== [] && !in_array($request->getHeaderLine('Origin'), $this->origins, true)) {
            return ErrorResponse::make($request, 403, 'This origin is not allowed.');
        }
        if ($this->tickets === null || $this->tokens === null) {
            throw new \LogicException('Ticket authentication needs its services. Register AuthProvider, CacheProvider and TokenAuthProvider, and put Ticket on a route or supply its tickets and tokens.');
        }
        $query = $request->getQueryParams();
        if (!array_key_exists('ticket', $query)) {
            return $this->tokens->process($request, $handler);
        }
        $user = is_string($query['ticket']) ? $this->tickets->redeem($query['ticket']) : null;
        if ($user === null) {
            return ErrorResponse::make($request, 401, 'Authentication is required.', ['WWW-Authenticate' => 'Bearer']);
        }
        return $handler->handle($request->withAttribute(Identity::ATTRIBUTE, $user));
    }
}
