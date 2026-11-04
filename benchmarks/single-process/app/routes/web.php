<?php

declare(strict_types=1);

use Crab\Http\Response;
use Crab\Route;

Route::get('/hello', static fn(): array => ['message' => 'Hello, world']);
Route::get('/users/{id}', App\Actions\ShowUser::class);
Route::get('/mysql/users/{id}', App\Actions\ShowMysqlUser::class);
Route::get('/mysql-async/users/{id}', App\Actions\ShowMysqlAsyncUser::class);
Route::post('/users', static fn(App\NewUser $user): Response => Response::created([
    'name' => $user->name,
    'email' => $user->email,
    'age' => $user->age,
]));
