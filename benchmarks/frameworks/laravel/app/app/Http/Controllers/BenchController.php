<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\BenchUser;
use Illuminate\Http\JsonResponse;

final class BenchController extends Controller
{
    public function hello(): JsonResponse
    {
        return response()->json(['message' => 'Hello, world']);
    }

    public function user(int $id): JsonResponse
    {
        return response()->json(BenchUser::findOrFail($id));
    }

    public function validateUser(UserRequest $request): JsonResponse
    {
        return response()->json($request->validated(), 201);
    }

    public function metadata(): JsonResponse
    {
        $loader = require base_path('vendor/autoload.php');
        $status = opcache_get_status(false);
        return response()->json([
            'php' => PHP_VERSION,
            'classmap_authoritative' => $loader->isClassMapAuthoritative(),
            'debug' => config('app.debug'),
            'config_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
            'persistent' => app('db')
                ->connection()
                ->getPdo()
                ->getAttribute(\PDO::ATTR_PERSISTENT),
            'sapi' => PHP_SAPI,
            'jit' => $status['jit'] ?? null,
            'opcache' => ini_get('opcache.enable'),
            'opcache_statistics' => $status['opcache_statistics'] ?? null,
            'opcache_cache_full' => $status['cache_full'] ?? null,
            'timestamps' => ini_get('opcache.validate_timestamps'),
            'preload' => ini_get('opcache.preload'),
            'preload_classes' => count($status['preload_statistics']['classes'] ?? []),
        ]);
    }
}
