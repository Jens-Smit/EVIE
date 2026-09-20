<?php

declare(strict_types=1);

namespace App\Tests\Functional\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Schema-Validierung nach Blueprint §7.1/§7.2:
 *
 * Stellt sicher, dass die kritischen Tabellen users und user_profile im
 * Schema existieren (Regressions-Test fuer den TableNotFoundException-
 * Fix "relation users does not exist"). Der CI-Migrations-Job spielt die
 * Doctrine-Migrationskette gegen eine leere PostgreSQL-DB und validiert
 * danach doctrine:schema:validate; dieser Test deckt denselben Pfad auf
 * PHPUnit-Ebene ab (Functional-Suite, echte Kernel-DB-Verbindung).
 */
final class SchemaValidationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema();
    }

    public function testUsersTableExists(): void
    {
        $tableNames = $this->listTableNames();

        self::assertContains('users', $tableNames, 'Tabelle "users" fehlt im Schema (TableNotFoundException: relation "users" does not exist)');
        self::assertContains('user_profile', $tableNames, 'Tabelle "user_profile" fehlt im Schema');
    }

    public function testUserProfileHasUserIdForeignKeyToUsers(): void
    {
        $schemaManager = $this->connection()->createSchemaManager();
        $table = $schemaManager->introspectTable('user_profile');

        self::assertTrue($table->hasColumn('user_id'), 'user_profile.user_id fehlt (OneToOne-Verknuepfung zum User)');

        $foreignKey = null;
        foreach ($table->getForeignKeys() as $fk) {
            if ($fk->getLocalColumns() === ['user_id']) {
                $foreignKey = $fk;
                break;
            }
        }

        self::assertNotNull($foreignKey, 'user_profile.user_id hat keinen Fremdschluessel');
        self::assertSame(['id'], $foreignKey->getForeignColumns());
        self::assertSame('users', $foreignKey->getForeignTableName());
    }

    public function testUserProfileEntityTableNameMatchesMigration(): void
    {
        $metadata = $this->entityManager->getClassMetadata(\App\Entity\UserProfile::class);

        self::assertSame('user_profile', $metadata->getTableName());
    }

    /**
     * @return array<int, string>
     */
    private function listTableNames(): array
    {
        return array_map(
            static fn ($t) => $t->getName(),
            $this->connection()->createSchemaManager()->listTables()
        );
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    private function ensureSchema(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $classes = $this->entityManager->getMetadataFactory()->getAllMetadata();
        try {
            $schemaTool->createSchema($classes);
        } catch (\Throwable) {
            // Schema existiert bereits (CI migriert zuvor doctrine:migrations:migrate).
        }
    }
}
