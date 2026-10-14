<?php

declare(strict_types=1);

namespace Crab\Contracts\Routing;

/**
 * Full URLs to the application's named routes, for what is read outside
 * a request: a link in an email a job sends, a callback address given to
 * another service. The address in front is the setting "app.url".
 */
interface Urls
{
    /**
     * The URL of the named route: to('orders.show', ['id' => 12]) is
     * "https://api.example.com/orders/12" when app.url is
     * "https://api.example.com". See Router::path() for the parameters.
     *
     * @param array<string, string|int|\Stringable|\BackedEnum> $parameters The pattern's parameters, by name.
     * @param array<string, mixed> $query Added as the query string.
     */
    public function to(string $name, array $parameters = [], array $query = []): string;
}
