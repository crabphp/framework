<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Auth\TokenStore;
use Crab\Contracts\Http\Middleware;
use Crab\Http\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware that lets a request through when it carries a token of a
 * known user, and passes that user on as the request's "user" attribute.
 * Any other request is answered with 401.
 *
 * As it comes it reads the standard "Authorization: Bearer <token>". Name
 * another header, or another word before the token, for clients that send
 * it differently:
 *
 *     new TokenAuth($tokens, $users);                          Authorization: Bearer <token>
 *     new TokenAuth($tokens, $users, 'X-Api-Key', '');         X-Api-Key: <token>
 *     new TokenAuth($tokens, $users, scheme: 'Token');         Authorization: Token <token>
 *
 * It is one way of authenticating, not the way: anything that puts an
 * Identity on the request's "user" attribute is another, and is written
 * as middleware like this one.
 */
final readonly class TokenAuth implements Middleware
{
    /**
     * @param string $header The header the token is sent in.
     * @param string $scheme The word that comes before the token, or '' when the header holds the token alone.
     * @param bool $required Whether a request without a valid token is refused. When not, it goes through with no user, for routes that work either way.
     */
    public function __construct(
        private TokenStore $tokens,
        private IdentityResolver $users,
        private string $header = 'Authorization',
        private string $scheme = 'Bearer',
        private bool $required = true,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute(Identity::ATTRIBUTE) instanceof Identity) {
            // Whoever put the user there, middleware before this one or a test, has said who it is.
            return $handler->handle($request);
        }
        $user = $this->user($request);
        if ($user !== null) {
            return $handler->handle($request->withAttribute(Identity::ATTRIBUTE, $user));
        }
        if (!$this->required) {
            return $handler->handle($request);
        }
        // What a client is told to send, as the standard asks of a 401.
        $challenge = $this->scheme !== '' ? $this->scheme : $this->header;
        return ErrorResponse::make($request, 401, 'Authentication is required.', ['WWW-Authenticate' => $challenge]);
    }

    private function user(ServerRequestInterface $request): ?Identity
    {
        $token = trim($request->getHeaderLine($this->header));
        if ($this->scheme !== '') {
            $prefix = $this->scheme . ' ';
            if (strncasecmp($token, $prefix, strlen($prefix)) !== 0) {
                return null;
            }
            $token = trim(substr($token, strlen($prefix)));
        }
        if ($token === '') {
            return null;
        }
        $owner = $this->tokens->owner($token);
        return $owner === null ? null : $this->users->resolve($owner);
    }
}
