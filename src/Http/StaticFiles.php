<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\Factory as FactoryContract;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves files below an application's public directory.
 *
 * This is a fallback for requests that did not match an application route;
 * it is deliberately not a route itself. A missing file returns null so the
 * router can produce its normal 404 or 405 response.
 */
final readonly class StaticFiles
{
    /** @var array<string, string> */
    private const TYPES = [
        'avif' => 'image/avif',
        'css' => 'text/css; charset=utf-8',
        'gif' => 'image/gif',
        'html' => 'text/html; charset=utf-8',
        'ico' => 'image/x-icon',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'js' => 'text/javascript; charset=utf-8',
        'json' => 'application/json',
        'map' => 'application/json',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'txt' => 'text/plain; charset=utf-8',
        'webm' => 'video/webm',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'xml' => 'application/xml',
    ];

    public function __construct(
        private string $root,
        private FactoryContract $factory,
    ) {}

    /**
     * Returns a static response when the request names a readable file, or
     * null when the public directory has no such file.
     */
    public function response(ServerRequestInterface $request): ?ResponseInterface
    {
        $file = $this->file($request);
        if ($file === null) {
            return null;
        }
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return ErrorResponse::make($request, 405, headers: ['Allow' => 'GET, HEAD']);
        }
        $modified = filemtime($file);
        $size = filesize($file);
        if ($modified === false || $size === false) {
            return null;
        }
        $etag = '"' . $modified . '-' . $size . '"';
        $headers = [
            'Cache-Control' => 'public, max-age=3600',
            'Content-Type' => self::type($file),
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $modified) . ' GMT',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($this->notModified($request, $etag, $modified)) {
            return new Response(304, $headers);
        }
        return new Response(
            200,
            ['Content-Length' => (string) $size, ...$headers],
            $this->factory->createStreamFromFile($file),
        );
    }

    private function file(ServerRequestInterface $request): ?string
    {
        $path = $request->getUri()->getPath();
        if ($path === '' || $path[0] !== '/') {
            return null;
        }
        $path = rawurldecode($path);
        if (str_contains($path, "\0") || str_contains($path, '\\')) {
            return null;
        }
        $segments = explode('/', ltrim($path, '/'));
        if ($segments === [] || in_array('', $segments, true)) {
            return null;
        }
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }
        $root = realpath($this->root);
        if ($root === false || !is_dir($root)) {
            return null;
        }
        $candidate = $root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        $file = realpath($candidate);
        if ($file === false || !is_file($file) || !is_readable($file)) {
            return null;
        }
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($file, $prefix) ? $file : null;
    }

    private function notModified(ServerRequestInterface $request, string $etag, int $modified): bool
    {
        $matches = trim($request->getHeaderLine('If-None-Match'));
        if ($matches !== '') {
            foreach (explode(',', $matches) as $match) {
                if (trim($match) === '*' || trim($match) === $etag) {
                    return true;
                }
            }
            return false;
        }
        $since = $request->getHeaderLine('If-Modified-Since');
        if ($since === '') {
            return false;
        }
        $timestamp = strtotime($since);
        return $timestamp !== false && $timestamp >= $modified;
    }

    private static function type(string $file): string
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return self::TYPES[$extension] ?? 'application/octet-stream';
    }
}
