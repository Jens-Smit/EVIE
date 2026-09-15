<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aktiviert die pgvector-Erweiterung in der PostgreSQL-Datenbank.
 *
 * EmbeddingRepository::findSimilarPgVector() castet die JSON-gespeicherten
 * Vektoren per ::text::vector in den pgvector-Typ und nutzt den Cosine-
 * Distanz-Operator <=>. Diese Typen/Operatoren existieren erst, nachdem die
 * Erweiterung 'vector' in der Datenbank aktiviert wurde. Ohne diesen Schritt
 * schlaegt jeder RAG-Such-Aufruf mit
 *   SQLSTATE[42704]: Undefined object: 7 ERROR: type "vector" does not exist
 * fehl, weil das pgvector-Docker-Image die Erweiterung zwar binaer mitliefert,
 * sie aber nicht automatisch aktiviert.
 *
 * CREATE EXTENSION ist idempotent (IF NOT EXISTS) und sicher fuer bereits
 * eingerichtete Umgebungen. Der Aufruf wird auf PostgreSQL beschraenkt,
 * damit SQLite-basierte Runs (z. B. lokale In-Memory-Fallbacks) nicht
 * abbrechen.
 */
final class Version20260903160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aktiviere pgvector-Erweiterung (CREATE EXTENSION IF NOT EXISTS vector)';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (method_exists($platform, 'getName') && $platform->getName() === 'postgresql') {
            $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (method_exists($platform, 'getName') && $platform->getName() === 'postgresql') {
            $this->addSql('DROP EXTENSION IF EXISTS vector');
        }
    }
}
