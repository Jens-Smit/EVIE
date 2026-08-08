<?php
namespace App\Controller;

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
        private readonly ContextStoreManager $contextStore,
        private readonly AgentHistoryRepository $historyRepo,
        private readonly UserProfileRepository $userProfileRepo,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Einzelner, zustandsloser Dialog-Turn mit dem Orchestrator-Agenten.
     * Speichert die Interaktion in der Datenbank.
     * Unterstützt sowohl JSON- als auch FormData-Anfragen.
     */
    #[Route('/dialog', name: 'agent_dialog', methods: ['POST'])]
    public function dialog(Request $request): JsonResponse
    {
        // Versuche, JSON-Payload zu parsen
        $payload = $request->toArray();
        
        // Falls JSON leer ist, versuche FormData (für HTML-Formular-Submissions)
        if (empty($payload)) {
            $payload = [
                'message' => $request->request->get('prompt'),
                'user_identifier' => $request->request->get('user_identifier', 'default_user'),
            ];
            $this->logger->debug('AgentDialogController::dialog - FormData erkannt:', $payload);
        } else {
            $this->logger->debug('AgentDialogController::dialog - JSON-Payload erkannt:', $payload);
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

        // Debugging: Logge den System-Prompt
        $this->logger->debug('AgentDialogController::dialog - System-Prompt:', ['prompt' => $systemPrompt]);

        $messages = new MessageBag(
            Message::forSystem($systemPrompt),
            Message::ofUser($userMessage),
        );

        // Debugging: Logge die Nachrichten
        $this->logger->debug('AgentDialogController::dialog - Nachrichten:', [
            'system' => $systemPrompt,
            'user' => $userMessage,
        ]);

        try {
            // Rufe den Agenten auf
            $result = $this->agent->call($messages);

            // Debugging: Logge das Ergebnis
            $this->logger->debug('AgentDialogController::dialog - Ergebnis:', [
                'content' => $result->getContent(),
                'metadata' => $result->getMetadata()->all(),
            ]);

            // Speichere die Interaktion in der Datenbank
            $historyEntry = new AgentHistory();
            $historyEntry->setAgentName('orchestrator');
            $historyEntry->setAction(['type' => 'dialog']);
            $historyEntry->setInput(['message' => $userMessage]);
            $historyEntry->setOutput(['response' => $result->getContent()]);
            $historyEntry->setStatus('success');
            $historyEntry->setUserProfile($userProfile);
            $this->historyRepo->save($historyEntry, true); // Sofort speichern

            return new JsonResponse([
                'response' => $result->getContent(),
                'token_usage' => $result->getMetadata()->get('token_usage'),
            ]);
        } catch (\Exception $e) {
            // Speichere fehlgeschlagene Interaktion
            $historyEntry = new AgentHistory();
            $historyEntry->setAgentName('orchestrator');
            $historyEntry->setAction(['type' => 'dialog']);
            $historyEntry->setInput(['message' => $userMessage]);
            $historyEntry->setOutput(['error' => $e->getMessage()]);
            $historyEntry->setStatus('failed');
            $historyEntry->setUserProfile($userProfile);
            $this->historyRepo->save($historyEntry, true);

            // Debugging: Logge den Fehler
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
