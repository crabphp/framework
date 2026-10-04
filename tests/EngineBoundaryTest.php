<?php

declare(strict_types=1);

namespace Crab\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The engine is published on its own, so it may depend on PHP, Revolt and
 * itself, never on the framework around it.
 */
final class EngineBoundaryTest extends TestCase
{
    public function testEngineSourceReferencesNoFrameworkNamespace(): void
    {
        $root = dirname(__DIR__) . '/src/Engine';
        $violations = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            ++$checked;
            foreach (file($file->getPathname()) as $number => $line) {
                // Any Crab\ name outside Crab\Engine\, imported or fully qualified.
                if (preg_match_all('/\\\\?\bCrab\\\\(?!Engine\b)[A-Za-z_]+/', $line, $matches)) {
                    $violations[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($number + 1) . ' ' . implode(', ', $matches[0]);
                }
            }
        }
        self::assertGreaterThan(20, $checked, 'The engine source was not found.');
        self::assertSame([], $violations, 'Engine code must not depend on framework code.');
    }

    public function testOnlyTheLoopAdapterKnowsRevolt(): void
    {
        $root = dirname(__DIR__) . '/src';
        $violations = [];
        $adapters = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = substr($file->getPathname(), strlen($root) + 1);
            foreach (file($file->getPathname()) as $number => $line) {
                if (!preg_match('/\bRevolt\\\\/', $line)) {
                    continue;
                }
                if (str_starts_with($path, 'Engine/EventLoop/')) {
                    ++$adapters;
                } else {
                    $violations[] = $path . ':' . ($number + 1);
                }
            }
        }
        self::assertGreaterThan(0, $adapters, 'The loop adapter was not found.');
        self::assertSame([], $violations, 'Only src/Engine/EventLoop/ may name Revolt; everything else uses Crab\\Engine\\Loop.');
    }

    public function testEnginePackageDeclaresOnlyItsOwnDependencies(): void
    {
        $package = json_decode(file_get_contents(dirname(__DIR__) . '/src/Engine/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('crabphp/engine', $package['name']);
        self::assertSame(['php', 'revolt/event-loop'], array_keys($package['require']));
        self::assertSame(['Crab\\Engine\\' => ''], $package['autoload']['psr-4']);
    }
}
