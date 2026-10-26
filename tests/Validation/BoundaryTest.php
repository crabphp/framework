<?php

declare(strict_types=1);

namespace Crab\Tests\Validation;

use Crab\App;
use Crab\Http\Request;
use Crab\Validation\Email;
use Crab\Validation\ValidationException;
use Crab\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

enum BoundaryNumber: int
{
    case Minimum = PHP_INT_MIN;
    case Maximum = PHP_INT_MAX;
    case Zero = 0;
    case One = 1;
    case Negative = -1;
}

final readonly class PlainNumber
{
    public function __construct(
        public int $number,
    ) {}
}

final readonly class EnumNumber
{
    public function __construct(
        public BoundaryNumber $number,
    ) {}
}

final readonly class UnimportedRule
{
    public function __construct(
        #[MissingEmail]
        public string $email,
    ) {}
}

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class DocumentedField {}

final readonly class DocumentedEmail
{
    public function __construct(
        #[DocumentedField, Email]
        public string $email,
    ) {}
}

final class BoundaryTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, string, int|null}>
     */
    public static function integerText(): iterable
    {
        $values = [
            'maximum' => [(string) PHP_INT_MAX, PHP_INT_MAX],
            'minimum' => [(string) PHP_INT_MIN, PHP_INT_MIN],
            'zero' => ['0', 0],
            'leading zeroes' => ['0001', 1],
            'negative leading zeroes' => ['-001', -1],
            'zeroes' => ['000', 0],
            'positive overflow' => [PHP_INT_SIZE === 8 ? '9223372036854775808' : '2147483648', null],
            'negative overflow' => [PHP_INT_SIZE === 8 ? '-9223372036854775809' : '-2147483649', null],
            'negative zero' => ['-0', null],
            'fraction' => ['1.0', null],
            'suffix' => ['1st', null],
        ];
        foreach ([PlainNumber::class, EnumNumber::class] as $class) {
            foreach ($values as $name => [$text, $expected]) {
                yield $class . ' ' . $name => [$class, $text, $expected];
            }
        }
    }

    #[DataProvider('integerText')]
    public function testIntegersAndIntegerEnumsOnlyAcceptLosslessText(string $class, string $text, ?int $expected): void
    {
        $validator = new Validator();
        if ($expected === null) {
            $this->expectException(ValidationException::class);
        }

        $input = $validator->validate($class, ['number' => $text], coerce: true);
        $number = $input->number instanceof BoundaryNumber ? $input->number->value : $input->number;
        self::assertSame($expected, $number);
    }

    public function testAnUnresolvedInputAttributeNamesTheFieldAndTheMissingImport(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote(UnimportedRule::class . '::$email', '/') . '.*MissingEmail.*import/',
        );

        new Validator()->inspect(UnimportedRule::class);
    }

    public function testAnUnresolvedActionAttributeIsRefusedWhenItsRouteIsDeclared(): void
    {
        $app = new App();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('~(?s)(?=.*/signup)(?=.*\$email)(?=.*MissingEmail)~');

        $app->router->get('/signup', static fn(#[\Crab\Routing\FromQuery, MissingEmail] string $email): array => [
            'email' => $email,
        ]);
    }

    public function testAnExistingUnrelatedAttributeDoesNotDisableValidation(): void
    {
        $validator = new Validator();
        self::assertSame(
            'ada@example.com',
            $validator->validate(DocumentedEmail::class, ['email' => 'ada@example.com'])->email,
        );
        $this->expectException(ValidationException::class);
        $validator->validate(DocumentedEmail::class, ['email' => 'invalid']);
    }

    public function testAResolvedEmailRuleStillAnswersWithValidationErrors(): void
    {
        $app = new App();
        $app->router->post('/signup', static fn(
            #[\Crab\Validation\Fields(['email' => ['string', new Email()]])]
            \Crab\Validation\Data $user,
        ): array => [
            'email' => $user->email,
        ]);
        $response = $app->handle(
            new Request('POST', '/signup', ['Content-Type' => 'application/json'], '{"email":"invalid"}'),
        );
        self::assertSame(422, $response->getStatusCode());
    }
}
