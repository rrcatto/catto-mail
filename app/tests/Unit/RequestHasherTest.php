<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Api\RequestHasher;
use PHPUnit\Framework\TestCase;

final class RequestHasherTest extends TestCase
{
    private static function h(string $json): string
    {
        return RequestHasher::hash(json_decode($json, false, 512, \JSON_THROW_ON_ERROR));
    }

    public function testKeyOrderAndWhitespaceDoNotMatter(): void
    {
        self::assertSame(self::h('{"a":1,"b":{"y":2,"x":[1,2]}}'), self::h("{ \"b\" : {\"x\":[1, 2], \"y\":2},\n \"a\":1 }"));
    }

    public function testValuesArrayOrderAndExtraFieldsMatter(): void
    {
        $base = self::h('{"a":[1,2]}');
        self::assertNotSame($base, self::h('{"a":[2,1]}'));
        self::assertNotSame($base, self::h('{"a":[1,2],"b":false}'));
        self::assertNotSame(self::h('{"a":"1"}'), self::h('{"a":1}'));
        self::assertNotSame(self::h('{"a":{}}'), self::h('{"a":[]}'));
    }

    public function testDeterministicHex(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', self::h('{"x":"é/ü"}'));
        self::assertSame(self::h('{"x":"é/ü"}'), self::h('{"x":"é\/ü"}'));
    }
}
