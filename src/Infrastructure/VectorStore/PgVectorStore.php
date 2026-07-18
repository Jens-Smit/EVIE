<?php

namespace App\Infrastructure\VectorStore;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(tags: ['ai.vector_store'])]
class PgVectorStore implements VectorStoreInterface
{
    private Connection $connection;
    private string $tableName = 'vector_embeddings';

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->initializeTable();
    }

    /**
     * Initializes the vector embeddings table if it doesn't exist.
     */
    private function initializeTable(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        
        if (!$schemaManager->tablesExist([$this->tableName])) {
            $schemaManager->createTable($this->createTableSchema());
        }
    }

    /**
     * Creates the schema for the vector embeddings table.
     */
    private function createTableSchema(): \Doctrine\DBAL\Schema\Table
    {
        $table = new \Doctrine\DBAL\Schema\Table($this->tableName);
        
        $table->addColumn('id', 'string', ['length' => 255]);
        $table->addColumn('embedding', 'json');
        $table->addColumn('metadata', 'json');
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addColumn('updated_at', 'datetime_immutable', ['notnull' => false]);
        
        $table->setPrimaryKey(['id']);
        
        return $table;
    }

    /**
     * {@inheritdoc}
     */
    public function storeEmbedding(string $identifier, array $embedding, array $metadata = []): void
    {
        $now = new \DateTimeImmutable();
        
        $this->connection->insert($this->tableName, [
            'id' => $identifier,
            'embedding' => json_encode($embedding),
            'metadata' => json_encode($metadata),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function findSimilar(array $queryEmbedding, int $limit = 5): array
    {
        // This is a simplified version. In a real implementation with pgvector,
        // you would use the vector similarity functions provided by the extension.
        
        $queryBuilder = $this->connection->createQueryBuilder();
        $queryBuilder
            ->select('id', 'embedding', 'metadata')
            ->from($this->tableName)
            ->orderBy('RAND()') // Placeholder for actual vector similarity
            ->setMaxResults($limit);
        
        $results = $queryBuilder->executeQuery()->fetchAllAssociative();
        
        return array_map(function ($row) {
            return [
                'id' => $row['id'],
                'embedding' => json_decode($row['embedding'], true),
                'metadata' => json_decode($row['metadata'], true),
            ];
        }, $results);
    }

    /**
     * {@inheritdoc}
     */
    public function getEmbedding(string $identifier): ?array
    {
        $queryBuilder = $this->connection->createQueryBuilder();
        $queryBuilder
            ->select('embedding', 'metadata')
            ->from($this->tableName)
            ->where('id = :id')
            ->setParameter('id', $identifier);
        
        $result = $queryBuilder->executeQuery()->fetchAssociative();
        
        if (!$result) {
            return null;
        }
        
        return [
            'embedding' => json_decode($result['embedding'], true),
            'metadata' => json_decode($result['metadata'], true),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function updateEmbedding(string $identifier, array $newEmbedding, array $newMetadata = []): void
    {
        $now = new \DateTimeImmutable();
        
        $this->connection->update($this->tableName, [
            'embedding' => json_encode($newEmbedding),
            'metadata' => json_encode($newMetadata),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ], ['id' => $identifier]);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteEmbedding(string $identifier): void
    {
        $this->connection->delete($this->tableName, ['id' => $identifier]);
    }
}
