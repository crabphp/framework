<?php

declare(strict_types=1);

namespace Crab\Tests\Routing;

use Crab\App;
use Crab\Config\Config;
use Crab\Container\Container;
use Crab\Contracts\Database\Page;
use Crab\Contracts\Http\Request as RequestContract;
use Crab\Contracts\Validation\Validator as ValidatorContract;
use Crab\Http\Request;
use Crab\Http\Response;
use Crab\Routing\OpenApi;
use Crab\Routing\Router;
use Crab\Validation\Email;
use Crab\Validation\Length;
use Crab\Validation\ListOf;
use Crab\Validation\Range;
use Crab\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

enum Plan: string
{
    case Free = 'free';
    case Paid = 'paid';
}

final readonly class CreateUser
{
    public function __construct(
        #[Email] public string $email,
        #[Length(min: 2, max: 50)] public string $name,
        public Plan $plan = Plan::Free,
    ) {
    }
}

final readonly class ListUsers
{
    public function __construct(
        #[Range(min: 1)] public int $page = 1,
        public ?string $search = null,
    ) {
    }
}

final readonly class User
{
    public function __construct(public int $id, public string $email, public string $name, public Plan $plan)
    {
    }
}

final readonly class Event
{
    /**
     * @param list<\DateTimeImmutable> $reminders
     */
    public function __construct(
        public string $title,
        public \DateTimeImmutable $at,
        #[ListOf(\DateTimeImmutable::class)] public array $reminders = [],
    ) {
    }
}

final class Names
{
    public int $made = 0;

    public function prefix(): string
    {
        return 'user-';
    }
}

/**
 * A handler class: services in the constructor, the request's data in __invoke().
 */
final readonly class ShowUser
{
    public function __construct(private Names $names)
    {
        ++$names->made;
    }

    public function __invoke(int $id): User
    {
        return new User($id, "{$id}@example.com", $this->names->prefix() . $id, Plan::Paid);
    }
}

/**
 * Several routes in one class, each a method.
 */
final readonly class UserController
{
    public function __construct(private Names $names)
    {
        ++$names->made;
    }

    #[ListOf(User::class)]
    public function index(int $page = 1): array
    {
        return [$this->show($page)];
    }

    public function show(int $id): User
    {
        return new User($id, "{$id}@example.com", $this->names->prefix() . $id, Plan::Free);
    }

    public function store(CreateUser $input): User|Response
    {
        return Response::created(new User(9, $input->email, $input->name, $input->plan), '/users/9');
    }

    private function secret(): string
    {
        return '';
    }
}

final class PlainController
{
    public function hello(): string
    {
        return 'hello';
    }
}

final class TypedHandlerTest extends TestCase
{
    public function testAHandlerReceivesWhatItsSignatureAsksFor(): void
    {
        $router = new Router();
        $router->post('/users', static fn (CreateUser $input): User => new User(1, $input->email, $input->name, $input->plan));
        $router->get('/users', static fn (ListUsers $query): array => ['page' => $query->page, 'search' => $query->search]);
        $router->get('/users/{id}/posts/{slug}', static fn (string $slug, int $id): array => [$id, $slug]);
        $router->put('/users/{id}', static fn (int $id, CreateUser $input, ServerRequestInterface $request): array => [$id, $input->name, $request->getMethod()]);
        $router->get('/ratio/{value}/{exact}', static fn (float $value, bool $exact): array => [$value, $exact]);
        $router->get('/raw/{name}', static fn ($request, $name): array => [$name, $request->getAttribute('name')]);
        $router->delete('/users/{id}', static function (int $id, RequestContract $request): void {
        });
        $router->get('/text', static fn (): string => 'plain');
        $router->get('/made', static fn (): ResponseInterface => Response::json(['made' => true], 201));
        $router->get('/number', static fn (): int => 7);

        $created = $router->handle($this->json('POST', '/users', ['email' => 'ada@example.com', 'name' => 'Ada', 'plan' => 'paid']));
        self::assertSame([200, 'application/json', '{"id":1,"email":"ada@example.com","name":"Ada","plan":"paid"}'],
            [$created->getStatusCode(), $created->getHeaderLine('Content-Type'), (string) $created->getBody()]);
        self::assertSame('{"page":1,"search":null}', $this->body($router, new Request('GET', '/users')));
        self::assertSame('{"page":3,"search":"ada"}', $this->body($router, new Request('GET', '/users?page=3&search=ada&unknown=1')));
        self::assertSame('[42,"hello world"]', $this->body($router, new Request('GET', '/users/42/posts/hello%20world')));
        self::assertSame('[7,"Grace","PUT"]', $this->body($router, $this->json('PUT', '/users/7', ['email' => 'g@example.com', 'name' => 'Grace'])));
        self::assertSame('[2.5,true]', $this->body($router, new Request('GET', '/ratio/2.5/1')));
        self::assertSame('["x","x"]', $this->body($router, new Request('GET', '/raw/x')));
        $deleted = $router->handle(new Request('DELETE', '/users/9'));
        self::assertSame([204, ''], [$deleted->getStatusCode(), (string) $deleted->getBody()]);
        $text = $router->handle(new Request('GET', '/text'));
        self::assertSame(['text/plain; charset=utf-8', 'plain'], [$text->getHeaderLine('Content-Type'), (string) $text->getBody()]);
        self::assertSame(201, $router->handle(new Request('GET', '/made'))->getStatusCode());
        self::assertSame('7', $this->body($router, new Request('GET', '/number')));
    }

    public function testDataThatDoesNotFitIsAnsweredWith422AndTheHandlerIsNotCalled(): void
    {
        $calls = 0;
        $router = new Router();
        $router->put('/users/{id}', static function (int $id, CreateUser $input) use (&$calls): array {
            ++$calls;
            return [];
        });
        $router->get('/users', static function (ListUsers $query) use (&$calls): array {
            ++$calls;
            return [];
        });

        $response = $router->handle($this->json('PUT', '/users/abc', ['email' => 'nope', 'plan' => 'gold']));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'error' => [
                'status' => 422,
                'message' => 'The request is invalid.',
                'fields' => [
                    'id' => ['must be a whole number'],
                    'email' => ['must be an email address'],
                    'name' => ['is required'],
                    'plan' => ['must be one of: free, paid'],
                ],
            ],
        ], json_decode((string) $response->getBody(), true));

        $query = $router->handle(new Request('GET', '/users?page=0', ['Accept' => 'application/json']));
        self::assertSame(['page' => ['must be at least 1']], json_decode((string) $query->getBody(), true)['error']['fields']);
        $plain = $router->handle(new Request('GET', '/users?page=first'));
        self::assertSame([422, "422 The request is invalid.\npage must be a whole number\n"], [$plain->getStatusCode(), (string) $plain->getBody()]);

        foreach (['', '"text"', '[1,2]x'] as $body) {
            $request = new Request('PUT', '/users/1', ['Content-Type' => 'application/json', 'Accept' => 'application/json'], $body);
            try {
                $status = $router->handle($request)->getStatusCode();
            } catch (\Crab\Http\HttpException $malformed) {
                $status = $malformed->status;
            }
            self::assertContains($status, [400, 422], "A body of {$body} was accepted.");
        }
        $noBody = $router->handle(new Request('PUT', '/users/1', ['Accept' => 'application/json']));
        self::assertSame(['email' => ['is required'], 'name' => ['is required']], json_decode((string) $noBody->getBody(), true)['error']['fields'], 'With no body, every field is missing.');
        self::assertSame(0, $calls);
    }

    public function testAHandlerClassIsMadeByTheContainerOnceAndCalledByItsSignature(): void
    {
        $container = new Container();
        $router = new Router($container);
        $router->get('/users/{id}', ShowUser::class);
        $names = $container->get(Names::class);
        self::assertSame(0, $names->made, 'Adding the route does not make the handler.');

        self::assertSame('{"id":5,"email":"5@example.com","name":"user-5","plan":"paid"}', $this->body($router, new Request('GET', '/users/5')));
        self::assertSame('{"id":6,"email":"6@example.com","name":"user-6","plan":"paid"}', $this->body($router, new Request('GET', '/users/6')));
        self::assertSame(1, $names->made);
        self::assertSame(422, $router->handle(new Request('GET', '/users/x'))->getStatusCode());

        $route = $router->routes()[0];
        self::assertSame(['GET', '/users/{id}', ShowUser::class, ['id' => 'int'], null, null, User::class, false],
            [$route->method, $route->pattern, $route->handler, $route->parameters, $route->input, $route->inputFrom, $route->output, $route->outputList]);
    }

    public function testDatesInADeclaredResponseAreWrittenAsText(): void
    {
        $at = new \DateTimeImmutable('2026-01-31 12:00:00', new \DateTimeZone('UTC'));
        $router = new Router();
        $router->get('/event', static fn (): Event => new Event('Launch', $at, [$at->modify('-1 day')]));
        $router->get('/events', #[ListOf(Event::class)] static fn (): array => [new Event('Launch', $at)]);
        $router->post('/events', static fn (Event $input): Event => $input);

        self::assertSame('{"title":"Launch","at":"2026-01-31T12:00:00+00:00","reminders":["2026-01-30T12:00:00+00:00"]}', $this->body($router, new Request('GET', '/event')));
        self::assertSame('[{"title":"Launch","at":"2026-01-31T12:00:00+00:00","reminders":[]}]', $this->body($router, new Request('GET', '/events')));
        self::assertSame('{"title":"Talk","at":"2026-02-01T09:30:00+00:00","reminders":[]}',
            $this->body($router, $this->json('POST', '/events', ['title' => 'Talk', 'at' => '2026-02-01T09:30:00Z'])));
    }

    public function testTheRequestsDataCanBeTakenAsSeparateParameters(): void
    {
        $router = new Router();
        $router->get('/users', static fn (int $page = 1, ?string $search = null, Plan $plan = Plan::Free, bool $active = true): array => [$page, $search, $plan, $active]);
        $router->post('/users', static fn (#[Email] string $email, #[Length(min: 2)] string $name, ?int $age = null): array => [$email, $name, $age]);
        $router->patch('/users/{id}', static fn (int $id, ServerRequestInterface $request, ?string $name = null, #[ListOf('string')] array $tags = [], ?\DateTimeImmutable $seen = null): array
            => [$id, $name, $tags, $seen?->format('Y'), $request->getMethod()]);

        self::assertSame('[1,null,"free",true]', $this->body($router, new Request('GET', '/users')));
        self::assertSame('[3,"ada","paid",false]', $this->body($router, new Request('GET', '/users?page=3&search=ada&plan=paid&active=0&unknown=x')));
        self::assertSame('["ada@example.com","Ada",null]', $this->body($router, $this->json('POST', '/users', ['email' => 'ada@example.com', 'name' => 'Ada', 'is_admin' => true])));
        self::assertSame('["ada@example.com","Ada",36]', $this->body($router, $this->json('POST', '/users', ['age' => 36, 'name' => 'Ada', 'email' => 'ada@example.com'])));
        self::assertSame('[7,null,[],null,"PATCH"]', $this->body($router, $this->json('PATCH', '/users/7', [])));
        self::assertSame('[7,"Ada",["a","b"],"2026","PATCH"]', $this->body($router, $this->json('PATCH', '/users/7', ['name' => 'Ada', 'tags' => ['a', 'b'], 'seen' => '2026-01-31T12:00:00Z'])));

        $invalid = $router->handle($this->json('POST', '/users', ['email' => 'nope', 'age' => '36']));
        self::assertSame(
            ['email' => ['must be an email address'], 'name' => ['is required'], 'age' => ['must be a whole number']],
            json_decode((string) $invalid->getBody(), true)['error']['fields'],
        );
        $invalid = $router->handle($this->json('PATCH', '/users/x', ['tags' => ['a', 1]]));
        self::assertSame(['id' => ['must be a whole number'], 'tags.1' => ['must be text']], json_decode((string) $invalid->getBody(), true)['error']['fields']);
        self::assertSame(422, $router->handle(new Request('GET', '/users?page=first'))->getStatusCode());

        $routes = $router->routes();
        self::assertSame([null, 'query', ['type' => 'integer', 'default' => 1]], [$routes[0]->input, $routes[0]->inputFrom, $routes[0]->fields['properties']['page']]);
        self::assertSame(['body', ['email', 'name']], [$routes[1]->inputFrom, $routes[1]->fields['required']]);
        self::assertSame([['id' => 'int'], ['name', 'tags', 'seen']], [$routes[2]->parameters, array_keys($routes[2]->fields['properties'])]);

        $document = (new OpenApi($router, new Validator()))->document('Shop', '1');
        self::assertSame(['page', 'search', 'plan', 'active'], array_column($document['paths']['/users']['get']['parameters'], 'name'));
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'email' => ['type' => 'string', 'format' => 'email'],
                'name' => ['type' => 'string', 'minLength' => 2],
                'age' => ['type' => ['integer', 'null'], 'default' => null],
            ],
            'required' => ['email', 'name'],
        ], $document['paths']['/users']['post']['requestBody']['content']['application/json']['schema']);
        self::assertSame(['ValidationError'], array_keys($document['components']['schemas']), 'Loose fields belong to their route and are not listed as a class.');
    }

    public function testAMethodOfAClassHandlesARoute(): void
    {
        $container = new Container();
        $router = new Router($container);
        $router->get('/users', [UserController::class, 'index']);
        $router->get('/users/{id}', [UserController::class, 'show']);
        $router->post('/users', [UserController::class, 'store']);
        $names = $container->get(Names::class);
        self::assertSame(0, $names->made, 'Adding the routes does not make the controller.');

        self::assertSame('[{"id":2,"email":"2@example.com","name":"user-2","plan":"free"}]', $this->body($router, new Request('GET', '/users?page=2')));
        self::assertSame('{"id":5,"email":"5@example.com","name":"user-5","plan":"free"}', $this->body($router, new Request('GET', '/users/5')));
        $created = $router->handle($this->json('POST', '/users', ['email' => 'ada@example.com', 'name' => 'Ada']));
        self::assertSame([201, '/users/9', '{"id":9,"email":"ada@example.com","name":"Ada","plan":"free"}'],
            [$created->getStatusCode(), $created->getHeaderLine('Location'), (string) $created->getBody()]);
        self::assertSame(422, $router->handle($this->json('POST', '/users', ['email' => 'x']))->getStatusCode());
        self::assertSame(1, $names->made, 'One controller serves all its routes.');

        $routes = $router->routes();
        self::assertSame([UserController::class . '::index', UserController::class . '::show', UserController::class . '::store'], array_column($routes, 'handler'));
        self::assertSame([User::class, true], [$routes[0]->output, $routes[0]->outputList]);
        self::assertSame([User::class, CreateUser::class], [$routes[2]->output, $routes[2]->input], 'A "User|Response" return is described as a User.');
        $paths = (new OpenApi($router, new Validator()))->document('Shop', '1')['paths'];
        self::assertSame(['$ref' => '#/components/schemas/User'], $paths['/users']['post']['responses']['200']['content']['application/json']['schema']);

        $standalone = new Router();
        $standalone->get('/plain', [PlainController::class, 'hello']);
        self::assertSame('hello', (string) $standalone->handle(new Request('GET', '/plain'))->getBody(), 'Without a container, a class that needs nothing is simply made.');
    }

    public function testAHandlerReturnsAResponseToControlTheStatusHeadersOrBody(): void
    {
        $at = new \DateTimeImmutable('2026-01-31 12:00:00', new \DateTimeZone('UTC'));
        $file = sys_get_temp_dir() . '/crab-download-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($file, str_repeat('0123456789', 10000));
        $router = new Router();
        $router->post('/events', static fn (string $title): Response => Response::created(new Event($title, $at), '/events/1'));
        $router->post('/empty', static fn (): Response => Response::created());
        $router->delete('/events/{id}', static fn (int $id): Response => Response::noContent());
        $router->get('/old', static fn (): Response => Response::redirect('/new'));
        $router->post('/login', static fn (): Response => Response::redirect('/home', 303));
        $router->get('/file', static fn (): Response => Response::download($file, 'réport "final".txt', 'text/plain'));
        $router->get('/cached', static fn (): ResponseInterface => Response::json(['a' => 1])->withHeader('Cache-Control', 'max-age=60'));

        $created = $router->handle($this->json('POST', '/events', ['title' => 'Launch']));
        self::assertSame([201, '/events/1', 'application/json', '{"title":"Launch","at":"2026-01-31T12:00:00+00:00","reminders":[]}'],
            [$created->getStatusCode(), $created->getHeaderLine('Location'), $created->getHeaderLine('Content-Type'), (string) $created->getBody()]);
        $empty = $router->handle(new Request('POST', '/empty'));
        self::assertSame([201, '', false], [$empty->getStatusCode(), (string) $empty->getBody(), $empty->hasHeader('Location')]);
        $deleted = $router->handle(new Request('DELETE', '/events/1'));
        self::assertSame([204, ''], [$deleted->getStatusCode(), (string) $deleted->getBody()]);
        $moved = $router->handle(new Request('GET', '/old'));
        self::assertSame([302, '/new'], [$moved->getStatusCode(), $moved->getHeaderLine('Location')]);
        self::assertSame(303, $router->handle(new Request('POST', '/login'))->getStatusCode());
        $download = $router->handle(new Request('GET', '/file'));
        self::assertSame([200, 'text/plain', "attachment; filename=\"r__port _final_.txt\"; filename*=UTF-8''r%C3%A9port%20%22final%22.txt", 100000],
            [$download->getStatusCode(), $download->getHeaderLine('Content-Type'), $download->getHeaderLine('Content-Disposition'), $download->getBody()->getSize()]);
        self::assertSame(str_repeat('0123456789', 10000), (string) $download->getBody());
        self::assertNull($download->getBody() instanceof \Crab\Http\Stream ? $download->getBody()->text() : '', 'A file is read as it is sent, not held as a string.');
        self::assertSame('max-age=60', $router->handle(new Request('GET', '/cached'))->getHeaderLine('Cache-Control'));
        unlink($file);

        self::assertSame('attachment; filename="notes.txt"; filename*=UTF-8\'\'notes.txt', Response::download(__FILE__, 'notes.txt')->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('filename="TypedHandlerTest.php"', Response::download(__FILE__)->getHeaderLine('Content-Disposition'));
        self::assertSame('application/octet-stream', Response::download(__FILE__)->getHeaderLine('Content-Type'));
        $refused = 0;
        foreach ([static fn () => Response::redirect('/x', 200), static fn () => Response::download('/no/such/file'), static fn () => Response::download(__DIR__)] as $mistake) {
            try {
                $mistake();
            } catch (\InvalidArgumentException|\RuntimeException) {
                ++$refused;
            }
        }
        self::assertSame(3, $refused);
    }

    public function testASignatureTheRouterCannotCallStopsTheRouteFromBeingAdded(): void
    {
        $router = new Router();
        $refused = [
            'takes Crab\Contracts\Validation\Validator $validator, which is neither the request, the user, a parameter of the pattern, a field nor an input class' => static fn () => $router->get('/a', static fn (ValidatorContract $validator) => ''),
            'Services belong in the constructor of a handler class' => static fn () => $router->get('/a', static fn (ValidatorContract $validator) => ''),
            "cannot take the request's data: Crab\\Database\\Database::\$connect is declared as Closure" => static fn () => $router->get('/a', static fn (\Crab\Database\Database $db) => ''),
            'If it is a service, it belongs in the constructor of a handler class' => static fn () => $router->get('/a', static fn (\Crab\Database\Database $db) => ''),
            'takes the route parameter $id as array; it can be string, int, float or bool' => static fn () => $router->get('/a/{id}', static fn (array $id) => ''),
            'takes two inputs' => static fn () => $router->post('/a', static fn (CreateUser $a, ListUsers $b) => ''),
            'takes the input Crab\Tests\Routing\CreateUser and also the field $force; put every field in the class, or take them all as parameters' => static fn () => $router->post('/a', static fn (CreateUser $input, bool $force = false) => ''),
            'takes $id, which must have one plain type' => static fn () => $router->get('/a/{id}', static fn (int|string $id) => ''),
            'takes $rest, which must have one plain type' => static fn () => $router->get('/a', static fn (string ...$rest) => ''),
            'has #[ListOf] but does not declare that it returns an array' => static fn () => $router->get('/a', #[ListOf(User::class)] static fn () => []),
            'Ambiguous::$id has a union or intersection type' => static fn () => $router->post('/a', static fn (\Crab\Tests\Routing\Ambiguous $input) => ''),
            'A closure takes $tags, which has #[ListOf] but is not declared as an array' => static fn () => $router->post('/a', static fn (#[ListOf('int')] string $tags) => ''),
            "is an array, which must be a class and the name of its method: [UserController::class, 'show']" => static fn () => $router->get('/a', [UserController::class]),
            'names the class App\Missing, which does not exist' => static fn () => $router->get('/a', ['App\Missing', 'show']),
            'UserController::missing(), which is not a public method' => static fn () => $router->get('/a', [UserController::class, 'missing']),
            'UserController::secret(), which is not a public method' => static fn () => $router->get('/a', [UserController::class, 'secret']),
        ];
        foreach ($refused as $expected => $attempt) {
            try {
                $attempt();
                self::fail("Not refused: {$expected}");
            } catch (\LogicException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
                self::assertStringContainsString(' /a', $error->getMessage() . ' /a', 'The route is named.');
            }
        }
        self::assertSame([], $router->routes(), 'A refused route is not half added.');
    }

    public function testTheOpenApiDocumentIsWrittenFromTheSignatures(): void
    {
        $router = new Router(new Container());
        $router->post('/users', static fn (CreateUser $input): User => new User(1, $input->email, $input->name, $input->plan));
        $router->get('/users', #[ListOf(User::class)] static fn (ListUsers $query): array => []);
        $router->get('/users/{id:\d{1,9}}', ShowUser::class);
        $router->delete('/users/{id}', static function (int $id): void {
        });
        $router->get('/health', static fn (): string => 'ok');
        $router->get('/tags', #[ListOf('string')] static fn (): array => []);
        $router->get('/count', static fn (): int => 1);
        $router->get('/page', static fn (): Page => new Page([], 15, 1, false));
        $router->get('/anything', static fn () => Response::text(''));
        $router->get('/files/{name}', static fn (string $name): ResponseInterface => Response::text(''));
        $router->get('/psr', new \Crab\Http\ClosureHandler(static fn () => ''));

        $document = (new OpenApi($router, new Validator()))->document('Shop', '2.1.0');
        self::assertSame(['3.1.0', ['title' => 'Shop', 'version' => '2.1.0']], [$document['openapi'], $document['info']]);
        self::assertSame(['/users', '/users/{id}', '/health', '/tags', '/count', '/page', '/anything', '/files/{name}', '/psr'], array_keys($document['paths']));
        self::assertSame(['post', 'get'], array_keys($document['paths']['/users']));
        self::assertSame(['get', 'delete'], array_keys($document['paths']['/users/{id}']));

        $invalid = ['description' => 'The request is invalid.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationError']]]];
        $user = ['$ref' => '#/components/schemas/User'];
        self::assertSame([
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CreateUser']]]],
            'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => $user]]], '422' => $invalid],
        ], $document['paths']['/users']['post']);
        self::assertSame([
            'parameters' => [
                ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                ['name' => 'search', 'in' => 'query', 'required' => false, 'schema' => ['type' => ['string', 'null'], 'default' => null]],
            ],
            'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'array', 'items' => $user]]]], '422' => $invalid],
        ], $document['paths']['/users']['get']);
        self::assertSame([
            'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
            'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => $user]]], '422' => $invalid],
        ], $document['paths']['/users/{id}']['get']);
        self::assertSame(['204' => ['description' => 'No Content'], '422' => $invalid], $document['paths']['/users/{id}']['delete']['responses']);
        self::assertSame(['200' => ['description' => 'OK', 'content' => ['text/plain' => ['schema' => ['type' => 'string']]]]], $document['paths']['/health']['get']['responses']);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string']], $document['paths']['/tags']['get']['responses']['200']['content']['application/json']['schema']);
        self::assertSame(['type' => 'integer'], $document['paths']['/count']['get']['responses']['200']['content']['application/json']['schema']);
        foreach (['/page', '/anything', '/psr'] as $undescribed) {
            self::assertSame(['responses' => ['200' => ['description' => 'OK']]], $document['paths'][$undescribed]['get']);
        }
        self::assertSame([
            'parameters' => [['name' => 'name', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
            'responses' => ['200' => ['description' => 'OK']],
        ], $document['paths']['/files/{name}']['get']);

        self::assertSame(['CreateUser', 'User', 'ValidationError'], array_keys($document['components']['schemas']));
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'email' => ['type' => 'string', 'format' => 'email'],
                'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 50],
                'plan' => ['type' => 'string', 'enum' => ['free', 'paid']],
            ],
            'required' => ['email', 'name'],
        ], $document['components']['schemas']['CreateUser']);
        self::assertSame(['id', 'email', 'name', 'plan'], $document['components']['schemas']['User']['required']);

        $other = new Router();
        $other->post('/a', static fn (CreateUser $input): Event => new Event('a', new \DateTimeImmutable()));
        $other->post('/b', static fn (\Crab\Tests\Routing\Other\CreateUser $input): array => []);
        $names = array_keys((new OpenApi($other, new Validator()))->document('A', '1')['components']['schemas']);
        self::assertSame(['Crab.Tests.Routing.Other.CreateUser', 'CreateUser', 'Event', 'ValidationError'], $names, 'Two classes with one short name are both listed.');
        self::assertSame('{"openapi":"3.1.0","info":{"title":"Empty","version":"1"},"paths":{}}', json_encode((new OpenApi(new Router(), new Validator()))->document('Empty', '1')));
    }

    public function testTheApplicationBindsTheValidatorAndPrintsTheDocument(): void
    {
        $app = new App(new Config(['app' => ['name' => 'Shop', 'version' => '3.0.0']]));
        self::assertInstanceOf(Validator::class, $app->container->get(ValidatorContract::class));
        self::assertSame($app->container->get(ValidatorContract::class), $app->container->get(Validator::class));
        $app->router->post('/users', static fn (CreateUser $input): User => new User(1, $input->email, $input->name, $input->plan));

        $response = $app->handle($this->json('POST', '/users', ['email' => 'x']));
        self::assertSame(422, $response->getStatusCode());

        $out = fopen('php://memory', 'w+');
        self::assertSame(0, $app->console->run(['openapi'], new \Crab\Console\Output($out, $out)));
        rewind($out);
        $document = json_decode(stream_get_contents($out), true);
        self::assertSame(['Shop', '3.0.0'], [$document['info']['title'], $document['info']['version']]);
        self::assertSame('#/components/schemas/CreateUser', $document['paths']['/users']['post']['requestBody']['content']['application/json']['schema']['$ref']);

        $file = sys_get_temp_dir() . '/crab-openapi-' . bin2hex(random_bytes(4)) . '.json';
        self::assertSame(0, $app->console->run(['openapi', "--output={$file}"], new \Crab\Console\Output($out, $out)));
        self::assertSame($document, json_decode((string) file_get_contents($file), true));
        unlink($file);
        self::assertSame(1, $app->console->run(['openapi', '--output=/no/such/folder/openapi.json'], new \Crab\Console\Output($out, $out)));
    }

    /**
     * @param array<mixed> $data
     */
    private function json(string $method, string $path, array $data): Request
    {
        return new Request($method, $path, ['Content-Type' => 'application/json', 'Accept' => 'application/json'], json_encode($data));
    }

    private function body(Router $router, Request $request): string
    {
        $response = $router->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return (string) $response->getBody();
    }
}

final class Ambiguous
{
    public function __construct(public int|string $id)
    {
    }
}

namespace Crab\Tests\Routing\Other;

final readonly class CreateUser
{
    public function __construct(public string $handle)
    {
    }
}
