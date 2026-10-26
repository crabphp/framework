<?php

declare(strict_types=1);

namespace Crab\Tests\Routing;

use Crab\App;
use Crab\Contracts\HttpClient\File;
use Crab\Testing\Api;
use Crab\Validation\FileSize;
use Crab\Validation\Length;
use Crab\Validation\ListOf;
use Crab\Validation\MediaType;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A file the request carries is a field of the action like any other:
 * typed, required unless nullable, checked by rules, and refused with 422
 * before the action runs.
 */
final class UploadFieldTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function testAFileArrivesAsItsParameterBesideTheFormsOtherFields(): void
    {
        $api = $this->api();
        $png = base64_decode(self::PNG, true);

        $saved = $api->post(
            '/avatar',
            form: ['caption' => 'Me', 'rotate' => '90'],
            files: ['avatar' => File::fromContents($png, 'me.png', 'image/png')],
        );

        $saved->assertStatus(200);
        self::assertSame(
            ['caption' => 'Me', 'rotate' => 90, 'size' => strlen($png), 'name' => 'me.png', 'same' => true],
            $saved->json(),
        );
    }

    public function testAMissingOrEmptyFileIsRequiredAndANullableOneIsNull(): void
    {
        $api = $this->api();

        self::assertSame(
            ['avatar' => ['is required']],
            $api->post('/avatar', form: ['caption' => 'Me'])->assertStatus(422)->json('error.fields'),
        );
        // What a browser sends for a file input left empty.
        $empty = $api->post('/avatar', form: ['caption' => 'Me'], files: ['avatar' => File::fromContents('', '')]);
        self::assertSame(['avatar' => ['is required']], $empty->assertStatus(422)->json('error.fields'));
        self::assertSame(['attached' => false], $api->post('/note', form: ['text' => 'hi'])->json());
        self::assertSame(
            ['attached' => true],
            $api->post(
                '/note',
                form: ['text' => 'hi'],
                files: ['attachment' => File::fromContents('x', 'x.txt')],
            )->json(),
        );
    }

    public function testJsonCannotCarryAFile(): void
    {
        self::assertSame(
            ['avatar' => ['must be a file, sent as multipart/form-data']],
            $this
                ->api()
                ->post('/avatar', ['caption' => 'Me', 'avatar' => 'iVBORw0KGgo='])
                ->assertStatus(422)
                ->json('error.fields'),
        );
    }

    public function testSizeAndKindAreCheckedAndTheKindIsReadFromTheContent(): void
    {
        $api = $this->api();

        $big = $api->post(
            '/avatar',
            form: ['caption' => 'Me'],
            files: [
                'avatar' => File::fromContents(str_repeat('a', 2049), 'big.png', 'image/png'),
            ],
        );
        self::assertSame(
            ['avatar' => ['must be at most 2 KB', 'must be image/png or image/gif, not text/plain']],
            $big->assertStatus(422)->json('error.fields'),
        );

        // The client says PNG; the bytes say text.
        $lie = $api->post(
            '/avatar',
            form: ['caption' => 'Me'],
            files: [
                'avatar' => File::fromContents('<?php echo 1;', 'me.png', 'image/png'),
            ],
        );
        $lie->assertStatus(422);
        self::assertStringStartsWith('must be image/png or image/gif, not text/', $lie->json('error.fields.avatar.0'));
    }

    public function testAListOfFilesIsCheckedFileByFile(): void
    {
        $api = $this->api();
        $png = base64_decode(self::PNG, true);

        $two = $api->post('/gallery', files: ['photos' => [
            File::fromContents($png, 'a.png', 'image/png'),
            File::fromContents($png, 'b.png', 'image/png'),
        ]]);
        self::assertSame([2, 'b.png'], $two->assertStatus(200)->json());

        self::assertSame(
            ['photos.1' => ['must be image/png, not text/plain']],
            $api
                ->post('/gallery', files: ['photos' => [
                    File::fromContents($png, 'a.png', 'image/png'),
                    File::fromContents('text', 'b.png', 'image/png'),
                ]])
                ->assertStatus(422)
                ->json('error.fields'),
        );

        // A multiple file input left empty sends one empty file: no photos at all.
        self::assertSame(
            ['photos' => ['must have at least 1 item(s)']],
            $api
                ->post('/gallery', files: ['photos' => [File::fromContents('', '')]])
                ->assertStatus(422)
                ->json('error.fields'),
        );
    }

    public function testAnInputClassTakesFilesToo(): void
    {
        $api = $this->api();

        $made = $api->post(
            '/documents',
            form: ['title' => 'Plan'],
            files: [
                'document' => File::fromContents('the plan', 'plan.txt', 'text/plain'),
            ],
        );
        self::assertSame(['Plan', 'the plan'], $made->assertStatus(200)->json());

        self::assertSame(
            ['document' => ['is required']],
            $api->post('/documents', form: ['title' => 'Plan'])->assertStatus(422)->json('error.fields'),
        );
    }

    public function testARouteReadFromTheQueryStringCannotTakeAFile(): void
    {
        $app = App::configure()->create();

        $this->expectExceptionMessage(
            'The action of GET /avatar takes the file $avatar, but its data is the query string, which carries no file. Upload with POST, PUT or PATCH.',
        );
        $app->router->get('/avatar', static fn(UploadedFileInterface $avatar): int => (int) $avatar->getSize());
    }

    public function testTheRouteDescribesItsFiles(): void
    {
        $app = App::configure()->create();
        $app->router->post('/avatar', static fn(#[FileSize(max: 1024)]
            #[MediaType('image/*')] UploadedFileInterface $avatar, ?UploadedFileInterface $thumbnail): null => null);

        self::assertSame(
            [
                'type' => 'object',
                'properties' => [
                    'avatar' => [
                        'type' => 'string',
                        'format' => 'binary',
                        'maxBytes' => 1024,
                        'mediaTypes' => ['image/*'],
                    ],
                    'thumbnail' => ['type' => ['string', 'null'], 'format' => 'binary'],
                ],
                'required' => ['avatar'],
            ],
            $app->router->input($app->router->routes()[0])['body'] ?? null,
        );
    }

    public function testAMediaTypeThatIsNoMediaTypeStopsTheRoute(): void
    {
        $this->expectExceptionMessage(
            "MediaType is given \"png\", which is not a media type; write it as 'image/png' or 'image/*', in lower case.",
        );
        new MediaType('png');
    }

    private function api(): Api
    {
        $app = App::configure()->create();
        $app->router->post('/avatar', static fn(string $caption, #[FileSize(max: 2048)]
            #[MediaType(
                'image/png',
                'image/gif',
            )] UploadedFileInterface $avatar, int $rotate = 0): array => [
            'caption' => $caption,
            'rotate' => $rotate,
            'size' => $avatar->getSize(),
            'name' => $avatar->getClientFilename(),
            'same' => (string) $avatar->getStream() === base64_decode(self::PNG, true),
        ]);
        $app->router->post('/note', static fn(string $text, ?UploadedFileInterface $attachment): array => [
            'attached' => $attachment !== null,
        ]);
        $app->router->post('/gallery', static fn(#[ListOf(UploadedFileInterface::class)]
            #[Length(min: 1, max: 5)]
            #[MediaType('image/png')] array $photos): array => [count($photos), $photos[1]->getClientFilename()]);
        $app->router->post('/documents', static fn(NewDocument $input): array => [
            $input->title,
            (string) $input->document->getStream(),
        ]);
        return new Api($app);
    }
}

final readonly class NewDocument
{
    public function __construct(
        public string $title,
        public UploadedFileInterface $document,
    ) {}
}
