<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

/**
 * Test-only DBAL middleware (registered for the default connection in the test
 * environment): records every statement with its positional parameters, so tests
 * can count the queries of a dashboard page and EXPLAIN exactly those queries.
 * Static so that it survives kernel reboots between test requests.
 */
final class QueryRecorder implements Middleware
{
    /** @var list<array{sql: string, params: array<int|string, mixed>}> */
    public static array $queries = [];
    public static bool $enabled = false;

    public static function start(): void
    {
        self::$queries = [];
        self::$enabled = true;
    }

    /** @return list<array{sql: string, params: array<int|string, mixed>}> */
    public static function stop(): array
    {
        self::$enabled = false;

        return self::$queries;
    }

    public static function record(string $sql, array $params): void
    {
        if (self::$enabled) {
            self::$queries[] = ['sql' => $sql, 'params' => $params];
        }
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): DriverConnection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        return new class(parent::prepare($sql), $sql) extends AbstractStatementMiddleware {
                            /** @var array<int|string, mixed> */
                            private array $params = [];

                            public function __construct(Statement $statement, private readonly string $sql)
                            {
                                parent::__construct($statement);
                            }

                            public function bindValue(int|string $param, mixed $value, ParameterType $type): void
                            {
                                $this->params[$param] = $value;
                                parent::bindValue($param, $value, $type);
                            }

                            public function execute(): Result
                            {
                                QueryRecorder::record($this->sql, $this->params);

                                return parent::execute();
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        QueryRecorder::record($sql, []);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        QueryRecorder::record($sql, []);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
