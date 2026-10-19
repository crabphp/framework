<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\HttpClient\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testJsonDecodingRefusesAnObjectAboveTheMemberLimit(): void
    {
        $body = '{' . implode(',', array_fill(0, 1001, '"member":0')) . '}';
        $this->expectException(\LengthException::class);
        $this->expectExceptionMessage('A JSON object may have at most 1000 members. Send smaller objects.');
        new Response(200, body: $body)->json();
    }

    public function testJsonDecodingKeepsScalarsAndListsAndStillReportsMalformedJson(): void
    {
        foreach ([null, false, 1, 'text', array_fill(0, 2000, ['id' => 1])] as $value) {
            $response = new Response(200, body: json_encode($value, JSON_THROW_ON_ERROR));
            self::assertSame($value, $response->json());
        }
        $this->expectException(\JsonException::class);
        new Response(200, body: '{invalid' . str_repeat(':', 1001))->json();
    }
}
