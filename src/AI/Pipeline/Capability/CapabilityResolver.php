<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Capability;

use App\AI\Agent\SubAgentFactoryInterface;
use App\AI\Pipeline\Plan\Step;
use App\AI\Pipeline\PipelineContext;
use App\AI\Skills\Tool\ToolRegistry;
use App\AI\Skills\ToolDefinitionGenerator;
use App\Entity\ToolDefinition;
use App\Event\PendingToolApprovalEvent;
use App\Repository\ToolDefinitionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Phase 4: Loest pro Schritt, ob die benoetigte Faehigkeit vorhanden ist.
 *
 * Lookup-Reihenfolge pro Step-Typ:
 *  - tool:    statisches Tool (ToolRegistry) -> Available; sonst
 *             ToolDefinition (status approved/pending) via Repository ->
 *             Available/Pending; sonst Missing (ToolDefinitionGenerator + HITL).
 *  - subagent: SubAgentFactoryInterface::getAvailableSubAgents -> Available;
 *             sonst Missing (keine Generierung fuer Sub-Agenten).
 *  - clarify: direkt Available (keine Faehigkeit noetig).
 *
 * Capability Discovery/Generierung findet ausschliesslich hier statt. Keine
 * Konstruktor-Injection fuer Tools; verfuegbare Faehigkeiten werden zur
 * Laufzeit gelesen. Bei Missing/Pending wird ein
 * PendingToolApprovalEvent ausgeloest (bestehendes HITL, Blueprint §5).
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 4
 */
final class CapabilityResolver implements CapabilityResolverInterface
{
    private ToolRegistry $toolRegistry;
    private ToolDefinitionRepository $toolDefinitionRepo;
    private SubAgentFactoryInterface $subAgentFactory;
    private ToolDefinitionGenerator $toolGenerator;
    private EventDispatcherInterface $dispatcher;
    private LoggerInterface $logger;

    public function __construct(
        ToolRegistry $toolRegistry,
        ToolDefinitionRepository $toolDefinitionRepo,
        SubAgentFactoryInterface $subAgentFactory,
        ToolDefinitionGenerator $toolGenerator,
        EventDispatcherInterface $dispatcher,
        LoggerInterface $logger
    ) {
        $this->toolRegistry = $toolRegistry;
        $this->toolDefinitionRepo = $toolDefinitionRepo;
        $this->subAgentFactory = $subAgentFactory;
        $this->toolGenerator = $toolGenerator;
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
    }

    public function resolve(Step $step, PipelineContext $context): CapabilityResult
    {
        if ($step->getType() === Step::TYPE_CLARIFY) {
            return new CapabilityResult(CapabilityDecision::Available);
        }

        if ($step->getType() === Step::TYPE_SUBAGENT) {
            return $this->resolveSubAgent($step);
        }

        return $this->resolveTool($step, $context);
    }

    private function resolveSubAgent(Step $step): CapabilityResult
    {
        $subAgents = $this->subAgentFactory->getAvailableSubAgents();
        $name = $step->getTarget();
        if (isset($subAgents[$name])) {
            $this->logger->debug('CapabilityResolver: Sub-Agent vorhanden', ['subagent' => $name]);

            return new CapabilityResult(CapabilityDecision::Available, $subAgents[$name]);
        }

        $this->logger->info('CapabilityResolver: Sub-Agent fehlt', ['subagent' => $name]);

        return new CapabilityResult(CapabilityDecision::Missing);
    }

    private function resolveTool(Step $step, PipelineContext $context): CapabilityResult
    {
        $name = $step->getTarget();

        if ($this->toolRegistry->has($name)) {
            $this->logger->debug('CapabilityResolver: statisches Tool vorhanden', ['tool' => $name]);

            return new CapabilityResult(CapabilityDecision::Available, $this->toolRegistry->get($name));
        }

        $definition = $this->toolDefinitionRepo->findOneByNameForUser($name, $context->getUserIdentifier());
        if ($definition !== null) {
            return $this->resultFromExistingDefinition($definition);
        }

        if (!$step->needsCapability()) {
            // Der Planner hat die Faehigkeit als vorhanden markiert, aber sie
            // existiert nicht. Konservativ als Missing melden (kein Erfinden).
            $this->logger->info('CapabilityResolver: Tool nicht gefunden, kein needs_capability-Flag', ['tool' => $name]);

            return new CapabilityResult(CapabilityDecision::Missing);
        }

        return $this->generateMissingCapability($step, $context);
    }

    private function resultFromExistingDefinition(ToolDefinition $definition): CapabilityResult
    {
        $status = $definition->getStatus();
        if ($status === 'approved') {
            $this->logger->debug('CapabilityResolver: dynamisches Tool approved', ['tool' => $definition->getName()]);

            return new CapabilityResult(CapabilityDecision::Available, $definition, $definition->getId());
        }

        // pending/pending_approval -> wartet auf Freigabe.
        $this->logger->info('CapabilityResolver: Tool wartet auf Freigabe', [
            'tool' => $definition->getName(),
            'status' => $status,
        ]);

        return new CapabilityResult(CapabilityDecision::Pending, null, $definition->getId());
    }

    private function generateMissingCapability(Step $step, PipelineContext $context): CapabilityResult
    {
        $name = $step->getTarget();
        $description = $step->getReason() ?? $step->getTarget();

        $this->logger->info('CapabilityResolver: generiere fehlende Faehigkeit', [
            'tool' => $name,
            'user' => $context->getUserIdentifier(),
        ]);

        try {
            $definition = $this->toolGenerator->generateToolDefinition(
                $name,
                $description,
                [
                    'user_identifier' => $context->getUserIdentifier(),
                    'original_request' => $context->getMessage(),
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error('CapabilityResolver: Tool-Generierung fehlgeschlagen: ' . $e->getMessage());

            return new CapabilityResult(CapabilityDecision::Missing);
        }

        $this->dispatcher->dispatch(new PendingToolApprovalEvent($definition, $context->getUserIdentifier()));

        return new CapabilityResult(CapabilityDecision::Pending, null, $definition->getId());
    }
}
