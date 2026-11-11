# Views

- [Introduction](#introduction)
- [Installation](#installation)
- [Rendering a page](#rendering-a-page)
- [Writing templates](#writing-templates)
- [Components and layouts](#components-and-layouts)
- [HTML safety](#html-safety)
- [Forms and errors](#forms-and-errors)
- [Rendering a string](#rendering-a-string)
- [Developing and deploying](#developing-and-deploying)
- [Testing a view](#testing-a-view)

## Introduction

> [!WARNING]
> **Experimental — not for production.** Arc and Crab's views integration are an experiment. Their APIs, template syntax and behaviour may change without backward compatibility. Do not use them in production.

Crab focuses on JSON APIs and services. [Arc](https://github.com/crabphp/arc) is an optional template engine for experimenting with server-rendered HTML, layouts and reusable components. Templates compile to PHP closures, which a worker loads once and keeps. An API does not need this package.

## Installation

Install Arc in your application:

```sh
composer require crabphp/arc
```

Register its plugin alongside the plugins you already use:

```php
// app.php
use Crab\App;
use Crab\Arc\ArcPlugin;

return App::configure(__DIR__)
    ->withPlugins([ArcPlugin::class])
    ->withRouting(routes: 'routes/web.php')
    ->create();
```

| Setting | Default | What it does |
| --- | --- | --- |
| `view.path` | `resources/views` | The templates, relative to the application's base path |
| `view.cache` | `null` | Uses the application's storage folder plus `/view`; a string sets another compiled-template directory |

The default compiled-template directory is `storage/view`, and follows [a relocated storage folder](deployment.md#storage-elsewhere). Keep templates in `resources/views`, not in `public/`. Only static assets such as CSS and JavaScript belong in `public/`.

## Rendering a page

Return `view()` from an action. It builds an HTML response with `Content-Type: text/html; charset=utf-8`, not a JSON response or a plain-text string:

```php
// routes/web.php
use Crab\Http\Response;
use Crab\Route;

Route::get('/hello/{name}', static fn (string $name): Response => view('hello', ['name' => $name]));
```

Create `resources/views/hello.arc.php`:

```html
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Hello</title>
</head>
<body>
    <h1>Hello, {{ $name }}</h1>
</body>
</html>
```

Open `/hello/Ada`. The keys of the data array become the template's variables. A dotted name follows the directory: `orders.index` means `resources/views/orders/index.arc.php`.

`view()` also takes `status:` and `headers:`, and its response supports the same headers and cookies as any other [response](responses.md). A string returned directly by an action is plain text; use `view()` for a template, or `Response::html()` when you already have HTML.

## Writing templates

Templates are HTML with PHP expressions and a small set of directives:

| Write | For |
| --- | --- |
| `{{ $name }}` | A value, escaped for HTML text or a quoted HTML attribute |
| `{!! $html !!}` | Trusted HTML, without escaping |
| `{{-- A note --}}` | A comment left out of the rendered page |
| `@if(...)`, `@elseif(...)`, `@else`, `@endif` | Choosing what to render |
| `@unless(...)`, `@endunless` | The inverse of `@if` |
| `@foreach(...)`, `@endforeach` | Rendering a list |
| `@for(...)`, `@endfor`, `@while(...)`, `@endwhile` | Other loops, with `@break` and `@continue` |

You may use complete `<?php ... ?>` statements to prepare a local value. Do not echo or use `<?=`: Arc builds a string, rather than using the process-wide output buffer that overlapping requests would share. Use Arc directives to control markup; PHP control flow cannot span separate PHP blocks. Names beginning with `$__` belong to the compiler.

Pass request-specific data explicitly. Keep database calls and other application work in the action, with services in its constructor; a shared renderer must not keep the current user or request in its properties.

## Components and layouts

A capitalised tag calls a component. `<Layout>` uses `resources/views/components/layout.arc.php`; `<ProductCard>` uses `components/product-card.arc.php`. Lowercase tags remain HTML.

Create a layout with typed props and a slot for the page:

```html
@props(string $title, string $head = '', string $slot = '')
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    {!! $head !!}
</head>
<body>
    <main>{!! $slot !!}</main>
</body>
</html>
```

Replace `resources/views/hello.arc.php` with a page that uses it:

```html
<Layout title="Greeting">
    <Slot name="head"><meta name="description" content="A greeting from Crab."></Slot>
    <h1>Hello, {{ $name }}</h1>
</Layout>
```

The markup between the tags becomes `$slot`. A named `<Slot>` fills a declared string prop, here `$head`; its default applies when no slot is supplied. Use `{!! !!}` for slot markup, but escape its data with `{{ }}` inside the calling template.

An attribute such as `title="Greeting"` passes text; a colon-prefixed attribute such as `:total="$total"` passes a PHP expression. Props must be declared with `@props`. Missing required props, unknown props and missing components are compile errors. Layouts are components: there is no separate `@extends` or `@section` system. See the [Arc reference](https://github.com/crabphp/arc#components) for nested component names and more prop examples.

## HTML safety

`{{ }}` escapes `<`, `>`, `&` and both quotes. Always quote attribute values. Use `{!! !!}` only for HTML you trust, never for user input.

HTML escaping does not validate a URL scheme or make a value safe inside JavaScript, CSS, a `style` attribute or an event-handler attribute. Keep untrusted data out of executable contexts. Build local URLs from fixed paths and encoded path segments; validate external URLs and allow only the schemes your application intends. The [Arc escaping guide](https://github.com/crabphp/arc#escaping-by-context) shows these cases.

## Forms and errors

A rendered page can use ordinary HTML forms. Crab parses URL-encoded forms and multipart uploads, and validates them through the same [typed action inputs](actions.md) as JSON. After a successful write, return a [redirect](responses.md#redirects) when the browser should load another page.

For browser login, use [cookie authentication](authentication.md#cookie-authentication); it uses an HttpOnly token cookie, not a PHP session. Crab does not add a session or flash-message system when Arc is installed.

Arc adds no error middleware. Default validation and error responses remain JSON or plain text according to the request. To render HTML error pages, bind an [error renderer](errors.md#your-own-error-format); pass the safe message and field problems to a view, and keep the error status. Templates are not selected automatically by status code.

## Rendering a string

For an email body or another place that needs HTML without a response, ask for `Arc` in a constructor and call `render()`:

```php
use Crab\Arc\Arc;

final readonly class GreetingBody
{
    public function __construct(private Arc $arc)
    {
    }

    public function render(string $name): string
    {
        return $this->arc->render('hello', ['name' => $name]);
    }
}
```

## Developing and deploying

Add `resources` to the existing `server.watch` list in `config/server.php`, so `php cua dev` restarts workers when a template changes. Loaded workers keep their compiled closures; editing a template alone does not update a running worker.

Compile templates before reloading workers during deployment:

```sh
set -e
php cua view:cache
php cua reload
```

The plugin registers `view:cache` to compile every template, and `view:clear` to remove compiled templates and dependency indexes. Clearing the cache is maintenance, not a substitute for reloading workers. A changed component invalidates its callers when templates are next compiled or loaded by a fresh worker. See [Deployment](deployment.md) for the full release sequence, including migrations.

Compilation validates the generated PHP using an isolated `php -l` process. It needs `proc_open`, a writable temporary directory and the PHP CLI at `PHP_BINARY`. An invalid template fails compilation; warm rendering needs no further file checks. The configured cache directory must be writable by the application's user.

## Testing a view

Call the HTML route without starting a server, as with any other [route test](testing.md#testing-a-route):

```php
use Crab\Http\Request;

$app = require dirname(__DIR__) . '/app.php';
$response = $app->handle(new Request('GET', '/hello/Ada'));

self::assertSame(200, $response->getStatusCode());
self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
self::assertStringContainsString('<h1>Hello, Ada</h1>', (string) $response->getBody());
```

Use an isolated writable storage directory for tests. Also test values containing HTML metacharacters, so an accidental unescaped value is caught before it reaches a browser.
