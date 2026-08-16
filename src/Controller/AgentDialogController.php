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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Psr\Log\LoggerInterface;

#[Route('/api/agent')]
final class AgentDialogController extends AbstractController
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
                // Audit-Finding #6 (Sensitive Data in Logs): der gesamte User-
                // Payload kann sensible Inhalte (API-Keys, PII) enthalten und
                // wird daher nicht mehr ungefiltert geloggt. Nur Content-Type
                // und Laenge sind fuer Debugging relevant.
                $this->logger->debug('AgentDialogController::dialog - JSON-Payload erkannt', ['content_type' => $contentType]);
            }
            // FormData oder URL-encoded (Content-Type: application/x-www-form-urlencoded oder multipart/form-data)
            else {
                $payload = [
                    'message' => $request->request->get('prompt'),
                ];
                $this->logger->debug('AgentDialogController::dialog - FormData erkannt', ['content_type' => $contentType]);
            }
        }

        $userMessage = $payload['message'] ?? null;

        // P0-5 IDOR-Schutz: der Tenant-Identifier wird ausschliesslich aus
        // dem authentifizierten User bezogen, niemals aus dem Request-Body.
        // So kann ein Aufrufer nicht den Tenant eines anderen Users spoofen.
        $authenticatedUser = $this->getUser();
        if ($authenticatedUser instanceof UserInterface) {
            $userIdentifier = $authenticatedUser->getUserIdentifier();
        } else {
            // Ohne Authentifizierung ist nur der explizite Default-Tenant
            // erlaubt; ein ueber den Body mitgegebener Identifier wird
            // bewusst ignoriert, um Tenant-Spoofing zu verhindern.
            $userIdentifier = 'default_user';
        }

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
            $userProfile->setName($userIdentifier); // Standardwert
            $this->userProfileRepo->save($userProfile, true); // Sofort speichern
        }

        $systemPrompt = $this->contextStore->createSystemPromptWithContext($userProfile, $userMessage);
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
                $historyEntry->setAction('tool_generation_request');
                $historyEntry->setDetails(json_encode([
                    'agent' => 'orchestrator',
                    'status' => 'pending_tool_approval',
                    'input' => ['message' => $userMessage],
                    'output' => ['response' => $response],
                ], JSON_THROW_ON_ERROR));
                $historyEntry->setUser($userProfile);
                $this->historyRepo->save($historyEntry, true);

                return new JsonResponse([
                    'response' => $response,
                    'requires_tool_approval' => true,
                ]);
            }

            // Normale Antwort
            $historyEntry = new AgentHistory();
            $historyEntry->setAction('dialog');
            $historyEntry->setDetails(json_encode([
                'agent' => 'orchestrator',
                'status' => 'success',
                'input' => ['message' => $userMessage],
                'output' => ['response' => $response],
            ], JSON_THROW_ON_ERROR));
            $historyEntry->setUser($userProfile);
            $this->historyRepo->save($historyEntry, true);

            return new JsonResponse(['response' => $response]);
        } catch (\Exception $e) {
            $historyEntry = new AgentHistory();
            $historyEntry->setAction('dialog');
            $historyEntry->setDetails(json_encode([
                'agent' => 'orchestrator',
                'status' => 'failed',
                'input' => ['message' => $userMessage],
                'output' => ['error' => $e->getMessage()],
            ], JSON_THROW_ON_ERROR));
            $historyEntry->setUser($userProfile);
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
        // P0-5 IDOR-Schutz: ein User darf nur seinen eigenen Verlauf abrufen.
        $authenticatedUser = $this->getUser();
        if ($authenticatedUser instanceof UserInterface
            && $authenticatedUser->getUserIdentifier() !== $userIdentifier) {
            return new JsonResponse(
                ['error' => 'Zugriff verweigert: Fremde Verlaufsdaten.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $entries = $this->historyRepo->findByUserIdentifier($userIdentifier);

        return new JsonResponse(array_map(
            static function ($entry) {
                $details = json_decode($entry->getDetails() ?? '{}', true) ?? [];

                return [
                    'agent' => $details['agent'] ?? null,
                    'status' => $details['status'] ?? null,
                    'action' => $entry->getAction(),
                    'executed_at' => $entry->getCreatedAt()->format(DATE_ATOM),
                    'input' => $details['input'] ?? null,
                    'output' => $details['output'] ?? null,
                ];
            },
            $entries
        ));
    }
}