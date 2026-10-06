<?php

declare(strict_types=1);

namespace Crab\Tests\Console;

use Crab\App;
use Crab\Console\Console;
use Crab\Console\Input;
use Crab\Console\Output;
use Crab\Container\Container;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Console as ConsoleContract;
use Crab\Contracts\Console\Input as InputContract;
use Crab\Contracts\Console\Output as OutputContract;
use PHPUnit\Framework\TestCase;

final class Prefix
{
    public string $value = 'Hello';
}

final class Greet implements Command
{
    public static int $made = 0;

    public function __construct(private Prefix $prefix = new Prefix())
    {
        ++self::$made;
    }

    public function description(): string
    {
        return "Greet someone\n\nArguments:\n  name";
    }

    public function handle(InputContract $input, OutputContract $output): int
    {
        $output->line($this->prefix->value . ', ' . $input->argument(0, 'world') . ($input->flag('shout') ? '!' : ''));
        return 0;
    }
}

final class ConsoleTest extends TestCase
{
    /**
     * @var resource
     */
    private mixed $out;
    /**
     * @var resource
     */
    private mixed $error;

    protected function setUp(): void
    {
        $this->out = fopen('php://memory', 'w+b');
        $this->error = fopen('php://memory', 'w+b');
        Greet::$made = 0;
    }

    public function testInputSeparatesArgumentsFromOptions(): void
    {
        $input = new Input(['one', '--name=Crab', '--force', '-vq', '--empty=', '--path=a=b', '-', '--', '--literal', 'two']);
        self::assertSame(['one', '-', '--literal', 'two'], $input->arguments());
        self::assertSame(['name' => 'Crab', 'force' => true, 'v' => true, 'q' => true, 'empty' => '', 'path' => 'a=b'], $input->options());
        self::assertSame('one', $input->argument(0));
        self::assertSame('default', $input->argument(9, 'default'));
        self::assertNull($input->argument(9));
        self::assertSame('Crab', $input->option('name'));
        self::assertTrue($input->option('force'));
        self::assertSame('default', $input->option('missing', 'default'));
        self::assertTrue($input->flag('force') && $input->flag('name') && $input->flag('v'));
        self::assertFalse($input->flag('missing') || $input->flag('empty'));
        self::assertFalse((new Input(['--single=false']))->flag('single'));
        self::assertSame([['-5', '3', '-1.5'], []], [(new Input(['-5', '3', '-1.5']))->arguments(), (new Input(['-5', '3', '-1.5']))->options()]);
    }

    public function testRunsClosuresObjectsAndClasses(): void
    {
        $console = (new Console())
            ->add('sum', static function (Input $input, Output $output): int {
                $output->line((string) array_sum($input->arguments()));
                return 3;
            }, 'Add numbers')
            ->add('greet', Greet::class)
            ->add('greet:object', new Greet());
        self::assertTrue($console->has('sum'));
        self::assertFalse($console->has('missing'));
        self::assertSame(1, Greet::$made);

        self::assertSame(3, $this->exec($console, 'sum', '2', '3'));
        self::assertSame(0, $this->exec($console, 'greet', 'Crab', '--shout'));
        self::assertSame(0, $this->exec($console, 'greet'));
        self::assertSame(0, $this->exec($console, 'greet:object'));
        self::assertSame("5\nHello, Crab!\nHello, world\nHello, world\n", $this->written($this->out));
        self::assertSame(2, Greet::$made);
    }

    public function testClassCommandsAreMadeByTheContainer(): void
    {
        $container = new Container();
        $prefix = new Prefix();
        $prefix->value = 'Hi';
        $container->instance(Prefix::class, $prefix);
        self::assertSame(0, $this->exec((new Console($container))->add('greet', Greet::class), 'greet'));
        self::assertSame("Hi, world\n", $this->written($this->out));
    }

    public function testListAndHelp(): void
    {
        $console = (new Console())->add('zeta', static fn() => 0, 'Last')->add('greet', Greet::class);
        foreach ([[], ['list'], ['--help'], ['help']] as $arguments) {
            ftruncate($this->out, 0);
            rewind($this->out);
            self::assertSame(0, $this->exec($console, ...$arguments));
            self::assertSame("Commands:\n  greet           Greet someone\n  zeta            Last\n"
                . "  help <command>  Describe a command; so does <command> --help\n  list            List the commands\n", $this->written($this->out));
        }
        ftruncate($this->out, 0);
        rewind($this->out);
        self::assertSame(0, $this->exec((new Console(title: 'Shop'))->add('zeta', static fn() => 0, 'Last'), 'list'));
        self::assertStringStartsWith("Shop\n\nCommands:\n  zeta", $this->written($this->out));

        ftruncate($this->out, 0);
        rewind($this->out);
        self::assertSame(0, $this->exec($console, 'help', 'greet'));
        self::assertSame("Greet someone\n\nArguments:\n  name\n", $this->written($this->out));
    }

    public function testAskingACommandForHelpDescribesItAndDoesNotRunIt(): void
    {
        $ran = 0;
        $console = (new Console())
            ->add('greet', Greet::class)
            ->add('count', static function () use (&$ran): int {
                ++$ran;
                return 0;
            }, 'Count up');
        Greet::$made = 0;
        foreach ([['greet', '--help'], ['greet', '-h'], ['greet', 'Ada', '--shout', '--help'], ['greet', '-sh']] as $index => $arguments) {
            $out = fopen('php://memory', 'w+');
            self::assertSame(0, $console->run($arguments, new Output($out, $out)));
            rewind($out);
            $written = stream_get_contents($out);
            if ($index < 3) {
                self::assertSame("Greet someone\n\nArguments:\n  name\n", $written, implode(' ', $arguments));
            } else {
                self::assertStringStartsWith('Hello, world', $written, 'A letter among others is that command\'s own option.');
            }
        }
        $out = fopen('php://memory', 'w+');
        self::assertSame(0, $console->run(['count', '--help'], new Output($out, $out)));
        rewind($out);
        self::assertSame(["Count up\n", 0], [stream_get_contents($out), $ran]);
        self::assertSame(0, $console->run(['count', '--', '--help'], new Output($out, $out)));
        self::assertSame(1, $ran, 'After "--" the words are arguments, and the command runs.');
    }

    public function testListSurvivesACommandThatCannotBeMade(): void
    {
        $console = (new Console())->add('fine', static fn() => 0, 'Fine')->add('broken', 'No\\Such\\Command');
        self::assertSame(0, $this->exec($console, 'list'));
        self::assertStringContainsString("  broken          (unavailable: Command \"broken\" is No\\Such\\Command", $this->written($this->out));
        self::assertStringContainsString("  fine            Fine\n", $this->written($this->out));
    }

    public function testFailuresReturnOne(): void
    {
        $console = (new Console())
            ->add('fail', static fn() => throw new \RuntimeException('broken'))
            ->add('wrong', Prefix::class)
            ->add('missing', 'No\\Such\\Command');
        self::assertSame(1, $this->exec($console, 'nope'));
        self::assertSame(1, $this->exec($console, 'help', 'nope'));
        self::assertSame(1, $this->exec($console, 'fail'));
        self::assertSame(1, $this->exec($console, 'wrong'));
        self::assertSame(1, $this->exec($console, 'missing'));
        $errors = $this->written($this->error);
        self::assertStringContainsString('Unknown command "nope"', $errors);
        self::assertStringContainsString('RuntimeException: broken (' . __FILE__, $errors);
        self::assertStringContainsString('must implement ' . Command::class, $errors);
        self::assertSame('', $this->written($this->out));
    }

    public function testInvalidNamesAreRejected(): void
    {
        foreach (['', 'Has Space', 'list', 'help', '--flag'] as $name) {
            try {
                (new Console())->add($name, static fn() => 0);
                self::fail("\"{$name}\" was accepted.");
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertTrue((new Console())->add('make:thing-2', static fn() => 0)->has('make:thing-2'));
    }

    public function testApplicationCommands(): void
    {
        $app = new App();
        self::assertSame($app->console, $app->container->get(ConsoleContract::class));
        self::assertSame($app->console, $app->container->get(Console::class));
        self::assertTrue($app->console->has('serve') && $app->console->has('routes'));
        self::assertSame(0, $this->exec($app->console, 'routes'));
        $app->router->get('/', static fn() => 'home')->post('/users/{id}', Greet::class);
        self::assertSame(0, $this->exec($app->console, 'routes'));
        self::assertSame("No routes are registered.\nGET   /            Closure\nPOST  /users/{id}  " . Greet::class . "\n", $this->written($this->out));
        $routes = $app->router->routes();
        self::assertSame(['GET', '/', 'Closure', []], [$routes[0]->method, $routes[0]->pattern, $routes[0]->handler, $routes[0]->parameters]);
        self::assertSame(['POST', '/users/{id}', Greet::class, ['id' => 'string']], [$routes[1]->method, $routes[1]->pattern, $routes[1]->handler, $routes[1]->parameters]);

        $app->console->add('hello', static function (Input $input, Output $output): int {
            $output->line('hello');
            return 0;
        });
        self::assertSame(0, $this->exec($app->console, 'hello'));
    }

    private function exec(Console $console, string ...$arguments): int
    {
        return $console->run($arguments, new Output($this->out, $this->error));
    }

    /**
     * @param resource $stream
     */
    private function written(mixed $stream): string
    {
        rewind($stream);
        return stream_get_contents($stream);
    }
}
