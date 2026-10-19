<?php

declare(strict_types=1);

namespace Crab\Tests\Validation;

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
}
