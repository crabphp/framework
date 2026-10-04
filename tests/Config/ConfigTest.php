<?php

declare(strict_types=1);

namespace Crab\Tests\Config;

use Crab\App;
use Crab\Config\Config;
use Crab\Config\Env;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crab-config-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory . '/{,.}[!.]*', GLOB_BRACE));
        rmdir($this->directory);
    }

    public function testDottedKeys(): void
    {
        $config = new Config(['app' => ['debug' => true, 'name' => null, 'nested' => ['deep' => 1]], 'top' => 'value']);
        self::assertTrue($config->get('app.debug'));
        self::assertSame(1, $config->get('app.nested.deep'));
        self::assertSame(['deep' => 1], $config->get('app.nested'));
        self::assertSame('value', $config->get('top'));
        self::assertNull($config->get('app.name', 'default'));
        self::assertSame('default', $config->get('app.missing', 'default'));
        self::assertSame('default', $config->get('top.below', 'default'));
        self::assertTrue($config->has('app.name'));
        self::assertFalse($config->has('app.missing'));
        self::assertSame('value', $config->all()['top']);
    }

    public function testLoadsADirectoryOfFilesThatReadTheEnvironment(): void
    {
        file_put_contents($this->directory . '/.env', <<<'ENV'
            # comment
            CRAB_TEST_NAME=Crab App   # trailing comment
            export CRAB_TEST_DEBUG=true
            CRAB_TEST_EMPTY=
            CRAB_TEST_NULL=null
            CRAB_TEST_QUOTED="line one\nline \"two\" # kept"
            CRAB_TEST_LITERAL='raw \n #text'
            CRAB_TEST_HASH=color#fff
            CRAB_TEST_REAL=from file
            ENV);
        putenv('CRAB_TEST_REAL=from environment');
        try {
            self::assertTrue(Env::load($this->directory . '/.env'));
            self::assertFalse(Env::load($this->directory . '/missing.env'));
            self::assertSame('Crab App', Env::get('CRAB_TEST_NAME'));
            self::assertTrue(Env::get('CRAB_TEST_DEBUG'));
            self::assertSame('', Env::get('CRAB_TEST_EMPTY', 'default'));
            self::assertNull(Env::get('CRAB_TEST_NULL', 'default'));
            self::assertSame("line one\nline \"two\" # kept", Env::get('CRAB_TEST_QUOTED'));
            self::assertSame('raw \n #text', Env::get('CRAB_TEST_LITERAL'));
            self::assertSame('color#fff', Env::get('CRAB_TEST_HASH'));
            self::assertSame('from environment', Env::get('CRAB_TEST_REAL'));
            self::assertSame('default', Env::get('CRAB_TEST_UNSET', 'default'));

            file_put_contents($this->directory . '/app.php', "<?php return ['name' => env('CRAB_TEST_NAME'), 'debug' => env('CRAB_TEST_DEBUG', false)];");
            file_put_contents($this->directory . '/log.php', "<?php return ['level' => 'info'];");
            $config = Config::load($this->directory);
            self::assertSame(['app' => ['name' => 'Crab App', 'debug' => true], 'log' => ['level' => 'info']], $config->all());

            $app = new App($config);
            self::assertSame($app, App::current());
            self::assertSame($config, config());
            self::assertSame('Crab App', config('app.name'));
            self::assertSame('default', config('app.missing', 'default'));
            self::assertSame('default', env('CRAB_TEST_UNSET', 'default'));
        } finally {
            putenv('CRAB_TEST_REAL');
        }
    }

    public function testInvalidSourcesAreRejected(): void
    {
        file_put_contents($this->directory . '/.env', "GOOD=1\nnot a pair\n");
        try {
            Env::load($this->directory . '/.env');
            self::fail('A malformed line was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('line 2', $error->getMessage());
        }
        file_put_contents($this->directory . '/bad.php', '<?php return 1;');
        try {
            Config::load($this->directory);
            self::fail('A file that returns no array was accepted.');
        } catch (\UnexpectedValueException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        Config::load($this->directory . '/missing');
    }
}
