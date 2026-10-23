<?php

declare(strict_types=1);

namespace Crab\Tests\Config;

use Crab\App;
use Crab\Auth\AuthProvider;
use Crab\Broadcast\BroadcastProvider;
use Crab\Cache\CacheProvider;
use Crab\Config\Config;
use Crab\Config\Schema;
use Crab\Contracts\Config\InvalidSettings;
use Crab\Database\DatabaseProvider;
use Crab\Events\EventsProvider;
use Crab\HttpClient\HttpClientProvider;
use Crab\Queue\QueueProvider;
use Crab\Schedule\ScheduleProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every setting the framework reads is checked at startup against what
 * it takes, and a setting that does not fit is named with what it takes.
 */
final class SettingsTest extends TestCase
{
    private const PROVIDERS = [
        EventsProvider::class,
        AuthProvider::class,
        BroadcastProvider::class,
        CacheProvider::class,
        DatabaseProvider::class,
        HttpClientProvider::class,
        QueueProvider::class,
        ScheduleProvider::class,
    ];

    /**
     * @param array<string, mixed> $settings
     */
    private static function app(array $settings = []): App
    {
        $app = new App(new Config($settings));
        $app->register(...self::PROVIDERS);
        return $app;
    }

    public function testEverySectionHasRulesAndEveryDefaultFitsThem(): void
    {
        $app = self::app();
        // A default without a rule would be reported as not a setting, so this also finds a setting left unruled.
        self::assertSame([], $app->config->problems());
        self::assertSame(
            array_keys($app->config->all()),
            array_keys($app->config->rules()),
            'Every section the framework declares has rules.',
        );
    }

    /**
     * A value of the wrong kind for every setting, made from its rule, and
     * one past each limit, so that no rule takes everything by mistake.
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function wrongValues(): iterable
    {
        foreach (self::app()->config->rules() as $section => $rules) {
            foreach ($rules as $key => $rule) {
                if ($key === '*' || $rule === [] || $rule === ['type' => 'object']) {
                    // What it takes is anything, or any settings by name, passed on as they are.
                    continue;
                }
                $types = (array) ($rule['type'] ?? []);
                $wrong = match (true) {
                    isset($rule['enum']) => 'not-one-of-them',
                    !in_array('string', $types, true) => 'four',
                    !in_array('array', $types, true) && !in_array('object', $types, true) => ['a', 'list'],
                    default => 12.5,
                };
                yield "{$section}.{$key} kind" => ["{$section}.{$key}", $wrong];
                if (isset($rule['minimum'])) {
                    yield "{$section}.{$key} minimum" => ["{$section}.{$key}", $rule['minimum'] - 1];
                }
                if (isset($rule['exclusiveMinimum'])) {
                    yield "{$section}.{$key} exclusive minimum" => ["{$section}.{$key}", $rule['exclusiveMinimum']];
                }
                if (($rule['minLength'] ?? 0) > 0) {
                    yield "{$section}.{$key} empty" => ["{$section}.{$key}", ''];
                }
            }
        }
    }

    #[DataProvider('wrongValues')]
    public function testEverySettingRefusesAWrongValueAndSaysWhatItTakes(string $key, mixed $value): void
    {
        [$section, $name] = explode('.', $key, 2);
        $problems = self::app([$section => [$name => $value]])->config->problems();
        self::assertCount(1, $problems, implode("\n", $problems));
        self::assertMatchesRegularExpression('/^' . preg_quote($key, '/') . ' is .+; it takes .+\.$/', $problems[0]);
    }

    public function testStringsFromTheEnvironmentAreTakenForTheNumbersAndSwitchesTheySpell(): void
    {
        $app = self::app([
            'server' => ['workers' => '4', 'drain' => '2.5', 'max_body' => '1048576'],
            'app' => ['debug' => '1'],
            'http_client' => ['http2' => '0', 'timeout' => '10'],
            'database' => ['connections' => ['main' => ['driver' => 'mysql', 'port' => '3306', 'pool' => null]]],
        ]);
        self::assertSame([], $app->config->problems());
        self::assertSame(
            [
                'server.workers is "4.0"; it takes a whole number, at least 1.',
                'server.drain is "-2"; it takes a number greater than 0.',
            ],
            self::app(['server' => ['workers' => '4.0', 'drain' => '-2']])->config->problems(),
        );
    }

    public function testEveryProblemIsSaidAtOnceInTheOrderOfTheSettings(): void
    {
        $problems = self::app([
            'cache' => ['store' => 'reddis', 'stroe' => 'file'],
            'queue' => ['backoff' => [10, 'soon'], 'supervisors' => ['mail' => ['queues' => [], 'workres' => 2]]],
            'log' => ['level' => 'verbose'],
        ])->config->problems();
        self::assertSame(
            [
                'log.level is "verbose"; it takes "debug", "info", "notice", "warning", "error", "critical", "alert" or "emergency".',
                'cache.store is "reddis"; it takes "file", "database" or "array". "redis" is one more, with crabphp/redis and its RedisProvider registered after this provider.',
                'cache.stroe is not a setting; cache takes store, directory, table, database, prefix and ttl. Did you mean cache.store?',
                'queue.backoff.1 is "soon"; it takes a whole number, at least 0.',
                'queue.supervisors.mail.queues is empty; it takes a non-empty list of non-empty strings.',
                'queue.supervisors.mail.workres is not a setting; queue.supervisors.mail takes queues, tries, backoff, retry_after, workers, concurrency and sleep. Did you mean queue.supervisors.mail.workers?',
            ],
            $problems,
        );
    }

    public function testADatabaseConnectionIsCheckedKeyByKey(): void
    {
        $problems = self::app([
            'database' => [
                'connections' => [
                    'main' => ['driver' => 'postgres', 'port' => 70_000, 'password' => 12_345, 'hots' => 'db'],
                ],
            ],
        ])->config->problems();
        self::assertSame(
            [
                'database.connections.main.driver is "postgres"; it takes "sqlite", "mysql" or null.',
                'database.connections.main.port is 70000; it takes a whole number from 1 to 65535 or null.',
                'database.connections.main.password is not right; it takes a string or null.',
                'database.connections.main.hots is not a setting; database.connections.main takes driver, host, port, database, username, password, charset, unix_socket, dsn, migrations, options, pool, timeout and async. Did you mean database.connections.main.host?',
            ],
            $problems,
        );
    }

    public function testASecretIsNotRepeatedInAProblem(): void
    {
        $config = new Config(['mail' => [
            'password' => '',
            'api_key' => 'short',
            'url' => 'redis://user:hunter2@cache:6379',
            'dsn' => 'smtp://host?password=hunter2&x=1',
        ]]);
        $config->defaults(
            'mail',
            [],
            [
                'password' => ['type' => 'string', 'minLength' => 1],
                'api_key' => ['type' => 'string', 'minLength' => 8],
                'url' => ['type' => 'string', 'pattern' => '^https://'],
                'dsn' => ['type' => 'string', 'pattern' => '^mysql:'],
            ],
        );
        $problems = implode("\n", $config->problems());
        self::assertStringNotContainsString('hunter2', $problems);
        self::assertStringNotContainsString('short', $problems);
        self::assertStringContainsString('mail.password is ""; it takes a non-empty string.', $problems);
        self::assertStringContainsString(
            'mail.api_key is not right; it takes a string of at least 8 characters.',
            $problems,
        );
        self::assertStringContainsString('mail.url is "redis://user:(hidden)@cache:6379"', $problems);
        self::assertStringContainsString('mail.dsn is "smtp://host?password=(hidden)&x=1"', $problems);
    }

    public function testAnotherProviderAddsAChoiceAndAnyOtherRuleReplacesTheEarlierOne(): void
    {
        $config = new Config(['cache' => ['store' => 'redis', 'ttl' => 0]]);
        $config->defaults(
            'cache',
            ['store' => 'file', 'ttl' => null],
            [
                'store' => ['enum' => ['file', 'array']],
                'ttl' => ['type' => ['integer', 'null'], 'minimum' => 1],
            ],
        );
        self::assertSame(
            [
                'cache.store is "redis"; it takes "file" or "array".',
                'cache.ttl is 0; it takes a whole number, at least 1, or null.',
            ],
            $config->problems(),
        );
        $config->defaults('cache', [], ['store' => ['enum' => ['redis', 'file']], 'ttl' => ['type' => 'integer']]);
        self::assertSame(['file', 'array', 'redis'], $config->rules()['cache']['store']['enum']);
        self::assertSame([], $config->problems());
    }

    public function testASectionWithRulesTakesNoOtherKeysUnlessItSaysSo(): void
    {
        $app = self::app([
            'app' => ['timezone' => 'UTC'],
            'server' => ['wokers' => 2],
            'payments' => ['anything' => true],
        ]);
        self::assertSame(
            [
                'server.wokers is not a setting; server takes address, workers, with, watch, max_body, body_timeout, drain, max_connections, loop, pid and log. Did you mean server.workers?',
            ],
            $app->config->problems(),
            "The application's own settings may sit in app, and a section of its own has no rules.",
        );
        self::assertSame(
            ['cache is not settings by name; it takes store, directory, table, database, prefix, ttl.'],
            self::app(['cache' => 'file'])->config->problems(),
        );
    }

    public function testOnlyTheSectionsAskedForAreChecked(): void
    {
        $config = self::app(['cache' => ['store' => 'x'], 'log' => ['level' => 'x']])->config;
        self::assertCount(1, $config->problems('log'));
        self::assertCount(2, $config->problems('log', 'cache'));
        self::assertSame([], $config->problems('server'));
    }

    public function testATypoInARuleIsRefusedWhenItIsDeclared(): void
    {
        $config = new Config();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The rule for cache.ttl uses minimun, which settings do not check.');
        $config->defaults('cache', ['ttl' => 5], ['ttl' => ['type' => 'integer', 'minimun' => 1]]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function descriptions(): iterable
    {
        yield 'positive whole number' => [['type' => 'integer', 'minimum' => 1], 'a whole number, at least 1'];
        yield 'range' => [['type' => 'integer', 'minimum' => 1, 'maximum' => 65_535], 'a whole number from 1 to 65535'];
        yield 'seconds' => [['type' => 'number', 'exclusiveMinimum' => 0], 'a number greater than 0'];
        yield 'nullable' => [['type' => ['string', 'null']], 'a string or null'];
        yield 'switch' => [['type' => 'boolean'], 'true or false'];
        yield 'choice' => [['enum' => ['a', 'b', 'c']], '"a", "b" or "c"'];
        yield 'list' => [
            ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0]],
            'a list of whole numbers, at least 0',
        ];
        yield 'names' => [
            ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'names, each with a string',
        ];
        yield 'example' => [
            ['type' => 'string', 'examples' => ['tcp://127.0.0.1:8080']],
            'a string, such as "tcp://127.0.0.1:8080"',
        ];
        yield 'time zone' => [['type' => ['string', 'null'], 'format' => 'timezone'], 'a time zone or null'];
        yield 'anything' => [[], 'anything'];
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('descriptions')]
    public function testWhatARuleTakesIsSaidInWords(array $schema, string $words): void
    {
        self::assertSame($words, Schema::describe($schema));
    }

    public function testATimeZoneMustBeOneThatExists(): void
    {
        self::assertSame([], self::app(['schedule' => ['timezone' => 'Europe/Paris']])->config->problems());
        self::assertSame(
            ['schedule.timezone is "Mars/Olympus"; it takes a time zone or null, such as "Europe/Paris".'],
            self::app(['schedule' => ['timezone' => 'Mars/Olympus']])->config->problems(),
        );
    }

    public function testTheApplicationRefusesToRunWorkWithSettingsThatDoNotFit(): void
    {
        $app = self::app(['queue' => ['tries' => 0]]);
        try {
            $app->run('test', static function (): void {}, single: true);
            self::fail('Nothing runs with a setting that does not fit.');
        } catch (InvalidSettings $error) {
            self::assertSame(['queue.tries is 0; it takes a whole number, at least 1.'], $error->problems());
            self::assertSame(
                "A setting does not fit:\n  queue.tries is 0; it takes a whole number, at least 1.",
                $error->getMessage(),
            );
        }
    }

    public function testTheConsoleSaysWhatDoesNotFitWithoutAStackTrace(): void
    {
        $app = self::app(['server' => ['drain' => 0, 'loop' => 'epoll']]);
        $stream = fopen('php://memory', 'w+b');
        try {
            $status = $app->console->run(['start', '--single'], new \Crab\Console\Output($stream, $stream));
            rewind($stream);
            self::assertSame(1, $status);
            self::assertSame(
                "2 settings do not fit:\n"
                . "  server.drain is 0; it takes a number greater than 0.\n"
                . "  server.loop is \"epoll\"; it takes \"select\", \"ev\", \"uv\" or \"event\".\n",
                stream_get_contents($stream),
            );
        } finally {
            fclose($stream);
        }
    }
}
