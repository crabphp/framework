<?php

declare(strict_types=1);

namespace Crab\Tests\Http;

use Crab\App;
use Crab\Engine\Http\Request as EngineRequest;
use Crab\Http\ChunkStream;
use Crab\Http\EngineHandler;
use Crab\Http\Response;
use Crab\Testing\Api;
use PHPUnit\Framework\TestCase;

final class StreamingTest extends TestCase
{
    public function testAnActionThatReturnsAGeneratorIsAnsweredWithServerSentEvents(): void
    {
        $app = new App();
        $app->router->get('/reply/{id}', static function (int $id): \Generator {
            foreach (['Hi', "two\nlines"] as $token) {
                yield ['token' => $token];
            }
            yield 'done' => ['id' => $id, 'at' => new \DateTimeImmutable('2026-10-30 09:00:00 UTC')];
            yield 'note' => 'plain text';
        });

        $response = new Api($app)->get('/reply/7')->assertStatus(200);

        self::assertSame('text/event-stream; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('no-cache', $response->header('Cache-Control'));
        self::assertSame(
            "data: {\"token\":\"Hi\"}\n\n"
            . "data: {\"token\":\"two\\nlines\"}\n\n"
            . "event: done\ndata: {\"id\":7,\"at\":\"2026-10-30T09:00:00+00:00\"}\n\n"
            . "event: note\ndata: plain text\n\n",
            $response->body(),
        );
        self::assertSame(
            [
                ['event' => 'message', 'data' => ['token' => 'Hi']],
                ['event' => 'message', 'data' => ['token' => "two\nlines"]],
                ['event' => 'done', 'data' => ['id' => 7, 'at' => '2026-10-30T09:00:00+00:00']],
                ['event' => 'note', 'data' => 'plain text'],
            ],
            $response->events(),
        );
    }

    public function testATextEventOfSeveralLinesIsSentAsSeveralDataLines(): void
    {
        $body = (string) Response::events(['a' => "one\r\ntwo\nthree"])->getBody();

        self::assertSame("event: a\ndata: one\ndata: two\ndata: three\n\n", $body);
    }

    public function testAnEventNameOfMoreThanOneLineIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Response::events(["bad\nname" => 1])->getBody()->getContents();
    }

    public function testAStreamIsMadeOnlyWhenSentAndHandedToTheServerInPieces(): void
    {
        $made = false;
        $big = str_repeat('x', 70_000);
        $response = Response::stream(
            (static function () use (&$made, $big): \Generator {
                $made = true;
                yield 'a';
                yield $big;
            })(),
            'text/csv',
        );
        self::assertFalse($made, 'Nothing is made until the body is sent.');
        self::assertSame('text/csv', $response->getHeaderLine('Content-Type'));

        $engine = new EngineHandler(static fn() => $response)(new EngineRequest('GET', '/', '1.1', []));

        self::assertNotInstanceOf(\Crab\Engine\Http\Upgrade::class, $engine);
        self::assertSame(
            ['a', str_repeat('x', 65_536), str_repeat('x', 70_000 - 65_536)],
            iterator_to_array($engine->body, false),
        );
    }

    public function testAStreamIsReadOnceThroughPsr7(): void
    {
        $stream = new ChunkStream(['ab', '', 'cde']);

        self::assertFalse($stream->isSeekable());
        self::assertNull($stream->getSize());
        self::assertSame('a', $stream->read(1));
        self::assertSame('b', $stream->read(5));
        self::assertSame('cd', $stream->read(2));
        self::assertSame(4, $stream->tell());
        self::assertSame('e', $stream->getContents());
        self::assertTrue($stream->eof());
        self::assertSame('', (string) $stream);

        $this->expectException(\RuntimeException::class);
        $stream->rewind();
    }

    public function testAChunkThatIsNotTextIsRefused(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        new ChunkStream([1])->getContents();
    }
}
