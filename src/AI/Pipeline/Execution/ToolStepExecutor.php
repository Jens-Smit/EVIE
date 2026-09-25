<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Execution;

use App\AI\Skills\Tool\DynamicToolExecutor;
use App\AI\Skills\Tool\DynamicToolFactory;
use App\AI\Skills\Tool\ToolRegistry;
use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use App\AI\Pipeline\Plan\Step;
use App\AI\Pipeline\PipelineContext;
use Psr\Log\LoggerInterface;

/**
 * Phase 5: Fuehrt type=tool-Schritte deterministisch aus.
 *
 * Reihenfolge (Blueprint §4.A Phase 4/5):
 *  1. ToolInterface-Tool aus der ToolRegistry (statische EVIE-Tools;
 *     native #[AsTool]-Tools werden ueber den AttributeToolAdapter
 *     ausgefuehrt)
 *  2. Freigegebene ToolDefinition (dynamische Tools) via
 *     DynamicToolFactory + DynamicToolExecutor
 *
 * Keine Konstruktor-Injection einzelner Tools; aufgeloest wird zur
 * Laufzeit ueber ToolRegistry bzw. ToolDefinitionRepository. Das
 * Ergebnis des Schritts wird als String/Array zurueckgegeben und vom
 * ExecutionCoordinator im ExecutionState abgelegt.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 5
 */
final class ToolStepExecutor implements StepExecutorInterface
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly ToolDefinitionRepository $toolDefinitionRepository,
        private readonly DynamicToolFactory $dynamicToolFactory,
        private readonly DynamicToolExecutor $dynamicToolExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Step $step): bool
    {
        return $step->getType() === Step::TYPE_TOOL;
    }

    public function execute(Step $step, PipelineContext $context, ExecutionState $state): mixed
    {
        $name = $step->getTarget();
        $parameters = $this->mergeInputs($step, $state);

        $this->logger->info('ToolStepExecutor: Fuehre Tool-Schritt aus', [
            'step_id' => $step->getId(),
            'tool' => $name,
            'parameters' => $parameters,
        ]);

        if ($this->toolRegistry->has($name)) {
            $tool = $this->toolRegistry->get($name);
            $result = $tool($parameters);

            return is_array($result) ? $result : (string) $result;
        }

        $definition = $this->toolDefinitionRepository->findOneByNameForUser($name, $context->getUserIdentifier());
        if ($definition instanceof ToolDefinition) {
            if ($definition->getStatus() !== 'approved') {
                throw new \RuntimeException(sprintf(
                    'Tool "%s" existiert als dynamisches Werkzeug, ist aber noch nicht freigegeben (Status: %s). '
                    . 'Bitte zuerst die Freigabe im Tool-Approval vornehmen.',
                    $name,
                    $definition->getStatus()
                ));
            }

            $dynamicTool = $this->dynamicToolFactory->createAndRegisterTool($definition);
            $result = $this->dynamicToolExecutor->execute($dynamicTool, $parameters);
            if (!$result->isSuccess()) {
                throw new \RuntimeException(sprintf(
                    'Tool "%s" (Schritt "%s") ist fehlgeschlagen: %s',
                    $name,
                    $step->getId(),
                    $result->getErrorMessage() ?? 'unbekannter Fehler'
                ));
            }

            return $result->getResult();
        }

        throw new \RuntimeException(sprintf(
            'Tool "%s" fuer Schritt "%s" konnte nicht aufgeloest werden.',
            $name,
            $step->getId()
        ));
    }

    /**
     * Mischt die geplanten Parameter mit den Ergebnissen der in
     * input_from referenzierten Schritte; input_from-Ergebnisse werden
     * unter 'input_from' als Key uebergeben, damit Tool-Parameter nicht
     * kollidieren.
     *
     * @return array<string, mixed>
     */
    private function mergeInputs(Step $step, ExecutionState $state): array
    {
        $parameters = $step->getParameters();
        $inputs = $state->collect($step->getInputFrom());
        if ($inputs !== []) {
            $parameters['input_from'] = $inputs;
        }

        return $parameters;
    }
}