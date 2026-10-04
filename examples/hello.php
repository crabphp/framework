<?php

declare(strict_types=1);

use Crab\App;
use Crab\Contracts\Http\Request;
use Crab\Http\Response;

$app = new App();
$app->router
    ->get('/', static fn() => "Hello, world!\n")
    ->get('/hello/{name}', static fn(Request $request) => Response::json([
        'hello' => $request->getAttribute('name'),
        'shout' => $request->query('shout') !== null,
    ]));

return $app;
