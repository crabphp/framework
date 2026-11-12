<?php

declare(strict_types=1);

namespace Crab\Tests\Session;

use Crab\App;
use Crab\Config\Config;
use Crab\Contracts\Session\Driver;
use Crab\Contracts\Session\Session;
use Crab\Http\Request;
use Crab\Http\Response;
use Crab\Route;
use Crab\Session\CookieDriver;
use Crab\Session\SessionPlugin;
use Crab\Session\StartSession;
use Crab\Session\StoreDriver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * A form's action as an application writes one: it saves, flashes a message
 * and goes back to the page that shows it.
 */
final readonly class SaveProfile
{
    public function handle(Session $session): Response
    {
        $session->put('name', 'Ada');
        $session->flash('status', 'Profile saved.');
        return Response::redirect('/profile', 303);
    }
}

final class SessionPluginTest extends TestCase
{
    private ?string $cookie = null;

    public function testAnActionTakesTheSessionThatStartSessionGivesIt(): void
    {
        $app = self::app(['session' => ['driver' => 'memory', 'secure' => false]]);
        Route::middleware(StartSession::class)->group(static function (): void {
            Route::post('/profile', SaveProfile::class);
            Route::get(
                '/profile',
                static fn(Session $session): string => (
                    $session->get('name', 'nobody') . ' ' . $session->get('status', '-')
                ),
            );
        });

        self::assertSame('nobody -', $this->get($app, '/profile'));
        $saved = $this->send($app, 'POST', '/profile');
        self::assertSame(303, $saved->getStatusCode());
        self::assertMatchesRegularExpression(
            '/^session=[0-9a-f]{64}; Max-Age=7200; Path=\/; HttpOnly; SameSite=Lax$/',
            $saved->getHeaderLine('Set-Cookie'),
        );
        self::assertSame('Ada Profile saved.', $this->get($app, '/profile'));
        self::assertSame('Ada -', $this->get($app, '/profile'), 'The flash was for one request.');
    }

    public function testTheDriversComeFromTheSettings(): void
    {
        $base = sys_get_temp_dir() . '/crab-session-app-' . bin2hex(random_bytes(6));
        $file = new App(new Config(), storagePath: $base);
        $file->register(SessionPlugin::class);
        self::assertInstanceOf(StoreDriver::class, $file->container->get(Driver::class));

        $cookie = self::app(['session' => ['driver' => 'cookie'], 'app' => ['key' => str_repeat('k', 32)]]);
        self::assertInstanceOf(CookieDriver::class, $cookie->container->get(Driver::class));

        try {
            self::app(['session' => ['driver' => 'cookie']])->container->get(Driver::class);
            self::fail('The cookie driver was made without a key.');
        } catch (\LogicException $error) {
            self::assertStringContainsString(
                'signs its cookie with app.key: run php cua key:generate',
                $error->getMessage(),
            );
        }
        try {
            self::app([])->container->get(Driver::class);
            self::fail('File sessions were given a folder no one chose.');
        } catch (\LogicException $error) {
            self::assertStringContainsString(
                'File sessions need a folder: set session.directory',
                $error->getMessage(),
            );
        }
    }

    public function testAnActionThatTakesTheSessionNeedsStartSessionAroundIt(): void
    {
        $app = self::app([
            'session' => ['driver' => 'memory'],
            'app' => ['debug' => true],
            'log' => ['path' => '/dev/null'],
        ]);
        Route::get('/bare', static fn(Session $session): string => (string) $session->get('a'));
        $response = $app->handle(new Request('GET', 'http://shop.test/bare'));
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('no StartSession runs around its route', (string) $response->getBody());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('type it as Crab\\Contracts\\Session\\Session');
        Route::get('/class', static fn(\Crab\Session\Session $session): string => '');
    }

    public function testTheMemoryDriverIsRefusedWhereMoreThanOneWorkerServes(): void
    {
        $app = self::app(['session' => ['driver' => 'memory'], 'server' => ['workers' => 2]]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The memory session driver keeps sessions in one worker');
        $app->runtime('tcp://127.0.0.1:0');
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function app(array $settings): App
    {
        $app = new App(new Config($settings));
        $app->register(SessionPlugin::class);
        return $app;
    }

    private function get(App $app, string $path): string
    {
        return (string) $this->send($app, 'GET', $path)->getBody();
    }

    /**
     * Sends a request as a browser would: with the cookie the last answer set.
     */
    private function send(App $app, string $method, string $path): ResponseInterface
    {
        $headers = ['Origin' => 'http://shop.test'];
        if ($this->cookie !== null) {
            $headers['Cookie'] = $this->cookie;
        }
        $response = $app->handle(new Request($method, "http://shop.test{$path}", $headers));
        $set = $response->getHeaderLine('Set-Cookie');
        if ($set !== '') {
            $this->cookie = explode(';', $set, 2)[0];
        }
        return $response;
    }
}
