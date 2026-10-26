<?php

declare(strict_types=1);

namespace Crab\Tests\Validation;

use Crab\App;
use Crab\Contracts\Validation\ObjectRule;
use Crab\Routing\FromQuery;
use Crab\Testing\Api;
use Crab\Validation\Data;
use Crab\Validation\Fields;
use Crab\Validation\RejectUnknown;
use Crab\Validation\ValidationException;
use Crab\Validation\Validator;
use PHPUnit\Framework\TestCase;

enum Setting: string
{
    case Sound = 'sound';
    case Volume = 'volume';
    case Theme = 'theme';
}

/**
 * The value's kind depends on which setting it is: a rule on the object as a whole.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PARAMETER)]
final readonly class ValueFitsSetting implements ObjectRule
{
    public function check(object $input): array
    {
        $fits = match ($input->setting) {
            Setting::Sound => is_bool($input->value),
            Setting::Volume => is_int($input->value) && $input->value >= 0 && $input->value <= 100,
            Setting::Theme => is_string($input->value) || $input->value === null,
        };
        return $fits ? [] : ['value' => "does not fit the setting {$input->setting->value}"];
    }
}

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PARAMETER)]
final readonly class EndsAfterStarts implements ObjectRule
{
    public function check(object $input): array
    {
        return $input->endsAt > $input->startsAt ? [] : ['endsAt' => 'must be after the start'];
    }
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Unnamed implements ObjectRule
{
    public function check(object $input): array
    {
        return ['must be something'];
    }
}

#[RejectUnknown, ValueFitsSetting]
final readonly class ChangeSetting
{
    public function __construct(
        public Setting $setting,
        // Sent, always; null clears a theme.
        public bool|int|string|null $value,
    ) {}
}

#[EndsAfterStarts]
final readonly class Slot
{
    public function __construct(
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
    ) {}
}

final readonly class Booking
{
    public function __construct(
        public string $room,
        public Slot $slot,
        public LooseAddress $address,
        public ?string $note,
        public int $guests = 1,
    ) {}
}

#[RejectUnknown]
final readonly class StrictBooking
{
    public function __construct(
        public string $room,
        public LooseAddress $address,
    ) {}
}

final readonly class LooseAddress
{
    public function __construct(
        public string $city,
    ) {}
}

#[Unnamed]
final readonly class NamesNoField
{
    public function __construct(
        public int $id,
    ) {}
}

final class InputShapeTest extends TestCase
{
    public function testAFieldWithoutADefaultIsSentEvenWhenItMayBeNull(): void
    {
        $validator = new Validator();
        $booking = ['room' => 'A', 'slot' => self::slot(), 'address' => ['city' => 'Hanoi']];

        self::assertNull($validator->validate(Booking::class, [...$booking, 'note' => null])->note);
        self::assertSame(3, $validator->validate(Booking::class, [...$booking, 'note' => 'x', 'guests' => 3])->guests);
        self::assertSame(
            ['note' => ['is required']],
            self::errors(static fn() => $validator->validate(Booking::class, $booking)),
        );
    }

    public function testAnInputMarkedRejectUnknownRefusesWhatItDoesNotDeclare(): void
    {
        $validator = new Validator();

        // Ignored where it is not marked, nested in a class that is.
        self::assertSame(
            'A',
            $validator->validate(StrictBooking::class, [
                'room' => 'A',
                'address' => ['city' => 'Hanoi', 'zip' => '100000'],
            ])->room,
        );
        self::assertSame(
            ['room' => ['is required'], 'admin' => ['is not expected']],
            self::errors(static fn() => $validator->validate(StrictBooking::class, [
                'address' => ['city' => 'Hanoi'],
                'admin' => true,
            ])),
        );
        self::assertSame(
            ['value' => ['is required'], 'extra' => ['is not expected']],
            self::errors(static fn() => $validator->validate(ChangeSetting::class, [
                'setting' => 'sound',
                'extra' => 1,
            ])),
        );
    }

    public function testDescribedDataMarkedRejectUnknownRefusesItAtEveryDepth(): void
    {
        $api = $this->api();

        self::assertSame(
            ['Ada', 'Hanoi'],
            $api
                ->post('/profile', [
                    'name' => 'Ada',
                    'address' => ['city' => 'Hanoi'],
                ])
                ->assertStatus(200)
                ->json(),
        );
        self::assertSame(
            ['address.zip' => ['is not expected'], 'role' => ['is not expected']],
            $api
                ->post('/profile', ['name' => 'Ada', 'address' => ['city' => 'Hanoi', 'zip' => '1'], 'role' => 'admin'])
                ->assertStatus(422)
                ->json('error.fields'),
        );
        // The query string is refused the same way, by its own names.
        self::assertSame(
            ['sort' => ['is not expected']],
            $api->get('/search', ['q' => 'mug', 'sort' => 'price'])->assertStatus(422)->json('error.query'),
        );
    }

    public function testARuleOnTheObjectSeesItBuiltAndNamesTheFieldItIsAbout(): void
    {
        $validator = new Validator();

        $slot = self::slot();
        self::assertSame(
            '2026-01-31T10:00:00+00:00',
            $validator->validate(Slot::class, $slot)->startsAt->format(DATE_ATOM),
        );
        self::assertSame(
            ['slot.endsAt' => ['must be after the start']],
            self::errors(static fn() => $validator->validate(Booking::class, [
                'room' => 'A',
                'note' => null,
                'address' => ['city' => 'Hanoi'],
                'slot' => ['startsAt' => $slot['endsAt'], 'endsAt' => $slot['startsAt']],
            ])),
        );
        // It runs once the fields are valid, and never sees a field that is not.
        self::assertSame(
            ['endsAt' => ['must be a date or a date and time, such as 2026-01-31 or 2026-01-31T12:00:00Z']],
            self::errors(static fn() => $validator->validate(Slot::class, [...$slot, 'endsAt' => 'soon'])),
        );

        $api = $this->api();
        self::assertSame(
            ['endsAt' => ['must be after the start']],
            $api
                ->post('/slots', ['startsAt' => '2026-01-31', 'endsAt' => '2026-01-30'])
                ->assertStatus(422)
                ->json('error.fields'),
        );
        self::assertSame(
            ['ok' => true],
            $api->post('/slots', [
                'startsAt' => '2026-01-30',
                'endsAt' => '2026-01-31',
            ])->json(),
        );
    }

    public function testARuleThatNamesNoFieldIsAMistakeOfTheApplication(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(Unnamed::class . '::check() must return what is wrong by the name of a field');
        new Validator()->validate(NamesNoField::class, ['id' => 1]);
    }

    public function testAUnionOfScalarsTakesWhatJsonSaysAndTextAsText(): void
    {
        $api = $this->api();

        foreach ([['sound', true], ['volume', 40], ['theme', 'dark'], ['theme', null]] as [$setting, $value]) {
            self::assertSame(
                ['setting' => $setting, 'value' => $value],
                $api->put('/settings', ['setting' => $setting, 'value' => $value])->assertStatus(200)->json(),
            );
        }
        self::assertSame(
            ['value' => ['does not fit the setting volume']],
            $api->put('/settings', ['setting' => 'volume', 'value' => '40'])->assertStatus(422)->json('error.fields'),
        );
        self::assertSame(
            ['value' => ['must be a whole number, true or false or text']],
            $api->put('/settings', ['setting' => 'volume', 'value' => 1.5])->assertStatus(422)->json('error.fields'),
        );
        // Text is read as the first kind it can be, where text itself is not one.
        self::assertSame([3, true], $api->get('/pick', ['a' => '3', 'b' => 'true'])->json());
        self::assertSame(
            ['a' => ['must be a whole number or true or false']],
            $api->get('/pick', ['a' => 'x', 'b' => '1'])->assertStatus(422)->json('error.query'),
        );
    }

    public function testTheShapeIsDescribedAndMistakesAreRefusedWhenTheRouteIsAdded(): void
    {
        $app = App::configure()->create();
        $app->router->put('/settings', static fn(ChangeSetting $change): null => null);
        $schema = $app->router->input($app->router->routes()[0])['body'] ?? [];

        self::assertSame(['integer', 'boolean', 'string', 'null'], $schema['properties']['value']['type']);
        self::assertSame(['setting', 'value'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame([ValueFitsSetting::class], $schema['x-rules']);

        foreach ([
            'has #[RejectUnknown], which is for an object' => static fn() => $app->router->get(
                '/a',
                static fn(#[FromQuery, RejectUnknown] string $q): null => null,
            ),
            'marks $slot #[' . EndsAfterStarts::class . '], which belongs on the class ' . Slot::class . ' itself' =>
                static fn() => $app->router->post('/b', static fn(#[EndsAfterStarts] Slot $slot): null => null),
            'describes "value", which is declared as int|array, and a union can be validated only' =>
                static fn() => $app->router->post(
                    '/c',
                    static fn(#[Fields(['value' => 'int|array'])] Data $input): null => null,
                ),
        ] as $message => $attempt) {
            try {
                $attempt();
                self::fail("Not refused: {$message}");
            } catch (\LogicException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }

    private function api(): Api
    {
        $app = App::configure()->create();
        $app->router->post('/profile', static fn(
            #[Fields(['name' => 'string', 'address' => ['city' => 'string']]), RejectUnknown]
            Data $profile,
        ): array => [$profile->name, $profile->address->city]);
        $app->router->get('/search', static fn(
            #[FromQuery, Fields(['q' => 'string']), RejectUnknown]
            Data $search,
        ): array => [$search->q]);
        $app->router->post('/slots', static fn(
            #[Fields(['startsAt' => \DateTimeImmutable::class, 'endsAt' => \DateTimeImmutable::class]), EndsAfterStarts]
            Data $slot,
        ): array => ['ok' => true]);
        $app->router->put('/settings', static fn(ChangeSetting $change): array => [
            'setting' => $change->setting,
            'value' => $change->value,
        ]);
        $app->router->get('/pick', static fn(#[FromQuery] int|bool $a, #[FromQuery] int|bool $b): array => [$a, $b]);
        return new Api($app);
    }

    /**
     * @return array{startsAt: string, endsAt: string}
     */
    private static function slot(): array
    {
        return ['startsAt' => '2026-01-31T10:00:00Z', 'endsAt' => '2026-01-31T11:00:00Z'];
    }

    /**
     * @return array<string, list<string>>
     */
    private static function errors(\Closure $validate): array
    {
        try {
            $validate();
        } catch (ValidationException $invalid) {
            return $invalid->errors;
        }
        self::fail('The data was accepted.');
    }
}
