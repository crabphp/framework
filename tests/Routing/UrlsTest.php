<?php

declare(strict_types=1);

namespace Crab\Tests\Routing;

use Crab\App;
use Crab\Config\Config;
use Crab\Contracts\Routing\Urls as UrlsContract;
use Crab\Routing\Router;
use Crab\Routing\Urls;
use PHPUnit\Framework\TestCase;

final class UrlsTest extends TestCase
{
    public function testAFullUrlIsTheApplicationsAddressAndTheRoutesPath(): void
    {
        $router = new Router();
        $router->get('/orders/{id}', static fn() => 'order')->name('orders.show');

        self::assertSame('https://api.example.com/orders/12', new Urls(
            $router,
            'https://api.example.com',
        )->to('orders.show', ['id' => 12]));
        self::assertSame('https://api.example.com/orders/12?page=2', new Urls($router, 'https://api.example.com/')->to(
            'orders.show',
            ['id' => 12],
            ['page' => 2],
        ));
        self::assertSame('http://127.0.0.1:8080/v1/orders/3', new Urls(
            $router,
            'http://127.0.0.1:8080/v1',
        )->to('orders.show', ['id' => 3]));
    }

    public function testWithoutAnAddressItSaysWhatToSet(): void
    {
        $router = new Router();
        $router->get('/orders/{id}', static fn() => 'order')->name('orders.show');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Set app.url');
        new Urls($router, null)->to('orders.show', ['id' => 1]);
    }

    public function testAnAddressThatIsNotOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"api.example.com" is not');
        new Urls(new Router(), 'api.example.com');
    }

    public function testTheApplicationGivesUrlsFromItsSetting(): void
    {
        $app = new App(new Config(['app' => ['url' => 'https://shop.example']]));
        $app->router->get('/products/{id}', static fn() => 'product')->name('products.show');

        $urls = $app->container->get(UrlsContract::class);
        self::assertSame($urls, $app->container->get(Urls::class));
        self::assertSame('https://shop.example/products/5', $urls->to('products.show', ['id' => 5]));
    }
}
