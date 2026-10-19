# Actions

- [Introduction](#introduction)
- [Writing an action](#writing-an-action)
    - [A closure](#a-closure)
    - [A class](#a-class)
    - [A class with several methods](#a-class-with-several-methods)
- [What an action takes](#what-an-action-takes)
    - [Fields as parameters](#fields-as-parameters)
    - [An input class](#an-input-class)
    - [Described fields](#described-fields)
    - [The request itself](#the-request-itself)
    - [The logged-in user](#the-logged-in-user)
    - [Services](#services)
- [What an action returns](#what-an-action-returns)

## Introduction

An action is the code that answers a route. In Crab an action says in its signature what it takes, and the framework does the HTTP around it: it reads the request, checks the data against the signature, calls the action, and turns what it returns into a response.

This is where Crab differs most from Laravel. There is no base controller, no `$request->validate()` and no form request class. The signature is the validation, and an action is an ordinary function you can call in a test.

```php
use Crab\Http\Response;
use Crab\Route;
use Crab\Validation\Email;
use Crab\Validation\Length;

Route::post('/users', static function (#[Email] string $email, #[Length(min: 2, max: 50)] string $name): Response {
    // $email and $name are already checked.

    return Response::created(['email' => $email, 'name' => $name]);
});
```

## Writing an action

### A closure

For a route that needs no service:

```php
Route::get('/ping', static fn (): array => ['pong' => true]);
```

### A class

One class per action, with `handle()`. Its constructor takes the services it needs, and the container makes it. Name it after what it does, verb first: `ShowUser`, `CreateOrder`, `SendInvoice`.

```php
// app/Actions/ShowUser.php
namespace App\Actions;

use Crab\Contracts\Database\Database;
use Crab\Http\HttpException;

final readonly class ShowUser
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(int $id): array
    {
        return $this->db->table('users')->find($id) ?? throw new HttpException(404, 'No such user.');
    }
}
```

```php
Route::get('/users/{id}', ShowUser::class);
```

### A class with several methods

A route may also name a class and one of its methods. Use this when a few small routes share the same services and a class each would be more files than code:

```php
Route::get('/orders/{id}', [OrderController::class, 'show']);
Route::post('/orders', [OrderController::class, 'store']);
```

```php
use App\Http\CreateOrder;
use Crab\Contracts\Database\Database;
use Crab\Http\HttpException;
use Crab\Http\Response;

final readonly class OrderController
{
    public function __construct(private Database $db)
    {
    }

    public function show(int $id): array
    {
        return $this->db->table('orders')->find($id) ?? throw new HttpException(404);
    }

    public function store(CreateOrder $input): Response
    {
        $id = $this->db->table('orders')->insert(['sku' => $input->sku, 'quantity' => $input->quantity]);

        return Response::created(['id' => $id], "/orders/{$id}");
    }
}
```

An action class extends nothing. Each route retains its resolved action callable for the worker’s life, even when the class has a transient `bind()` binding. Like every service it must not keep anything about one request in a property; see [Request lifecycle](lifecycle.md#services-hold-no-request-state).

## What an action takes

| Parameter | Is |
| --- | --- |
| Named as in the route's pattern | That route parameter, as `string`, `int`, `float` or `bool` |
| Any other `int`, `float`, `string`, `bool`, `array`, enum or `DateTimeImmutable` | A field of the request's data, by its name |
| One class | The input: all the fields in one object |
| Typed as a PSR-7 server request | The request itself |
| A class that implements `Identity` | The logged-in user |

The request's data is the JSON body, or the query string for `GET`, `HEAD` and `DELETE`. Anything else in a signature is refused when the route is added.

### Fields as parameters

Take a few flat fields as parameters, one each:

```php
Route::get('/users', static function (int $page = 1, ?string $search = null): array {
    // GET /users?page=2&search=ada

    return ['page' => $page, 'search' => $search];
});
```

Every parameter has a type: one without is refused when the route is added, since nothing says what it is. A parameter with a default or a nullable type may be left out of the request. Fields the action does not declare are ignored and never reach it.

### An input class

Use a class when there are many fields, when they nest, or when several routes take the same ones. Its constructor declares every field:

```php
// app/Http/CreateOrder.php
namespace App\Http;

use Crab\Validation\Length;
use Crab\Validation\ListOf;
use Crab\Validation\Range;

final readonly class CreateOrder
{
    public function __construct(
        #[Length(min: 1, max: 40)]
        public string $sku,
        #[Range(min: 1, max: 100)]
        public int $quantity,
        public Address $shipTo,                 // another class, checked the same way
        #[ListOf('string')]
        public array $notes = [],
    ) {
    }
}
```

An action takes either separate fields or one input, never both. [Validation](validation.md) lists the types and rules.

### Described fields

When the data nests and you would rather not write a class for it, describe its fields on the parameter with `#[Fields]`. The parameter is typed `Data`, and receives the whole of the request's data, checked:

```php
use Crab\Validation\Data;
use Crab\Validation\Fields;
use Crab\Validation\Length;
use Crab\Validation\Range;

public function handle(
    User $user,
    #[Fields([
        'note' => '?string',
        'shipTo' => ['street' => 'string', 'country' => ['string', new Length(min: 2, max: 2)]],
        'lines' => [['productId' => 'int', 'quantity' => ['int', new Range(min: 1, max: 20)]], new Length(min: 1)],
    ])]
    Data $order,
): Response {
    $order->shipTo->country;                 // "GB"
    foreach ($order->lines as $line) {
        $line->productId;                    // 5
    }
    $order->note;                            // null when it was left out

    return Response::created($order->toArray());
}
```

It holds what the request sent. A field that may be left out, and was, reads as null; `$order->has('note')` tells that from a null that was sent, and `$order->toArray()` gives only what was sent. That is what an update wants:

```php
// PATCH /users/7 with {"name": "Ada"} changes the name and leaves the rest alone
public function handle(int $id, #[Fields(['name' => '?string', 'bio' => '?string'])] Data $changes): void
{
    $values = $changes->toArray();
    if ($values === []) {
        return;
    }

    $this->db->table('users')->where('id', $id)->update($values);
}
```

An empty object, or one containing only undeclared fields, changes nothing and returns `204`. A supplied null stays in `toArray()` and writes SQL NULL, so these nullable fields must map to columns that accept NULL. Omit a field to leave its column unchanged.

It is validated before the action runs, as an input class is, and a problem is reported the same way: `lines.0.quantity`. A description that makes no sense stops the server from starting. Reading a field you did not describe throws, so a slip of the hand shows in the first test.

For a list of objects among other fields, `#[ListOf]` takes the same description:

```php
public function handle(string $name, #[ListOf(['productId' => 'int', 'quantity' => '?int'])] array $lines): array
```

Use an [input class](#an-input-class) when several routes take the same data, or when you want your editor to know the fields. [Validation](validation.md#describing-fields) lists how a field is described.

### The request itself

Take the request when you need what a signature cannot say: a header, a cookie, an uploaded file, the raw body.

```php
use Crab\Contracts\Http\Request;

Route::post('/users/{id}/avatar', static function (int $id, Request $request): array {
    $file = $request->file('avatar');

    return ['received' => $file?->getSize()];
});
```

See [Requests](requests.md).

### The logged-in user

A parameter whose class implements `Crab\Contracts\Auth\Identity` receives the user that authentication middleware put on the request. A request without one is answered `401` unless the parameter is nullable.

```php
Route::get('/me', static fn (User $user): User => $user)->middleware(TokenAuth::class);
```

See [Authentication](authentication.md).

### Services

Services are never action parameters. They belong in the constructor of an action class. This keeps "what the client sends" and "what the application provides" apart, so a parameter can never be confused for a field a client may set.

## What an action returns

```php
use Crab\Http\Response;

return $user;                                             // an object or array: JSON, 200
return 'pong';                                            // a string: plain text
return;                                                   // nothing: 204
return Response::created($user, "/users/{$user->id}");    // 201, with a Location header
return Response::noContent();                             // 204
return Response::json($user)->withHeader('Cache-Control', 'max-age=60');
```

Return the data when `200` is right, and a response when the status, a header or the body needs saying. An object is written as JSON from its public properties, and a date as ISO 8601 text. See [Responses](responses.md).

For an error, throw `HttpException`; see [Error handling](errors.md).
