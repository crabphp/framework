<?php

declare(strict_types=1);

namespace Crab\Tests\Validation;

use Crab\App;
use Crab\Config\Config;
use Crab\Http\Request;
use Crab\Validation\Length;
use Crab\Validation\Pattern;
use PHPUnit\Framework\TestCase;

/**
 * Rules checked on their own, on the values a client can send that a
 * rule could misread.
 */
final class RuleTest extends TestCase
{
    public function testAPatternEndingInDollarRefusesATrailingLineBreak(): void
    {
        $slug = new Pattern('^[a-z0-9-]+$', 'must be a slug');

        self::assertNull($slug->check('abc'));
        self::assertSame('must be a slug', $slug->check("abc\n"));
    }

    public function testALengthRefusesTextThatIsNotValidUtf8(): void
    {
        $name = new Length(max: 8);

        self::assertSame('must be valid UTF-8 text', $name->check("\xFF" . str_repeat('A', 20)));
        self::assertSame('must be valid UTF-8 text', new Length(min: 1)->check("\xC3"));
    }

    public function testALengthCountsCharactersNotBytes(): void
    {
        self::assertNull(new Length(max: 4)->check('héé!'));
        self::assertSame('must be at most 3 character(s)', new Length(max: 3)->check('héé!'));
    }

    public function testALengthRefusesValuesThatAreNeitherTextNorAnArray(): void
    {
        $length = new Length(max: 30);
        foreach ([new \DateTimeImmutable('2026-01-31'), new \stdClass(), 123, 1.5, true] as $value) {
            self::assertSame('must be text or a list', $length->check($value));
        }
        self::assertNull($length->check('text'));
        self::assertNull($length->check(['one', 'two']));
        self::assertSame('must have at most 1 item(s)', new Length(max: 1)->check(['one', 'two']));
    }

    public function testALengthOnADateReturnsAFieldProblemBeforeCallingTheAction(): void
    {
        $app = new App(new Config(['log' => ['path' => 'php://memory']]));
        $called = false;
        $app->router->post('/date', static function (#[Length(max: 30)] \DateTimeImmutable $day) use (&$called): void {
            $called = true;
        });

        $response = $app->handle(
            new Request(
                'POST',
                '/date',
                [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                '{"day":"2026-01-31"}',
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ['day' => ['must be text or a list']],
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['fields'],
        );
        self::assertFalse($called);
    }
}
