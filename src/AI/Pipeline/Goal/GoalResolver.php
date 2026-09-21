<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Goal;

use App\AI\Pipeline\PipelineContext;
use App\Repository\AgentGoalRepository;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Phase 1: Liefert das uebergeordnete Ziel eines Pipeline-Laufs.
 *
 * Quellen-Reihenfolge (erste Treffer gewinnt):
 *  1. Aktives, freigegebenes AgentGoal (status active, isApproved true)
 *     fuer den userIdentifier (autonome Ziele via RunAgentGoalMessage).
 *  2. Ad-hoc-Ziel aus der Nachricht via PlatformInterface::invoke.
 *
 * Keine Tool-Generierung, kein HITL. Bei LLM-Ausfall liefert der
 * Resolver ein generisches ad-hoc Goal, damit die Pipeline fortgesetzt
 * wird und aus Fehlern keine ungewollten Tools entstehen.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 1
 */
final class GoalResolver implements GoalResolverInterface
{
    private AgentGoalRepository $goalRepository;
    private PlatformInterface $platform;
    private LoggerInterface $logger;

    public function __construct(
        AgentGoalRepository $goalRepository,
        PlatformInterface $platform,
        LoggerInterface $logger
    ) {
        $this->goalRepository = $goalRepository;
        $this->platform = $platform;
        $this->logger = $logger;
    }

    public function resolve(PipelineContext $context): Goal
    {
        $activeGoals = $this->goalRepository->findActiveByUser($context->getUserIdentifier());
        if (!empty($activeGoals)) {
            $goal = $activeGoals[0];
            $identifier = 'goal-' . ($goal->getId() ?? 'unsaved');
            $description = $goal->getTitle();
            $successMetric = $goal->getSuccessMetric();

            $this->logger->info('GoalResolver: aktives AgentGoal verwendet', [
                'goal_id' => $goal->getId(),
                'user_identifier' => $context->getUserIdentifier(),
            ]);

            return new Goal($identifier, $description, $successMetric, Goal::SOURCE_AGENT_GOAL);
        }

        return $this->resolveAdHoc($context);
    }

    /**
     * Leitet ein ad-hoc-Ziel aus der Nachricht ab. Scheitert der LLM-Abruf,
     * wird ein generisches Goal zurueckgegeben, damit die Pipeline
     * fortgesetzt wird, ohne eine Capability zu generieren.
     */
    private function resolveAdHoc(PipelineContext $context): Goal
    {
        try {
            $prompt = $this->buildAdHocPrompt($context->getMessage());
            $messages = new MessageBag(Message::ofUser($prompt));
            $description = trim($this->platform->invoke('mistral-small-latest', $messages)->asText());

            if ($description === '') {
                $description = $context->getMessage();
            }
        } catch (\Exception $e) {
            $this->logger->warning('GoalResolver: ad-hoc-LLM fehlgeschlagen, verwende Nachricht als Ziel: ' . $e->getMessage());
            $description = $context->getMessage();
        }

        $this->logger->info('GoalResolver: ad-hoc-Ziel aufgeloest', [
            'goal_description' => $description,
            'source' => Goal::SOURCE_AD_HOC,
        ]);

        return new Goal(
            'ad-hoc-' . substr(sha1($context->getMessage()), 0, 8),
            $description,
            null,
            Goal::SOURCE_AD_HOC
        );
    }

    private function buildAdHocPrompt(string $userMessage): string
    {
        return "Fasse das uebergeordnete Ziel der folgenden Anfrage in einem kurzen Satz zusammen. "
            . "Antworte ausschliesslich mit diesem Satz, ohne Erklaerung.\n\n"
            . 'Anfrage: "' . $userMessage . '"';
    }
}
