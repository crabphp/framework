<?php

declare(strict_types=1);

namespace Crab\Tests\Http;

use Crab\Contracts\Http\Decoding;
use Crab\Http\HttpException;
use Crab\Http\Multipart;
use Crab\Http\Request;
use Crab\Http\StrictDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

final class StrictDecoderTest extends TestCase
{
    public function testNamesNestAsWritten(): void
    {
        $fields = StrictDecoder::urlencoded(
            'title=A+b&address[city]=Hue&tags[]=x&tags[]=y&lines[1][n]=2&lines[0][n]=1&empty=',
            new Decoding(),
            'query string',
        );

        self::assertSame(
            [
                'title' => 'A b',
                'address' => ['city' => 'Hue'],
                'tags' => ['x', 'y'],
                'lines' => [['n' => '1'], ['n' => '2']],
                'empty' => '',
            ],
            $fields,
        );
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function refused(): iterable
    {
        yield 'a scalar given twice' => ['a=1&a=2', 400];
        yield 'a list given two ways' => ['a[]=1&a[0]=2', 400];
        yield 'a list and an object' => ['a[0]=1&a[x]=2', 400];
        yield 'a place left out' => ['a[0]=1&a[2]=2', 400];
        yield 'a place with a leading zero' => ['a[01]=1', 400];
        yield 'something after an append' => ['a[][b]=1', 400];
        yield 'a value and an object' => ['a=1&a[b]=2', 400];
        yield 'an unclosed bracket' => ['a[b=1', 400];
        yield 'a dot in a name' => ['a.b=1', 400];
        yield 'bytes that are not UTF-8' => ['a=%ff', 400];
        yield 'a name too long' => [str_repeat('n', 300) . '=1', 413];
        yield 'nesting too deep' => ['a[b][c][d]=1', 413];
        yield 'too many fields' => ['a=1&b=2&c=3&d=4&e=5&f=6', 413];
    }

    #[DataProvider('refused')]
    public function testWhatIsAmbiguousOrTooLargeIsRefused(string $encoded, int $status): void
    {
        try {
            StrictDecoder::urlencoded($encoded, new Decoding(maxDepth: 3, maxNodes: 5), 'form');
            self::fail('It was read.');
        } catch (HttpException $refused) {
            self::assertSame($status, $refused->status);
        }
    }

    public function testAJsonBodyIsOneObjectWithNoRepeatedMember(): void
    {
        self::assertSame(
            ['a' => ['b' => [1, 2]], 'c' => null],
            StrictDecoder::plain(StrictDecoder::json(' {"a":{"b":[1,2]},"c":null}', new Decoding())),
        );
        // An object stays apart from a list, an empty one and one whose names are 0, 1, 2 included.
        $exact = StrictDecoder::json('{"none":{},"empty":[],"numbered":{"0":7},"list":[{"1":[]}]}', new Decoding());
        self::assertInstanceOf(\stdClass::class, $exact->none);
        self::assertSame([], $exact->empty);
        self::assertEquals((object) ['0' => 7], $exact->numbered);
        self::assertInstanceOf(\stdClass::class, $exact->list[0]);
        self::assertSame(
            ['none' => [], 'empty' => [], 'numbered' => [7], 'list' => [[1 => []]]],
            StrictDecoder::plain($exact),
        );
        foreach ([
            '[]' => 400,
            'null' => 400,
            '"x"' => 400,
            '{"a":1,"a":2}' => 400,
            '{"a":{"b":1,"b":2}}' => 400,
            '{"a":' => 400,
            '{"a":[[[[1]]]]}' => 413,
            '{"a":[1,2,3,4,5,6,7]}' => 413,
        ] as $body => $status) {
            try {
                StrictDecoder::json((string) $body, new Decoding(maxDepth: 4, maxNodes: 6));
                self::fail("{$body} was read.");
            } catch (HttpException $refused) {
                self::assertSame($status, $refused->status, (string) $body);
            }
        }
        // The same name in two objects, and a name inside a string, are not repeats.
        self::assertSame(
            ['a' => ['x' => 1], 'b' => ['x' => '"x":'], 'l' => [['x' => 1], ['x' => 2]]],
            StrictDecoder::plain(StrictDecoder::json(
                '{"a":{"x":1},"b":{"x":"\"x\":"},"l":[{"x":1},{"x":2}]}',
                new Decoding(),
            )),
        );
    }

    public function testARequestIsReadStrictlyOnlyOnceItIsMarked(): void
    {
        $ordinary = new Request('GET', '/?a=1&a=2');
        $strict = new Request('GET', '/?a=1&a=2')->withDecoding(new Decoding());

        self::assertSame(['a' => '2'], $ordinary->getQueryParams());
        $this->expectException(HttpException::class);
        $strict->getQueryParams();
    }

    public function testAStrictBodyIsJsonOrAFormInUtf8(): void
    {
        $decoding = new Decoding();
        $json = new Request('POST', '/', ['Content-Type' => 'application/json; charset=utf-8'], '{"a":1}');
        $form = new Request('POST', '/', ['Content-Type' => 'application/x-www-form-urlencoded'], 'a=1&b[]=2');
        $none = new Request('POST', '/');

        self::assertSame(['a' => 1], $json->withDecoding($decoding)->getParsedBody());
        self::assertSame(['a' => '1', 'b' => ['2']], $form->withDecoding($decoding)->getParsedBody());
        self::assertNull($none->withDecoding($decoding)->getParsedBody());
        foreach ([
            [['Content-Type' => 'text/plain'], 'a', 415],
            [['Content-Type' => 'application/json; charset=latin1'], '{}', 415],
            [['Content-Type' => 'application/json'], '', 400],
            [['Content-Type' => 'application/json'], '[]', 400],
        ] as [$headers, $body, $status]) {
            try {
                new Request('POST', '/', $headers, $body)
                    ->withDecoding($decoding)
                    ->getParsedBody();
                self::fail('It was read.');
            } catch (HttpException $refused) {
                self::assertSame($status, $refused->status);
            }
        }
    }

    public function testAStrictMultipartFormKeepsFilesAndRefusesARepeatedName(): void
    {
        [$body, $type] = Multipart::write(['title' => 'Crab', 'meta' => ['kind' => 'photo']], [
            'cover' => \Crab\Contracts\HttpClient\File::fromContents('pixels', 'a.png', 'image/png'),
        ]);
        $request = new Request('POST', '/', ['Content-Type' => $type], $body)->withDecoding(new Decoding());

        self::assertSame(['title' => 'Crab', 'meta' => ['kind' => 'photo']], $request->getParsedBody());
        self::assertInstanceOf(UploadedFileInterface::class, $request->getUploadedFiles()['cover']);
        self::assertSame('pixels', (string) $request->getUploadedFiles()['cover']->getStream());

        $boundary = 'x';
        $part = static fn(
            string $name,
            string $value,
        ): string => "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        $twice = $part('title', 'a') . $part('title', 'b') . "--{$boundary}--\r\n";
        $headers = ['Content-Type' => "multipart/form-data; boundary={$boundary}"];
        self::assertSame(['title' => 'b'], new Request('POST', '/', $headers, $twice)->getParsedBody());
        $this->expectException(HttpException::class);
        new Request('POST', '/', $headers, $twice)
            ->withDecoding(new Decoding())
            ->getParsedBody();
    }
}
