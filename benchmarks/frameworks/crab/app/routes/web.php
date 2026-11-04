<?php

declare(strict_types=1);

use Crab\Http\Response;
use Crab\Route;

Route::get('/hello', static fn(): array => ['message' => 'Hello, world']);
Route::get('/users/{id}', App\Actions\ShowUser::class);
Route::post('/users', static fn(App\NewUser $user): Response => Response::created([
    'name' => $user->name,
    'email' => $user->email,
    'age' => $user->age,
]));
Route::get('/__bench', static function (): array {
    $loader = require dirname(__DIR__) . '/vendor/autoload.php';
    $status = opcache_get_status(false);
    return [
        'php' => PHP_VERSION,
        'classmap_authoritative' => $loader->isClassMapAuthoritative(),
        'debug' => config('app.debug'),
        'sapi' => PHP_SAPI,
        'jit' => $status['jit'] ?? null,
        'opcache' => ini_get('opcache.enable'),
        'opcache_statistics' => $status['opcache_statistics'] ?? null,
        'opcache_cache_full' => $status['cache_full'] ?? null,
        'timestamps' => ini_get('opcache.validate_timestamps'),
        'preload' => ini_get('opcache.preload'),
        'preload_classes' => count($status['preload_statistics']['classes'] ?? []),
    ];
});
