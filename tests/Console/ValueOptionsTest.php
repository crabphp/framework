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
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function invalidTimeouts(): iterable
    {
        foreach (['stop', 'restart'] as $command) {
            yield $command . ' bare' => [$command, ['--timeout'], '--timeout needs a value'];
            yield $command . ' separated' => [$command, ['--timeout', '15'], '--timeout needs a value'];
            foreach (['fifteen', '15seconds', '', '-1', '1e309', '-1e309', 'INF', 'NaN'] as $value) {
                yield $command . ' value ' . $value => [
                    $command,
                    ['--timeout=' . $value],
                    '--timeout must be a finite number of seconds at least zero',
                ];
            }
        }
    }

    #[DataProvider('invalidTimeouts')]
    public function testAnInvalidTimeoutIsRefusedBeforeAccessingServerState(
        string $command,
        array $arguments,
        string $message,
    ): void {
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
        self::assertStringContainsString($message, $output);
        self::assertStringContainsString('--timeout=', $output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validTimeouts(): iterable
    {
        foreach (['0', '0.01', '15', '1e1'] as $value) {
            yield 'value ' . $value => [$value];
        }
    }

    #[DataProvider('validTimeouts')]
    public function testAValuedStopTimeoutStillChecksTheConfiguredServer(string $value): void
    {
        $app = new App(new Config(['server' => ['pid' => $this->directory . '/absent.pid']]));
        [$status, $output] = $this->command($app, ['stop', '--timeout=' . $value]);

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

    public function testAFailedMiddlewareWriteNamesThePathWithoutClaimingSuccess(): void
    {
        // A directory cannot be overwritten as a PHP file, even when this test runs as root.
        $file = $this->directory . '/generated/RequireJson.php';
        mkdir($file, recursive: true);
        $app = new App(basePath: $this->directory);
        [$status, $output] = $this->command($app, ['make:middleware', 'RequireJson', '--path=generated']);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString($file, $output);
        self::assertStringContainsString('permissions', $output);
        self::assertStringNotContainsString('Created ', $output);
        self::assertStringNotContainsString('Use it around', $output);
        self::assertDirectoryExists($file);
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

    /**
     * @return iterable<string, array{?string}>
     */
    public static function middlewareShellHints(): iterable
    {
        yield 'help' => [null];
        yield 'reserved import' => ['Middleware'];
        yield 'PHP keyword' => ['Readonly'];
    }

    #[DataProvider('middlewareShellHints')]
    public function testTheMiddlewareNamespaceHintCanBeCopiedThroughAShell(?string $invalidName): void
    {
        $app = new App(basePath: $this->directory);
        [$status, $output] = $this->command($app, ['make:middleware', $invalidName ?? '--help']);
        self::assertSame($invalidName === null ? 0 : 1, $status, $output);
        if ($invalidName === null) {
            preg_match('/--namespace=\S+/', $output, $match);
            self::assertArrayHasKey(0, $match, $output);
            $hint = 'make:middleware RequireAdmin ' . $match[0];
        } else {
            preg_match('/make:middleware[^\n]+/', $output, $match);
            self::assertArrayHasKey(0, $match, $output);
            $hint = $match[0];
        }
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $entry = <<<'PHP'
            $loader = require AUTOLOAD;
            $loader->addPsr4('App\\', __DIR__ . '/app/');
            $app = new Crab\App(basePath: __DIR__);
            if (is_file(__DIR__ . '/route.php')) {
                require __DIR__ . '/route.php';
            }
            exit($app->console->run(array_slice($argv, 1)));
            PHP;
        file_put_contents($this->directory . '/cua', "<?php\n" . str_replace('AUTOLOAD', $autoload, $entry));
        [$status, $generated] = $this->runProcess(['/bin/sh', '-c', escapeshellarg(PHP_BINARY) . ' cua ' . $hint]);
        self::assertSame(0, $status, $generated);
        self::assertFileExists($this->directory . '/app/Http/RequireAdmin.php');
        preg_match('/->middleware\([^)]+\)/', $generated, $route);
        self::assertArrayHasKey(0, $route, $generated);
        file_put_contents(
            $this->directory . '/route.php',
            "<?php\nuse Crab\\Route;\nRoute::get('/guarded', static fn(): array => ['ok' => true])" . $route[0] . ";\n",
        );
        [$status, $routes] = $this->runProcess([PHP_BINARY, 'cua', 'route:list']);
        self::assertSame(0, $status, $routes);
        self::assertStringContainsString('App\\Http\\RequireAdmin', $routes);
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function invalidServerValues(): iterable
    {
        foreach (['serve', 'restart', 'reload'] as $command) {
            foreach ([
                '',
                'four',
                '2workers',
                '1.5',
                '0',
                '-1',
                '1e2',
                '1e309',
                '9223372036854775808',
                "2\n",
            ] as $value) {
                yield $command . ' workers ' . $value => [$command, ['--workers=' . $value], '--workers='];
            }
            foreach ([
                '',
                'not-an-address',
                'http://localhost:8080',
                'tcp://localhost',
                'tcp://localhost:65536',
                'tcp://user@localhost:8080',
                'tcp://localhost:8080/path',
                'tcp://localhost:8080?x=1',
                'tcp://localhost:8080#fragment',
                'tcp://[not-ip]:8080',
                "tcp://local\nhost:8080",
            ] as $value) {
                yield $command . ' address ' . $value => [$command, ['--address=' . $value], '--address='];
            }
        }
    }

    #[DataProvider('invalidServerValues')]
    public function testInvalidServerValuesNeverReachTheRunningServer(
        string $command,
        array $arguments,
        string $usage,
    ): void {
        $pid = new class implements \Stringable {
            public int $reads = 0;

            public function __toString(): string
            {
                ++$this->reads;
                throw new \RuntimeException('The command reached the server pid setting.');
            }
        };
        $app = new App(new Config(['server' => ['pid' => $pid]]));
        [$status, $output] = $this->command($app, [
            $command,
            ...($command === 'serve' ? ['--daemon'] : []),
            ...$arguments,
        ]);

        self::assertSame(0, $pid->reads, $output);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString($usage, $output);
    }

    public function testValidListenerFormsAndWholeWorkerCountsArePreserved(): void
    {
        $app = new App();
        foreach (['tcp://127.0.0.1:8080', 'tcp://localhost:8080', 'tcp://[::1]:8080', 'tcp://[::]:0'] as $address) {
            self::assertSame($address, \Crab\Commands\Serve::address(
                $app,
                new \Crab\Console\Input(['--address=' . $address]),
            ));
        }
        foreach (['1', '4', (string) PHP_INT_MAX] as $value) {
            self::assertSame((int) $value, \Crab\Commands\Serve::workers(
                $app,
                new \Crab\Console\Input(['--workers=' . $value]),
            ));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidQueueValues(): iterable
    {
        foreach (['workers', 'concurrency'] as $name) {
            foreach (['', 'two', '2workers', '1.5', '0', '-1', '1e309', '9223372036854775808', "2\n"] as $value) {
                yield $name . ' ' . $value => [$name, $value];
            }
        }
        foreach (['', ',', 'mail,', ',mail', 'mail,,default', "mail,\n"] as $value) {
            yield 'queue ' . $value => ['queue', $value];
        }
        foreach (['', 'missing', "mail\0"] as $value) {
            yield 'supervisor ' . $value => ['supervisor', $value];
        }
    }

    #[DataProvider('invalidQueueValues')]
    public function testMalformedQueueSelectionsAndLimitsLeaveEveryJobPending(string $name, string $value): void
    {
        $app = $this->queuedApplication();
        [$status, $output] = $this->command($app, ['queue:work', '--stop-when-empty', '--' . $name . '=' . $value]);
        $store = $app->container->get(Store::class);

        self::assertSame([1, 1], [$store->size('default'), $store->size('mail')], $output);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('--' . $name . '=', $output);
    }

    public function testAQueueNamedZeroIsNotDiscardedFromTheSelection(): void
    {
        $app = $this->queuedApplication();
        $app->container->get(Queue::class)->on('0')->push(EchoQueueValue::class, value: 'zero');
        [$status, $output] = $this->command($app, ['queue:work', '--stop-when-empty', '--queue=0']);
        self::assertSame(0, $status, $output);
        self::assertSame(0, $app->container->get(Store::class)->size('0'));
        self::assertSame(1, $app->container->get(Store::class)->size('default'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidMiddlewareDeclarations(): iterable
    {
        foreach ([
            'Class',
            'Readonly',
            'String',
            'Int',
            'Object',
            'Self',
            'Parent',
            'Never',
            'Mixed',
            'Middleware',
            'ResponseInterface',
            'ServerRequestInterface',
            'RequestHandlerInterface',
        ] as $name) {
            yield $name => [$name, 'App\\Http'];
        }
        foreach ([
            '',
            'Bad-Name',
            'App/Http',
            'App\\\\Http',
            'App\\3Http',
            '\\App\\Http',
            'App\\Http\\',
            "App\\Http\0",
        ] as $namespace) {
            yield 'namespace ' . $namespace => ['RequireJson', $namespace];
        }
    }

    #[DataProvider('invalidMiddlewareDeclarations')]
    public function testAnInvalidMiddlewareDeclarationWritesNothing(string $name, string $namespace): void
    {
        $app = new App(basePath: $this->directory);
        [$status, $output] = $this->command($app, [
            'make:middleware',
            $name,
            '--path=generated',
            '--namespace=' . $namespace,
        ]);
        self::assertSame([], glob($this->directory . '/*'), $output);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('make:middleware RequireAdmin', $output);
        self::assertStringNotContainsString('Created ', $output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        foreach (['', "bad\0path", 'php://memory'] as $path) {
            yield 'path ' . $path => [$path];
        }
    }

    #[DataProvider('invalidPaths')]
    public function testAnInvalidGeneratorPathWritesNothing(string $path): void
    {
        $app = new App(basePath: $this->directory);
        [$status, $output] = $this->command($app, ['make:middleware', 'RequireJson', '--path=' . $path]);
        self::assertSame([], glob($this->directory . '/*'), $output);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('--path=app/Http', $output);
    }

    public function testAFileCannotBeUsedAsTheGeneratorDirectory(): void
    {
        file_put_contents($this->directory . '/occupied', 'keep');
        $app = new App(basePath: $this->directory);
        [$status, $output] = $this->command($app, ['make:middleware', 'RequireJson', '--path=occupied/child']);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('--path=app/Http', $output);
        self::assertSame('keep', file_get_contents($this->directory . '/occupied'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidWatchPaths(): iterable
    {
        foreach (['', ',', 'missing', 'missing,', 'php://memory', "bad\0path"] as $value) {
            yield 'value ' . $value => [$value];
        }
    }

    #[DataProvider('invalidWatchPaths')]
    public function testWatchPathsAreCheckedEvenWhenWatchingWouldBeDisabled(string $value): void
    {
        $app = new App(basePath: $this->directory);
        // The incompatible flags also prevent startup on the old implementation.
        [$status, $output] = $this->command($app, ['serve', '--single', '--daemon', '--no-watch', '--watch=' . $value]);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('--watch=app,config', $output);
    }

    public function testAnAlreadyDeclaredMiddlewareClassCannotBeGeneratedElsewhere(): void
    {
        $app = new App(basePath: $this->directory);
        [$status, $output] = $this->command($app, [
            'make:middleware',
            'ValueOptionsTest',
            '--namespace=' . __NAMESPACE__,
            '--path=generated',
        ]);
        self::assertSame(1, $status, $output);
        self::assertSame([], glob($this->directory . '/*'));
        self::assertStringContainsString('already', $output);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function malformedJobIds(): iterable
    {
        foreach (['queue:retry', 'queue:forget'] as $command) {
            foreach ([[], [''], [' '], ["bad\n"], ['all', 'extra']] as $i => $arguments) {
                yield $command . ' ' . $i => [$command, $arguments];
            }
        }
    }

    #[DataProvider('malformedJobIds')]
    public function testAnInvalidJobSelectionDoesNotTouchTheStore(string $name, array $arguments): void
    {
        $store = $this->createMock(Store::class);
        $store->expects(self::never())->method($name === 'queue:retry' ? 'retry' : 'forget');
        $command = $name === 'queue:retry'
            ? new \Crab\Queue\Commands\RetryJobs($store)
            : new \Crab\Queue\Commands\ForgetJobs($store);
        $app = new App();
        $app->console->add($name, $command);
        [$status, $output] = $this->command($app, [$name, ...$arguments]);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString($name . ' 12', $output);
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function missingValuesBeforeOtherErrors(): iterable
    {
        foreach (['serve', 'restart', 'reload'] as $command) {
            yield $command . ' workers' => [$command, ['--address=bad', '--workers'], 'workers'];
        }
        yield 'serve watch' => ['serve', ['--workers=bad', '--watch'], 'watch'];
        yield 'restart timeout' => ['restart', ['--workers=bad', '--timeout'], 'timeout'];
        yield 'migration without name' => ['make:migration', ['--database'], 'database'];
        yield 'migration invalid name' => ['make:migration', ['???', '--database'], 'database'];
    }

    #[DataProvider('missingValuesBeforeOtherErrors')]
    public function testAMissingValueIsNamedBeforeAnyOtherInputError(
        string $name,
        array $arguments,
        string $option,
    ): void {
        $app = new App();
        $app->register(\Crab\Database\DatabaseProvider::class);
        [$status, $output] = $this->command($app, [$name, ...$arguments]);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('--' . $option . ' needs a value', $output);
        self::assertStringContainsString('--' . $option . '=value', $output);
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
    private function runProcess(array $arguments): array
    {
        $path = $this->directory . '/command-output';
        $process = proc_open($arguments, [1 => ['file', $path, 'w'], 2 => ['redirect', 1]], $pipes, $this->directory);
        self::assertIsResource($process);
        $state = proc_get_status($process);
        $deadline = hrtime(true) + 5_000_000_000;
        try {
            while ($state['running']) {
                if (hrtime(true) >= $deadline) {
                    self::fail(
                        'The middleware example command did not finish within 5 seconds: ' . implode(' ', $arguments),
                    );
                }
                usleep(1000);
                $state = proc_get_status($process);
            }
            return [$state['exitcode'], file_get_contents($path)];
        } finally {
            if ($state['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
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
