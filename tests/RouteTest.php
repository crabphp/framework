<?php

declare(strict_types=1);

namespace Crab\Tests;

use Crab\App;
use Crab\Command;
use Crab\Console\Output;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output as OutputContract;
use Crab\Http\Request;
use Crab\Route;
use PHPUnit\Framework\TestCase;

final class Ping
{
    public function show(int $id): array
    {
        return ['id' => $id];
    }
}

final class RouteTest extends TestCase
{
    public function testRoutesAndCommandsAreDeclaredOnTheCurrentApplication(): void
    {
        $first = new App();
        $app = new App();
        Route::get('/', static fn (): string => 'home');
        Route::post('/ping/{id}', [Ping::class, 'show']);
        Route::put('/a', static fn (): string => 'put');
        Route::get('/chained', static fn (): string => 'chained');
        Route::patch('/a', static fn (): string => 'patch');
        Route::delete('/a', static fn (): string => 'delete');
        Route::add('OPTIONS', '/a', static fn (): string => 'options');

        self::assertSame('home', (string) $app->handle(new Request('GET', '/'))->getBody());
        self::assertSame('{"id":7}', (string) $app->handle(new Request('POST', '/ping/7'))->getBody());
        foreach (['PUT' => 'put', 'PATCH' => 'patch', 'DELETE' => 'delete', 'OPTIONS' => 'options'] as $method => $body) {
            self::assertSame($body, (string) $app->handle(new Request($method, '/a'))->getBody());
        }
        self::assertSame('chained', (string) $app->handle(new Request('GET', '/chained'))->getBody());
        self::assertInstanceOf(\Crab\Contracts\Routing\DeclaredRoute::class, Route::get('/same', static fn (): string => '')->name('same'));
        self::assertSame('/same', $app->router->path('same'), 'It is declared on the application\'s own router.');
        self::assertSame([], $first->router->routes(), 'Only the application created last receives them.');

        Command::add('hello', static function (Input $input, OutputContract $output): int {
            $output->line('Hello, ' . $input->argument(0, 'world'));
            return 0;
        }, 'Say hello');
        $out = fopen('php://memory', 'w+');
        self::assertSame(0, $app->console->run(['hello', 'Crab'], new Output($out, $out)));
        rewind($out);
        self::assertSame("Hello, Crab\n", stream_get_contents($out));
        self::assertFalse($first->console->has('hello'));
    }

    public function testARouteIsNamedWhereItIsDeclared(): void
    {
        $app = new App();
        Route::get('/orders/{id}', static fn(): string => 'order')->name('orders.show');
        Route::prefix('/admin')->group(static function (): void {
            Route::put('/orders/{id}/ship', static fn(): string => 'shipped')->name('admin.orders.ship');
        });

        self::assertSame('/orders/9', $app->router->path('orders.show', ['id' => 9]));
        self::assertSame('PUT', $app->router->route('admin.orders.ship')?->method);
        self::assertSame('/admin/orders/9/ship', $app->router->path('admin.orders.ship', ['id' => 9]));
    }

    public function testThereMustBeAnApplicationFirst(): void
    {
        $current = new \ReflectionProperty(App::class, 'current');
        $before = $current->getValue();
        $current->setValue(null, null);
        try {
            $refused = 0;
            foreach ([static fn () => Route::get('/', static fn (): string => ''), static fn () => Command::add('x', static fn (): int => 0)] as $attempt) {
                try {
                    $attempt();
                } catch (\LogicException $error) {
                    self::assertStringContainsString('Create the App before', $error->getMessage());
                    ++$refused;
                }
            }
            self::assertSame(2, $refused);
        } finally {
            $current->setValue(null, $before);
        }
    }
}
