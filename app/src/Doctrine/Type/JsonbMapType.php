<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\SerializationFailed;
use Doctrine\DBAL\Types\Type;

/**
 * `jsonb` column holding a JSON object, exposed as a PHP associative array.
 * Unlike Doctrine's `json` type an empty array is stored as `{}` (the column
 * defaults in the reference schema are `'{}'::jsonb`), never as `[]`.
 */
final class JsonbMapType extends Type
{
    public const NAME = 'jsonb_map';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        try {
            return json_encode([] === $value ? new \stdClass() : $value,
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $e) {
            throw SerializationFailed::new($value, 'json', $e->getMessage(), $e);
        }
    }

    /** @return array<string, mixed>|null */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        if (null === $value || '' === $value) {
            return null;
        }
        try {
            $decoded = json_decode(\is_resource($value) ? (string) stream_get_contents($value) : (string) $value, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw InvalidFormat::new((string) $value, self::NAME, 'JSON object', $e);
        }

        return \is_array($decoded) ? $decoded : throw InvalidFormat::new((string) $value, self::NAME, 'JSON object');
    }
}
