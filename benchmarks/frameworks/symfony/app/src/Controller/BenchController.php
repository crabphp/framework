<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\UserInput;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class BenchController extends AbstractController
{
    #[Route('/hello', methods: ['GET'], format: 'json')]
    public function hello(): JsonResponse
    {
        return new JsonResponse(['message' => 'Hello, world']);
    }

    #[Route('/users/{id}', methods: ['GET'], format: 'json')]
    public function user(int $id, Connection $db): JsonResponse
    {
        $row = $db->fetchAssociative('SELECT * FROM users WHERE id = ?', [$id]);
        return $row === false ? new JsonResponse(['error' => 'Not found'], 404) : new JsonResponse($row);
    }

    #[Route('/users', methods: ['POST'], format: 'json')]
    public function validateUser(#[MapRequestPayload] UserInput $data): JsonResponse
    {
        return new JsonResponse($data, 201);
    }

    #[Route('/__bench', methods: ['GET'], format: 'json')]
    public function metadata(): JsonResponse
    {
        $loader = require $this->getParameter('kernel.project_dir') . '/vendor/autoload.php';
        $status = opcache_get_status(false);
        return new JsonResponse([
            'php' => PHP_VERSION,
            'classmap_authoritative' => $loader->isClassMapAuthoritative(),
            'debug' => $this->getParameter('kernel.debug'),
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
