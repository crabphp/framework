<?php

declare(strict_types=1);

namespace Crab\Tests\Validation;

use Crab\App;
use Crab\Http\Request;
use Crab\Validation\ValidationException;
use Crab\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final readonly class AmountInput
{
    public function __construct(
        public float $amount,
    ) {}
}

final readonly class TimestampInput
{
    public function __construct(
        public \DateTimeImmutable $at,
    ) {}
}

final class ValueTest extends TestCase
{
    /**
     * @return list<array{mixed, bool}>
     */
    public static function nonFiniteNumbers(): array
    {
        return [[INF, false], [-INF, false], [NAN, false], ['1e999', true], ['-1e999', true]];
    }

    #[DataProvider('nonFiniteNumbers')]
    public function testAFloatFieldRejectsEveryNonFiniteValue(mixed $value, bool $coerce): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('amount must be a number.');
        new Validator()->validate(AmountInput::class, ['amount' => $value], coerce: $coerce);
    }

    public function testOverflowFromJsonOrQueryTextIsAnsweredBeforeTheActionRuns(): void
    {
        $app = new App();
        $ran = false;
        $action = static function (float $amount) use (&$ran): array {
            $ran = true;
            return ['accepted' => true];
        };
        $app->router->get('/amount', $action);
        $app->router->post('/amount', $action);
        foreach ([
            new Request('GET', '/amount?amount=1e999', ['Accept' => 'application/json']),
            new Request(
                'POST',
                '/amount',
                ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
                '{"amount":1e999}',
            ),
        ] as $request) {
            $response = $app->handle($request);
            self::assertSame(422, $response->getStatusCode());
            self::assertSame(
                ['must be a number'],
                json_decode((string) $response->getBody(), true)['error']['fields']['amount'],
            );
            self::assertFalse($ran);
        }
    }

    public function testFiniteNumbersKeepTheirExistingTypesAndCoercion(): void
    {
        $validator = new Validator();
        foreach ([0, 1, -1.25, PHP_FLOAT_MAX] as $number) {
            self::assertSame((float) $number, $validator->validate(AmountInput::class, ['amount' => $number])->amount);
        }
        foreach (['1.25', '-2', '1e308'] as $text) {
            self::assertSame(
                (float) $text,
                $validator->validate(AmountInput::class, ['amount' => $text], coerce: true)->amount,
            );
        }
        $this->expectException(ValidationException::class);
        $validator->validate(AmountInput::class, ['amount' => '1.25']);
    }

    /**
     * @return list<array{string}>
     */
    public static function invalidTimestamps(): array
    {
        return [
            ['now'],
            ['+1000 years'],
            ['next Monday'],
            ['@0'],
            ['2026-02-31'],
            ['2026-1-31'],
            ["2026-01-31\n"],
            ['2026-01-31 12:00:00'],
            ['2026-01-31T12:00:00'],
            ['2026-01-31T12:00:00Z +1 day'],
            ["2026-01-31T12:00:00Z\n"],
            ['2026-02-31T12:00:00Z'],
            ['2026-02-29T12:00:00Z'],
            ['2026-01-31T24:00:00Z'],
            ['2026-01-31T12:00:00+99:00'],
            ["2026-01-31T12:00:00Z\0"],
        ];
    }

    #[DataProvider('invalidTimestamps')]
    public function testADateRequiresTheFormsPromisedByItsMessage(string $text): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(
            'at must be a date or a date and time, such as 2026-01-31 or 2026-01-31T12:00:00Z.',
        );
        new Validator()->validate(TimestampInput::class, ['at' => $text]);
    }

    public function testACalendarDateIsReadAsItsMidnight(): void
    {
        self::assertSame(
            '2026-02-28 00:00:00',
            new Validator()->validate(TimestampInput::class, ['at' => '2026-02-28'])->at->format('Y-m-d H:i:s'),
        );
    }

    public function testAbsoluteTimestampsKeepTheirDateTimeAndOffset(): void
    {
        $validator = new Validator();
        foreach ([
            '2026-01-31T12:00:00Z' => '2026-01-31T12:00:00+00:00',
            '2026-01-31T12:00:00+07:00' => '2026-01-31T12:00:00+07:00',
            '2026-01-31T12:00:00-03:30' => '2026-01-31T12:00:00-03:30',
            '2024-02-29T00:00:00Z' => '2024-02-29T00:00:00+00:00',
        ] as $text => $expected) {
            self::assertSame(
                $expected,
                $validator->validate(TimestampInput::class, ['at' => $text])->at->format(DATE_ATOM),
            );
        }
    }
}
