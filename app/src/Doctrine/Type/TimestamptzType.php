<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Util\Clock;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;

/**
 * PostgreSQL `timestamptz` with microsecond precision, handled in the installation's
 * zone (conventions: Identifiers and time; Clock::zone()). Doctrine's built-in
 * datetimetz types truncate to seconds, which would lose ordering information
 * (e.g. between recipient batches of one job).
 */
final class TimestamptzType extends Type
{
    public const NAME = 'timestamptz';
    private const FORMAT = 'Y-m-d H:i:s.uP';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'TIMESTAMPTZ';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(Clock::zone())->format(self::FORMAT);
        }

        throw InvalidType::new($value, self::NAME, ['null', \DateTimeInterface::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }
        try {
            // PostgreSQL output, e.g. "2026-10-03 10:00:00.123456+02" (fraction optional).
            return (new \DateTimeImmutable((string) $value))->setTimezone(Clock::zone());
        } catch (\Exception $e) {
            throw InvalidFormat::new((string) $value, self::NAME, 'PostgreSQL timestamptz', $e);
        }
    }
}
