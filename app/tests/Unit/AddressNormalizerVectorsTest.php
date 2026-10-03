<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Sending\AddressNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * D-32: PHP passes every vector of the language-neutral contract
 * docs/contracts/address-normalization-vectors.json (also consumed unchanged by the
 * Python validator, and by Go from Phase 4).
 */
final class AddressNormalizerVectorsTest extends TestCase
{
    public function testEverySharedVector(): void
    {
        $file = \dirname(__DIR__).'/contracts/address-normalization-vectors.json';
        $contract = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual(80, \count($contract['vectors']));
        $failures = [];
        foreach ($contract['vectors'] as $i => $v) {
            $got = AddressNormalizer::normalize($v['input']);
            if ($got !== $v['normalized']) {
                $failures[] = \sprintf('#%d %s: expected %s, got %s', $i, json_encode($v['input']), json_encode($v['normalized']), json_encode($got));
            }
        }
        self::assertSame([], $failures);
    }
}
