<?php

declare(strict_types=1);

namespace Crab\Tests\Http;

use Crab\Container\Container;
use Crab\Http\ClosureHandler;
use Crab\Http\Factory;
use Crab\Http\MiddlewareStack;
use Crab\Http\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Mark implements MiddlewareInterface
{
    public static int $made = 0;

    public function __construct(private string $mark = 'named')
    {
        ++self::$made;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute('marks', $request->getAttribute('marks', '') . $this->mark . ' '));
    }
}

final class MiddlewareStackTest extends TestCase
{
    public function testAddsInOrderAndLinksOnce(): void
    {
        Mark::$made = 0;
        $handler = new ClosureHandler(static fn(ServerRequestInterface $request) => (string) $request->getAttribute('marks', 'none'));
        $stack = new MiddlewareStack($handler, new Container());
        self::assertSame('none', (string) $stack->handle(new Request('GET', '/'))->getBody());

        $stack->add(new Mark('first'))->add(Mark::class, new Mark('last'));
        self::assertSame(2, Mark::$made, 'Named middleware is made when the stack is linked.');
        $pipeline = $stack->assemble();
        self::assertSame(3, Mark::$made);
        self::assertSame('first named last ', (string) $stack->handle(new Request('GET', '/'))->getBody());
        self::assertSame('first named last ', (string) $pipeline->handle(new Request('GET', '/'))->getBody());
        self::assertSame(3, Mark::$made);

        $stack->add(new Mark('later'));
        self::assertSame('first named last later ', (string) $stack->handle(new Request('GET', '/'))->getBody());
    }

    public function testNamesNeedAContainerAndAMiddleware(): void
    {
        $handler = new ClosureHandler(static fn() => 'ok');
        try {
            (new MiddlewareStack($handler))->add(Mark::class);
            self::fail('A name was accepted without a container.');
        } catch (\LogicException) {
        }
        $this->expectException(\LogicException::class);
        (new MiddlewareStack($handler, new Container()))->add(Factory::class)->assemble();
    }

    public function testDeclaredMiddlewareCanBeFoundWithoutConstructingIt(): void
    {
        $handler = new ClosureHandler(static fn () => 'ok');
        $named = new MiddlewareStack($handler, new Container());
        self::assertFalse($named->has(Mark::class));
        $made = Mark::$made;
        $named->add(Mark::class);
        self::assertTrue($named->has(Mark::class));
        self::assertSame($made, Mark::$made);
        $objects = new MiddlewareStack($handler);
        $objects->add(new Mark());
        self::assertTrue($objects->has(Mark::class));
        self::assertFalse($objects->has(\Crab\Http\AccessLog::class));
    }
}
