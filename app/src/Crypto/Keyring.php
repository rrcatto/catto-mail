<?php

declare(strict_types=1);

namespace App\Crypto;

/**
 * Application encryption keyring (APP_ENCRYPTION_KEYS = "key_id:base64key[,...]").
 * The first key encrypts; any listed key decrypts, so keys can be rotated by
 * prepending a new one. AEAD: libsodium secretbox (XSalsa20-Poly1305) with a
 * random 24-byte nonce and a purpose string bound into the plaintext framing, as
 * the conventions expect. Ciphertext text form: base64(nonce || box).
 * Values decrypted here are secrets and are never logged.
 */
final class Keyring
{
    /** @var array<string, string> key id => 32-byte key */
    private array $keys = [];
    private string $primaryId;

    public function __construct(#[\SensitiveParameter] string $keyring)
    {
        foreach (array_filter(array_map('trim', explode(',', $keyring)), 'strlen') as $entry) {
            $parts = explode(':', $entry, 2);
            $key = 2 === \count($parts) ? base64_decode($parts[1], true) : false;
            if (1 !== preg_match('/^[A-Za-z0-9_-]{1,64}$/', $parts[0]) || false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
                throw new \InvalidArgumentException('APP_ENCRYPTION_KEYS must be "key_id:base64(32-byte key)[,...]".');
            }
            if (isset($this->keys[$parts[0]])) {
                throw new \InvalidArgumentException('APP_ENCRYPTION_KEYS contains a duplicate key id.');
            }
            $this->keys[$parts[0]] = $key;
            $this->primaryId ??= $parts[0];
        }
        if ([] === $this->keys) {
            throw new \InvalidArgumentException('APP_ENCRYPTION_KEYS is empty.');
        }
    }

    public function primaryKeyId(): string
    {
        return $this->primaryId;
    }

    /** @return array{ciphertext: string, key_id: string} */
    public function encrypt(#[\SensitiveParameter] string $plaintext, string $purpose): array
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox(self::frame($purpose, $plaintext), $nonce, $this->keys[$this->primaryId]);

        return ['ciphertext' => base64_encode($nonce.$box), 'key_id' => $this->primaryId];
    }

    public function decrypt(string $ciphertext, string $keyId, string $purpose): string
    {
        $key = $this->keys[$keyId] ?? throw new \RuntimeException(\sprintf('Encryption key "%s" is not in the keyring.', $keyId));
        $raw = base64_decode($ciphertext, true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Malformed ciphertext.');
        }
        $framed = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
        $prefix = self::frame($purpose, '');
        if (false === $framed || !str_starts_with($framed, $prefix)) {
            throw new \RuntimeException('Ciphertext authentication failed.');
        }

        return substr($framed, \strlen($prefix));
    }

    private static function frame(string $purpose, string $plaintext): string
    {
        return 'smarthost:v1:'.$purpose."\0".$plaintext;
    }
}
