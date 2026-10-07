<?php

declare(strict_types=1);

namespace App\Tests\Schema;

use App\Tests\Support\Db;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The ORM model covers the migrated schema exactly: one entity per table, every
 * column mapped (fields and join columns) with matching nullability and a
 * compatible type. The migrations, not the ORM, are the schema authority; this
 * keeps the entities honest against them.
 */
final class OrmMappingTest extends KernelTestCase
{
    private const TYPE_MAP = [
        'uuid' => ['uuid'], 'text' => ['text'], 'timestamptz' => ['timestamp with time zone'],
        'integer' => ['integer'], 'smallint' => ['smallint'], 'bigint' => ['bigint'], 'boolean' => ['boolean'],
        'jsonb' => ['jsonb'], 'jsonb_map' => ['jsonb'], 'date_immutable' => ['date'], 'decimal' => ['numeric'],
    ];

    public function testEveryTableAndColumnIsMappedConsistently(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $db = [];
        foreach (Db::owner()->fetchAllAssociative("SELECT table_name, column_name, is_nullable, data_type FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name <> 'doctrine_migration_versions'") as $r) {
            $db[$r['table_name']][$r['column_name']] = ['nullable' => 'YES' === $r['is_nullable'], 'type' => $r['data_type']];
        }

        $mapped = [];
        $problems = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
            /** @var ClassMetadata<object> $meta */
            $table = $meta->getTableName();
            $mapped[$table] = true;
            $columns = [];
            foreach ($meta->getFieldNames() as $field) {
                $m = $meta->getFieldMapping($field);
                $columns[$m->columnName] = ['nullable' => (bool) $m->nullable, 'type' => $m->type];
            }
            foreach ($meta->getAssociationMappings() as $assoc) {
                if ($assoc->isToOneOwningSide()) {
                    foreach ($assoc->joinColumns as $jc) {
                        $columns[$jc->name] = ['nullable' => (bool) ($jc->nullable ?? true), 'type' => 'uuid'];
                    }
                }
            }
            foreach ($db[$table] ?? [] as $column => $info) {
                if (!isset($columns[$column])) {
                    $problems[] = "$table.$column is not mapped";
                    continue;
                }
                if ($columns[$column]['nullable'] !== $info['nullable']) {
                    $problems[] = "$table.$column nullability differs";
                }
                if (!\in_array($info['type'], self::TYPE_MAP[$columns[$column]['type']] ?? [], true)) {
                    $problems[] = "$table.$column type {$columns[$column]['type']} vs {$info['type']}";
                }
            }
            foreach (array_diff_key($columns, $db[$table] ?? []) as $column => $_) {
                $problems[] = "$table.$column is mapped but does not exist";
            }
        }
        self::assertSame([], array_keys(array_diff_key($db, $mapped)), 'Tables without an entity');
        self::assertSame([], $problems);
        self::assertCount(48, $mapped);
    }
}
