<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Http\Body;
use Crab\Engine\Http\Request;
use Crab\Engine\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RequestTokenTest extends TestCase
{
    #[DataProvider('keepAliveProvider')]
    public function testConnectionPersistence(string $version, array $headers, bool $expected): void
    {
        self::assertSame($expected, self::request($version, $headers)->keepAlive());
    }

    public static function keepAliveProvider(): iterable
    {
        yield 'HTTP/1.1 absent field' => ['1.1', [], true];
        yield 'HTTP/1.0 absent field' => ['1.0', [], false];
        yield 'HTTP/1.1 null field' => ['1.1', ['connection' => null], true];
        yield 'HTTP/1.0 null field' => ['1.0', ['connection' => null], false];
        yield 'HTTP/1.1 empty field' => ['1.1', ['connection' => ['']], true];
        yield 'HTTP/1.0 empty field' => ['1.0', ['connection' => ['']], false];
        yield 'HTTP/1.1 empty values' => ['1.1', ['connection' => []], true];
        yield 'HTTP/1.0 empty values' => ['1.0', ['connection' => []], false];
        yield 'HTTP/1.1 close' => ['1.1', ['connection' => ['close']], false];
        yield 'HTTP/1.0 close' => ['1.0', ['connection' => ['close']], false];
        yield 'HTTP/1.0 explicit persistence' => ['1.0', ['connection' => ['keep-alive']], true];
        yield 'HTTP/1.1 close overrides keep-alive' => ['1.1', ['connection' => ['keep-alive, close']], false];
        yield 'HTTP/1.0 close overrides keep-alive' => ['1.0', ['connection' => ['close, keep-alive']], false];
        yield 'repeated mixed-case fields' => ['1.1', ['connection' => ['Keep-Alive', ' ClOsE ', 'keep-alive']], false];
        yield 'persistence ignores surrounding whitespace' => ['1.0', ['connection' => [" \tKeEp-AlIvE\t "]], true];
        yield 'empty tokens do not prevent persistence' => ['1.0', ['connection' => [', keep-alive, ,']], true];
        yield 'close must be a complete token' => ['1.1', ['connection' => ['disclose, not-close']], true];
        yield 'constructor preserves unnormalized names' => ['1.1', ['Connection' => ['close']], true];
    }

    #[DataProvider('tokenProvider')]
    public function testTokenLookup(
        array $headers,
        string $name,
        #[\SensitiveParameter]
        string $token,
        bool $expected,
    ): void {
        self::assertSame($expected, self::request('1.1', $headers)->hasToken($name, $token));
    }

    public static function tokenProvider(): iterable
    {
        yield 'absent nonempty token' => [[], 'connection', 'close', false];
        yield 'absent empty token' => [[], 'connection', '', true];
        yield 'absent whitespace token' => [[], 'connection', ' ', false];
        yield 'null field empty token' => [['connection' => null], 'connection', '', true];
        yield 'empty values empty token' => [['connection' => []], 'connection', '', true];
        yield 'empty field empty token' => [['connection' => ['']], 'connection', '', true];
        yield 'whitespace field empty token' => [['connection' => [" \t "]], 'connection', '', true];
        yield 'nonempty field lacks empty token' => [['connection' => ['close']], 'connection', '', false];
        yield 'trailing separator has empty token' => [['connection' => ['close,']], 'connection', '', true];
        yield 'repeated empty field has empty token' => [['connection' => ['close', '']], 'connection', '', true];
        yield 'header and token names ignore case' => [['connection' => ['ClOsE']], 'CoNnEcTiOn', 'CLOSE', true];
        yield 'repeated fields and tokens' => [
            ['connection' => ['upgrade, close', 'KEEP-ALIVE, close']],
            'connection',
            'keep-alive',
            true,
        ];
        yield 'surrounding field whitespace' => [
            ['connection' => ["\t upgrade \t, \tClOsE\t "]],
            'connection',
            'close',
            true,
        ];
        yield 'searched token whitespace is preserved' => [
            ['connection' => [' close ']],
            'connection',
            ' close ',
            false,
        ];
        yield 'no token substring matches' => [['connection' => ['disclose, not-close']], 'connection', 'close', false];
        yield 'generic header lookup' => [['upgrade' => ['WebSocket, h2c']], 'UPGRADE', 'websocket', true];
        yield 'constructor does not lowercase stored names' => [
            ['Connection' => ['close']],
            'Connection',
            'close',
            false,
        ];
    }

    public function testReferencedHeaderListChangesRemainVisible(): void
    {
        $values = ['keep-alive'];
        $request = self::request('1.0', ['connection' => &$values]);
        self::assertTrue($request->keepAlive());
        self::assertTrue($request->hasToken('CONNECTION', 'KEEP-ALIVE'));

        $values = ['close'];
        self::assertFalse($request->keepAlive());
        self::assertTrue($request->hasToken('connection', 'close'));
        self::assertFalse($request->hasToken('connection', 'keep-alive'));

        $values = null;
        self::assertFalse($request->keepAlive());
        self::assertTrue($request->hasToken('connection', ''));

        $values = ['keep-alive'];
        self::assertTrue($request->keepAlive());
        self::assertFalse($request->hasToken('connection', ''));
    }

    public function testReferencedHeaderValueChangesRemainVisible(): void
    {
        $value = 'keep-alive';
        $request = self::request('1.1', ['connection' => [&$value]]);
        self::assertTrue($request->keepAlive());

        $value = 'close';
        self::assertFalse($request->keepAlive());
        self::assertTrue($request->hasToken('connection', 'CLOSE'));

        $value = '';
        self::assertTrue($request->keepAlive());
        self::assertTrue($request->hasToken('connection', ''));
    }

    private static function request(string $version, array $headers): Request
    {
        return new Request('GET', '/', $version, $headers, new Body());
    }
}
