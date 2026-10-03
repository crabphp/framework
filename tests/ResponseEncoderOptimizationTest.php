<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Http\Body;
use Crab\Engine\Http\Request;
use Crab\Engine\Http\Response;
use Crab\Engine\Http\ResponseEncoder;
use Crab\Engine\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

final class ResponseEncoderOptimizationTest extends TestCase
{
    #[DataProvider('cachedSecondProvider')]
    public function testGeneratedDateRefreshesWhenTheClockMovesToAnotherSecond(int $offset): void
    {
        $cachedSecond = time() + $offset;
        $this->seedDateCache($cachedSecond, self::httpDate($cachedSecond));
        $before = time();
        $wire = self::encode(new Response(body: 'ok'));
        $after = time();

        self::assertContains(self::dateHeader($wire), [self::httpDate($before), self::httpDate($after)]);
        self::assertNotSame(self::httpDate($cachedSecond), self::dateHeader($wire));
        self::assertStringEndsWith("\r\n\r\nok", $wire);
    }

    public static function cachedSecondProvider(): iterable
    {
        yield 'forward rollover' => [-60];
        yield 'backward correction' => [60];
    }

    public function testExplicitDateBypassesTheCacheAndDoesNotLeakIntoLaterResponses(): void
    {
        $cachedSecond = time() - 60;
        $cachedDate = self::httpDate($cachedSecond);
        $this->seedDateCache($cachedSecond, $cachedDate);
        $explicit = 'Sat, 03 Oct 2020 12:34:56 GMT';
        $wire = self::encode(new Response(headers: ['DaTe' => $explicit], body: 'explicit'));

        self::assertSame($explicit, self::dateHeader($wire));
        self::assertSame($cachedSecond, (new ReflectionProperty(ResponseEncoder::class, 'dateSecond'))->getValue());
        self::assertSame($cachedDate, (new ReflectionProperty(ResponseEncoder::class, 'date'))->getValue());

        $before = time();
        $next = self::encode(new Response(body: 'generated'));
        $after = time();
        self::assertContains(self::dateHeader($next), [self::httpDate($before), self::httpDate($after)]);
        self::assertNotSame($explicit, self::dateHeader($next));
    }

    #[DataProvider('connectionProvider')]
    public function testResponseConnectionTokensPreserveCloseDecisions(array $headers, bool $expectedClose): void
    {
        $encoder = new ResponseEncoder(new Response(headers: $headers, body: 'ok'), self::request());
        $wire = implode('', iterator_to_array($encoder->bytes()));

        self::assertSame($expectedClose, $encoder->close);
        self::assertStringContainsString("\r\nconnection: " . ($expectedClose ? 'close' : 'keep-alive') . "\r\n", $wire);
        self::assertStringEndsWith("\r\n\r\nok", $wire);
    }

    public static function connectionProvider(): iterable
    {
        yield 'absent' => [[], false];
        yield 'empty list' => [['Connection' => []], false];
        yield 'keep alive' => [['Connection' => 'keep-alive'], false];
        yield 'close' => [['Connection' => 'close'], true];
        yield 'mixed case token list' => [['cOnNeCtIoN' => "upgrade, \tCloSe\t"], true];
        yield 'multiple field values' => [['Connection' => ['keep-alive', 'upgrade, close']], true];
        yield 'token substring is not close' => [['Connection' => 'disclose, close-extra'], false];
    }

    public function testRequestAndForcedCloseStillApplyWithoutAResponseConnectionHeader(): void
    {
        $requestClose = new ResponseEncoder(new Response(body: 'ok'), self::request(['connection' => ['close']]));
        $forced = new ResponseEncoder(new Response(body: 'ok'), self::request(), forceClose: true);

        self::assertTrue($requestClose->close);
        self::assertTrue($forced->close);
    }

    public function testUpgradePreservesItsConnectionHeaderDespiteOtherCloseReasons(): void
    {
        $encoder = new ResponseEncoder(
            new Response(101, ['Connection' => 'Upgrade', 'Upgrade' => 'test'], 'hidden'),
            self::request(['connection' => ['close']]),
            forceClose: true,
            upgrade: true,
        );
        $wire = implode('', iterator_to_array($encoder->bytes()));

        self::assertFalse($encoder->close);
        self::assertStringContainsString("\r\nconnection: Upgrade\r\n", $wire);
        self::assertStringContainsString("\r\nupgrade: test\r\n", $wire);
        self::assertStringNotContainsString('hidden', $wire);
        self::assertStringNotContainsString('content-length:', $wire);
        self::assertStringNotContainsString('transfer-encoding:', $wire);
        self::assertMatchesRegularExpression('/ GMT$/', self::dateHeader($wire));
    }

    private function seedDateCache(int $second, string $date): void
    {
        $secondProperty = new ReflectionProperty(ResponseEncoder::class, 'dateSecond');
        $dateProperty = new ReflectionProperty(ResponseEncoder::class, 'date');
        $previousSecond = $secondProperty->getValue();
        $previousDate = $dateProperty->getValue();
        $this->deferCleanup(static function () use ($secondProperty, $dateProperty, $previousSecond, $previousDate): void {
            $secondProperty->setValue(null, $previousSecond);
            $dateProperty->setValue(null, $previousDate);
        });
        $secondProperty->setValue(null, $second);
        $dateProperty->setValue(null, $date);
    }

    private static function request(array $headers = []): Request
    {
        return new Request('GET', '/', '1.1', ['host' => ['test'], ...$headers], new Body());
    }

    private static function encode(Response $response): string
    {
        return implode('', iterator_to_array((new ResponseEncoder($response, self::request()))->bytes()));
    }

    private static function dateHeader(string $wire): string
    {
        preg_match_all('/\r\ndate: ([^\r\n]+)\r\n/', $wire, $matches);
        self::assertCount(1, $matches[1], 'Each response must have exactly one Date header.');
        return $matches[1][0];
    }

    private static function httpDate(int $second): string
    {
        return gmdate('D, d M Y H:i:s', $second) . ' GMT';
    }
}
