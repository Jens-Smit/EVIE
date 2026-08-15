<?php

namespace App\AI\Skills\Tool;

use App\AI\Skills\Executor\ExecutorResolverInterface;
use Psr\Log\LoggerInterface;
use App\AI\Skills\Tool\ToolExecutionResult;

/**
 * DynamicToolExecutor - Nutzt ExecutorResolver statt hardcoded Logik
 * Schema-Typ-Konflikt behoben: Trennung von JSON Schema und Execution Metadata
 * Hardcoded Demo-Ergebnisse entfernt
 */
class DynamicToolExecutor
{
    public function __construct(
        private ExecutorResolverInterface $executorResolver,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Führt ein DynamicTool aus
     */
    public function execute(DynamicTool $tool, array $parameters = []): ToolExecutionResult
    {
        $executorType = $tool->getExecutorType();
        
        if (!$executorType) {
            $this->logger->error('Tool hat keinen Executor-Type definiert', [
                'tool_name' => $tool->getName()
            ]);
            
            return new ToolExecutionResult(
                $tool->getName(),
                false,
                'Fehler: Kein Executor-Type definiert für dieses Tool',
                null
            );
        }

        try {
            $executor = $this->executorResolver->resolve($executorType);
            $result = $executor->execute($tool, $parameters);

            $this->logger->info('Tool erfolgreich ausgeführt', [
                'tool_name' => $tool->getName(),
                'executor_type' => $executorType,
                'version' => $tool->getVersion()
            ]);

            return new ToolExecutionResult(
                $tool->getName(),
                true,
                null,
                $result
            );

        } catch (\Exception $e) {
            $this->logger->error('Fehler bei Tool-Ausführung', [
                'tool_name' => $tool->getName(),
                'executor_type' => $executorType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new ToolExecutionResult(
                $tool->getName(),
                false,
                'Fehler: ' . $e->getMessage(),
                null
            );
        }
    }

    /**
     * Prüft ob ein Tool ausführbar ist
     */
    public function isExecutable(DynamicTool $tool): bool
    {
        $executorType = $tool->getExecutorType();
        return $executorType && $this->executorResolver->supports($executorType);
    }

    /**
     * Gibt unterstützte Executor-Typen zurück
     */
    public function getSupportedExecutorTypes(): array
    {
        return ['api', 'database', 'filesystem', 'http', 'generic'];
    }
}