<?php

declare(strict_types=1);

namespace Crab\Tests;

use Crab\App;
use Crab\Config\Config;
use Crab\Database\Database;
use Crab\Http\Request;
use PHPUnit\Framework\TestCase;

final class DocumentationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crab-docs-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testTheIntroductoryPostExampleCreatesAndValidatesAUser(): void
    {
        $app = new App(new Config(['log' => ['path' => 'php://memory']]));
        $example = $this->example('actions.md', 'php', "Route::post('/users'");
        file_put_contents($this->directory . '/route.php', "<?php\n" . $example);
        require $this->directory . '/route.php';
        $valid = $app->handle(
            new Request(
                'POST',
                '/users',
                ['Content-Type' => 'application/json'],
                '{"email":"ada@example.com","name":"Ada"}',
            ),
        );
        self::assertSame(201, $valid->getStatusCode());
        $invalid = $app->handle(
            new Request('POST', '/users', ['Content-Type' => 'application/json'], '{"email":"invalid","name":"x"}'),
        );
        self::assertSame(422, $invalid->getStatusCode());
    }

    public function testThePartialUpdateExampleAcceptsAnEmptyChangeSet(): void
    {
        $db = new Database(static fn(): \PDO => new \PDO('sqlite::memory:'), 1);
        $db->execute('create table users (id integer primary key, name text not null, bio text)');
        $db->table('users')->insert(['id' => 7, 'name' => 'Original']);
        $example = $this->example('actions.md', 'php', '// PATCH /users/7');
        file_put_contents(
            $this->directory . '/action.php',
            "<?php\nuse Crab\\Validation\\Fields;\nuse Crab\\Validation\\Data;\nreturn new class(\$db) {\npublic function __construct(private Crab\\Contracts\\Database\\Database \$db) {}\n"
            . $example
            . "\n};\n",
        );
        $action = require $this->directory . '/action.php';
        $app = new App(new Config(['log' => ['path' => 'php://memory']]));
        $app->container->instance($action::class, $action);
        $app->router->patch('/users/{id}', $action::class);
        try {
            foreach (['{}', '{"unknown":"ignored"}'] as $body) {
                $response = $app->handle(
                    new Request('PATCH', '/users/7', ['Content-Type' => 'application/json'], $body),
                );
                self::assertSame(204, $response->getStatusCode(), $body);
                self::assertSame('Original', $db->table('users')->find(7)['name']);
            }
            $response = $app->handle(
                new Request('PATCH', '/users/7', ['Content-Type' => 'application/json'], '{"name":"Ada"}'),
            );
            self::assertSame(204, $response->getStatusCode());
            self::assertSame('Ada', $db->table('users')->find(7)['name']);
        } finally {
            $db->close();
        }
    }

    public function testTheDeployRecipeStopsWhenAMigrationFails(): void
    {
        $stub = <<<'SH'
            #!/bin/sh
            printf '%s %s\n' "${0##*/}" "$*" >> "$DX_DEPLOY_CALLS"
            if [ "${0##*/}" = php ] && [ "$*" = 'cua migrate' ]; then
                exit 1
            fi
            exit 0
            SH;
        foreach (['git', 'composer', 'php', 'systemctl'] as $command) {
            file_put_contents($this->directory . '/' . $command, $stub);
            chmod($this->directory . '/' . $command, 0o700);
        }
        $page = file_get_contents(dirname(__DIR__) . '/docs/deployment.md');
        $section = substr($page, strpos($page, '## A deploy, start to finish'));
        preg_match('/```sh\n(.*?)```/s', $section, $match);
        self::assertArrayHasKey(1, $match);
        file_put_contents($this->directory . '/deploy.sh', $match[1]);
        $calls = $this->directory . '/calls';
        $environment = getenv();
        $environment['PATH'] = $this->directory . ':' . $environment['PATH'];
        $environment['DX_DEPLOY_CALLS'] = $calls;
        $process = proc_open(
            ['/bin/sh', $this->directory . '/deploy.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->directory,
            $environment,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($process), $output);
        $invoked = file_get_contents($calls);
        self::assertStringContainsString('php cua migrate', $invoked);
        self::assertStringNotContainsString('php cua reload', $invoked);
        self::assertStringNotContainsString('systemctl', $invoked);
    }

    private function example(string $page, string $language, string $contains): string
    {
        $markdown = file_get_contents(dirname(__DIR__) . '/docs/' . $page);
        preg_match_all('/```' . $language . '\n(.*?)```/s', $markdown, $matches);
        foreach ($matches[1] as $code) {
            if (str_contains($code, $contains)) {
                return $code;
            }
        }
        self::fail('No ' . $language . ' example containing ' . $contains . ' in ' . $page);
    }
}
