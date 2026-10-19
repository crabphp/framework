<?php

declare(strict_types=1);

namespace Crab\Tests\Console;

use Crab\App;
use Crab\Config\Config;
use Crab\Console\Output;
use Crab\Contracts\Queue\Queue;
use Crab\Contracts\Queue\Store;
use Crab\Queue\QueueProvider;
use Crab\Testing\Api;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueOptionsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crab-command-options-' . bin2hex(random_bytes(6));
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
     * @return iterable<string, array{string, list<string>}>
     */
    public static function missingTimeouts(): iterable
    {
        foreach (['stop', 'restart'] as $command) {
            yield $command . ' bare' => [$command, ['--timeout']];
            yield $command . ' separated' => [$command, ['--timeout', '15']];
        }
    }

    #[DataProvider('missingTimeouts')]
    public function testAMissingTimeoutIsRefusedBeforeAccessingServerState(string $command, array $arguments): void
    {
        // Stop at the server-state boundary, without ever inspecting or signalling a real process.
        $pid = new class implements \Stringable {
            public int $reads = 0;

            public function __toString(): string
            {
                ++$this->reads;
                throw new \RuntimeException('The command reached the server pid setting.');
            }
        };
        $app = new App(new Config(['server' => ['pid' => $pid]]));
        [$status, $output] = $this->command($app, [$command, ...$arguments]);

        self::assertSame(0, $pid->reads, $output);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('--timeout needs a value', $output);
        self::assertStringContainsString('--timeout=value', $output);
    }

    public function testAValuedStopTimeoutStillChecksTheConfiguredServer(): void
    {
        $app = new App(new Config(['server' => ['pid' => $this->directory . '/absent.pid']]));
        [$status, $output] = $this->command($app, ['stop', '--timeout=15']);

        self::assertSame(0, $status, $output);
        self::assertSame("No server is running in the background.\n", $output);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function missingQueueValues(): iterable
    {
        foreach ([
            'queue' => 'mail',
            'workers' => '4',
            'concurrency' => '2',
            'supervisor' => 'mail',
        ] as $name => $value) {
            yield $name . ' bare' => [$name, ['--' . $name]];
            yield $name . ' separated' => [$name, ['--' . $name, $value]];
        }
    }

    #[DataProvider('missingQueueValues')]
    public function testAMissingQueueOptionCannotConsumeAnyQueue(string $name, array $arguments): void
    {
        $app = $this->queuedApplication();
        [$status, $output] = $this->command($app, ['queue:work', '--stop-when-empty', ...$arguments]);
        $store = $app->container->get(Store::class);

        self::assertSame([1, 1], [$store->size('default'), $store->size('mail')], $output);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('--' . $name . ' needs a value', $output);
        self::assertStringContainsString('--' . $name . '=value', $output);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function queueSelections(): iterable
    {
        yield 'queue and limits' => [['--queue=mail', '--workers=4', '--concurrency=2']];
        yield 'supervisor' => [['--supervisor=mail']];
    }

    #[DataProvider('queueSelections')]
    public function testValuedQueueOptionsRunOnlyTheSelectedQueue(array $arguments): void
    {
        $app = $this->queuedApplication();
        [$status, $output] = $this->command($app, ['queue:work', '--stop-when-empty', ...$arguments]);
        $store = $app->container->get(Store::class);

        self::assertSame(0, $status, $output);
        self::assertSame("Ran 1 job.\n", $output);
        self::assertSame([1, 0], [$store->size('default'), $store->size('mail')]);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function missingMiddlewareValues(): iterable
    {
        foreach (['path' => 'app/Http', 'namespace' => 'App\\Http'] as $name => $value) {
            yield $name . ' bare' => [$name, ['--' . $name]];
            yield $name . ' separated' => [$name, ['--' . $name, $value]];
        }
    }

    #[DataProvider('missingMiddlewareValues')]
    public function testAMissingMiddlewareOptionCannotWriteAFile(string $name, array $arguments): void
    {
        $app = new App(basePath: $this->directory);
        [$status, $output] = $this->command($app, ['make:middleware', 'RequireJson', ...$arguments]);

        self::assertSame([], glob($this->directory . '/*'), $output);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('--' . $name . ' needs a value', $output);
        self::assertStringContainsString('--' . $name . '=value', $output);
    }

    public function testTheGeneratedMiddlewareCanBeUsedAsItsMessageInstructs(): void
    {
        $app = new App(basePath: $this->directory);
        $name = 'GeneratedGate' . bin2hex(random_bytes(6));
        [$status, $output] = $this->command($app, [
            'make:middleware',
            $name,
            '--path=generated',
            '--namespace=Example\\Http',
        ]);
        self::assertSame(0, $status, $output);
        require $this->directory . '/generated/' . $name . '.php';

        preg_match('/->middleware\([^)]+\)/', $output, $match);
        self::assertArrayHasKey(0, $match, $output);
        file_put_contents(
            $this->directory . '/route.php',
            "<?php\nuse Crab\\Route;\nRoute::get('/guarded', static fn(): array => ['ok' => true])" . $match[0] . ";\n",
        );
        require $this->directory . '/route.php';

        new Api($app)
            ->get('/guarded')
            ->assertStatus(200)
            ->assertJson('ok', true);
        [$status, $routes] = $this->command($app, ['route:list']);
        self::assertSame(0, $status, $routes);
        self::assertStringContainsString('Example\\Http\\' . $name, $routes);
    }

    private function queuedApplication(): App
    {
        $app = new App(new Config([
            'queue' => [
                'store' => 'memory',
                'supervisors' => [
                    'mail' => ['queues' => ['mail']],
                    'default' => ['queues' => ['default']],
                ],
            ],
            'log' => ['path' => 'php://memory'],
        ]));
        $app->register(QueueProvider::class);
        $queue = $app->container->get(Queue::class);
        $queue->push(EchoQueueValue::class, value: 'default');
        $queue->on('mail')->push(EchoQueueValue::class, value: 'mail');
        return $app;
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
            return [$status, stream_get_contents($stream)];
        } finally {
            fclose($stream);
        }
    }
}

final readonly class EchoQueueValue
{
    public function handle(string $value): string
    {
        return $value;
    }
}
