<?php

declare(strict_types=1);

namespace App\Endpoint\Web;

use Cycle\Database\DatabaseInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\Http\ResponseWrapper;
use Spiral\Router\Annotation\Route;
use Spiral\Validation\ValidationInterface;

final class BenchController
{
    #[Route(route: '/hello', name: 'bench.hello', methods: ['GET'])]
    public function hello(): array
    {
        return ['message' => 'Hello, world'];
    }

    #[Route(route: '/users/<id>', name: 'bench.user', methods: ['GET'])]
    public function user(string $id, DatabaseInterface $database, ResponseWrapper $response): ResponseInterface
    {
        $row = $database
            ->select(['id', 'name', 'email', 'age'])
            ->from('users')
            ->where('id', (int) $id)
            ->limit(1)
            ->run()
            ->fetch();
        return $response->json($row ?: ['error' => 'Not found'], $row ? 200 : 404);
    }

    #[Route(route: '/users', name: 'bench.create', methods: ['POST'])]
    public function create(
        ServerRequestInterface $request,
        ValidationInterface $validation,
        ResponseWrapper $response,
    ): ResponseInterface {
        $data = (array) $request->getParsedBody();
        $validator = $validation->validate($data, [
            'name' => ['notNull', 'string', ['string::regexp', '/\A.{1,100}\z/us']],
            'email' => ['notEmpty', 'string', 'email'],
            'age' => ['notNull', 'integer', ['number::range', 18, 120]],
        ]);
        if (!$validator->isValid()) {
            return $response->json(['errors' => $validator->getErrors()], 422);
        }
        return $response->json(array_intersect_key($data, array_flip(['name', 'email', 'age'])), 201);
    }

    #[Route(route: '/__bench', name: 'bench.metadata', methods: ['GET'])]
    public function metadata(
        \Spiral\Boot\EnvironmentInterface $env,
        \Spiral\Tokenizer\Config\TokenizerConfig $tokenizer,
    ): array {
        $loaders = \Composer\Autoload\ClassLoader::getRegisteredLoaders();
        $loader = reset($loaders);
        $status = opcache_get_status(false);
        return [
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'pid' => getmypid(),
            'classmap_authoritative' => $loader->isClassMapAuthoritative(),
            'debug' => filter_var($env->get('DEBUG', true), FILTER_VALIDATE_BOOL),
            'opcache' => ini_get('opcache.enable'),
            'timestamps' => ini_get('opcache.validate_timestamps'),
            'jit' => $status['jit'] ?? null,
            'opcache_statistics' => $status['opcache_statistics'] ?? null,
            'cache_full' => $status['cache_full'] ?? null,
            'workers' => 2,
            'tokenizer_cache' => $tokenizer->isCacheEnabled(),
            'connection_reuse' => 'Cycle driver kept in worker',
        ];
    }
}
