<?php

declare(strict_types=1);

namespace Crab\Tests\Routing;

use Crab\App;
use Crab\Contracts\HttpClient\File;
use Crab\Routing\FromQuery;
use Crab\Testing\Api;
use Crab\Validation\FileSize;
use Crab\Validation\Length;
use Crab\Validation\ListOf;
use Crab\Validation\MediaType;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A file the request carries is a field of the body like any other:
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

    public function testAFileIsAFieldOfTheBodyAndNeverAParameterOfItsOwn(): void
    {
        $app = App::configure()->create();
        $refused = [
            'The action of POST /avatar takes the file $avatar. A file is a field of the body: declare it in the body\'s input class, public UploadedFileInterface $avatar.' =>
                static fn() => $app->router->post('/avatar', static fn(UploadedFileInterface $avatar): null => null),
            'The action of GET /avatar takes the file $avatar from the query string, which carries no file.' =>
                static fn() => $app->router->get(
                    '/avatar',
                    static fn(#[FromQuery] UploadedFileInterface $avatar): null => null,
                ),
            'The action of GET /avatar takes Crab\Tests\Routing\Avatar $input as its body, and a GET request has none.' =>
                static fn() => $app->router->get('/avatar', static fn(Avatar $input): null => null),
        ];
        foreach ($refused as $message => $attempt) {
            try {
                $attempt();
                self::fail("Not refused: {$message}");
            } catch (\LogicException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }

    public function testTheRouteDescribesItsFiles(): void
    {
        $app = App::configure()->create();
        $app->router->post('/avatar', static fn(Thumbnailed $input): null => null);

        self::assertSame(
            [
                'x-class' => Thumbnailed::class,
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
        $app->router->post('/avatar', static fn(Avatar $input): array => [
            'caption' => $input->caption,
            'rotate' => $input->rotate,
            'size' => $input->avatar->getSize(),
            'name' => $input->avatar->getClientFilename(),
            'same' => (string) $input->avatar->getStream() === base64_decode(self::PNG, true),
        ]);
        $app->router->post('/note', static fn(Note $input): array => ['attached' => $input->attachment !== null]);
        $app->router->post('/gallery', static fn(Gallery $input): array => [
            count($input->photos),
            $input->photos[1]->getClientFilename(),
        ]);
        $app->router->post('/documents', static fn(NewDocument $input): array => [
            $input->title,
            (string) $input->document->getStream(),
        ]);
        return new Api($app);
    }
}

final readonly class Avatar
{
    public function __construct(
        public string $caption,
        #[FileSize(max: 2048), MediaType('image/png', 'image/gif')]
        public UploadedFileInterface $avatar,
        public int $rotate = 0,
    ) {}
}

final readonly class Thumbnailed
{
    public function __construct(
        #[FileSize(max: 1024), MediaType('image/*')]
        public UploadedFileInterface $avatar,
        public ?UploadedFileInterface $thumbnail,
    ) {}
}

final readonly class Note
{
    public function __construct(
        public string $text,
        public ?UploadedFileInterface $attachment,
    ) {}
}

final readonly class Gallery
{
    public function __construct(
        #[ListOf(UploadedFileInterface::class), Length(min: 1, max: 5), MediaType('image/png')]
        public array $photos,
    ) {}
}

final readonly class NewDocument
{
    public function __construct(
        public string $title,
        public UploadedFileInterface $document,
    ) {}
}
