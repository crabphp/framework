<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Container\Container;
use Crab\Contracts\HttpClient\Client as ClientContract;
use Crab\Contracts\WorkerProvider;
use Crab\Engine\Process\Worker;
use Psr\Http\Client\ClientInterface;

/**
 * Binds the HTTP client from the "http_client" configuration section.
 * Each worker has one, which keeps its connections for the worker's life
 * and closes them when it stops.
 *
 * A client for one API, with its base URL and its key, is an object an
 * application makes and binds itself: new Client('https://api...', ...).
 */
final class HttpClientProvider implements WorkerProvider
{
    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults('http_client', [
            // What a path given to a request is added to; none, so that requests give whole URLs.
            'base_url' => '',
            // Sent with every request.
            'headers' => [],
            // Seconds a whole exchange may take, and connecting to a server.
            'timeout' => 30.0,
            'connect_timeout' => 5.0,
            // How many redirects are followed, and how many requests may be on their way to one server at once.
            'redirects' => 5,
            'connections' => 8,
            // PHP's "ssl" context options, such as ['cafile' => '/path/ca.pem'].
            'tls' => [],
            // A proxy every request goes through: "http://proxy.example:8080", with "user:password@" before the host when it asks.
            'proxy' => null,
        ]);
        $container->singleton(ClientContract::class, static function (Container $container): Client {
            $config = $container->get(Config::class);
            return new Client(
                (string) $config->get('http_client.base_url'),
                (array) $config->get('http_client.headers'),
                (float) $config->get('http_client.timeout'),
                (float) $config->get('http_client.connect_timeout'),
                (int) $config->get('http_client.redirects'),
                (int) $config->get('http_client.connections'),
                (array) $config->get('http_client.tls'),
                $config->get('http_client.proxy'),
            );
        });
        // The same object for code written against the standard, and by class.
        $container->singleton(ClientInterface::class, ClientContract::class);
        $container->singleton(Client::class, ClientContract::class);
    }

    public function start(Worker $worker, Container $container): void
    {
    }

    public function stop(Worker $worker, Container $container): void
    {
        $container->get(Client::class)->close();
    }
}
