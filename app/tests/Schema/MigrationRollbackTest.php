<?php

declare(strict_types=1);

namespace App\Tests\Schema;

use App\Tests\Support\Catalog;
use App\Tests\Support\Db;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Migrations from empty, full rollback and re-application on a scratch database
 * (<db>_migrations, owned by smarthost_owner via the infrastructure bootstrap).
 */
final class MigrationRollbackTest extends TestCase
{
    private static function console(string ...$args): Process
    {
        $p = new Process(['php', 'bin/console', ...$args, '--no-interaction'], \dirname(__DIR__, 2),
            ['SMARTHOST_DB_NAME' => Db::databaseName().'_migrations'], null, 300);
        $p->run();

        return $p;
    }

    /** @return list<string> */
    private static function tables(): array
    {
        return Db::owner(Db::databaseName().'_migrations')->fetchFirstColumn(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'doctrine_migration_versions' ORDER BY 1");
    }

    public function testUpDownUpReproducesTheReferenceSchema(): void
    {
        $up = self::console('doctrine:migrations:migrate');
        self::assertTrue($up->isSuccessful(), $up->getErrorOutput().$up->getOutput());
        self::assertCount(49, self::tables());

        $status = self::console('doctrine:migrations:up-to-date');
        self::assertTrue($status->isSuccessful(), $status->getOutput());

        $down = self::console('doctrine:migrations:migrate', 'first');
        self::assertTrue($down->isSuccessful(), $down->getErrorOutput().$down->getOutput());
        self::assertSame([], self::tables(), 'Rolling back every migration must remove every application table.');

        $again = self::console('doctrine:migrations:migrate');
        self::assertTrue($again->isSuccessful(), $again->getErrorOutput().$again->getOutput());
        $reference = Catalog::describe(Db::owner(Db::databaseName().'_reference'));
        self::assertSame([], Catalog::diff($reference, Catalog::describe(Db::owner(Db::databaseName().'_migrations'))));
    }

    public function testEachMigrationRollsBackIndividually(): void
    {
        self::assertTrue(self::console('doctrine:migrations:migrate')->isSuccessful());
        $expected = [49, 48, 48, 39, 31, 30, 30, 29, 25, 25, 24, 24, 19, 17, 9, 5, 0];
        $versions = ['DoctrineMigrations\Version20261012000100', 'DoctrineMigrations\Version20261011000100', 'DoctrineMigrations\Version20261010000100', 'DoctrineMigrations\Version20261009000100', 'DoctrineMigrations\Version20261008000100', 'DoctrineMigrations\Version20261007000200', 'DoctrineMigrations\Version20261007000100', 'DoctrineMigrations\Version20261006000200', 'DoctrineMigrations\Version20261006000100', 'DoctrineMigrations\Version20261005000100', 'DoctrineMigrations\Version20261004000100', 'DoctrineMigrations\Version20261003000500', 'DoctrineMigrations\Version20261003000400',
            'DoctrineMigrations\Version20261003000300', 'DoctrineMigrations\Version20261003000200', 'DoctrineMigrations\Version20261003000100'];
        self::assertCount($expected[0], self::tables());
        foreach ($versions as $i => $version) {
            $p = self::console('doctrine:migrations:execute', $version, '--down');
            self::assertTrue($p->isSuccessful(), $p->getErrorOutput());
            self::assertCount($expected[$i + 1], self::tables(), "after rolling back $version");
        }
        self::assertTrue(self::console('doctrine:migrations:migrate')->isSuccessful());
        self::assertCount(49, self::tables());
    }
}
