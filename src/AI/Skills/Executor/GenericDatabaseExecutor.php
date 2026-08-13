<?php

namespace App\AI\Skills\Executor;

use App\AI\Skills\Tool\DynamicTool;
use Doctrine\DBAL\Connection;
use RuntimeException;

class GenericDatabaseExecutor implements ExecutorInterface
{
    public function __construct(
        private Connection $connection
    ) {
    }

    public function execute(DynamicTool $tool, array $parameters): mixed
    {
        $config = $tool->getExecutorConfig();
        $query = $config['query'] ?? null;
        $type = $config['type'] ?? 'select';

        if (!$query) {
            throw new RuntimeException('Database-Executor: Query ist erforderlich');
        }

        switch ($type) {
            case 'select':
                $stmt = $this->connection->prepare($query);
                $stmt->execute($parameters);
                return $stmt->fetchAllAssociative();

            case 'insert':
            case 'update':
            case 'delete':
                $stmt = $this->connection->prepare($query);
                $result = $stmt->executeStatement($parameters);
                return ['affected_rows' => $result];

            default:
                throw new RuntimeException("Unbekannter Query-Typ: {$type}");
        }
    }

    public function getType(): string
    {
        return 'database';
    }
}
