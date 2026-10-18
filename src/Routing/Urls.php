<?php

declare(strict_types=1);

namespace Crab\Routing;

use Crab\Contracts\Routing\Router;
use Crab\Contracts\Routing\Urls as UrlsContract;

/**
 * Puts the application's address in front of a named route's path.
 */
final readonly class Urls implements UrlsContract
{
    private ?string $base;

    /**
     * @param string|null $base Where the application is reached, "https://api.example.com"; a path after the host, "/api", is kept.
     */
    public function __construct(
        private Router $router,
        ?string $base,
    ) {
        if ($base !== null && $base !== '') {
            $parts = parse_url($base);
            if (
                $parts === false
                || !isset($parts['scheme'], $parts['host'])
                || isset($parts['query'])
                || isset($parts['fragment'])
            ) {
                throw new \InvalidArgumentException(
                    "The application's URL must be a scheme and a host, with a path if it has one, such as \"https://api.example.com\"; \"{$base}\" is not.",
                );
            }
            $base = rtrim($base, '/');
        }
        $this->base = $base === '' ? null : $base;
    }

    public function to(string $name, array $parameters = [], array $query = []): string
    {
        if ($this->base === null) {
            throw new \LogicException(
                "Set app.url, such as APP_URL=https://api.example.com in .env, to write the full URL of route \"{$name}\". Router::path() gives its path without one.",
            );
        }
        return $this->base . $this->router->path($name, $parameters, $query);
    }
}
