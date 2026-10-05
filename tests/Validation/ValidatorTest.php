<?php

declare(strict_types=1);

namespace Crab\Tests\Validation;

use Crab\Contracts\Validation\Rule;
use Crab\Validation\Email;
use Crab\Validation\Length;
use Crab\Validation\ListOf;
use Crab\Validation\OneOf;
use Crab\Validation\Pattern;
use Crab\Validation\Range;
use Crab\Validation\Url;
use Crab\Validation\ValidationException;
use Crab\Validation\Validator;
use PHPUnit\Framework\TestCase;

enum Role: string
{
    case Admin = 'admin';
    case Member = 'member';
}

enum Level: int
{
    case Low = 1;
    case High = 2;
}

enum Unbacked
{
    case One;
}

final readonly class Address
{
    public function __construct(
        public string $city,
        #[Pattern('^[0-9]{5}$', 'must be five digits')] public string $zip,
    ) {
    }
}

final readonly class Tag
{
    public function __construct(#[Length(min: 2)] public string $name)
    {
    }
}

/**
 * A rule an application wrote.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Even implements Rule
{
    public function check(mixed $value): ?string
    {
        return $value % 2 === 0 ? null : 'must be even';
    }

    public function schema(string $type): array
    {
        return ['multipleOf' => 2];
    }
}

final readonly class Signup
{
    /**
     * @param list<Tag> $tags
     * @param list<int> $scores
     */
    public function __construct(
        #[Email] public string $email,
        #[Length(min: 2, max: 10)] public string $name,
        public Role $role,
        public Address $address,
        #[Range(min: 0, max: 150)] public ?int $age = null,
        public float $score = 1.5,
        public bool $newsletter = false,
        public ?\DateTimeImmutable $born = null,
        #[ListOf(Tag::class), Length(max: 2)] public array $tags = [],
        #[ListOf('int')] public array $scores = [],
        #[Url] public ?string $website = null,
        #[OneOf('light', 'dark')] public string $theme = 'light',
        public Level $level = Level::Low,
        #[Even] public int $pairs = 0,
        public array $extra = [],
        public mixed $anything = null,
        public ?Address $billing = null,
    ) {
    }
}

final readonly class Search
{
    public function __construct(
        public int $page = 1,
        public ?float $radius = null,
        public bool $open = false,
        public ?Level $level = null,
        public string $q = '',
        public ?int $limit = null,
    ) {
    }
}

final readonly class Node
{
    public function __construct(public string $name, public ?Node $parent = null)
    {
    }
}

final class Union
{
    public function __construct(public int|string $id)
    {
    }
}

final class Loose
{
    public function __construct(public iterable $items, public Unbacked $choice = Unbacked::One)
    {
    }
}

final class TextList
{
    public function __construct(#[ListOf('int')] public string $numbers)
    {
    }
}

final class WithUnbacked
{
    public function __construct(public Unbacked $choice)
    {
    }
}

final class Empty_
{
}

abstract class Shape
{
}

final class ValidatorTest extends TestCase
{
    private const VALID = [
        'email' => 'ada@example.com',
        'name' => 'Ada',
        'role' => 'admin',
        'address' => ['city' => 'London', 'zip' => '12345'],
    ];

    public function testDataBecomesATypedObject(): void
    {
        $validator = new Validator();
        $signup = $validator->validate(Signup::class, self::VALID + ['ignored' => 'a field the class does not name']);
        self::assertSame(['ada@example.com', 'Ada', Role::Admin, 'London', null, 1.5, false, null, [], 'light', Level::Low], [
            $signup->email, $signup->name, $signup->role, $signup->address->city, $signup->age, $signup->score,
            $signup->newsletter, $signup->born, $signup->tags, $signup->theme, $signup->level,
        ]);

        $full = $validator->validate(Signup::class, self::VALID + [
            'age' => 36, 'score' => 2, 'newsletter' => true, 'born' => '1815-12-10T00:00:00Z',
            'tags' => [['name' => 'math'], ['name' => 'engine']], 'scores' => [1, 2, 3], 'website' => 'https://example.com/a',
            'theme' => 'dark', 'level' => 2, 'pairs' => 4, 'extra' => ['any' => ['shape']], 'anything' => 3.5,
            'billing' => ['city' => 'Paris', 'zip' => '75001'],
        ]);
        self::assertSame([36, 2.0, true, '1815-12-10', 'engine', [1, 2, 3], Level::High, 4, ['any' => ['shape']], 3.5, 'Paris'], [
            $full->age, $full->score, $full->newsletter, $full->born->format('Y-m-d'), $full->tags[1]->name, $full->scores,
            $full->level, $full->pairs, $full->extra, $full->anything, $full->billing->city,
        ]);
        self::assertInstanceOf(Tag::class, $full->tags[0]);
        self::assertNull($validator->validate(Signup::class, self::VALID + ['age' => null, 'billing' => null])->billing);
        self::assertInstanceOf(Empty_::class, $validator->validate(Empty_::class, ['anything' => 1]));
    }

    public function testEveryProblemIsReportedByField(): void
    {
        $validator = new Validator();
        try {
            $validator->validate(Signup::class, [
                'email' => 'not an email',
                'name' => 'A',
                'role' => 'owner',
                'address' => ['zip' => 'abc'],
                'age' => 200,
                'score' => 'high',
                'newsletter' => 'yes',
                'born' => 'someday',
                'tags' => [['name' => 'ok'], ['name' => 'x'], 'not an object'],
                'scores' => [1, '2'],
                'website' => 'ftp://example.com',
                'theme' => 'blue',
                'level' => '2',
                'pairs' => 3,
                'extra' => 'text',
                'billing' => [1, 2],
            ]);
            self::fail('Invalid data was accepted.');
        } catch (ValidationException $invalid) {
            self::assertSame([
                'email' => ['must be an email address'],
                'name' => ['must be at least 2 character(s)'],
                'role' => ['must be one of: admin, member'],
                'address.city' => ['is required'],
                'address.zip' => ['must be five digits'],
                'age' => ['must be at most 150'],
                'score' => ['must be a number'],
                'newsletter' => ['must be true or false'],
                'born' => ['must be a date and time, such as 2026-01-31T12:00:00Z'],
                'tags.1.name' => ['must be at least 2 character(s)'],
                'tags.2' => ['must be an object'],
                'scores.1' => ['must be a whole number'],
                'website' => ['must be a web address starting with http:// or https://'],
                'theme' => ['must be one of: light, dark'],
                'level' => ['must be one of: 1, 2'],
                'pairs' => ['must be even'],
                'extra' => ['must be a list or an object'],
                'billing' => ['must be an object'],
            ], $invalid->errors);
            self::assertSame('email must be an email address, and 17 more invalid field(s).', $invalid->getMessage());
        }

        $problems = static function (array $data) use ($validator): array {
            try {
                $validator->validate(Signup::class, $data);
            } catch (ValidationException $invalid) {
                return $invalid->errors;
            }
            return [];
        };
        self::assertSame(
            ['email' => ['is required'], 'name' => ['is required'], 'role' => ['is required'], 'address' => ['is required']],
            $problems([]),
        );
        self::assertSame(['name' => ['must not be null']], $problems(['name' => null] + self::VALID));
        self::assertSame(['name' => ['must be text']], $problems(['name' => 7] + self::VALID));
        self::assertSame(['name' => ['must be at most 10 character(s)']], $problems(['name' => str_repeat('é', 11)] + self::VALID));
        self::assertSame([], $problems(['name' => str_repeat('é', 10)] + self::VALID), 'Length counts characters, not bytes.');
        self::assertSame(['age' => ['must be a whole number']], $problems(['age' => '36'] + self::VALID), 'JSON says what a number is; text is not one.');
        self::assertSame(['age' => ['must be a whole number']], $problems(['age' => 36.5] + self::VALID));
        self::assertSame(['tags' => ['must be a list']], $problems(['tags' => ['a' => ['name' => 'math']]] + self::VALID));
        self::assertSame(['scores' => ['must be a list']], $problems(['scores' => 5] + self::VALID));
        self::assertSame(['tags' => ['must have at most 2 item(s)']], $problems(['tags' => [['name' => 'aa'], ['name' => 'bb'], ['name' => 'cc']]] + self::VALID));
        self::assertSame(['born' => ['must be a date and time, such as 2026-01-31T12:00:00Z']], $problems(['born' => ''] + self::VALID));
        self::assertSame('name must not be null.', (new ValidationException(['name' => ['must not be null']]))->getMessage());
    }

    public function testTextFromAQueryStringIsReadAsItsType(): void
    {
        $validator = new Validator();
        $search = $validator->validate(Search::class, ['page' => '3', 'radius' => '2.5', 'open' => 'true', 'level' => '2', 'q' => '', 'limit' => ''], coerce: true);
        self::assertSame([3, 2.5, true, Level::High, '', null], [$search->page, $search->radius, $search->open, $search->level, $search->q, $search->limit]);
        self::assertFalse($validator->validate(Search::class, ['open' => '0'], coerce: true)->open);
        self::assertSame(-4, $validator->validate(Search::class, ['page' => '-4'], coerce: true)->page);
        try {
            $validator->validate(Search::class, ['page' => '3rd', 'radius' => 'far', 'open' => 'maybe', 'level' => '9'], coerce: true);
            self::fail('Text that is not a number was read as one.');
        } catch (ValidationException $invalid) {
            self::assertSame([
                'page' => ['must be a whole number'],
                'radius' => ['must be a number'],
                'open' => ['must be true or false'],
                'level' => ['must be one of: 1, 2'],
            ], $invalid->errors);
        }
    }

    public function testAClassIsDescribedAsJsonSchema(): void
    {
        $schema = (new Validator())->schema(Signup::class);
        self::assertSame(['email', 'name', 'role', 'address'], $schema['required']);
        $address = [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string'], 'zip' => ['type' => 'string', 'pattern' => '^[0-9]{5}$']],
            'required' => ['city', 'zip'],
        ];
        self::assertSame([
            'email' => ['type' => 'string', 'format' => 'email'],
            'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 10],
            'role' => ['type' => 'string', 'enum' => ['admin', 'member']],
            'address' => $address,
            'age' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 150, 'default' => null],
            'score' => ['type' => 'number', 'default' => 1.5],
            'newsletter' => ['type' => 'boolean', 'default' => false],
            'born' => ['type' => ['string', 'null'], 'format' => 'date-time', 'default' => null],
            'tags' => [
                'type' => 'array',
                'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'minLength' => 2]], 'required' => ['name']],
                'maxItems' => 2,
            ],
            'scores' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'website' => ['type' => ['string', 'null'], 'format' => 'uri', 'default' => null],
            'theme' => ['type' => 'string', 'enum' => ['light', 'dark'], 'default' => 'light'],
            'level' => ['type' => 'integer', 'enum' => [1, 2]],
            'pairs' => ['type' => 'integer', 'multipleOf' => 2, 'default' => 0],
            'extra' => ['type' => ['array', 'object']],
            'anything' => ['default' => null],
            'billing' => ['type' => ['object', 'null']] + $address + ['default' => null],
        ], $schema['properties']);
        self::assertSame('{"type":"object","properties":{}}', json_encode((new Validator())->schema(Empty_::class)));
    }

    public function testAClassThatCannotBeValidatedSaysWhy(): void
    {
        $validator = new Validator();
        $refused = [
            'union or intersection type' => static fn () => $validator->schema(Union::class),
            'is declared as iterable, which cannot be validated; use int' => static fn () => $validator->schema(Loose::class),
            'has #[ListOf] but is not declared as an array' => static fn () => $validator->schema(TextList::class),
            'which has no values; give its cases values' => static fn () => $validator->validate(WithUnbacked::class, []),
            'there is no such class' => static fn () => $validator->validate('App\Missing', []),
            'it cannot be constructed' => static fn () => $validator->validate(Shape::class, []),
            'contains itself' => static fn () => $validator->schema(Node::class),
            'Length needs a min, a max or both' => static fn () => new Length(),
            'Range needs a min, a max or both' => static fn () => new Range(),
            'OneOf needs at least one value' => static fn () => new OneOf(),
            'invalid regular expression: (' => static fn () => new Pattern('('),
        ];
        foreach ($refused as $expected => $attempt) {
            try {
                $attempt();
                self::fail("Not refused: {$expected}");
            } catch (\LogicException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
        $child = $validator->validate(Node::class, ['name' => 'child', 'parent' => ['name' => 'root']]);
        self::assertSame('root', $child->parent->name, 'A class that contains itself can still be validated.');
        self::assertSame('must be a number', (new Range(min: 1))->check('1'));
        self::assertSame(['minimum' => 1], (new Range(min: 1))->schema('integer'));
        self::assertSame(['maxLength' => 3], (new Length(max: 3))->schema('string'));
        self::assertNull((new Pattern('^a~b$'))->check('a~b'));
    }
}
