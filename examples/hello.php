<?php

declare(strict_types=1);

use Crab\App;
use Crab\Contracts\Http\Request;
use Crab\Http\Response;
use Crab\Route;

$app = new App();

Route::get('/', static fn() => "Hello, world!\n");
Route::get('/hello/{name}', static fn(Request $request) => Response::json([
    'hello' => $request->getAttribute('name'),
    'shout' => $request->query('shout') !== null,
]));

return $app;
