<?php

declare(strict_types=1);

namespace Crab\Tests\Validation;

use Crab\Validation\Email;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    public function testItDecidesEveryAddressAsFilterVarDoes(): void
    {
        $email = new Email();
        $samples = [
            'ada@example.com',
            'a@b',
            'a@b.c',
            'a..b@x.com',
            '.a@x.com',
            'a.@x.com',
            'a@-x.com',
            'a@x-.com',
            'a@x..com',
            "o'neil@ex-ample.org",
            'u_1@a1.b2.com',
            'A.B+C@Sub.Example.CO.UK',
            'a b@x.com',
            '"quoted"@x.com',
            'a@[127.0.0.1]',
            'a@127.0.0.1',
            'üser@example.com',
            'a@exämple.com',
            "a@x.com\n",
            str_repeat('a', 64) . '@x.com',
            str_repeat('a', 65) . '@x.com',
            'a@' . str_repeat('b', 63) . '.com',
            'a@' . str_repeat('b', 64) . '.com',
            'a@b.' . str_repeat('c', 63),
            'a@b.c0m',
            'a@b.123',
            '',
        ];
        // Variants of everyday addresses: an inserted, removed or repeated character.
        mt_srand(7);
        $characters = str_split("aZ09.-_+@!#\$%&'*/=?^`{|}~\"()<>[]:;,\\ ü");
        for ($i = 0; $i < 20_000; ++$i) {
            $sample = ['ada@example.com', 'a.b-c+d@sub.example.co.uk', 'x@y.zz'][$i % 3];
            $at = mt_rand(0, strlen($sample));
            $samples[] = match ($i % 3) {
                0 => substr($sample, 0, $at) . $characters[mt_rand(0, count($characters) - 1)] . substr($sample, $at),
                1 => substr($sample, 0, $at) . substr($sample, $at + 1),
                default => substr($sample, 0, $at)
                    . str_repeat($characters[mt_rand(0, count($characters) - 1)], mt_rand(2, 70))
                    . substr($sample, $at),
            };
        }
        foreach ($samples as $sample) {
            self::assertSame(
                filter_var($sample, FILTER_VALIDATE_EMAIL) !== false,
                $email->check($sample) === null,
                'Decided otherwise than filter_var(): ' . json_encode($sample),
            );
        }
        self::assertSame('must be an email address', $email->check(42));
    }
}
