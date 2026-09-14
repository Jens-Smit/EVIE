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

        // H-5: Validierung, dass der Query-Typ zum tatsächlichen SQL-Statement
        // passt. Verhindert, dass ein als 'select' deklarierter Tool ein
        // DELETE/DROP/ALTER ausfuehrt (Audit-Hardening der Tool-Konfiguration).
        $this->assertQueryTypeMatches($type, $query);

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

    /**
     * H-5: Validiert, dass das erste SQL-Schluesselwort zum konfigurierten
     * Query-Typ passt. Dies ist eine Audit-Hardening-Massnahme, die
     * verhindert, dass ein konfigurierter Tool-Typ ('select') ein
     * destruktives Statement (DELETE, DROP, ALTER, TRUNCATE, etc.) tarnt.
     *
     * Die Pruefung ist absichtlich pragmatisch (Prefix-Matching auf das
     * normalisierte erste Token) und ersetzt nicht ein DB-Nutzer-Principle-
     * of-Least-Privilege; sie erweitert die Auditierbarkeit der
     * Tool-Konfiguration.
     */
    private function assertQueryTypeMatches(string $type, string $query): void
    {
        $trimmed = ltrim($query);
        $firstWord = strtoupper(preg_split('/\s+/', $trimmed, 2)[0] ?? '');

        $allowedByType = [
            'select' => ['SELECT', 'WITH'],
            'insert' => ['INSERT'],
            'update' => ['UPDATE'],
            'delete' => ['DELETE'],
        ];

        if (!isset($allowedByType[$type])) {
            // Unbekannter Typ wird vom switch-Block spaeter abgelehnt.
            return;
        }

        if (!in_array($firstWord, $allowedByType[$type], true)) {
            throw new RuntimeException(sprintf(
                'Database-Executor: Query-Typ "%s" erwartet ein Statement, das mit einem der Schluesselwoerter [%s] beginnt, gefunden wurde "%s".',
                $type,
                implode(', ', $allowedByType[$type]),
                $firstWord
            ));
        }
    }

    public function getType(): string
    {
        return 'database';
    }
}
