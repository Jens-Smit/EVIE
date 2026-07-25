<?php

namespace App\Controller;

use App\AI\Onboarding\ContextStoreManager;
use App\Repository\AgentHistoryRepository;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/agent')]
final class AgentDialogController
{
    public function __construct(
        #[Autowire(service: 'ai.agent.orchestrator')]
        private readonly AgentInterface $agent,
        private readonly ContextStoreManager $contextStore,
        private readonly AgentHistoryRepository $historyRepo,
    ) {
    }

    /**
     * Einzelner, zustandsloser Dialog-Turn mit dem Orchestrator-Agenten.
     */
    #[Route('/dialog', name: 'agent_dialog', methods: ['POST'])]
    public function dialog(Request $request): JsonResponse
    {
        $payload = $request->toArray();
        $userMessage = $payload['message'] ?? null;
        $userIdentifier = $payload['user_identifier'] ?? null;

        if (!$userMessage || !$userIdentifier) {
            return new JsonResponse(
                ['error' => 'Felder "message" und "user_identifier" sind erforderlich.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $systemPrompt = $this->contextStore->getSystemPrompt($userIdentifier);

        $messages = new MessageBag(
            Message::forSystem($systemPrompt),
            Message::ofUser($userMessage),
        );

        $result = $this->agent->call($messages, ['user_identifier' => $userIdentifier]);

        return new JsonResponse([
            'response' => $result->getContent(),
            'token_usage' => $result->getMetadata()->get('token_usage'),
        ]);
    }

    /**
     * Liste bisheriger Interaktionen (Audit-Trail) für einen Nutzer.
     */
    #[Route('/history/{userIdentifier}', name: 'agent_history', methods: ['GET'])]
    public function history(string $userIdentifier): JsonResponse
    {
        $entries = $this->historyRepo->findByUserIdentifier($userIdentifier);

        return new JsonResponse(array_map(
            static fn ($entry) => [
                'agent' => $entry->getAgentName(),
                'status' => $entry->getStatus(),
                'executed_at' => $entry->getExecutedAt()->format(DATE_ATOM),
            ],
            $entries
        ));
    }
}