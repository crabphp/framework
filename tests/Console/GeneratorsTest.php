<?php

declare(strict_types=1);

namespace Crab\Tests\Console;

use Crab\App;
use Crab\Commands\PublishConfig;
use Crab\Console\Output;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeneratorsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crab-generators-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function commandNames(): iterable
    {
        yield 'area in the singular, then the action' => ['PruneUsers', 'user:prune'];
        yield 'a longer area' => ['SendWeeklyReports', 'weekly-report:send'];
        yield 'one word' => ['Report', 'app:report'];
        yield 'an area ending in ss' => ['ClearAccess', 'access:clear'];
    }

    #[DataProvider('commandNames')]
    public function testACommandIsNamedAfterItsClass(string $class, string $name): void
    {
        $app = new App(basePath: $this->directory);
        [$status, $output] = $this->command($app, [
            'make:command',
            $class,
            '--namespace=Generated' . bin2hex(random_bytes(4)),
        ]);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString("php cua {$name}", $output);
    }

    public function testAGeneratedCommandIsRegisteredAndRuns(): void
    {
        mkdir($this->directory . '/routes');
        file_put_contents($this->directory . '/routes/console.php', "<?php\n");
        $namespace = 'Generated' . bin2hex(random_bytes(6));
        $app = new App(basePath: $this->directory);
        [$status, $output] = $this->command($app, [
            'make:command',
            'PruneUsers',
            '--path=generated',
            '--namespace=' . $namespace,
        ]);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Registered it in ' . $this->directory . '/routes/console.php', $output);
        $routes = (string) file_get_contents($this->directory . '/routes/console.php');
        self::assertStringContainsString(
            "\\Crab\\Command::add('user:prune', \\{$namespace}\\PruneUsers::class);",
            $routes,
        );

        require $this->directory . '/generated/PruneUsers.php';
        $next = new App(basePath: $this->directory);
        $next->console->add('user:prune', $namespace . '\\PruneUsers');
        self::assertSame([0, "user:prune ran.\n"], $this->command($next, ['user:prune']));
    }

    public function testWithoutACommandFileTheLineToAddIsShown(): void
    {
        $app = new App(basePath: $this->directory);
        [$status, $output] = $this->command($app, [
            'make:command',
            'SendReport',
            '--command=report:send',
            '--namespace=Generated' . bin2hex(random_bytes(4)),
        ]);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Register it where your commands are declared', $output);
        self::assertStringContainsString("\\Crab\\Command::add('report:send',", $output);
        self::assertFileDoesNotExist($this->directory . '/routes/console.php');
    }

    public function testATakenOrInvalidCommandNameWritesNothing(): void
    {
        $app = new App(basePath: $this->directory);
        foreach ([
            ['RouteList', '--command=route:list'],
            ['Broken',    '--command=Not Valid'],
            ['Lister',    '--command=list'],
        ] as $arguments) {
            [$status, $output] = $this->command($app, ['make:command', ...$arguments]);
            self::assertSame(1, $status, $output);
        }
        self::assertSame([], glob($this->directory . '/*'));
    }

    public function testConfigPublishListsWritesAndLeavesAnExistingFileAlone(): void
    {
        $app = new App(basePath: $this->directory);
        [$status, $output] = $this->command($app, ['config:publish']);
        self::assertSame(0, $status);
        self::assertStringContainsString('server', $output);

        self::assertSame(0, $this->command($app, ['config:publish', 'server', 'queue'])[0]);
        self::assertFileExists($this->directory . '/config/server.php');
        self::assertFileExists($this->directory . '/config/queue.php');

        file_put_contents($this->directory . '/config/server.php', "<?php\nreturn ['workers' => 4];\n");
        [$status, $output] = $this->command($app, ['config:publish', 'server']);
        self::assertSame(1, $status);
        self::assertStringContainsString('--force', $output);
        self::assertStringContainsString(
            "'workers' => 4",
            (string) file_get_contents($this->directory . '/config/server.php'),
        );

        self::assertSame(0, $this->command($app, ['config:publish', 'server', '--force'])[0]);
        self::assertStringContainsString(
            "'loop' => 'select'",
            (string) file_get_contents($this->directory . '/config/server.php'),
        );

        [$status, $output] = $this->command($app, ['config:publish', 'nope']);
        self::assertSame(1, $status);
        self::assertStringContainsString('Unknown section nope', $output);
    }

    /**
     * A published file sets what the framework would anyway; only these
     * values deliberately differ: the connection the skeleton describes,
     * and the servers named, though off.
     */
    public function testEveryPublishedFileHoldsTheFrameworksDefaults(): void
    {
        $app = new App(basePath: $this->directory);
        foreach (App::PROVIDERS as $provider) {
            new $provider()->register($app->container);
        }
        $differ = ['server.with', 'database.connections'];
        foreach (PublishConfig::sections() as $section) {
            $published = (static fn(string $file): array => require $file)(
                dirname(__DIR__, 2) . "/src/Config/stubs/{$section}.php",
            );
            $defaults = $app->config->get($section);
            if ($section === 'app') {
                // Where the application is; never a setting of its own.
                unset($defaults['path']);
            }
            self::assertEqualsCanonicalizing(array_keys($defaults), array_keys($published), $section);
            foreach ($defaults as $key => $value) {
                if (in_array("{$section}.{$key}", $differ, true)) {
                    continue;
                }
                self::assertSame($value, $published[$key], "{$section}.{$key}");
            }
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function command(App $app, array $arguments): array
    {
        $stream = fopen('php://memory', 'w+b');
        try {
            $status = $app->console->run($arguments, new Output($stream, $stream));
            rewind($stream);
            return [$status, (string) stream_get_contents($stream)];
        } finally {
            fclose($stream);
        }
    }
}
