<?php

declare(strict_types=1);

namespace Crab\Tests\Http;

use Crab\Engine\Http\Body as EngineBody;
use Crab\Engine\Http\Request as EngineRequest;
use Crab\Http\Cookie;
use Crab\Http\Factory;
use Crab\Http\HttpException;
use Crab\Http\Request;
use Crab\Http\Response;
use Crab\Http\Stream;
use Crab\Http\StreamSlice;
use Crab\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

final class UploadTest extends TestCase
{
    private const BOUNDARY = '----crab7MA4YWxkTrZu0gW';

    public function testMultipartFieldsAndFiles(): void
    {
        $binary = "\x00\x01\r\n--not the boundary\r\n\xFF";
        $request = self::multipart([
            ['name="title"', 'Hello & goodbye'],
            ['name="user[name]"', 'Crab'],
            ['name="user[tags][]"', 'a'],
            ['name="user[tags][]"', 'b'],
            ['name="avatar"; filename="me \\"quoted\\".png"', $binary, 'image/png'],
            ['name="docs[]"; filename="one.txt"', 'first', 'text/plain'],
            ['name="docs[]"; filename="two.txt"', '', 'text/plain'],
            ['name="skipped"; filename=""', '', 'application/octet-stream'],
        ]);

        self::assertSame(
            ['title' => 'Hello & goodbye', 'user' => ['name' => 'Crab', 'tags' => ['a', 'b']]],
            $request->getParsedBody(),
        );
        self::assertSame('Crab', $request->input('user')['name']);

        $files = $request->getUploadedFiles();
        self::assertSame(['avatar', 'docs', 'skipped'], array_keys($files));
        $avatar = $request->file('avatar');
        self::assertInstanceOf(UploadedFileInterface::class, $avatar);
        self::assertSame($files['avatar'], $avatar);
        self::assertSame(['me "quoted".png', 'image/png', strlen($binary), UPLOAD_ERR_OK], [
            $avatar->getClientFilename(),
            $avatar->getClientMediaType(),
            $avatar->getSize(),
            $avatar->getError(),
        ]);
        self::assertSame($binary, (string) $avatar->getStream());
        self::assertSame(['first', ''], [
            (string) $files['docs'][0]->getStream(),
            (string) $files['docs'][1]->getStream(),
        ]);
        self::assertSame(0, $files['docs'][1]->getSize());
        self::assertSame(UPLOAD_ERR_NO_FILE, $files['skipped']->getError());
        self::assertNull($request->file('docs'), 'A name holding several files is not one file.');
        self::assertNull($request->file('missing'));
        self::assertSame($files, $request->getUploadedFiles(), 'The body is read once.');
    }

    public function testFilesFirstThenFieldsReadsTheBodyOnce(): void
    {
        $request = self::multipart([['name="a"', '1'], ['name="f"; filename="f.txt"', 'x', 'text/plain']]);
        $file = $request->file('f');
        self::assertSame(['a' => '1'], $request->getParsedBody());
        self::assertSame($file, $request->file('f'));

        $explicit = self::multipart([['name="a"', '1'], ['name="f"; filename="f.txt"', 'x', 'text/plain']])
            ->withParsedBody(null)
            ->withUploadedFiles([]);
        self::assertNull($explicit->getParsedBody());
        self::assertSame([], $explicit->getUploadedFiles());
    }

    #[DataProvider('malformedProvider')]
    public function testMalformedMultipartIsABadRequest(string $contentType, string $body): void
    {
        try {
            new Request('POST', '/', ['Content-Type' => $contentType], $body)->getUploadedFiles();
            self::fail('A malformed body was accepted.');
        } catch (HttpException $error) {
            self::assertSame(400, $error->status);
        }
    }

    public static function malformedProvider(): array
    {
        $type = 'multipart/form-data; boundary=B';
        return [
            'no boundary parameter' => ['multipart/form-data', "--B\r\n"],
            'boundary never appears' => [$type, 'nothing here'],
            'truncated after boundary' => [$type, '--B'],
            'no closing boundary' => [$type, "--B\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nvalue"],
            'no blank line' => [$type, "--B\r\nContent-Disposition: form-data; name=\"a\"\r\n--B--"],
            'no disposition' => [$type, "--B\r\nContent-Type: text/plain\r\n\r\nvalue\r\n--B--"],
            'no name' => [$type, "--B\r\nContent-Disposition: form-data; filename=\"a\"\r\n\r\nvalue\r\n--B--"],
            'junk after boundary' => [$type, "--Bxx\r\n"],
        ];
    }

    public function testTooManyPartsIsRejectedAndAnEmptyFormIsEmpty(): void
    {
        $empty = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary="B"'],
            "preamble\r\n--B--\r\n",
        );
        self::assertSame([[], []], [$empty->getParsedBody(), $empty->getUploadedFiles()]);
        try {
            self::multipart(array_fill(0, 1001, ['name="a[]"', 'x']))->getParsedBody();
            self::fail('An unbounded number of parts was accepted.');
        } catch (HttpException $error) {
            self::assertSame(413, $error->status);
        }
        self::assertCount(1000, self::multipart(array_fill(0, 1000, ['name="a[]"', 'x']))->getParsedBody()['a']);
    }

    public function testALargeUploadIsReadWhereItLiesAndNeverHeldInMemory(): void
    {
        // 40 MiB of content that holds line breaks and near-boundaries everywhere, built as the engine stores a body.
        $boundary = self::BOUNDARY;
        $block = str_repeat("line of a file\r\n--almost a boundary\r\n--{$boundary[0]}\r\n", 1024);
        $blocks = intdiv(40 * 1024 * 1024, strlen($block));
        $body = new EngineBody();
        $body->append("--{$boundary}\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nBackup\r\n");
        $body->append(
            "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"big.bin\"\r\nContent-Type: application/octet-stream\r\n\r\n",
        );
        $hash = hash_init('xxh128');
        for ($i = 0; $i < $blocks; ++$i) {
            $body->append($block);
            hash_update($hash, $block);
        }
        $body->append(
            "\r\n--{$boundary}\r\nContent-Disposition: form-data; name=\"note\"\r\n\r\nafter the file\r\n--{$boundary}--\r\n",
        );
        $size = $blocks * strlen($block);
        $request = Request::fromEngine(
            new EngineRequest(
                'POST',
                '/upload',
                '1.1',
                [
                    'content-type' => ["multipart/form-data; boundary={$boundary}"],
                ],
                $body,
            ),
        );

        $target = sys_get_temp_dir() . '/crab-upload-' . bin2hex(random_bytes(6));
        $before = memory_get_usage();
        memory_reset_peak_usage();
        try {
            $file = $request->file('file');
            self::assertSame(['title' => 'Backup', 'note' => 'after the file'], $request->getParsedBody());
            self::assertSame([$size, 'big.bin', 'application/octet-stream'], [
                $file->getSize(),
                $file->getClientFilename(),
                $file->getClientMediaType(),
            ]);
            $stream = $file->getStream();
            self::assertSame(substr($block, 0, 100), $stream->read(100));
            $stream->seek(-20, SEEK_END);
            self::assertSame(substr($block, -20), $stream->getContents());
            self::assertTrue($stream->eof());
            $file->moveTo($target);
            self::assertLessThan(
                4 * 1024 * 1024,
                memory_get_peak_usage() - $before,
                'Reading and moving a 40 MiB file took a few megabytes at most.',
            );
            self::assertSame($size, filesize($target));
            self::assertSame(hash_final($hash), hash_file('xxh128', $target));
        } finally {
            @unlink($target);
        }
    }

    public function testPartsAreFoundWhereverTheBodyIsCutWhileReading(): void
    {
        // Sizes around the 64 KiB the body is read in, so that every marker falls across a read at some size.
        foreach ([65_500, 65_530, 65_536, 65_540, 131_060, 131_072] as $size) {
            $content = str_repeat('x', $size);
            $request = self::multipart([
                ['name="before"', 'b'],
                ['name="f"; filename="f.bin"', $content, 'application/octet-stream'],
                ['name="after"', 'a'],
                ['name="g"; filename="g.bin"', $content . 'g', 'application/octet-stream'],
            ]);
            self::assertSame(['before' => 'b', 'after' => 'a'], $request->getParsedBody(), "At {$size} bytes.");
            self::assertSame([$size, $size + 1], [$request->file('f')->getSize(), $request->file('g')->getSize()]);
            self::assertSame($content, (string) $request->file('f')->getStream());
            self::assertSame(
                $content . 'g',
                (string) $request->file('g')->getStream(),
                'Two files of one body are read apart.',
            );
        }
    }

    public function testFieldsMayOnlyBeSoLargeAndAStreamOfAnotherKindIsRead(): void
    {
        try {
            self::multipart([
                ['name="a"', str_repeat('x', 600_000)],
                ['name="b"', str_repeat('x', 600_000)],
            ])->getParsedBody();
            self::fail('Fields of any size were read into memory.');
        } catch (HttpException $error) {
            self::assertSame([413, 'The form\'s fields are too large. Send large content as a file.'], [
                $error->status,
                $error->getMessage(),
            ]);
        }
        self::assertSame(
            1_200_000,
            self::multipart([['name="f"; filename="f"', str_repeat('x', 1_200_000), 'text/plain']])
                ->file('f')
                ->getSize(),
            'A file may be.',
        );

        $slice = new StreamSlice(fopen('php://memory', 'w+b'), 0, 0);
        self::assertSame(['', 0, true, false, true], [
            (string) $slice,
            $slice->getSize(),
            $slice->eof(),
            $slice->isWritable(),
            $slice->isSeekable(),
        ]);
        $slice->close();
        self::assertSame([false, false, null], [$slice->isReadable(), $slice->isSeekable(), $slice->detach()]);
    }

    public function testUploadedFileMovesOnce(): void
    {
        $target = sys_get_temp_dir() . '/crab-upload-' . bin2hex(random_bytes(6));
        try {
            $file = new Factory()->createUploadedFile(
                new Stream('content'),
                clientFilename: 'a.txt',
                clientMediaType: 'text/plain',
            );
            self::assertSame(7, $file->getSize());
            $file->moveTo($target);
            self::assertSame('content', file_get_contents($target));
            $read = new UploadedFile(new Stream('partly read'));
            $read->getStream()->read(3);
            $read->moveTo($target);
            self::assertSame('partly read', file_get_contents($target));

            $resource = fopen('php://memory', 'w+b');
            fwrite($resource, str_repeat('z', 200_000));
            new UploadedFile(new Stream($resource))->moveTo($target);
            self::assertSame(200_000, filesize($target));
        } finally {
            @unlink($target);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function movedFileOperations(): array
    {
        return ['move again' => ['move'], 'read stream' => ['read']];
    }

    #[DataProvider('movedFileOperations')]
    public function testAMovedUploadCannotBeUsedAgain(string $operation): void
    {
        $target = sys_get_temp_dir() . '/crab-upload-' . bin2hex(random_bytes(6));
        try {
            $file = new UploadedFile(new Stream('content'));
            $file->moveTo($target);
            $this->expectException(\RuntimeException::class);

            if ($operation === 'move') {
                $file->moveTo($target);
            } else {
                $file->getStream();
            }
        } finally {
            @unlink($target);
        }
    }

    public function testUploadedFileFailures(): void
    {
        $failed = new UploadedFile(new Stream(''), 0, UPLOAD_ERR_NO_FILE);
        $rejected = 0;
        foreach ([
            static fn() => $failed->getStream(),
            static fn() => $failed->moveTo('/tmp/x'),
            static fn() => new UploadedFile(new Stream('a'))->moveTo('/no/such/directory/file'),
        ] as $invalid) {
            try {
                $invalid();
            } catch (\RuntimeException) {
                ++$rejected;
            }
        }
        foreach ([
            static fn() => new UploadedFile(new Stream('a'))->moveTo(''),
            static fn() => new UploadedFile(new Stream('a'), error: 99),
        ] as $invalid) {
            try {
                $invalid();
            } catch (\InvalidArgumentException) {
                ++$rejected;
            }
        }
        try {
            new Request('POST', '/')->withUploadedFiles(['a' => ['b' => 'not a file']]);
        } catch (\InvalidArgumentException) {
            ++$rejected;
        }
        self::assertSame(6, $rejected);
        $file = new UploadedFile(new Stream('a'));
        self::assertSame(
            ['a' => ['b' => $file]],
            new Request('POST', '/')->withUploadedFiles(['a' => ['b' => $file]])->getUploadedFiles(),
        );
    }

    public function testMoreVariablesThanPhpReadsAreDroppedWithoutAWarning(): void
    {
        $limit = (int) ini_get('max_input_vars');
        $pairs = implode('&', array_map(static fn(int $i): string => "v{$i}=1", range(1, $limit + 5)));
        $warnings = [];
        // As the application's handler does in a worker: a silenced warning is not a failure.
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            if ((error_reporting() & $level) !== 0) {
                $warnings[] = $message;
            }
            return true;
        });
        $reporting = error_reporting(E_ALL);
        try {
            self::assertCount($limit, new Request('GET', '/?' . $pairs)->getQueryParams());
            self::assertCount(
                $limit,
                new Request(
                    'POST',
                    '/',
                    ['Content-Type' => 'application/x-www-form-urlencoded'],
                    $pairs,
                )->getParsedBody(),
            );
            @trigger_error('silenced', E_USER_WARNING);
            trigger_error('reported', E_USER_WARNING);
        } finally {
            error_reporting($reporting);
            restore_error_handler();
        }
        self::assertSame(['reported'], $warnings);
    }

    public function testJsonBodies(): void
    {
        $json = static fn(string $body, string $type = 'application/json') => new Request(
            'POST',
            '/',
            ['Content-Type' => $type],
            $body,
        );
        self::assertSame(['name' => 'Crab', 'tags' => ['a']], $json('{"name":"Crab","tags":["a"]}')->getParsedBody());
        self::assertSame('Crab', $json('{"name":"Crab"}', 'application/vnd.api+json; charset=utf-8')->input('name'));
        self::assertSame([1, 2], $json('[1,2]')->getParsedBody());
        self::assertNull($json('')->getParsedBody());
        self::assertNull($json('"text"')->getParsedBody());
        self::assertNull($json('{"a":1}', 'text/plain')->getParsedBody());
        self::assertNull($json('{"a":1}', 'application/jsonp')->getParsedBody());
        try {
            $json('{"name":')->getParsedBody();
            self::fail('Malformed JSON was accepted.');
        } catch (HttpException $error) {
            self::assertSame(400, $error->status);
            self::assertInstanceOf(\JsonException::class, $error->getPrevious());
        }
    }

    public function testCookies(): void
    {
        self::assertSame('theme=dark; Path=/; HttpOnly; SameSite=Lax', (string) new Cookie('theme', 'dark'));
        self::assertSame(
            'v=a%20b%3B%3D; Path=/admin; Domain=example.com; Secure; SameSite=None',
            (string) new Cookie(
                'v',
                'a b;=',
                path: '/admin',
                domain: 'example.com',
                secure: true,
                httpOnly: false,
                sameSite: 'None',
            ),
        );
        self::assertSame(
            's=1; Max-Age=3600; Path=/; HttpOnly; SameSite=Strict',
            (string) new Cookie('s', '1', 3600, sameSite: 'Strict'),
        );
        self::assertSame(
            's=; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Path=/; HttpOnly; SameSite=Lax',
            (string) Cookie::forget('s'),
        );
        self::assertSame(
            's=; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Path=/; HttpOnly; SameSite=Lax',
            (string) new Cookie('s', maxAge: -1),
        );

        $response = Response::text('ok')->withCookie(new Cookie('a', '1'))->withCookie(new Cookie('b', '2'));
        self::assertSame(
            ['a=1; Path=/; HttpOnly; SameSite=Lax', 'b=2; Path=/; HttpOnly; SameSite=Lax'],
            $response->getHeader('Set-Cookie'),
        );
        $read = new Request('GET', '/', ['Cookie' => 'v=' . rawurlencode('a b;=')]);
        self::assertSame(['v' => 'a b;='], $read->getCookieParams(), 'A value written by Cookie reads back unchanged.');

        foreach ([
            static fn() => new Cookie('bad name'),
            static fn() => new Cookie('a', path: '/x;y'),
            static fn() => new Cookie('a', sameSite: 'lax'),
            static fn() => new Cookie('a', sameSite: 'None'),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('An invalid cookie was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    /**
     * @param list<array{0: string, 1: string, 2?: string}> $parts Disposition parameters, content and media type.
     */
    private static function multipart(array $parts): Request
    {
        $body = "This preamble is ignored.\r\n";
        foreach ($parts as $part) {
            $body .=
                '--'
                . self::BOUNDARY
                . "\r\nContent-Disposition: form-data; "
                . $part[0]
                . "\r\n"
                . (isset($part[2]) ? "Content-Type: {$part[2]}\r\n" : '')
                . "\r\n"
                . $part[1]
                . "\r\n";
        }
        $body .= '--' . self::BOUNDARY . "--\r\nThis epilogue is ignored.";
        return new Request(
            'POST',
            '/upload',
            ['Content-Type' => 'multipart/form-data; boundary=' . self::BOUNDARY],
            $body,
        );
    }
}
