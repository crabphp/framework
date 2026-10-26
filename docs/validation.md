# Validation

- [Introduction](#introduction)
- [Types are rules](#types-are-rules)
    - [Required, optional and null](#required-optional-and-null)
    - [A value of several kinds](#a-value-of-several-kinds)
- [Rule attributes](#rule-attributes)
- [Nested objects and lists](#nested-objects-and-lists)
- [Describing fields](#describing-fields)
- [Refusing unknown fields](#refusing-unknown-fields)
- [Rules across fields](#rules-across-fields)
- [The error response](#the-error-response)
- [Writing a rule](#writing-a-rule)
- [Validating by hand](#validating-by-hand)

## Introduction

Crab validates a request against the action's signature before the action runs. You do not call a validator and you do not write a rules array: a field's type says what is accepted, and attributes add what a type cannot say.

```php
use Crab\Validation\Email;
use Crab\Validation\Length;

final readonly class CreateUser
{
    public function __construct(
        #[Email]
        public string $email,
        #[Length(min: 2, max: 50)]
        public string $name,
    ) {
    }
}

Route::post('/users', static function (CreateUser $input): array {
    // Runs only when both are present and fit.

    return ['email' => $input->email, 'name' => $input->name];
});
```

A request that does not fit is answered `422` with every problem, by field. The same rules hold wherever a field is declared: a property of an [input class](actions.md#an-input-class), a field described with [`#[Fields]`](#describing-fields), a [query field](actions.md#query-fields) or a parameter of the path.

## Types are rules

| Declaration | Accepts |
| --- | --- |
| `int`, `float`, `string`, `bool` | That value |
| `?type` | That, or null |
| A default value, `int $page = 1` | May be left out |
| `bool\|int\|string` | A value of any of these scalar types; see [A value of several kinds](#a-value-of-several-kinds) |
| A backed enum | One of its values |
| `DateTimeImmutable` | A date, or a date and time with an offset, such as `2026-01-31` or `2026-01-31T12:00:00Z`; not a phrase such as `now` |
| Another class | An object, checked the same way |
| `array` with `#[ListOf(...)]` | A list of the given kind |
| `Psr\Http\Message\UploadedFileInterface` | A file of a `multipart/form-data` form, received whole; see [Uploaded files](requests.md#uploaded-files) |

JSON is taken as it is: `"36"` is text and is refused for an `int` field. A query string, a route parameter and a form (`application/x-www-form-urlencoded` or `multipart/form-data`) are always text, so there `page=3` is read as the number `3` and `active=true` as a boolean.

```php
use Crab\Routing\FromQuery;

enum Status: string
{
    case Draft = 'draft';
    case Published = 'published';
}

Route::get('/posts', static function (
    #[FromQuery] Status $status = Status::Published,
    #[FromQuery] ?\DateTimeImmutable $since = null,
): array {
    // GET /posts?status=draft&since=2026-01-01T00:00:00Z

    return ['status' => $status, 'since' => $since];
});
```

Prefer an enum to `#[OneOf]` when the choices are used in more than one place: the type then carries the rule everywhere.

### Required, optional and null

Whether a field may be left out and whether it may be null are two questions, answered as PHP answers them for an argument: a default says it may be left out, `?` says it may be null.

| Declaration | Left out | Sent as null |
| --- | --- | --- |
| `string $name` | `is required` | `must not be null` |
| `?string $note` | `is required` | null |
| `?string $note = null` | null | null |
| `int $guests = 1` | 1 | `must not be null` |

So `?string $note` asks the client to say the note, if only to say there is none. A query string and a form have no null: there, a nullable field that is not text reads an empty value, `?age=`, as null.

To take a field that may be left out but never cleared, as an update does, describe it: `'name?' => 'string'`. `Data::has('name')` says whether it was sent; see [Described fields](actions.md#described-fields).

### A value of several kinds

A union of scalar types takes a value of any of them, for a field whose kind depends on another, such as a setting's value:

```php
public bool|int|string|null $value,
```

JSON says which kind a value is, and that is the kind it arrives as: `true`, `40` or `"dark"`. A float is taken only where `float` is in the union. Text, from a query string or a form, says nothing of its kind, so it stays text when `string` is in the union, and is otherwise read as the first of `int`, `float` and `bool` it can be. A union may hold only these four, and null; give a field that holds an object or a list one type. Check which kind fits with a [rule across fields](#rules-across-fields).

## Rule attributes

| Attribute | Checks |
| --- | --- |
| `#[Email]` | An email address |
| `#[Url]` | A URL |
| `#[Length(min: 2, max: 50)]` | Characters of a text, or items of a list; either bound may be left out |
| `#[Range(min: 1, max: 100)]` | A number between the two; either bound may be left out |
| `#[Pattern('^[a-z0-9-]+$')]` | A regular expression |
| `#[OneOf('draft', 'published')]` | One of the listed values |
| `#[ListOf(Tag::class)]`, `#[ListOf('int')]` | What an `array` holds |
| `#[FileSize(max: 2 * 1024 * 1024)]` | The bytes of an uploaded file; either bound may be left out |
| `#[MediaType('image/png', 'image/*')]` | The kind of an uploaded file, read from its content; needs PHP's `fileinfo` extension |

They live in `Crab\Validation`; import each rule you use. An attribute whose class cannot be found stops route registration and names the field and missing class. A rule runs only when the value is present and of the right type, so `#[Email] ?string $email` accepts a missing email and checks one that is given.

A rule on a route parameter holds as on any field: with `#[Range(min: 1)] int $id`, a request for `/users/0` is answered `422`. A whole number too large for an integer, including the value of an integer-backed enum, and a date the calendar does not have, such as February 31st, are refused and not read as something else.

## Nested objects and lists

A parameter typed as another class is an object in the data, checked by that class's constructor. `#[ListOf]` says what an array holds:

```php
use Crab\Validation\Length;
use Crab\Validation\ListOf;

final readonly class Address
{
    public function __construct(
        public string $street,
        #[Length(min: 2, max: 2)]
        public string $country,
    ) {
    }
}

final readonly class CreateOrder
{
    public function __construct(
        public Address $shipTo,
        #[ListOf(OrderLine::class), Length(min: 1)]
        public array $lines,
    ) {
    }
}
```

A typed list is checked against its `Length` bounds before any item is validated or constructed. A list outside those bounds reports its size error on the list's field; items inside an accepted list report their own errors as usual.

A problem inside is reported by its path: `shipTo.country`, `lines.0.quantity`.

Every class is read when the route is added, so one that cannot be validated, such as a constructor parameter with no type, stops the server from starting.

## Describing fields

`#[Fields]` describes data where it is used, in place of a class written for it; see [Actions](actions.md#described-fields). Each field's name comes with one of these:

| Description | Accepts |
| --- | --- |
| `'int'` | A type: `int`, `float`, `string`, `bool`, `array`, `mixed`, an enum's class, a class, `DateTimeImmutable::class` or `UploadedFileInterface::class` |
| `'?int'` | The same, or null |
| `'bool\|int\|string'` | One of these scalar types |
| `['int', new Range(min: 1)]` | A type, then rules for it |
| `['street' => 'string', 'city' => 'string']` | An object with these fields |
| `[['street' => 'string']]` | A list of those objects |
| `[['int']]` | A list of ints |
| `[[...], new Length(min: 1)]` | A list, then rules for the list |

A name ending in `?`, as in `'shipTo?' => [...]`, is a field that may be left out, whether it describes a scalar, an object or a list. A field described there is sent unless its name says so, and is null only when its type says so: `'note?' => '?string'` may be both. An object or a list described in place is never null; for one that may be, name a class, `'?' . Address::class`. Rules and nested fields are checked only for non-null values. A supplied null stays in `Data::toArray()`; an omitted field does not.

```php
#[Fields([
    'email' => ['string', new Email()],
    'plan' => Plan::class,
    'note?' => '?string',
    'shipTo?' => ['street' => 'string', 'country' => ['string', new Length(min: 2, max: 2)]],
    'tags' => [['string'], new Length(max: 5)],
])]
Data $signup,
```

What is described arrives as `Crab\Validation\Data`: read-only, its fields read as properties, an object inside it another `Data`. The rules are the same attribute classes, written with `new`.

## Refusing unknown fields

Fields an input does not declare are ignored, so a client that sends more than you read is not refused. Mark the input `#[RejectUnknown]` to refuse them instead, where an extra field is a client's mistake worth hearing about, such as a settings form whose keys must all be known:

```php
use Crab\Validation\RejectUnknown;

#[RejectUnknown]
final readonly class ChangeSetting
{
    public function __construct(
        public Setting $setting,
        public bool|int|string|null $value,
    ) {
    }
}
```

```json
{"error": {"status": 422, "message": "The request is invalid.", "fields": {"theme": ["is not expected"]}}}
```

On a class it holds for that class's own fields; a nested class refuses what it does not declare only when it is marked too. On described `Data`, `#[Fields([...]), RejectUnknown] Data $changes`, it holds for every object the description has. A query string is refused the same way, by its own names, with a marked query class or described `Data`: `#[FromQuery, Fields(['q' => 'string']), RejectUnknown] Data $search`. `describe` shows it as `additionalProperties: false`.

## Rules across fields

A rule on one field sees one value. For what only the object as a whole can say, an end after its start, a value whose kind depends on its setting, write an attribute class that implements `Crab\Contracts\Validation\ObjectRule` and put it on the class:

```php
// app/Rules/EndsAfterStarts.php
namespace App\Rules;

use Crab\Contracts\Validation\ObjectRule;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PARAMETER)]
final readonly class EndsAfterStarts implements ObjectRule
{
    public function check(object $input): array
    {
        return $input->endsAt > $input->startsAt ? [] : ['endsAt' => 'must be after the start'];
    }
}
```

```php
#[EndsAfterStarts]
final readonly class Booking
{
    public function __construct(
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
    ) {
    }
}
```

`check()` receives the object built from the data, an instance of the class or the `Data` it is on (`#[Fields([...]), EndsAfterStarts] Data $booking`), and returns what is wrong by the name of the field it is told against. It runs only once every field of the object is valid, so it never sees a value of the wrong type. A problem inside a nested object is reported by its path, `slot.endsAt`.

A rule like this is a pure check of the data. Whether this user may book this room, or the room is free, needs the database or the user, and belongs in the action, which throws `HttpException(409)` or answers with a `422` of its own.

## The error response

```json
{"error": {"status": 422, "message": "The request is invalid.", "fields": {
    "email": ["must be an email address"],
    "shipTo.country": ["is required"]
}}}
```

Every problem is reported at once, so a client can show them all. The response is an ordinary one: it passes back through your middleware like any other.

## Writing a rule

A rule is an attribute class that implements `Crab\Contracts\Validation\Rule`. `check()` returns what is wrong, worded to follow the field's name, or null:

```php
// app/Rules/Slug.php
namespace App\Rules;

use Crab\Contracts\Validation\Rule;

#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Slug implements Rule
{
    public function check(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', $value) === 1
            ? null
            : 'must be lower-case words joined by dashes';
    }
}
```

```php
use App\Rules\Slug;
use Crab\Validation\Data;
use Crab\Validation\Fields;

Route::post('/posts', static fn (#[Fields(['slug' => ['string', new Slug()], 'title' => 'string'])] Data $post): array => [
    'slug' => $post->slug,
    'title' => $post->title,
]);
```

A rule sees one value. A check that needs the database, such as "this email is not taken", belongs in the action, which throws `HttpException(409)` or returns a `422` of its own.

## Validating by hand

To validate data that did not come through a route, such as a row of an imported file, ask for the validator:

```php
use Crab\Contracts\Validation\Validator;
use Crab\Validation\ValidationException;

final readonly class ImportUsers
{
    public function __construct(private Validator $validator)
    {
    }

    public function row(array $data): void
    {
        try {
            $user = $this->validator->validate(CreateUser::class, $data);
        } catch (ValidationException $error) {
            // $error->errors is the problems by field.
        }
    }
}
```

`validate()` returns the object or throws. Pass `coerce: true` when the data is all text, as a CSV row is, to have numbers and booleans read from it.
