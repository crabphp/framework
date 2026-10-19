<?php

declare(strict_types=1);

namespace Crab\Tests;

use Crab\App;
use Crab\Config\Config;
use Crab\Console\Console;
use Crab\Console\Output;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Database\Database as DatabaseContract;
use Crab\Database\Database;
use Crab\Database\Migrator;
use Crab\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testTheReadmePostExampleUsesItsServiceOnlyAfterValidation(): void
    {
        $app = new App(new Config(['log' => ['path' => 'php://memory']]));
        $users = new class {
            public int $created = 0;

            public function create(string $email, string $name): object
            {
                ++$this->created;
                return (object) ['id' => 7, 'email' => $email, 'name' => $name];
            }
        };
        $example = $this->example('../README.md', 'php', '// A few fields:', '~~~');
        $routes = explode('// Several routes', $example)[0];
        file_put_contents($this->directory . '/readme-routes.php', "<?php\nuse Crab\\Route;\n" . $routes);
        require $this->directory . '/readme-routes.php';

        $api = new \Crab\Testing\Api($app);
        $api
            ->post('/users', ['email' => 'ada@example.com', 'name' => 'Ada'])
            ->assertStatus(201)
            ->assertJson('email', 'ada@example.com')
            ->assertHeader('Location', '/users/7');
        $api->post('/users', ['email' => 'invalid', 'name' => 'x'])->assertStatus(422);
        self::assertSame(1, $users->created);
    }

    public function testTheReadmeLoginVerifiesPasswordsForKnownAndMissingUsers(): void
    {
        $example = $this->example('../README.md', 'php', 'final readonly class AuthController', '~~~');
        $name = 'ReadmeLogin' . bin2hex(random_bytes(6));
        file_put_contents(
            $this->directory . '/readme-login.php',
            "<?php\nuse Crab\\Contracts\\Database\\Database;\nuse Crab\\Contracts\\Auth\\PasswordHasher;\n"
                . "use Crab\\Contracts\\Auth\\TokenStore;\nuse Crab\\Http\\HttpException;\nuse Crab\\Validation\\Email;\n"
                . str_replace('class AuthController', 'class ' . $name, $example),
        );
        require $this->directory . '/readme-login.php';
        $db = new Database(static fn(): \PDO => new \PDO('sqlite::memory:'), 1);
        try {
            $db->execute('create table users (id integer primary key, email text, password text)');
            $db->table('users')->insert(['email' => 'known@example.com', 'password' => 'stored-hash']);
            $checked = [];
            $passwords = $this->createStub(\Crab\Contracts\Auth\PasswordHasher::class);
            $passwords
                ->method('verify')
                ->willReturnCallback(static function (#[\SensitiveParameter] string $password, string $hash) use (
                    &$checked,
                ): bool {
                    $checked[] = $hash;
                    return false;
                });
            $tokens = $this->createMock(\Crab\Contracts\Auth\TokenStore::class);
            $tokens->expects(self::never())->method('issue');
            $controller = new $name($db, $passwords, $tokens);
            foreach (['known@example.com', 'missing@example.com'] as $email) {
                $error = null;
                try {
                    $controller->login($email, 'incorrect');
                } catch (\Crab\Http\HttpException $failure) {
                    $error = $failure;
                }
                self::assertNotNull($error);
                self::assertSame(401, $error->status);
                self::assertSame('These credentials do not match.', $error->getMessage());
            }
            self::assertCount(2, $checked, 'Both account paths must reach password verification.');
            self::assertSame('stored-hash', $checked[0]);
            self::assertSame('bcrypt', password_get_info($checked[1])['algoName']);
            self::assertSame(12, password_get_info($checked[1])['options']['cost']);
        } finally {
            $db->close();
        }
    }

    public function testTheDescribedFieldsExampleDistinguishesOmissionFromExplicitNull(): void
    {
        $example = $this->example('validation.md', 'php', "'shipTo?'");
        $namespace = 'DescribedFields' . bin2hex(random_bytes(6));
        file_put_contents(
            $this->directory . '/described-fields.php',
            "<?php\nnamespace {$namespace};\nuse Crab\\Validation\\Fields;\nuse Crab\\Validation\\Data;\n"
            . "use Crab\\Validation\\Email;\nuse Crab\\Validation\\Length;\n"
            . "enum Plan: string { case Free = 'free'; }\nreturn static fn(\n"
            . $example
            . "): array => \$signup->toArray();\n",
        );
        $action = require $this->directory . '/described-fields.php';
        $app = new App(new Config(['log' => ['path' => 'php://memory']]));
        $app->router->post('/signup', $action);
        $api = new \Crab\Testing\Api($app);
        $fields = ['email' => 'ada@example.com', 'plan' => 'free', 'tags' => []];

        $omitted = $api->post('/signup', $fields)->assertStatus(200)->json();
        self::assertArrayNotHasKey('shipTo', $omitted);
        $api->post('/signup', [...$fields, 'shipTo' => null])->assertStatus(200)->assertJson('shipTo', null);
        $api
            ->post('/signup', [...$fields, 'shipTo' => ['street' => 7, 'country' => 'GB']])
            ->assertStatus(422)
            ->assertJson('error.fields', ['shipTo.street' => ['must be text']]);
        $api
            ->post('/signup', [...$fields, 'shipTo' => ['street' => '1 Main St', 'country' => 'GB']])
            ->assertStatus(200)
            ->assertJson('shipTo.street', '1 Main St');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function httpClientLimits(): iterable
    {
        yield 'whole-call deadline' => ['http_client.timeout'];
        yield 'HTTP/1.1 connection limit' => ['http_client.connections'];
    }

    #[DataProvider('httpClientLimits')]
    public function testTheReadmeHttpClientLimitsMatchTheGuide(string $setting): void
    {
        $pattern = '/^\| `' . preg_quote($setting, '/') . '` \| (.+)$/m';
        $guide = file_get_contents(dirname(__DIR__) . '/docs/http-client.md');
        $readme = file_get_contents(dirname(__DIR__) . '/README.md');
        preg_match($pattern, $guide, $canonical);
        preg_match($pattern, $readme, $summary);
        self::assertArrayHasKey(1, $canonical, $setting);
        self::assertArrayHasKey(1, $summary, $setting);
        self::assertSame($canonical[1], $summary[1], $setting . ' must describe the same limit in both places.');
    }

    public function testThePartialUpdateExampleAcceptsAnEmptyChangeSet(): void
    {
        $db = new Database(static fn(): \PDO => new \PDO('sqlite::memory:'), 1);
        $db->execute('create table users (id integer primary key, name text not null, bio text)');
        $db->table('users')->insert(['id' => 7, 'name' => 'Original', 'bio' => 'Original bio']);
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
            self::assertSame('Original bio', $db->table('users')->find(7)['bio'], 'Omission leaves the column alone.');
            $response = $app->handle(
                new Request('PATCH', '/users/7', ['Content-Type' => 'application/json'], '{"bio":null}'),
            );
            self::assertSame(204, $response->getStatusCode());
            self::assertNull($db->table('users')->find(7)['bio'], 'An explicit null writes SQL NULL.');
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

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function missingPruningDays(): iterable
    {
        yield 'bare' => [['--days']];
        yield 'separated' => [['--days', '30']];
        yield 'bare dry run' => [['--days', '--dry-run']];
        yield 'separated dry run' => [['--days', '30', '--dry-run']];
    }

    #[DataProvider('missingPruningDays')]
    public function testThePruningExampleRefusesMissingDaysBeforeQuerying(array $arguments): void
    {
        $db = $this->createMock(DatabaseContract::class);
        $db->expects(self::never())->method('table');
        [$status, $output] = $this->prune($db, $arguments);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('--days needs a value', $output);
        self::assertStringContainsString('--days=30', $output);
        self::assertStringNotContainsString('users deleted', $output);
        self::assertStringNotContainsString('users would be deleted', $output);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function malformedPruningDays(): iterable
    {
        foreach ([
            'banana',
            '',
            '-2',
            '1.5',
            '30oops',
            '1e309',
            (string) PHP_INT_MAX,
            '9223372036854775808',
            "30\n",
        ] as $value) {
            yield 'days ' . $value => [['--days=' . $value]];
            yield $value . ' dry run' => [['--days=' . $value, '--dry-run']];
        }
    }

    #[DataProvider('malformedPruningDays')]
    public function testThePruningExampleRejectsMalformedDaysBeforeQuerying(array $arguments): void
    {
        $db = $this->createMock(DatabaseContract::class);
        $db->expects(self::never())->method('table');
        [$status, $output] = $this->prune($db, $arguments);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('--days=30', $output);
        self::assertStringNotContainsString('users deleted', $output);
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, string}>
     */
    public static function validPruningDays(): iterable
    {
        yield 'default' => [[], ['recent'], '1 users deleted.'];
        yield 'explicit' => [['--days=30'], ['recent'], '1 users deleted.'];
        yield 'zero' => [['--days=0'], [], '2 users deleted.'];
        yield 'dry run' => [['--days=30', '--dry-run'], ['recent', 'old'], '1 users would be deleted.'];
    }

    #[DataProvider('validPruningDays')]
    public function testThePruningExampleKeepsRecentUsersAndHonorsDryRun(
        array $arguments,
        array $remaining,
        string $message,
    ): void {
        $db = new Database(static fn(): \PDO => new \PDO('sqlite::memory:'), 1);
        try {
            $db->execute('create table users (id integer primary key, name text, created_at text, confirmed_at text)');
            foreach (['recent' => 10, 'old' => 40] as $name => $days) {
                $db->table('users')->insert([
                    'name' => $name,
                    'created_at' => new \DateTimeImmutable('2099-01-31 12:00:00')
                        ->modify('-' . $days . ' days')
                        ->format('Y-m-d H:i:s'),
                ]);
            }
            [$status, $output] = $this->prune($db, $arguments);

            self::assertSame(0, $status, $output);
            self::assertSame($message . "\n", $output);
            self::assertSame($remaining, $db->table('users')->orderBy('id')->pluck('name'));
        } finally {
            $db->close();
        }
    }

    public function testTheChangingTablesExampleRollsBackItsIndexAndColumns(): void
    {
        $db = new Database(static fn(): \PDO => new \PDO('sqlite::memory:'), 1);
        try {
            $db->execute('create table users (id integer primary key, name text, legacy_code text)');
            $example = $this->example('migrations.md', 'php', "renameColumn('name', 'full_name')");
            $name = '2026_01_01_000000_change_users';
            file_put_contents(
                $this->directory . '/' . $name . '.php',
                "<?php\nuse Crab\\Contracts\\Database\\Schema;\nuse Crab\\Contracts\\Database\\Table;\n"
                . "return new class implements Crab\\Contracts\\Database\\Migration {\n"
                . $example
                . "\n};\n",
            );
            $migrator = new Migrator($db, $this->directory);

            self::assertSame([$name], $migrator->run());
            self::assertContains('users_phone_index', array_column($db->select('pragma index_list(users)'), 'name'));
            self::assertSame([$name], $migrator->rollback());
            self::assertSame(
                ['id', 'name', 'legacy_code'],
                array_column($db->select('pragma table_info(users)'), 'name'),
            );
            self::assertSame([], $db->select('pragma index_list(users)'));
            self::assertSame(0, $db->table('migrations')->count());
        } finally {
            $db->close();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function queueClockPages(): iterable
    {
        yield 'testing' => ['testing.md'];
        yield 'queues' => ['queues.md'];
    }

    #[DataProvider('queueClockPages')]
    public function testQueueAssertionsUseTheApplicationsClock(string $page): void
    {
        $app = new App(new Config(['queue' => ['store' => 'memory']]));
        $app->register(\Crab\Queue\QueueProvider::class);
        $clock = new \Crab\Testing\FrozenClock('2099-01-01');
        $app->container->instance(\Crab\Contracts\Clock\Clock::class, $clock);
        $app->container->get(\Crab\Contracts\Queue\Queue::class)->push(DocumentationQueuedJob::class, userId: 1);
        $example = $this->example($page, 'php', "->jobs('default', 'pending'");
        preg_match_all('/^\$(?:clock|waiting) = .*;$/m', $example, $lines);
        $code = str_replace('$this->app', '$app', implode("\n", $lines[0]));
        file_put_contents(
            $this->directory . '/queue-clock.php',
            "<?php\nuse Crab\\Contracts\\Queue\\Store;\nuse Crab\\Contracts\\Clock\\Clock;\n"
            . $code
            . "\nreturn \$waiting;",
        );
        $waiting = require $this->directory . '/queue-clock.php';
        self::assertCount(1, $waiting);
        self::assertSame(['userId' => 1], $waiting[0]->data);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidImportBatches(): iterable
    {
        yield 'bare' => [['--batch']];
        yield 'separated' => [['--batch', '500']];
        foreach (['', 'many', '-1', '0', '1.5', '500rows', '1e309', '9223372036854775808'] as $value) {
            yield 'batch ' . $value => [['--batch=' . $value]];
        }
    }

    #[DataProvider('invalidImportBatches')]
    public function testTheImportExampleRejectsAnInvalidBatchBeforeReadingItsFile(array $arguments): void
    {
        $input = new \Crab\Console\Input($arguments);
        $example = $this->example('console.md', 'php', '$batch =');
        file_put_contents($this->directory . '/import.php', "<?php\n" . $example);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--batch=500');
        require $this->directory . '/import.php';
    }

    public function testTheImportExampleAcceptsAReadableFileAndAWholeBatch(): void
    {
        $source = $this->directory . '/users.csv';
        file_put_contents($source, "name\nAda\n");
        $input = new \Crab\Console\Input([$source, '--batch=500']);
        $example = $this->example('console.md', 'php', '$batch =');
        file_put_contents($this->directory . '/import.php', "<?php\n" . $example . "\nreturn [\$file, \$batch];");
        $result = require $this->directory . '/import.php';
        self::assertSame([$source, 500], $result);
    }

    public function testTheImportExampleNamesTheFileItNeedsWhenNoneCanBeRead(): void
    {
        $input = new \Crab\Console\Input([$this->directory . '/missing.csv', '--batch=500']);
        $example = $this->example('console.md', 'php', '$batch =');
        file_put_contents($this->directory . '/import.php', "<?php\n" . $example);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('readable local CSV file');
        require $this->directory . '/import.php';
    }

    public function testTheReadmeUploadCookieUsesTheInjectedClock(): void
    {
        $app = new App(new Config(['log' => ['path' => 'php://memory']]));
        $clock = new \Crab\Testing\FrozenClock('2099-01-01 12:34:56');
        $app->container->instance(\Crab\Contracts\Clock\Clock::class, $clock);
        $example = $this->example('../README.md', 'php', "'/users/{id}/avatar'", '~~~');
        $imports = '';
        foreach ([
            'Crab\\Route',
            'Crab\\Contracts\\Http\\Request',
            'Crab\\Http\\Response',
            'Crab\\Http\\Cookie',
        ] as $type) {
            if (str_contains($example, 'use ' . $type . ';')) {
                continue;
            }
            $imports .= 'use ' . $type . ";\n";
        }
        file_put_contents($this->directory . '/upload-clock.php', "<?php\n" . $imports . $example);
        require $this->directory . '/upload-clock.php';
        $response = new \Crab\Testing\Api($app)
            ->post('/users/1/avatar', [])
            ->assertStatus(200);
        self::assertStringContainsString(
            'last_upload=' . $clock->now()->getTimestamp(),
            $response->header('Set-Cookie'),
        );
    }

    public function testTheTokenExpiryExampleUsesTheSuppliedClock(): void
    {
        $example = $this->example('authentication.md', 'php', "'ci'");
        preg_match('/^\$token = .*\x27ci\x27.*;.*$/m', $example, $line);
        $clock = new \Crab\Testing\FrozenClock('2099-01-01');
        $user = 7;
        $tokens = new class {
            public ?\DateTimeImmutable $expiry = null;

            public function issue(int $user, string $name, \DateTimeImmutable $expiry): string
            {
                $this->expiry = $expiry;
                return 'token';
            }
        };
        file_put_contents($this->directory . '/token-clock.php', "<?php\n" . $line[0]);
        require $this->directory . '/token-clock.php';
        self::assertSame('2099-01-31', $tokens->expiry?->format('Y-m-d'));
    }

    public function testTheReceiptExampleUsesTheInjectedClock(): void
    {
        $example = $this->example('queues.md', 'php', 'receipt_sent_at');
        $source =
            '<?php return new class($db, $mailer, $clock) { public function __construct(private $db, private $mailer, private $clock) {} '
            . $example
            . ' };';
        $db = new Database(static fn(): \PDO => new \PDO('sqlite::memory:'), 1);
        $db->execute('create table orders (id integer primary key, receipt_sent_at text)');
        $db->table('orders')->insert(['id' => 1]);
        $clock = new \Crab\Testing\FrozenClock('2099-01-01 12:34:56');
        $mailer = new class {
            public function sendReceipt(array $order, string $idempotencyKey): void {}
        };
        try {
            file_put_contents($this->directory . '/receipt-clock.php', $source);
            $action = require $this->directory . '/receipt-clock.php';
            $action->handle(1);
            self::assertSame('2099-01-01 12:34:56', $db->table('orders')->value('receipt_sent_at'));
        } finally {
            $db->close();
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function timerPauses(): iterable
    {
        yield 'ordinary loop' => [0];
        yield 'paused loop' => [250_000];
    }

    #[DataProvider('timerPauses')]
    public function testTheTimerExampleWaitsForAnObservedFlush(int $pause): void
    {
        $this->runTimerExample($pause);
    }

    public function testTheTimerExampleNamesTheDeadlineWhenNothingFlushes(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The metrics timer did not flush within 5 seconds.');

        $this->runTimerExample(0, flushes: false);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function prune(DatabaseContract $db, array $arguments): array
    {
        $name = 'PruneUsers' . bin2hex(random_bytes(6));
        $example = $this->example('console.md', 'php', 'class PruneUsers');
        file_put_contents(
            $this->directory . '/prune.php',
            "<?php\n" . str_replace('class PruneUsers', 'class ' . $name, $example),
        );
        require $this->directory . '/prune.php';
        $class = 'App\\Console\\' . $name;
        /** @var Command $command */
        $command = new $class($db, new \Crab\Testing\FrozenClock('2099-01-31 12:00:00'));
        $console = new Console();
        $console->add('user:prune', $command);
        $stream = fopen('php://memory', 'w+b');
        try {
            $status = $console->run(['user:prune', ...$arguments], new Output($stream, $stream));
            rewind($stream);
            return [$status, stream_get_contents($stream)];
        } finally {
            fclose($stream);
        }
    }

    private function runTimerExample(int $pause, bool $flushes = true): void
    {
        $namespace = 'DocumentedTimer' . bin2hex(random_bytes(6));
        $provider = $this->example('providers.md', 'php', 'class MetricsProvider');
        if ($pause > 0) {
            $provider = str_replace(
                '$worker->loop->repeat(',
                '$worker->loop->defer(static function (): void { usleep('
                . $pause
                . '); });'
                . "\n"
                . '$worker->loop->repeat(',
                $provider,
            );
        }
        if (!$flushes) {
            // Keep the timer active but disable its effect, so only the documented deadline can end the wait.
            $provider = str_replace('static fn () => $metrics->flush()', 'static fn () => null', $provider);
        }
        $metrics = <<<'PHP'
            final class Metrics
            {
                public int $flushes = 0;

                public function flush(): void
                {
                    ++$this->flushes;
                }
            }
            PHP;
        file_put_contents(
            $this->directory . '/timer-provider.php',
            "<?php\nnamespace {$namespace};\n" . $provider . "\n" . $metrics,
        );
        require $this->directory . '/timer-provider.php';
        $example = $this->example('testing.md', 'php', 'WorkerHarness::run(');
        file_put_contents(
            $this->directory . '/timer-test.php',
            "<?php\nnamespace {$namespace};\n" . str_replace('self::', '\\PHPUnit\\Framework\\Assert::', $example),
        );
        require $this->directory . '/timer-test.php';
    }

    private function example(string $page, string $language, string $contains, string $fence = '```'): string
    {
        $markdown = file_get_contents(dirname(__DIR__) . '/docs/' . $page);
        preg_match_all('/' . $fence . $language . '\n(.*?)' . $fence . '/s', $markdown, $matches);
        foreach ($matches[1] as $code) {
            if (str_contains($code, $contains)) {
                return $code;
            }
        }
        self::fail('No ' . $language . ' example containing ' . $contains . ' in ' . $page);
    }
}

final readonly class DocumentationQueuedJob
{
    public function handle(int $userId): void {}
}
