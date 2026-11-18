<?php

declare(strict_types=1);

namespace Crab\Tests\Routing;

use Crab\Contracts\Routing\InputProjection;
use Crab\Http\Request;
use Crab\Routing\Actions;
use Crab\Routing\FromQuery;
use Crab\Validation\RejectUnknown;
use Crab\Validation\Validator;
use PHPUnit\Framework\TestCase;

#[RejectUnknown]
final readonly class PreparedSearch
{
    public function __construct(
        public string $term = '',
    ) {}
}

final readonly class PreparedBody
{
    public function __construct(
        public string $title,
    ) {}
}

final class PreparedActionTest extends TestCase
{
    public function testAnActionReturnsItsRawValueAndNotAResponse(): void
    {
        $prepared = new Actions(new Validator())->prepare('GET', '/posts/{id}', static fn(
            int $id,
            #[FromQuery]
            int $page = 1,
        ): array => ['id' => $id, 'page' => $page]);

        $outcome = $prepared->invoke(new Request('GET', '/posts/7?page=3')->withAttribute('id', '7'));

        self::assertTrue($outcome->entered);
        self::assertSame(['id' => 7, 'page' => 3], $outcome->value);
        self::assertNull($outcome->rejection);
    }

    public function testNothingReturnedIsAReturnedOutcomeAndNotARejection(): void
    {
        $prepared = new Actions(new Validator())->prepare('POST', '/done', static function (): void {});

        $outcome = $prepared->invoke(new Request('POST', '/done'));

        self::assertTrue($outcome->entered);
        self::assertNull($outcome->value);
    }

    public function testARequestThatDoesNotFitIsRejectedWithoutCallingTheAction(): void
    {
        $called = false;
        $prepared = new Actions(new Validator())->prepare('POST', '/posts/{id}', static function (
            int $id,
            PreparedBody $input,
        ) use (&$called): string {
            $called = true;
            return $input->title;
        });
        $request = new Request('POST', '/posts/x', ['Content-Type' => 'application/json'], '{}')->withAttribute(
            'id',
            'x',
        );

        $outcome = $prepared->invoke($request);

        self::assertFalse($called);
        self::assertFalse($outcome->entered);
        self::assertSame(422, $outcome->rejection?->getStatusCode());
        self::assertSame(['title' => ['is required']], $outcome->problem?->fields);
        self::assertSame(['id' => ['must be a whole number']], $outcome->problem?->path);
    }

    public function testAProjectionLeavesOutTheFieldsOfOtherActionsOfTheRequest(): void
    {
        $prepared = new Actions(new Validator())->prepare(
            'GET',
            '/posts',
            static fn(#[FromQuery] PreparedSearch $search): string => $search->term,
        );
        $request = new Request('GET', '/posts?term=crab&tab=drafts');

        $alone = $prepared->invoke($request);
        $shared = $prepared->invoke($request, new InputProjection(['tab']));
        $unknown = $prepared->invoke(new Request('GET', '/posts?term=crab&other=1'), new InputProjection(['tab']));

        self::assertFalse($alone->entered);
        self::assertSame('crab', $shared->value);
        // Only what the others declare is left out: a field nobody declares is still refused.
        self::assertFalse($unknown->entered);
    }

    public function testItSaysWhatItTakesAndWhatTheContainerMakes(): void
    {
        $prepared = new Actions(new Validator())->prepare('POST', '/posts/{id}', [PreparedController::class, 'save']);

        self::assertSame(['id'], array_keys($prepared->input()['parameters']));
        self::assertSame('object', $prepared->input()['body']['type']);
        self::assertSame([PreparedController::class], $prepared->dependencies());
        self::assertSame('save', $prepared->signature()->getName());
    }

    public function testASignatureThatCannotBeHonouredIsRefusedWhenPrepared(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has none');

        new Actions(new Validator())->prepare('GET', '/posts', static fn(PreparedBody $input): string => $input->title);
    }

    public function testOnlyRequestMethodsArePrepared(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Actions(new Validator())->prepare('WS', '/chat', static fn(): string => '');
    }
}

final class PreparedController
{
    public function save(int $id, PreparedBody $input): string
    {
        return "{$id} {$input->title}";
    }
}
