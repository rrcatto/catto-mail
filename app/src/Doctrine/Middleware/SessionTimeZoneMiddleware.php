<?php

declare(strict_types=1);

namespace App\Doctrine\Middleware;

use App\Util\Clock;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Every PostgreSQL session of the application uses the installation's zone (SMARTHOST_TIMEZONE,
 * the PHP default zone): `now()::date`, `date_trunc('day', ...)` and every timestamptz the
 * server prints are in it, as in the Go and Python sessions.
 */
#[AsMiddleware]
final class SessionTimeZoneMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);
                $connection->exec('SET TIME ZONE '.$connection->quote(Clock::zone()->getName()));

                return $connection;
            }
        };
    }
}
