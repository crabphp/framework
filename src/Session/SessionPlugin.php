<?php

declare(strict_types=1);

namespace Crab\Session;

use Crab\Cache\ArrayStore;
use Crab\Cache\FileStore;
use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Console;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Plugin;
use Crab\Contracts\Session\Driver;
use Crab\Session\Commands\PruneSessions;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Binds where sessions are kept from the "session" configuration section,
 * and the StartSession middleware that gives each request its session:
 *
 *     driver     "file", "cookie" or "memory"; file unless set
 *     lifetime   seconds a session lasts without being used; 7200
 *     directory  the file driver's folder; storage/sessions in the application
 *     key        the secret the cookie driver signs with, at least 32 bytes
 *     cookie     the cookie's name; "__Host-session" when secure, "session" otherwise
 *     secure     whether the cookie is sent only over HTTPS; true
 *     lottery    the chances a write sweeps expired sessions; [2, 100], [0, 1] never
 *
 * The memory driver keeps sessions in one worker and is refused where more
 * than one serves. To keep sessions elsewhere, bind Contracts\Session\Driver
 * to a driver of your own after registering this plugin.
 */
final class SessionPlugin implements Plugin
{
    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults(
            'session',
            [
                'driver' => 'file',
                'lifetime' => 7_200,
                'directory' => null,
                'key' => null,
                'cookie' => null,
                'secure' => true,
                'lottery' => [2, 100],
            ],
            [
                'driver' => [
                    'enum' => ['file', 'cookie', 'memory'],
                    'description' => '"redis" is one more, with crabphp/redis and its RedisPlugin registered after this plugin.',
                ],
                'lifetime' => ['type' => 'integer', 'minimum' => 1],
                'directory' => ['type' => ['string', 'null'], 'minLength' => 1],
                'key' => ['type' => ['string', 'null'], 'minLength' => 32],
                'cookie' => ['type' => ['string', 'null'], 'pattern' => '^[A-Za-z0-9_.-]+$'],
                'secure' => ['type' => 'boolean'],
                'lottery' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 0],
                    'minItems' => 2,
                    'maxItems' => 2,
                    'description' => 'The chances and what they are out of, such as [2, 100].',
                ],
            ],
        );
        $container->singleton(Driver::class, static function (Container $container): Driver {
            $config = $container->get(Config::class);
            $config->check('session');
            $driver = (string) $config->get('session.driver');
            if ($driver === 'file') {
                $directory = $config->get('session.directory');
                if ($directory === null) {
                    // Never a shared temp folder: another account could make it first and fill it.
                    $storage = $config->get('app.storage');
                    if (!is_string($storage)) {
                        throw new \LogicException(
                            'File sessions need a folder: set session.directory, or build the application with App::configure($basePath).',
                        );
                    }
                    $directory = $storage . '/sessions';
                }
                return new StoreDriver(new FileStore((string) $directory));
            }
            if ($driver === 'memory') {
                return new StoreDriver(new ArrayStore());
            }
            if ($driver === 'cookie') {
                $key = $config->get('session.key');
                if (!is_string($key)) {
                    throw new \LogicException(
                        'The cookie session driver signs its cookie with session.key: set it to a secret of at least 32 bytes, such as one made with php -r \'echo bin2hex(random_bytes(32)), PHP_EOL;\'.',
                    );
                }
                return new CookieDriver($key);
            }
            throw new \LogicException(
                "\"session.driver\" is \"{$driver}\", and the drivers are \"file\", \"cookie\" and \"memory\". For \"redis\", install crabphp/redis and register its RedisPlugin after this one; for another, bind "
                . Driver::class
                . ' to a driver of your own.',
            );
        });
        $container->singleton(StartSession::class, static function (Container $container): StartSession {
            $config = $container->get(Config::class);
            $config->check('session');
            $cookie = $config->get('session.cookie');
            /** @var array{int, int} $lottery */
            $lottery = array_values((array) $config->get('session.lottery'));
            return new StartSession(
                $container->get(Driver::class),
                (int) $config->get('session.lifetime'),
                is_string($cookie) ? $cookie : null,
                (bool) $config->get('session.secure'),
                $lottery,
                $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : null,
                $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
            );
        });
        if ($container->has(Console::class)) {
            $container->get(Console::class)->add('session:prune', PruneSessions::class);
        }
    }
}
