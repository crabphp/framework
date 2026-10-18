<?php

declare(strict_types=1);

/**
 * In-process cost of a route's handler: user-mode CPU per request from
 * Router::handle() to the response, without the engine, a socket or a
 * load generator. It compares the ways a route can be handled:
 *
 *     psr15            a PSR-15 handler, which the router calls directly
 *     closure          a closure taking the request, called by its signature
 *     parameter        a closure taking a typed route parameter
 *     manual input     a PSR-15 handler that reads and checks a JSON body by hand
 *     typed input      a closure taking an input class, returning an output class
 *     typed fields     a closure taking the same fields as parameters
 *
 * Every request has a different body or path, so nothing parsed is reused.
 * Run with JIT on:
 *
 *     php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M benchmarks/typed-handler.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Crab\Http\ClosureHandler;
use Crab\Http\Request;
use Crab\Http\Response;
use Crab\Routing\Router;
use Crab\Validation\Email;
use Crab\Validation\Length;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class CreateUser
{
    public function __construct(
        #[Email]
        public string $email,
        #[Length(min: 2, max: 50)]
        public string $name,
        public ?int $age = null,
    ) {}
}

final readonly class User
{
    public function __construct(
        public int $id,
        public string $email,
        public string $name,
        public ?int $age,
    ) {}
}

final class ManualCreateUser implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $data = $request->getParsedBody();
        $errors = [];
        if (!is_array($data)) {
            return Response::json(['error' => 'The request body must be a JSON object.'], 422);
        }
        $email = $data['email'] ?? null;
        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'][] = 'must be an email address';
        }
        $name = $data['name'] ?? null;
        if (!is_string($name) || strlen($name) < 2 || strlen($name) > 50) {
            $errors['name'][] = 'must be 2 to 50 characters';
        }
        $age = $data['age'] ?? null;
        if ($age !== null && !is_int($age)) {
            $errors['age'][] = 'must be a whole number';
        }
        if ($errors !== []) {
            return Response::json(['error' => $errors], 422);
        }
        return Response::json(['id' => 1, 'email' => $email, 'name' => $name, 'age' => $age]);
    }
}

$options = getopt('', ['iterations:', 'trials:']);
$iterations = (int) ($options['iterations'] ?? 200_000);
$trials = (int) ($options['trials'] ?? 5);

$router = new Router();
$router->get('/psr15', new ClosureHandler(static fn(ServerRequestInterface $request) => Response::text('ok')));
$router->get('/closure', static fn(ServerRequestInterface $request) => Response::text('ok'));
$router->get('/users/{id}', static fn(int $id) => Response::text('ok'));
$router->post('/manual', new ManualCreateUser());
$router->post('/typed', static fn(CreateUser $input): User => new User(1, $input->email, $input->name, $input->age));
$router->post(
    '/fields',
    static fn(#[Email] string $email, #[Length(min: 2, max: 50)] string $name, ?int $age = null): User => new User(
        1,
        $email,
        $name,
        $age,
    ),
);

$json = ['Content-Type' => 'application/json'];
$cases = [
    'psr15' => static fn(int $i): Request => new Request('GET', '/psr15'),
    'closure' => static fn(int $i): Request => new Request('GET', '/closure'),
    'parameter' => static fn(int $i): Request => new Request('GET', '/users/' . $i),
    'manual input' => static fn(int $i): Request => new Request(
        'POST',
        '/manual',
        $json,
        '{"email":"user' . $i . '@example.com","name":"User ' . $i . '","age":' . ($i % 90) . '}',
    ),
    'typed input' => static fn(int $i): Request => new Request(
        'POST',
        '/typed',
        $json,
        '{"email":"user' . $i . '@example.com","name":"User ' . $i . '","age":' . ($i % 90) . '}',
    ),
    'typed fields' => static fn(int $i): Request => new Request(
        'POST',
        '/fields',
        $json,
        '{"email":"user' . $i . '@example.com","name":"User ' . $i . '","age":' . ($i % 90) . '}',
    ),
];

printf(
    "PHP %s, JIT %s, %d iterations, best of %d trials\n\n",
    PHP_VERSION,
    opcache_get_status(false)['jit']['on'] ?? false ? 'on' : 'off',
    $iterations,
    $trials,
);
foreach ($cases as $name => $make) {
    $best = INF;
    for ($trial = 0; $trial <= $trials; ++$trial) {
        // Requests are built outside the timed part; the first trial warms up.
        $requests = [];
        for ($i = 0; $i < 1000; ++$i) {
            $requests[] = $make($i);
        }
        $before = getrusage();
        for ($i = 0; $i < $iterations; ++$i) {
            // A clone has not parsed its body yet, as a request from the engine has not.
            $response = $router->handle(clone $requests[$i % 1000]);
        }
        $after = getrusage();
        if ($response->getStatusCode() !== 200) {
            fwrite(STDERR, "{$name} answered {$response->getStatusCode()}: {$response->getBody()}\n");
            exit(1);
        }
        $micros =
            (($after['ru_utime.tv_sec'] - $before['ru_utime.tv_sec']) * 1e6)
            + ($after['ru_utime.tv_usec'] - $before['ru_utime.tv_usec']);
        if ($trial > 0) {
            $best = min($best, $micros / $iterations);
        }
    }
    printf("%-14s %6.3f µs per request\n", $name, $best);
}
