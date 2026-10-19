<?php

declare(strict_types=1);

namespace Crab\Tests\Console;

use Crab\App;
use Crab\Commands\Serve;
use Crab\Config\Config;
use Crab\Console\Input;
use Crab\Console\Output;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function missingValues(): iterable
    {
        foreach (['address', 'workers', 'watch'] as $name) {
            yield $name . ' omitted' => [$name, ['--' . $name]];
            yield $name . ' separated' => [$name, ['--' . $name, 'value']];
        }
    }

    #[DataProvider('missingValues')]
    public function testAValueOptionIsRejectedBeforeTheServerCanStart(string $name, array $arguments): void
    {
        $app = new App();
        $stream = fopen('php://memory', 'w+b');
        try {
            $status = $app->console->run(
                ['serve', '--single', '--daemon', ...$arguments],
                new Output($stream, $stream),
            );
            rewind($stream);
            $output = stream_get_contents($stream);
            self::assertSame(1, $status, $output);
            self::assertStringContainsString('--' . $name . ' needs a value', $output);
            self::assertStringContainsString('--' . $name . '=value', $output);
            self::assertStringNotContainsString('server running', $output);
        } finally {
            fclose($stream);
        }
    }

    public function testValuedOptionsOverrideTheConfiguration(): void
    {
        $app = new App(new Config(['server' => ['address' => 'tcp://127.0.0.1:28480', 'workers' => 2]]));
        self::assertSame('tcp://127.0.0.1:28481', Serve::address($app, new Input(['--address=tcp://127.0.0.1:28481'])));
        self::assertSame(4, Serve::workers($app, new Input(['--workers=4'])));
        self::assertSame(2, Serve::workers($app, new Input()));
    }
}
