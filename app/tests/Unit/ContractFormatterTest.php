<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Logging\ContractFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class ContractFormatterTest extends TestCase
{
    public function testJsonLinesCarryTheRequiredFields(): void
    {
        $line = (new ContractFormatter('json'))->format(new LogRecord(new \DateTimeImmutable(), 'app', Level::Warning, 'hello', ['client_id' => 'c1']));
        $data = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['ts', 'level', 'service', 'msg', 'channel', 'client_id'], array_keys($data));
        self::assertSame('warning', $data['level']);
        self::assertSame('symfony-app', $data['service']);
        self::assertMatchesRegularExpression('/Z$/', $data['ts']);
    }

    public function testUnknownFormatIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ContractFormatter('xml');
    }
}
