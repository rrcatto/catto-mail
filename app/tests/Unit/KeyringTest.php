<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Crypto\Keyring;
use PHPUnit\Framework\TestCase;

final class KeyringTest extends TestCase
{
    private static function key(): string
    {
        return base64_encode(random_bytes(32));
    }

    public function testRoundTripAndCiphertextHidesPlaintext(): void
    {
        $k = new Keyring('k1:'.self::key());
        $sealed = $k->encrypt('whsec_secret-value', 'purpose-a');
        self::assertSame('k1', $sealed['key_id']);
        self::assertStringNotContainsString('secret-value', base64_decode($sealed['ciphertext']));
        self::assertSame('whsec_secret-value', $k->decrypt($sealed['ciphertext'], 'k1', 'purpose-a'));
        self::assertNotSame($sealed['ciphertext'], $k->encrypt('whsec_secret-value', 'purpose-a')['ciphertext'], 'random nonce');
    }

    public function testPurposeIsBound(): void
    {
        $k = new Keyring('k1:'.self::key());
        $sealed = $k->encrypt('s', 'endpoint-1');
        $this->expectException(\RuntimeException::class);
        $k->decrypt($sealed['ciphertext'], 'k1', 'endpoint-2');
    }

    public function testTamperingIsDetected(): void
    {
        $k = new Keyring('k1:'.self::key());
        $raw = base64_decode($k->encrypt('s', 'p')['ciphertext']);
        $raw[30] = \chr(\ord($raw[30]) ^ 1);
        $this->expectException(\RuntimeException::class);
        $k->decrypt(base64_encode($raw), 'k1', 'p');
    }

    public function testRotationFirstKeyEncryptsAnyKeyDecrypts(): void
    {
        $old = self::key();
        $sealedOld = (new Keyring("k1:$old"))->encrypt('s', 'p');
        $rotated = new Keyring('k2:'.self::key().",k1:$old");
        self::assertSame('k2', $rotated->primaryKeyId());
        self::assertSame('s', $rotated->decrypt($sealedOld['ciphertext'], 'k1', 'p'));
        self::assertSame('k2', $rotated->encrypt('s', 'p')['key_id']);
    }

    public function testInvalidConfigurationIsRejected(): void
    {
        foreach (['', 'nokey', 'k1:'.base64_encode('short'), 'k1:'.self::key().',k1:'.self::key(), 'bad id:'.self::key()] as $config) {
            try {
                new Keyring($config);
                self::fail("accepted: $config");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
