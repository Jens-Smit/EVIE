<?php
namespace App\Controller;

use App\AI\Agent\OrchestratorDialogService;
use App\AI\Onboarding\ContextStoreManager;
use App\Entity\AgentHistory;
use App\Entity\UserProfile;
use App\Repository\AgentHistoryRepository;
use App\Repository\UserProfileRepository;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

#[Route('/api/agent')]
final class AgentDialogController
{
    public function __construct(
        #[Autowire(service: 'ai.agent.orchestrator')]
        private readonly AgentInterface $agent,
        private readonly OrchestratorDialogService $orchestratorDialogService,
        private readonly ContextStoreManager $contextStore,
        private readonly AgentHistoryRepository $historyRepo,
        private readonly UserProfileRepository $userProfileRepo,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Einzelner, zustandsloser Dialog-Turn mit dem Orchestrator-Agenten.
     * Speichert die Interaktion in der Datenbank.
     * Unterstützt JSON, FormData und URL-encoded Anfragen.
     */
    #[Route('/dialog', name: 'agent_dialog', methods: ['POST'])]
    public function dialog(Request $request): JsonResponse
    {
        // Versuche, JSON-Payload zu parsen (für fetch-Anfragen)
        $payload = [];
        
        // 1. Prüfe auf JSON (Content-Type: application/json)
        if ($request->isMethod('POST')) {
            $contentType = $request->headers->get('Content-Type', '');
            
            // JSON-Payload
            if (str_contains($contentType, 'application/json')) {
                $payload = $request->toArray();
                $this->logger->debug('AgentDialogController::dialog - JSON-Payload erkannt:', $payload);
            }
            // FormData oder URL-encoded (Content-Type: application/x-www-form-urlencoded oder multipart/form-data)
            else {
                $payload = [
                    'message' => $request->request->get('prompt'),
                    'user_identifier' => $request->request->get('user_identifier', 'default_user'),
                ];
                $this->logger->debug('AgentDialogController::dialog - FormData erkannt:', $payload);
            }
        }

        $userMessage = $payload['message'] ?? null;
        $userIdentifier = $payload['user_identifier'] ?? 'default_user';

        if (!$userMessage) {
            return new JsonResponse(
                ['error' => 'Feld "message" ist erforderlich.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Lade oder erstelle UserProfile
        $userProfile = $this->userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);
        if (!$userProfile) {
            $userProfile = new UserProfile();
            $userProfile->setUserIdentifier($userIdentifier);
            $userProfile->setUserType('unknown'); // Standardwert
            $this->userProfileRepo->save($userProfile, true); // Sofort speichern
        }

        $systemPrompt = $this->contextStore->getSystemPrompt($userIdentifier);
        $this->logger->debug('AgentDialogController::dialog - System-Prompt:', ['prompt' => $systemPrompt]);

        try {
            // NUTZE OrchestratorDialogService statt direkten Agent-Aufruf
            $response = $this->orchestratorDialogService->ask($userMessage, $userIdentifier);

            $this->logger->debug('AgentDialogController::dialog - Ergebnis:', [
                'content' => $response,
            ]);

            // Prüfe, ob es eine Tool-Generierungsanfrage ist
            if ($this->isToolGenerationRequest($response)) {
                // Speichere als "pending_tool"-Status
                $historyEntry = new AgentHistory();
                $historyEntry->setAgentName('orchestrator');
                $historyEntry->setAction(['type' => 'tool_generation_request']);
                $historyEntry->setInput(['message' => $userMessage]);
                $historyEntry->setOutput(['response' => $response]);
                $historyEntry->setStatus('pending_tool_approval');
                $historyEntry->setUserProfile($userProfile);
                $this->historyRepo->save($historyEntry, true);

                return new JsonResponse([
                    'response' => $response,
                    'requires_tool_approval' => true,
                ]);
            }

            // Normale Antwort
            $historyEntry = new AgentHistory();
            $historyEntry->setAgentName('orchestrator');
            $historyEntry->setAction(['type' => 'dialog']);
            $historyEntry->setInput(['message' => $userMessage]);
            $historyEntry->setOutput(['response' => $response]);
            $historyEntry->setStatus('success');
            $historyEntry->setUserProfile($userProfile);
            $this->historyRepo->save($historyEntry, true);

            return new JsonResponse(['response' => $response]);
        } catch (\Exception $e) {
            $historyEntry = new AgentHistory();
            $historyEntry->setAgentName('orchestrator');
            $historyEntry->setAction(['type' => 'dialog']);
            $historyEntry->setInput(['message' => $userMessage]);
            $historyEntry->setOutput(['error' => $e->getMessage()]);
            $historyEntry->setStatus('failed');
            $historyEntry->setUserProfile($userProfile);
            $this->historyRepo->save($historyEntry, true);

            $this->logger->error('AgentDialogController::dialog - Fehler:', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'error' => 'Ein Fehler ist aufgetreten: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Prüft, ob die Antwort eine Tool-Generierungsanfrage ist.
     */
    private function isToolGenerationRequest(string $response): bool
    {
        return str_contains($response, 'Neues Tool wartet auf Freigabe') ||
               str_contains($response, 'Tool entworfen') ||
               str_contains($response, 'Bitte genehmige dieses Tool');
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
                'input' => $entry->getInput(),
                'output' => $entry->getOutput(),
            ],
            $entries
        ));
    }
}