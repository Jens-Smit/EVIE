<?php

/**
 * P0-1 RAG-Regressionstest: fuehrt findSimilar()-SQL gegen echte
 * PostgreSQL+pgvector-Instanz aus. Verhindert Cast-Regressions,
 * die SQLite-Tests nicht erkennen.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;

$conn = DriverManager::getConnection(['url' => getenv('DATABASE_URL')]);

// Testdaten einfuegen
$conn->executeStatement(
    'INSERT INTO embeddings (content_hash, content, content_type, metadata, vector, created_at) '
    . 'VALUES (:hash, :content, :type, :meta, :vec, NOW()) ON CONFLICT DO NOTHING',
    [
        'hash' => 'rag_regression_test',
        'content' => 'Regression test content',
        'type' => 'knowledge',
        'meta' => '{}',
        'vec' => '[0.1,0.2,0.3]',
    ]
);

// findSimilar()-SQL ausfuehren (derselbe SQL-Pfad wie EmbeddingRepository::findSimilarPgVector)
$rows = $conn->executeQuery(
    'SELECT id, 1 - (vector::text::vector <=> :query::vector) AS similarity '
    . 'FROM embeddings '
    . 'WHERE content_type = :type '
    . 'AND 1 - (vector::text::vector <=> :query::vector) >= :minSimilarity '
    . 'ORDER BY vector::text::vector <=> :query::vector ASC '
    . 'LIMIT :limit',
    [
        'query' => '[0.1,0.2,0.3]',
        'type' => 'knowledge',
        'minSimilarity' => 0.5,
        'limit' => 5,
    ]
)->fetchAllAssociative();

if (count($rows) === 0) {
    echo "FAIL: findSimilar returned no results against real PostgreSQL+pgvector\n";
    exit(1);
}

echo "PASS: findSimilar returned " . count($rows) . " result(s) against real PostgreSQL+pgvector\n";
