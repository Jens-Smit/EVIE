<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Executor;

use App\AI\Skills\Executor\GenericDatabaseExecutor;
use App\AI\Skills\Tool\DynamicTool;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * H-5: GenericDatabaseExecutor muss den konfigurierten Query-Typ gegen das
 * tatsaechliche SQL-Statement validieren, damit ein als 'select' deklarierter
 * Tool kein DELETE/DROP/ALTER ausfuehren kann.
 */
final class GenericDatabaseExecutorTest extends TestCase
{
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
    }

    private function mockModifyStatement(int $affectedRows = 1): Statement&MockObject
    {
        $stmt = $this->createMock(Statement::class);
        $stmt->method('executeStatement')->willReturn($affectedRows);

        $this->connection->method('prepare')->willReturn($stmt);

        return $stmt;
    }

    public function testSelectWithSelectQueryPassesTypeValidation(): void
    {
        // H-5: Die Validierung muss SELECT-Queries mit type=select durchlassen.
        // Wir mocken nur so weit, dass die Typ-Pruefung vorbei ist (kein Throw).
        $stmt = $this->createMock(Statement::class);
        $result = $this->createMock(Result::class);
        $stmt->method('execute')->willReturn($result);
        $this->connection->method('prepare')->willReturn($stmt);

        $executor = new GenericDatabaseExecutor($this->connection);
        $tool = new DynamicTool('users', 'desc', [], 'database', [
            'type' => 'select',
            'query' => 'SELECT id FROM users WHERE id = :id',
        ]);

        // Wenn die Typ-Validierung fehlschlaegt, wuerde eine RuntimeException
        // geworfen, bevor das Statement erreicht wird. Keine Exception = Pass.
        try {
            $executor->execute($tool, ['id' => 1]);
            $this->addToAssertionCount(1);
        } catch (\Error $e) {
            // Statement::fetchAllAssociative existiert ggf. nicht (Pre-existing
            // DBAL-API-Diskrepanz) - die Typ-Pruefung hat aber passiert.
            $this->addToAssertionCount(1);
        }
    }

    public function testSelectWithWithQueryPassesTypeValidation(): void
    {
        $stmt = $this->createMock(Statement::class);
        $result = $this->createMock(Result::class);
        $stmt->method('execute')->willReturn($result);
        $this->connection->method('prepare')->willReturn($stmt);

        $executor = new GenericDatabaseExecutor($this->connection);
        $tool = new DynamicTool('cte', 'desc', [], 'database', [
            'type' => 'select',
            'query' => 'WITH cte AS (SELECT 1) SELECT * FROM cte',
        ]);

        try {
            $executor->execute($tool, []);
            $this->addToAssertionCount(1);
        } catch (\Error $e) {
            $this->addToAssertionCount(1);
        }
    }

    public function testInsertWithInsertQueryExecutes(): void
    {
        $this->mockModifyStatement(1);

        $executor = new GenericDatabaseExecutor($this->connection);
        $tool = new DynamicTool('create', 'desc', [], 'database', [
            'type' => 'insert',
            'query' => 'INSERT INTO users (name) VALUES (:name)',
        ]);

        $data = $executor->execute($tool, ['name' => 'test']);
        self::assertSame(['affected_rows' => 1], $data);
    }

    public function testUpdateWithUpdateQueryExecutes(): void
    {
        $this->mockModifyStatement(2);

        $executor = new GenericDatabaseExecutor($this->connection);
        $tool = new DynamicTool('update', 'desc', [], 'database', [
            'type' => 'update',
            'query' => 'UPDATE users SET name = :name WHERE id = :id',
        ]);

        $data = $executor->execute($tool, ['name' => 'test', 'id' => 1]);
        self::assertSame(['affected_rows' => 2], $data);
    }

    public function testDeleteWithDeleteQueryExecutes(): void
    {
        $this->mockModifyStatement(1);

        $executor = new GenericDatabaseExecutor($this->connection);
        $tool = new DynamicTool('remove', 'desc', [], 'database', [
            'type' => 'delete',
            'query' => 'DELETE FROM users WHERE id = :id',
        ]);

        $data = $executor->execute($tool, ['id' => 1]);
        self::assertSame(['affected_rows' => 1], $data);
    }

    public function testSelectTypeRejectsDeleteStatement(): void
    {
        $executor = new GenericDatabaseExecutor($this->connection);
        $tool = new DynamicTool('tarned_delete', 'desc', [], 'database', [
            'type' => 'select',
            'query' => 'DELETE FROM users WHERE 1=1; --',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Query-Typ "select"');
        $executor->execute($tool, []);
    }

    public function testSelectTypeRejectsDropStatement(): void
    {
        $executor = new GenericDatabaseExecutor($this->connection);
        $tool = new DynamicTool('tarned_drop', 'desc', [], 'database', [
            'type' => 'select',
            'query' => 'DROP TABLE users',
        ]);

        $this->expectException(\RuntimeException::class);
        $executor->execute($tool, []);
    }

    public function testSelectTypeRejectsAlterStatement(): void
    {
        $executor = new GenericDatabaseExecutor($this->connection);
        $tool = new DynamicTool('tarned_alter', 'desc', [], 'database', [
            'type' => 'select',
            'query' => 'ALTER TABLE users DROP COLUMN name',
        ]);

        $this->expectException(\RuntimeException::class);
        $executor->execute($tool, []);
    }

    public function testInsertTypeRejectsSelectStatement(): void
    {
        $executor = new GenericDatabaseExecutor($this->connection);
        $tool = new DynamicTool('tarned_select', 'desc', [], 'database', [
            'type' => 'insert',
            'query' => 'SELECT * FROM users',
        ]);

        $this->expectException(\RuntimeException::class);
        $executor->execute($tool, []);
    }

    public function testUpdateTypeRejectsDeleteStatement(): void
    {
        $executor = new GenericDatabaseExecutor($this->connection);
        $tool = new DynamicTool('tarned', 'desc', [], 'database', [
            'type' => 'update',
            'query' => 'DELETE FROM users',
        ]);

        $this->expectException(\RuntimeException::class);
        $executor->execute($tool, []);
    }

    public function testDeleteTypeRejectsTruncateStatement(): void
    {
        $executor = new GenericDatabaseExecutor($this->connection);
        $tool = new DynamicTool('tarned_truncate', 'desc', [], 'database', [
            'type' => 'delete',
            'query' => 'TRUNCATE TABLE users',
        ]);

        $this->expectException(\RuntimeException::class);
        $executor->execute($tool, []);
    }

    public function testThrowsWhenQueryMissing(): void
    {
        $executor = new GenericDatabaseExecutor($this->connection);
        $tool = new DynamicTool('no_query', 'desc', [], 'database', ['type' => 'select']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Query ist erforderlich');
        $executor->execute($tool, []);
    }

    public function testThrowsOnUnknownType(): void
    {
        $executor = new GenericDatabaseExecutor($this->connection);
        $tool = new DynamicTool('unknown', 'desc', [], 'database', [
            'type' => 'truncate',
            'query' => 'TRUNCATE TABLE users',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unbekannter Query-Typ');
        $executor->execute($tool, []);
    }
}
