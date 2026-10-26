<?php

declare(strict_types=1);

namespace Crab\Tests\Routing;

use Crab\App;
use Crab\Config\Config;
use Crab\Container\Container;
use Crab\Contracts\Http\Request as RequestContract;
use Crab\Contracts\Validation\Validator as ValidatorContract;
use Crab\Http\Request;
use Crab\Http\Response;
use Crab\Routing\FromQuery;
use Crab\Routing\Router;
use Crab\Validation\Data;
use Crab\Validation\Email;
use Crab\Validation\Fields;
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
        #[Email]
        public string $email,
        #[Length(min: 2, max: 50)]
        public string $name,
        public Plan $plan = Plan::Free,
    ) {}
}

final readonly class ListUsers
{
    public function __construct(
        #[Range(min: 1)]
        public int $page = 1,
        public ?string $search = null,
    ) {}
}

/**
 * A list of described objects is a field like any other, beside other fields.
 */
final readonly class Basket
{
    public function __construct(
        public string $name,
        #[ListOf(['productId' => 'int', 'quantity?' => 'int']), Length(min: 1)]
        public array $lines,
    ) {}
}

final readonly class User
{
    public function __construct(
        public int $id,
        public string $email,
        public string $name,
        public Plan $plan,
    ) {}
}

final readonly class Event
{
    /**
     * @param list<\DateTimeImmutable> $reminders
     */
    public function __construct(
        public string $title,
        public \DateTimeImmutable $at,
        #[ListOf(\DateTimeImmutable::class)]
        public array $reminders = [],
    ) {}
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
 * An action class: services in the constructor, the request's data in handle().
 */
final readonly class ShowUser
{
    public function __construct(
        private Names $names,
    ) {
        ++$names->made;
    }

    public function handle(int $id): User
    {
        return new User($id, "{$id}@example.com", $this->names->prefix() . $id, Plan::Paid);
    }
}

/**
 * Several routes in one class, each a method.
 */
final readonly class UserController
{
    public function __construct(
        private Names $names,
    ) {
        ++$names->made;
    }

    public function index(#[FromQuery] int $page = 1): array
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

/**
 * Deliberately untyped action parameters expose the registration error
 * for both a class action and a named method.
 */
final class UntypedRouteAction
{
    /**
     * The missing type must be caught before this action can run.
     */
    public function handle($id): string
    {
        return (string) $id;
    }

    /**
     * A default does not replace the parameter's required type.
     */
    public function show($id = 'fallback'): string
    {
        return (string) $id;
    }
}

/**
 * A malformed input whose constructor must never run during registration.
 */
final readonly class UntypedInput
{
    /**
     * The field has no type, so inspection must refuse it first.
     */
    public function __construct($email = null)
    {
        throw new \LogicException('The input was constructed before its signature was checked.');
    }
}

final class ActionHandlerTest extends TestCase
{
    /**
     * Class actions, named methods and closures all declare their inputs
     * when the route is registered, including parameters with defaults.
     */
    public function testAnActionParameterWithoutATypeIsRefusedWhenTheRouteIsAdded(): void
    {
        $router = new Router();
        $actions = [
            [UntypedRouteAction::class, UntypedRouteAction::class, 'id'],
            [[UntypedRouteAction::class, 'show'], UntypedRouteAction::class . '::show', 'id'],
            [static fn($id): string => (string) $id, 'Closure', 'id'],
            [static fn($id = null): string => (string) $id, 'Closure', 'id'],
            [static fn($email): string => (string) $email, 'Closure', 'email'],
            [static fn(#[Fields(['email' => 'string'])] $input): array => [], 'Closure', 'input'],
        ];
        foreach ($actions as [$action, $name, $parameter]) {
            try {
                $router->get('/untyped/{id}', $action);
                self::fail('An action parameter without a type was accepted.');
            } catch (\LogicException $error) {
                self::assertStringContainsString("The action {$name} of GET /untyped/{id}", $error->getMessage());
                self::assertStringContainsString("takes \${$parameter} with no type", $error->getMessage());
                self::assertStringContainsString("string \${$parameter}", $error->getMessage());
            }
            self::assertSame([], $router->routes(), 'The refusal happens before a route or request exists.');
        }
    }

    /**
     * Input classes are inspected at registration, including ones nested
     * in described fields or lists, rather than on the first request.
     */
    public function testAnInputFieldWithoutATypeIsRefusedWhenTheRouteIsAdded(): void
    {
        $router = new Router();
        $actions = [
            static fn(UntypedInput $input): array => [],
            static fn(#[FromQuery, ListOf(UntypedInput::class)] array $inputs): array => [],
            static fn(#[Fields(['customer' => UntypedInput::class])] Data $input): array => [],
            static fn(#[Fields(['customers' => [[UntypedInput::class]]])] Data $input): array => [],
        ];
        foreach ($actions as $action) {
            try {
                $router->post('/untyped-input', $action);
                self::fail('An input field without a type was accepted.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('POST /untyped-input', $error->getMessage());
                self::assertStringContainsString(UntypedInput::class . '::$email has no type', $error->getMessage());
                self::assertStringContainsString('string $email', $error->getMessage());
            }
            self::assertSame([], $router->routes(), 'No request is needed to find the malformed input.');
        }
    }

    /**
     * Descriptions need a type for every field just as constructors do,
     * including fields of a nested object or list.
     */
    public function testADescribedFieldWithoutATypeIsRefusedWhenTheRouteIsAdded(): void
    {
        $router = new Router();
        $actions = [
            [static fn(#[Fields(['email' => []])] Data $input): array => [], 'email'],
            [static fn(#[Fields(['customer' => ['email' => []]])] Data $input): array => [], 'email'],
            [static fn(#[Fields(['customers' => [['email' => []]]])] Data $input): array => [], 'email'],
        ];
        foreach ($actions as [$action, $field]) {
            try {
                $router->post('/untyped-description', $action);
                self::fail('A described field without a type was accepted.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('POST /untyped-description', $error->getMessage());
                self::assertStringContainsString(
                    "describes \"{$field}\", which must be a type such as 'int'",
                    $error->getMessage(),
                );
            }
            self::assertSame([], $router->routes());
        }
    }

    public function testTheFieldsOfARequestCanBeDescribedWhereTheyAreUsedInPlaceOfAClass(): void
    {
        $router = new Router();
        $router->post('/shops/{shop}/big', static fn(
            int $shop,
            #[Fields([
                'email' => ['string', new Email()],
                'plan' => Plan::class,
                'note?' => 'string',
                'address?' => ['street' => 'string', 'country' => ['string', new Length(min: 2, max: 2)]],
                'lines' => [
                    ['productId' => 'int', 'quantity' => ['int', new Range(min: 1, max: 20)]],
                    new Length(min: 1),
                ],
                'tags?' => [['string']],
            ])]
            Data $order,
        ): array => [
            'shop' => $shop,
            'email' => $order->email,
            'plan' => $order->plan,
            'note' => $order->note,
            'country' => $order->address?->country,
            'second' => $order->lines[1]->productId * $order->lines[1]->quantity,
            'tags' => $order->tags,
            'all' => $order,
        ]);
        $router->add('POST', '/shops/{shop}/orders', static fn(
            int $shop,
            #[Fields(['id' => 'int'])]
            Data $order,
        ): array => [$shop, $order->id]);
        $router->post('/baskets', static fn(Basket $basket): array => [
            $basket->name,
            array_map(static fn(Data $line): array => [$line->productId, $line->quantity], $basket->lines),
        ]);
        $router->get('/search', static fn(
            #[FromQuery, Fields(['q' => 'string', 'page?' => 'int'])]
            Data $query,
        ): array => [
            $query->q,
            $query->page,
        ]);

        $whole = [
            'email' => 'ada@example.com',
            'plan' => 'paid',
            'note' => 'ring twice',
            'address' => ['street' => '1 Main St', 'country' => 'GB'],
            'lines' => [['productId' => 5, 'quantity' => 2], ['productId' => 7, 'quantity' => 3]],
            'tags' => ['gift'],
            'unknown' => 'ignored',
        ];
        $answer = json_decode($this->body($router, $this->json('POST', '/shops/4/big', $whole)), true);
        self::assertSame([4, 'ada@example.com', 'paid', 'ring twice', 'GB', 21, ['gift']], [
            $answer['shop'],
            $answer['email'],
            $answer['plan'],
            $answer['note'],
            $answer['country'],
            $answer['second'],
            $answer['tags'],
        ]);
        // What was not described is not taken; an enum is the enum, and returned as its value.
        self::assertSame(['email', 'plan', 'note', 'address', 'lines', 'tags'], array_keys($answer['all']));
        // Left out, what may be is null.
        $less = json_decode($this->body($router, $this->json('POST', '/shops/4/big', [
            'email' => 'a@b.co',
            'plan' => 'free',
            'lines' => [['productId' => 1, 'quantity' => 1], ['productId' => 2, 'quantity' => 2]],
        ])), true);
        self::assertSame([null, null, null], [$less['note'], $less['country'], $less['tags']]);

        self::assertSame('[3,9]', $this->body($router, $this->json('POST', '/shops/3/orders', ['id' => 9])));
        self::assertSame('["weekly",[[5,2],[7,null]]]', $this->body($router, $this->json('POST', '/baskets', [
            'name' => 'weekly',
            'lines' => [['productId' => 5, 'quantity' => 2], ['productId' => 7]],
        ])));
        // A query string is text, and is read as the types described.
        self::assertSame('["mug",3]', $this->body($router, new Request('GET', '/search?q=mug&page=3')));
        self::assertSame('["mug",null]', $this->body($router, new Request('GET', '/search?q=mug')));

        // Every problem at once, each by the field's own path.
        $bad = $router->handle($this->json('POST', '/shops/3/orders', []));
        self::assertSame(['id' => ['is required']], json_decode((string) $bad->getBody(), true)['error']['fields']);
        $router->post(
            '/checkout',
            static fn(
                #[Fields([
                    'email' => ['string', new Email()],
                    'address?' => ['street' => 'string', 'country' => ['string', new Length(min: 2, max: 2)]],
                    'lines' => [
                        ['productId' => 'int', 'quantity' => ['int', new Range(min: 1, max: 20)]],
                        new Length(min: 1),
                    ],
                ])]
                Data $order,
            ): Data => $order,
        );
        $bad = $router->handle($this->json('POST', '/checkout', [
            'email' => 'nope',
            'address' => ['street' => 5, 'country' => 'GBR'],
            'lines' => [['productId' => 5, 'quantity' => 99], ['quantity' => 1], 'x'],
        ]));
        self::assertSame(422, $bad->getStatusCode());
        self::assertSame(
            [
                'email' => ['must be an email address'],
                'address.street' => ['must be text'],
                'address.country' => ['must be at most 2 character(s)'],
                'lines.0.quantity' => ['must be at most 20'],
                'lines.1.productId' => ['is required'],
                'lines.2' => ['must be an object'],
            ],
            json_decode((string) $bad->getBody(), true)['error']['fields'],
        );
        self::assertSame(
            ['lines' => ['must have at least 1 item(s)']],
            json_decode((string) $router->handle($this->json('POST', '/checkout', [
                'email' => 'a@b.co',
                'lines' => [],
            ]))->getBody(), true)['error']['fields'],
        );

        // Returned, it is its fields; what was left out is null, and what was not described is not there.
        $good = ['email' => 'ada@example.com', 'lines' => [['productId' => 5, 'quantity' => 2]], 'extra' => true];
        self::assertSame('{"email":"ada@example.com","lines":[{"productId":5,"quantity":2}]}', $this->body($router, $this->json(
            'POST',
            '/checkout',
            $good,
        )));

        // An update takes what was sent and nothing else: a field left out is not a field sent as null.
        $router->patch('/users/{id}', static fn(
            int $id,
            #[Fields(['name?' => 'string', 'bio?' => '?string', 'age?' => 'int'])]
            Data $changes,
        ): array => [
            'sent' => $changes->toArray(),
            'has' => [$changes->has('name'), $changes->has('bio'), $changes->has('age')],
            'reads' => [$changes->name, $changes->bio, $changes->age],
        ]);
        self::assertSame('{"sent":{"name":"Ada","bio":null},"has":[true,true,false],"reads":["Ada",null,null]}', $this->body($router, $this->json(
            'PATCH',
            '/users/7',
            ['name' => 'Ada', 'bio' => null],
        )));
        // A name may be left out but not cleared; a bio may be cleared.
        self::assertSame(
            ['name' => ['must not be null']],
            json_decode((string) $router->handle($this->json('PATCH', '/users/7', [
                'name' => null,
            ]))->getBody(), true)['error']['fields'],
        );

        // A form carries everything as text, as a query string does, and is read as the types described.
        $router->post('/form', static fn(#[Fields(['quantity' => 'int', 'gift?' => 'bool'])] Data $form): array => [
            $form->quantity,
            $form->gift ?? false,
        ]);
        $form = new Request(
            'POST',
            '/form',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'quantity=2&gift=true',
        );
        self::assertSame('[2,true]', $this->body($router, $form));
        // JSON says what each value is, and "2" there is text.
        self::assertSame(422, $router->handle($this->json('POST', '/form', ['quantity' => '2']))->getStatusCode());

        $data = new Data(['id' => 7, 'inner' => new Data(['ok' => true]), 'list' => [new Data(['n' => 1])]]);
        self::assertSame(['id' => 7, 'inner' => ['ok' => true], 'list' => [['n' => 1]]], $data->toArray());
        self::assertSame([true, false], [isset($data->id), isset($data->missing)]);
        try {
            $data->missing;
            self::fail('A field that was not described was read.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('has no field "missing"; it has id, inner, list', $error->getMessage());
        }

        // A description that cannot be is refused when the route is added.
        $mistakes = [
            'has no #[Fields] to say what its fields are' => static fn(Data $order) => '',
            'has #[Fields] but is not declared as' => static fn(#[Fields(['id' => 'int'])] array $order) => '',
            'must name each field with its description' => static fn(#[Fields(['int'])] Data $order) => '',
            'describes "id", which is declared as integer' => static fn(
                #[Fields(['id' => 'integer'])]
                Data $order,
            ) => '',
            'where only rules such as' => static fn(#[Fields(['id' => ['int', 'required']])] Data $order) => '',
            'describes "lines", which, in each item, describes "n", which is declared as number' => static fn(
                #[Fields(['lines' => [['n' => 'number']]])]
                Data $order,
            ) => '',
            'takes two bodies' => static fn(
                #[Fields(['a' => 'int'])]
                Data $one,
                #[Fields(['b' => 'int'])]
                Data $two,
            ) => '',
            'takes $email with no type' => static fn($email) => '',
            'and also its field $name' => static fn(
                #[FromQuery]
                string $name,
                #[FromQuery, Fields(['a' => 'int'])]
                Data $order,
            ) => '',
        ];
        $n = 0;
        foreach ($mistakes as $says => $action) {
            try {
                $router->post('/mistake/' . ++$n, $action);
                self::fail("A description that should say \"{$says}\" was accepted.");
            } catch (\LogicException $error) {
                self::assertStringContainsString($says, $error->getMessage());
            }
        }
    }

    public function testAHandlerReceivesWhatItsSignatureAsksFor(): void
    {
        $router = new Router();
        $router->post(
            '/users',
            static fn(CreateUser $input): User => new User(1, $input->email, $input->name, $input->plan),
        );
        $router->get('/users', static fn(#[FromQuery] ListUsers $query): array => [
            'page' => $query->page,
            'search' => $query->search,
        ]);
        $router->get('/users/{id}/posts/{slug}', static fn(string $slug, int $id): array => [$id, $slug]);
        $router->put('/users/{id}', static fn(int $id, CreateUser $input, ServerRequestInterface $request): array => [
            $id,
            $input->name,
            $request->getMethod(),
        ]);
        $router->get('/ratio/{value}/{exact}', static fn(float $value, bool $exact): array => [$value, $exact]);
        $router->get('/raw/{name}', static fn(ServerRequestInterface $request, string $name): array => [
            $name,
            $request->getAttribute('name'),
        ]);
        $router->delete('/users/{id}', static function (int $id, RequestContract $request): void {});
        $router->get('/text', static fn(): string => 'plain');
        $router->get('/made', static fn(): ResponseInterface => Response::json(['made' => true], 201));
        $router->get('/number', static fn(): int => 7);

        $created = $router->handle($this->json('POST', '/users', [
            'email' => 'ada@example.com',
            'name' => 'Ada',
            'plan' => 'paid',
        ]));
        self::assertSame([200, 'application/json', '{"id":1,"email":"ada@example.com","name":"Ada","plan":"paid"}'], [
            $created->getStatusCode(),
            $created->getHeaderLine('Content-Type'),
            (string) $created->getBody(),
        ]);
        self::assertSame('{"page":1,"search":null}', $this->body($router, new Request('GET', '/users')));
        self::assertSame('{"page":3,"search":"ada"}', $this->body(
            $router,
            new Request('GET', '/users?page=3&search=ada&unknown=1'),
        ));
        self::assertSame('[42,"hello world"]', $this->body(
            $router,
            new Request('GET', '/users/42/posts/hello%20world'),
        ));
        self::assertSame('[7,"Grace","PUT"]', $this->body($router, $this->json('PUT', '/users/7', [
            'email' => 'g@example.com',
            'name' => 'Grace',
        ])));
        self::assertSame('[2.5,true]', $this->body($router, new Request('GET', '/ratio/2.5/1')));
        self::assertSame('["x","x"]', $this->body($router, new Request('GET', '/raw/x')));
        $deleted = $router->handle(new Request('DELETE', '/users/9'));
        self::assertSame([204, ''], [$deleted->getStatusCode(), (string) $deleted->getBody()]);
        $text = $router->handle(new Request('GET', '/text'));
        self::assertSame(['text/plain; charset=utf-8', 'plain'], [
            $text->getHeaderLine('Content-Type'),
            (string) $text->getBody(),
        ]);
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
        $router->get('/users', static function (#[FromQuery] ListUsers $query) use (&$calls): array {
            ++$calls;
            return [];
        });

        $response = $router->handle($this->json('PUT', '/users/abc', ['email' => 'nope', 'plan' => 'gold']));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            [
                'error' => [
                    'status' => 422,
                    'message' => 'The request is invalid.',
                    'fields' => [
                        'email' => ['must be an email address'],
                        'name' => ['is required'],
                        'plan' => ['must be one of: free, paid'],
                    ],
                    // Said apart, since a field of the body and a parameter of the path may share a name.
                    'path' => ['id' => ['must be a whole number']],
                ],
            ],
            json_decode((string) $response->getBody(), true),
        );

        $query = $router->handle(new Request('GET', '/users?page=0', ['Accept' => 'application/json']));
        self::assertSame(
            ['page' => ['must be at least 1']],
            json_decode((string) $query->getBody(), true)['error']['query'],
        );
        $plain = $router->handle(new Request('GET', '/users?page=first'));
        self::assertSame([422, "422 The request is invalid.\n?page must be a whole number\n"], [
            $plain->getStatusCode(),
            (string) $plain->getBody(),
        ]);

        foreach (['', '"text"', '[1,2]x'] as $body) {
            $request = new Request(
                'PUT',
                '/users/1',
                ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                $body,
            );
            try {
                $status = $router->handle($request)->getStatusCode();
            } catch (\Crab\Http\HttpException $malformed) {
                $status = $malformed->status;
            }
            self::assertContains($status, [400, 422], "A body of {$body} was accepted.");
        }
        $noBody = $router->handle(new Request('PUT', '/users/1', ['Accept' => 'application/json']));
        self::assertSame(
            ['email' => ['is required'], 'name' => ['is required']],
            json_decode((string) $noBody->getBody(), true)['error']['fields'],
            'With no body, every field is missing.',
        );
        self::assertSame(0, $calls);
    }

    public function testAHandlerClassIsMadeByTheContainerOnceAndCalledByItsSignature(): void
    {
        $container = new Container();
        $router = new Router($container);
        $router->get('/users/{id}', ShowUser::class);
        $names = $container->get(Names::class);
        self::assertSame(0, $names->made, 'Adding the route does not make the action.');

        self::assertSame('{"id":5,"email":"5@example.com","name":"user-5","plan":"paid"}', $this->body(
            $router,
            new Request('GET', '/users/5'),
        ));
        self::assertSame('{"id":6,"email":"6@example.com","name":"user-6","plan":"paid"}', $this->body(
            $router,
            new Request('GET', '/users/6'),
        ));
        self::assertSame(1, $names->made);
        self::assertSame(422, $router->handle(new Request('GET', '/users/x'))->getStatusCode());

        $route = $router->routes()[0];
        self::assertSame(['GET', '/users/{id}', ShowUser::class], [$route->method, $route->pattern, $route->action]);
    }

    public function testDatesInADeclaredResponseAreWrittenAsText(): void
    {
        $at = new \DateTimeImmutable('2026-01-31 12:00:00', new \DateTimeZone('UTC'));
        $router = new Router();
        $router->get('/event', static fn(): Event => new Event('Launch', $at, [$at->modify('-1 day')]));
        $router->get('/events', static fn(): array => [new Event('Launch', $at)]);
        $router->post('/events', static fn(Event $input): Event => $input);

        self::assertSame('{"title":"Launch","at":"2026-01-31T12:00:00+00:00","reminders":["2026-01-30T12:00:00+00:00"]}', $this->body(
            $router,
            new Request('GET', '/event'),
        ));
        self::assertSame('[{"title":"Launch","at":"2026-01-31T12:00:00+00:00","reminders":[]}]', $this->body(
            $router,
            new Request('GET', '/events'),
        ));
        self::assertSame('{"title":"Talk","at":"2026-02-01T09:30:00+00:00","reminders":[]}', $this->body($router, $this->json(
            'POST',
            '/events',
            ['title' => 'Talk', 'at' => '2026-02-01T09:30:00Z'],
        )));
    }

    public function testTheQueryStringsFieldsCanBeTakenAsSeparateParameters(): void
    {
        $router = new Router();
        $router->get('/users', static fn(
            #[FromQuery]
            int $page = 1,
            #[FromQuery]
            ?string $search = null,
            #[FromQuery]
            Plan $plan = Plan::Free,
            #[FromQuery]
            bool $active = true,
        ): array => [$page, $search, $plan, $active]);
        $router->patch('/users/{id}', static fn(
            int $id,
            ServerRequestInterface $request,
            #[Fields(['name?' => 'string', 'tags?' => [['string']]])]
            Data $changes,
            #[FromQuery, ListOf('string')]
            array $notify = [],
            #[FromQuery]
            ?\DateTimeImmutable $at = null,
        ): array => [$id, $changes->name, $changes->tags, $notify, $at?->format('Y'), $request->getMethod()]);

        self::assertSame('[1,null,"free",true]', $this->body($router, new Request('GET', '/users')));
        self::assertSame('[3,"ada","paid",false]', $this->body(
            $router,
            new Request('GET', '/users?page=3&search=ada&plan=paid&active=0&unknown=x'),
        ));
        self::assertSame('[7,null,null,[],null,"PATCH"]', $this->body($router, $this->json('PATCH', '/users/7', [])));
        // The body and the query string are read each for its own fields: a name in one is not taken from the other.
        self::assertSame('[7,"Ada",["a","b"],["ops"],"2026","PATCH"]', $this->body($router, $this->json(
            'PATCH',
            '/users/7?notify[]=ops&at=2026-01-31T12:00:00Z&name=Grace',
            ['name' => 'Ada', 'tags' => ['a', 'b'], 'notify' => ['everyone']],
        )));

        $invalid = $router->handle($this->json('PATCH', '/users/x?at=soon', ['tags' => ['a', 1]]));
        self::assertSame(
            [
                'fields' => ['tags.1' => ['must be text']],
                'query' => ['at' => ['must be a date or a date and time, such as 2026-01-31 or 2026-01-31T12:00:00Z']],
                'path' => ['id' => ['must be a whole number']],
            ],
            array_intersect_key(json_decode((string) $invalid->getBody(), true)['error'], [
                'fields' => true,
                'query' => true,
                'path' => true,
            ]),
        );
        // As text, each part is told by how it is written in the address.
        self::assertSame(
            "422 The request is invalid.\n{id} must be a whole number\n?at must be a date or a date and time, such as 2026-01-31 or 2026-01-31T12:00:00Z\ntags.1 must be text\n",
            (string) $router
                ->handle(
                    new Request(
                        'PATCH',
                        '/users/x?at=soon',
                        ['Content-Type' => 'application/json'],
                        '{"tags":["a",1]}',
                    ),
                )
                ->getBody(),
        );
        self::assertSame(422, $router->handle(new Request('GET', '/users?page=first'))->getStatusCode());
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

        self::assertSame('[{"id":2,"email":"2@example.com","name":"user-2","plan":"free"}]', $this->body(
            $router,
            new Request('GET', '/users?page=2'),
        ));
        self::assertSame('{"id":5,"email":"5@example.com","name":"user-5","plan":"free"}', $this->body(
            $router,
            new Request('GET', '/users/5'),
        ));
        $created = $router->handle($this->json('POST', '/users', ['email' => 'ada@example.com', 'name' => 'Ada']));
        self::assertSame([201, '/users/9', '{"id":9,"email":"ada@example.com","name":"Ada","plan":"free"}'], [
            $created->getStatusCode(),
            $created->getHeaderLine('Location'),
            (string) $created->getBody(),
        ]);
        self::assertSame(422, $router->handle($this->json('POST', '/users', ['email' => 'x']))->getStatusCode());
        self::assertSame(1, $names->made, 'One controller serves all its routes.');

        $routes = $router->routes();
        self::assertSame(
            [UserController::class . '::index', UserController::class . '::show', UserController::class . '::store'],
            array_column($routes, 'action'),
        );

        $standalone = new Router();
        $standalone->get('/plain', [PlainController::class, 'hello']);
        self::assertSame(
            'hello',
            (string) $standalone->handle(new Request('GET', '/plain'))->getBody(),
            'Without a container, a class that needs nothing is simply made.',
        );
    }

    public function testAHandlerReturnsAResponseToControlTheStatusHeadersOrBody(): void
    {
        $at = new \DateTimeImmutable('2026-01-31 12:00:00', new \DateTimeZone('UTC'));
        $file = sys_get_temp_dir() . '/crab-download-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($file, str_repeat('0123456789', 10_000));
        $router = new Router();
        $router->post('/events', static fn(#[Fields(['title' => 'string'])] Data $event): Response => Response::created(
            new Event($event->title, $at),
            '/events/1',
        ));
        $router->post('/empty', static fn(): Response => Response::created());
        $router->delete('/events/{id}', static fn(int $id): Response => Response::noContent());
        $router->get('/old', static fn(): Response => Response::redirect('/new'));
        $router->post('/login', static fn(): Response => Response::redirect('/home', 303));
        $router->get('/file', static fn(): Response => Response::download($file, 'réport "final".txt', 'text/plain'));
        $router->get('/cached', static fn(): ResponseInterface => Response::json(['a' => 1])->withHeader(
            'Cache-Control',
            'max-age=60',
        ));

        $created = $router->handle($this->json('POST', '/events', ['title' => 'Launch']));
        self::assertSame([
            201,
            '/events/1',
            'application/json',
            '{"title":"Launch","at":"2026-01-31T12:00:00+00:00","reminders":[]}',
        ], [
            $created->getStatusCode(),
            $created->getHeaderLine('Location'),
            $created->getHeaderLine('Content-Type'),
            (string) $created->getBody(),
        ]);
        $empty = $router->handle(new Request('POST', '/empty'));
        self::assertSame([201, '', false], [
            $empty->getStatusCode(),
            (string) $empty->getBody(),
            $empty->hasHeader('Location'),
        ]);
        $deleted = $router->handle(new Request('DELETE', '/events/1'));
        self::assertSame([204, ''], [$deleted->getStatusCode(), (string) $deleted->getBody()]);
        $moved = $router->handle(new Request('GET', '/old'));
        self::assertSame([302, '/new'], [$moved->getStatusCode(), $moved->getHeaderLine('Location')]);
        self::assertSame(303, $router->handle(new Request('POST', '/login'))->getStatusCode());
        $download = $router->handle(new Request('GET', '/file'));
        self::assertSame([
            200,
            'text/plain',
            "attachment; filename=\"r__port _final_.txt\"; filename*=UTF-8''r%C3%A9port%20%22final%22.txt",
            100_000,
        ], [
            $download->getStatusCode(),
            $download->getHeaderLine('Content-Type'),
            $download->getHeaderLine('Content-Disposition'),
            $download->getBody()->getSize(),
        ]);
        self::assertSame(str_repeat('0123456789', 10_000), (string) $download->getBody());
        self::assertNull(
            $download->getBody() instanceof \Crab\Http\Stream ? $download->getBody()->text() : '',
            'A file is read as it is sent, not held as a string.',
        );
        self::assertSame('max-age=60', $router->handle(new Request('GET', '/cached'))->getHeaderLine('Cache-Control'));
        unlink($file);

        self::assertSame(
            'attachment; filename="notes.txt"; filename*=UTF-8\'\'notes.txt',
            Response::download(__FILE__, 'notes.txt')->getHeaderLine('Content-Disposition'),
        );
        self::assertStringContainsString(
            'filename="ActionHandlerTest.php"',
            Response::download(__FILE__)->getHeaderLine('Content-Disposition'),
        );
        self::assertSame('application/octet-stream', Response::download(__FILE__)->getHeaderLine('Content-Type'));
        $csv = Response::attachment("id,total\n1,4200\n", 'commande été.csv', 'text/csv');
        self::assertSame([
            200,
            'text/csv',
            "attachment; filename=\"commande __t__.csv\"; filename*=UTF-8''commande%20%C3%A9t%C3%A9.csv",
            "id,total\n1,4200\n",
        ], [
            $csv->getStatusCode(),
            $csv->getHeaderLine('Content-Type'),
            $csv->getHeaderLine('Content-Disposition'),
            (string) $csv->getBody(),
        ]);
        $refused = 0;
        foreach ([
            static fn() => Response::redirect('/x', 200),
            static fn() => Response::download('/no/such/file'),
            static fn() => Response::download(__DIR__),
        ] as $mistake) {
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
            'takes Crab\Contracts\Validation\Validator $validator, which is neither a parameter of the path, a query field, the body, the request nor the user' =>
                static fn() => $router->get('/a', static fn(ValidatorContract $validator) => ''),
            'Services belong in the constructor of an action class' => static fn() => $router->get(
                '/a',
                static fn(ValidatorContract $validator) => '',
            ),
            "cannot take the request's data: Crab\\Database\\Database::\$connect is declared as Closure" =>
                static fn() => $router->post('/a', static fn(\Crab\Database\Database $db) => ''),
            'If it is a service, it belongs in the constructor of an action class' => static fn() => $router->post(
                '/a',
                static fn(\Crab\Database\Database $db) => '',
            ),
            'has none. Mark it #[FromQuery] to read the query string; if it is a service, it belongs in the constructor of an action class' =>
                static fn() => $router->get('/a', static fn(\Crab\Database\Database $db) => ''),
            'takes Crab\Tests\Routing\ListUsers $query as its body, and a GET request has none' =>
                static fn() => $router->get('/a', static fn(ListUsers $query) => ''),
            'a DELETE request has none' => static fn() => $router->delete('/a', static fn(CreateUser $input) => ''),
            'takes the route parameter $id as array; it can be string, int, float or bool' =>
                static fn() => $router->get('/a/{id}', static fn(array $id) => ''),
            'takes two bodies, Crab\Tests\Routing\CreateUser and Crab\Tests\Routing\ListUsers, and a request has one; put their fields in one class' =>
                static fn() => $router->post('/a', static fn(CreateUser $a, ListUsers $b) => ''),
            'takes bool $force, which is not a parameter of the path /a. A field of the query string is marked #[FromQuery] bool $force; a field of the body belongs in the body\'s input class' =>
                static fn() => $router->post('/a', static fn(CreateUser $input, bool $force = false) => ''),
            'takes string $email, which is not a parameter of the path /a/{id}' => static fn() => $router->post(
                '/a/{id}',
                static fn(int $id, string $email) => '',
            ),
            'takes the query string twice, as Crab\Tests\Routing\ListUsers and Crab\Tests\Routing\CreateUser' =>
                static fn() => $router->get(
                    '/a',
                    static fn(#[FromQuery] ListUsers $a, #[FromQuery] CreateUser $b) => '',
                ),
            'takes the query string as Crab\Tests\Routing\ListUsers $query and also its field $force' =>
                static fn() => $router->get(
                    '/a',
                    static fn(#[FromQuery] ListUsers $query, #[FromQuery] bool $force = false) => '',
                ),
            'marks $id #[FromQuery], but it is a parameter of the path /a/{id}, which is not read from the query string; drop the mark' =>
                static fn() => $router->get('/a/{id}', static fn(#[FromQuery] int $id) => ''),
            'marks $request #[FromQuery], but it is the request' => static fn() => $router->get(
                '/a',
                static fn(#[FromQuery] ServerRequestInterface $request) => '',
            ),
            'takes $id, which must have one plain type' => static fn() => $router->get(
                '/a/{id}',
                static fn(int|string $id) => '',
            ),
            'takes $rest, which must have one plain type' => static fn() => $router->get(
                '/a',
                static fn(string ...$rest) => '',
            ),
            'Ambiguous::$id is declared as DateTimeImmutable|int, and a union can be validated only when it is of the scalar types' =>
                static fn() => $router->post('/a', static fn(\Crab\Tests\Routing\Ambiguous $input) => ''),
            'A closure takes $tags, which has #[ListOf] but is not declared as an array' => static fn() => $router->get(
                '/a',
                static fn(#[FromQuery, ListOf('int')] string $tags) => '',
            ),
            "is an array, which must be a class and the name of its method: [Users::class, 'show']" =>
                static fn() => $router->get('/a', [UserController::class]),
            'names the class App\Missing, which does not exist' => static fn() => $router->get('/a', [
                'App\Missing',
                'show',
            ]),
            'UserController::missing(), which is not a public method' => static fn() => $router->get('/a', [
                UserController::class,
                'missing',
            ]),
            'UserController::secret(), which is not a public method' => static fn() => $router->get('/a', [
                UserController::class,
                'secret',
            ]),
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

    public function testTheApplicationBindsTheValidator(): void
    {
        $app = new App(new Config(['app' => ['name' => 'Shop']]));
        self::assertInstanceOf(Validator::class, $app->container->get(ValidatorContract::class));
        self::assertSame($app->container->get(ValidatorContract::class), $app->container->get(Validator::class));
        $app->router->post(
            '/users',
            static fn(CreateUser $input): User => new User(1, $input->email, $input->name, $input->plan),
        );

        $response = $app->handle($this->json('POST', '/users', ['email' => 'x']));
        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * @param array<mixed> $data
     */
    private function json(string $method, string $path, array $data): Request
    {
        return new Request(
            $method,
            $path,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            json_encode($data),
        );
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
    public function __construct(
        public int|\DateTimeImmutable $id,
    ) {}
}
