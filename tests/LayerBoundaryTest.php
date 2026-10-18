<?php

declare(strict_types=1);

namespace Crab\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The layers of ARCHITECTURE.md: contracts know no component, and a
 * component knows neither the application glue nor, unless listed here,
 * another component.
 */
final class LayerBoundaryTest extends TestCase
{
    /**
     * Glue, which may use everything.
     */
    private const GLUE = ['Commands'];

    /**
     * Components that another component is built on, by design.
     */
    private const ALLOWED = [
        // Its middleware answers 401 through the one error renderer.
        'Auth' => ['Http'],
        // It sends and returns PSR-7 messages, which are Crab's own.
        'HttpClient' => ['Http'],
        // A route's handler is called with an input the validator builds.
        'Routing' => ['Http', 'Validation', 'WebSocket'],
        'Testing' => ['Container', 'Http'],
        // Socket admission returns an HTTP upgrade through the middleware.
        'WebSocket' => ['Http'],
    ];

    public function testContractsReferenceOnlyContractsAndTheEngine(): void
    {
        $violations = [];
        foreach (self::references('Contracts') as $location => $names) {
            foreach ($names as $name) {
                if (in_array(explode('\\', $name)[1], ['Contracts', 'Engine'], true)) {
                    continue;
                }

                $violations[] = "{$location} {$name}";
            }
        }
        self::assertSame([], $violations, 'A contract must not depend on a component.');
    }

    public function testComponentsReferenceNoGlueAndOnlyAllowedComponents(): void
    {
        $components = [];
        foreach (new \DirectoryIterator(dirname(__DIR__) . '/src') as $entry) {
            $name = $entry->getFilename();
            if ($entry->isDir() && !$entry->isDot() && !in_array($name, ['Engine', 'Contracts', ...self::GLUE], true)) {
                $components[] = $name;
            }
        }
        self::assertGreaterThanOrEqual(7, count($components), 'The components were not found.');
        $violations = [];
        foreach ($components as $component) {
            $allowed = [$component, 'Contracts', 'Engine', ...(self::ALLOWED[$component] ?? [])];
            foreach (self::references($component) as $location => $names) {
                foreach ($names as $name) {
                    // The segment after Crab\ is a layer, a component, or a glue class such as App.
                    if (in_array(explode('\\', $name)[1], $allowed, true)) {
                        continue;
                    }

                    $violations[] = "{$location} {$name}";
                }
            }
        }
        self::assertSame([], $violations, 'A component depends on glue or on a component not listed in ALLOWED.');
    }

    /**
     * @return array<string, list<string>> Every Crab name by "file:line" within a folder of src/.
     */
    private static function references(string $folder): array
    {
        $root = dirname(__DIR__) . '/src/';
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root . $folder,
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) as $number => $line) {
                if (!preg_match_all('/\bCrab\\\\[A-Za-z_\\\\]*[A-Za-z_]/', $line, $matches)) {
                    continue;
                }

                $found[substr($file->getPathname(), strlen($root)) . ':' . ($number + 1)] = $matches[0];
            }
        }
        return $found;
    }
}
