<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Sending\AddressNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** D-18 normalisation (spec sending.duplicate_recipients, conventions "Email addresses"). */
final class AddressNormalizerTest extends TestCase
{
    #[DataProvider('cases')]
    public function testNormalize(string $input, ?string $expected): void
    {
        self::assertSame($expected, AddressNormalizer::normalize($input));
    }

    public static function cases(): iterable
    {
        yield 'spec example: whitespace and domain case' => [' John@Example.COM', 'John@example.com'];
        yield 'local part case preserved' => ['John@example.com', 'John@example.com'];
        yield 'lower-case local part distinct' => ['john@example.com', 'john@example.com'];
        yield 'surrounding tabs and newlines' => ["\t a.b+tag@Sub.Example.Org \r\n", 'a.b+tag@sub.example.org'];
        yield 'last @ splits' => ['"a@b"@Example.com', '"a@b"@example.com'];
        yield 'IDN domain to A-label' => ['user@Bücher.Example', 'user@xn--bcher-kva.example'];
        yield 'IDN non-transitional (ß kept)' => ['user@faß.de', 'user@xn--fa-hia.de'];
        yield 'local part unicode untouched' => ['Jöhn@example.com', 'Jöhn@example.com'];
        yield 'no @' => ['example.com', null];
        yield 'empty local' => ['@example.com', null];
        yield 'empty domain' => ['john@', null];
        yield 'blank' => ['   ', null];
    }

    public function testDuplicatesAreDetectedOnlyAfterNormalisation(): void
    {
        self::assertSame(AddressNormalizer::normalize(' John@Example.COM'), AddressNormalizer::normalize('John@example.com'));
        self::assertNotSame(AddressNormalizer::normalize('John@example.com'), AddressNormalizer::normalize('john@example.com'));
    }

    #[DataProvider('syntax')]
    public function testDeliverableSyntax(string $normalized, bool $ok): void
    {
        self::assertSame($ok, AddressNormalizer::isDeliverableSyntax($normalized));
    }

    public static function syntax(): iterable
    {
        yield ['john@example.com', true];
        yield ['j.o-h_n+x@a-b.example.co.uk', true];
        yield ['john@localhost', false];
        yield ['john@-bad.example', false];
        yield ['jo hn@example.com', false];
        yield ["john\r\n@example.com", false];
        yield [str_repeat('a', 65).'@example.com', false];
        yield ['john@[192.0.2.1]', false];
    }
}
