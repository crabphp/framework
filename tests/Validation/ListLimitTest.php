<?php

declare(strict_types=1);

namespace Crab\Tests\Validation;

use Crab\App;
use Crab\Http\Request;
use Crab\Validation\Data;
use Crab\Validation\Fields;
use Crab\Validation\Length;
use Crab\Validation\ListOf;
use Crab\Validation\ValidationException;
use Crab\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CountedListItem
{
    public static int $constructed = 0;

    public function __construct(
        public readonly int $id,
    ) {
        ++self::$constructed;
    }
}

final readonly class LimitedItems
{
    public function __construct(
        #[ListOf(CountedListItem::class), Length(min: 2, max: 3)]
        public array $items,
    ) {}
}

final class ListLimitTest extends TestCase
{
    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function oversizedLists(): iterable
    {
        yield 'valid objects' => [array_fill(0, 4, ['id' => 1]), 'must have at most 3 item(s)'];
        yield 'invalid objects' => [array_fill(0, 4, ['id' => []]), 'must have at most 3 item(s)'];
        yield 'too few objects' => [[['id' => []]], 'must have at least 2 item(s)'];
    }

    #[DataProvider('oversizedLists')]
    public function testListLengthIsCheckedBeforeAnyItemIsValidatedOrConstructed(array $items, string $message): void
    {
        CountedListItem::$constructed = 0;
        $errors = null;
        try {
            new Validator()->validate(LimitedItems::class, ['items' => $items]);
        } catch (ValidationException $error) {
            $errors = $error->errors;
        }

        self::assertSame(['items' => [$message]], $errors);
        self::assertSame(0, CountedListItem::$constructed);
    }

    public function testListsWithinTheirBoundsStillValidateAndConvertTheirItems(): void
    {
        CountedListItem::$constructed = 0;
        $value = new Validator()->validate(LimitedItems::class, ['items' => [['id' => '1'], ['id' => '2']]], true);

        self::assertSame([1, 2], array_column($value->items, 'id'));
        self::assertSame(2, CountedListItem::$constructed);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('items.1.id must be a whole number');
        new Validator()->validate(LimitedItems::class, ['items' => [['id' => 1], ['id' => []]]]);
    }

    public function testDescribedNestedListsRejectTheirSizeBeforeExpandingItemErrors(): void
    {
        $app = new App();
        $app->router->post('/items', static fn(
            #[Fields(['order' => ['items' => [['id' => 'int'], new Length(max: 2)]]])]
            Data $input,
        ): array => $input->toArray());
        $body = json_encode(['order' => ['items' => array_fill(0, 3, ['id' => []])]], JSON_THROW_ON_ERROR);
        $response = $app->handle(
            new Request(
                'POST',
                '/items',
                [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                $body,
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ['order.items' => ['must have at most 2 item(s)']],
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['fields'],
        );
    }

    public function testAnOversizedListReturnsOneSizeErrorWithinABoundedChildProcess(): void
    {
        $code = <<<'PHP'
            require $argv[1];
            $app = new Crab\App();
            $app->router->post('/items', static fn(
                #[Crab\Validation\Fields(['items' => [['string'], new Crab\Validation\Length(max: 5)]])]
                Crab\Validation\Data $input,
            ): array => $input->toArray());
            $body = '{"items":[' . str_repeat('0,', 399999) . '0]}';
            $response = $app->handle(new Crab\Http\Request('POST', '/items', [
                'Content-Type' => 'application/json', 'Accept' => 'application/json',
            ], $body));
            echo json_encode([$response->getStatusCode(), json_decode((string) $response->getBody(), true)]);
            PHP;
        $process = proc_open(
            [
                PHP_BINARY,
                '-d',
                'memory_limit=128M',
                '-r',
                $code,
                dirname(__DIR__, 2) . '/vendor/autoload.php',
            ],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        self::assertSame(0, $status, $errors . $output);
        self::assertSame(
            [
                422,
                [
                    'error' => [
                        'status' => 422,
                        'message' => 'The request is invalid.',
                        'fields' => ['items' => ['must have at most 5 item(s)']],
                    ],
                ],
            ],
            json_decode($output, true, flags: JSON_THROW_ON_ERROR),
        );
    }
}
