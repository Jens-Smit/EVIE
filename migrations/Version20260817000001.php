<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-3: Placeholder-Migration. Ein echter HNSW-Index auf einem
 * pgvector vector(N)-Typ erfordert einen Custom-DBAL-Platform-Support,
 * der Doctrine DBAL die vector-Spalte als bekannten Typ bekannt macht.
 * Bis dieser implementiert ist, nutzt P1-A den JSON+Cast-Pfad.
 * Diese Migration ist ein No-Op (beweist, dass die Kette laeuft).
 */
final class Version20260817000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P2-3: HNSW-Index Placeholder (erfordert Custom-DBAL-Platform-Support)';
    }

    public function up(Schema $schema): void
    {
        // No-Op: HNSW-Index erfordert vector(N)-Spalte + Custom-DBAL-Platform.
        // P1-A nutzt JSON+Cast-Pfad. Follow-up: Custom-Platform fuer vector-Typ.
    }

    public function down(Schema $schema): void
    {
    }
}
