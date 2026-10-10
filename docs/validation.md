# Validation

- [Introduction](#introduction)
- [Types are rules](#types-are-rules)
- [Rule attributes](#rule-attributes)
- [Nested objects and lists](#nested-objects-and-lists)
- [The error response](#the-error-response)
- [Writing a rule](#writing-a-rule)
- [Validating by hand](#validating-by-hand)

## Introduction

Crab validates a request against the action's signature before the action runs. You do not call a validator and you do not write a rules array: the parameter's type says what is accepted, and attributes add what a type cannot say.

```php
use Crab\Validation\Email;
use Crab\Validation\Length;

Route::post('/users', static function (#[Email] string $email, #[Length(min: 2, max: 50)] string $name): array {
    // Runs only when both are present and fit.
});
```

A request that does not fit is answered `422` with every problem, by field. The same holds for the constructor of an [input class](actions.md#an-input-class).

## Types are rules

| Declaration | Accepts |
| --- | --- |
| `int`, `float`, `string`, `bool` | That value |
| `?type`, or a default value | May be left out |
| A backed enum | One of its values |
| `DateTimeImmutable` | A date and time as text, such as `2026-01-31T12:00:00Z` |
| Another class | An object, checked the same way |
| `array` with `#[ListOf(...)]` | A list of the given kind |

JSON is taken as it is: `"36"` is text and is refused for an `int` field. A query string and a route parameter are always text, so there `page=3` is read as the number `3` and `active=true` as a boolean.

```php
enum Status: string
{
    case Draft = 'draft';
    case Published = 'published';
}

Route::get('/posts', static function (Status $status = Status::Published, ?\DateTimeImmutable $since = null): array {
    // GET /posts?status=draft&since=2026-01-01T00:00:00Z
});
```

Prefer an enum to `#[OneOf]` when the choices are used in more than one place: the type then carries the rule everywhere.

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

They live in `Crab\Validation`. A rule runs only when the value is present and of the right type, so `#[Email] ?string $email` accepts a missing email and checks one that is given.

## Nested objects and lists

A parameter typed as another class is an object in the data, checked by that class's constructor. `#[ListOf]` says what an array holds:

```php
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

A problem inside is reported by its path: `shipTo.country`, `lines.0.quantity`.

Every class is read when the route is added, so one that cannot be validated, such as a constructor parameter with no type, stops the server from starting.

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
        return preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', (string) $value) === 1
            ? null
            : 'must be lower-case words joined by dashes';
    }
}
```

```php
Route::post('/posts', static fn (#[Slug] string $slug, string $title): array => [...]);
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
