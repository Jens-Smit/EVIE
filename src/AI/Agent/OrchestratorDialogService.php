<?php
// src/AI/Agent/OrchestratorDialogService.php

namespace App\AI\Agent;

use App\AI\Skills\ToolDefinitionGenerator;
use App\Event\PendingToolApprovalEvent;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

final readonly class OrchestratorDialogService
{
    public function __construct(
        #[Autowire(service: 'ai.agent.orchestrator')]
        private AgentInterface $agent,
        private ToolDefinitionGenerator $toolGenerator,
        private EventDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Sendet eine Nachricht an den Orchestrator-Agenten.
     * Falls kein passendes Tool gefunden wird, wird automatisch eine Tool-Generierung ausgelöst.
     */
    public function ask(string $userMessage, string $userIdentifier): string
    {
        $messages = new MessageBag(
            Message::ofUser($userMessage),
        );

        $result = $this->agent->call($messages, ['user_identifier' => $userIdentifier]);
        $responseContent = $result->getContent();

        // Prüfe, ob Mistral sagt, dass kein Tool gefunden wurde
        if ($this->isNoToolFoundResponse($responseContent)) {
            return $this->handleToolNotFound($userMessage, $userIdentifier);
        }

        return $responseContent;
    }

    /**
     * Prüft, ob die Antwort darauf hindeutet, dass kein Tool gefunden wurde.
     */
    private function isNoToolFoundResponse(string $response): bool
    {
        $noToolIndicators = [
            'ich kann diese anfrage nicht ausführen',
            'es tut mir leid, aber ich kann',
            'kein passendes tool',
            'keine passende funktion',
            'kann diese aufgabe nicht erledigen',
            'nicht verfügbar',
            'keine ressourcen',
            'keine schnittstellen',
            'ich kann kein tool entwickeln',
            'kein tool verfügbar',
            'ich habe kein passendes tool',
            'kein tool gefunden',
        ];

        $responseLower = strtolower($response);
        foreach ($noToolIndicators as $indicator) {
            if (str_contains($responseLower, $indicator)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Behandelt den Fall, wenn kein passendes Tool gefunden wurde.
     */
    private function handleToolNotFound(string $userMessage, string $userIdentifier): string
    {
        $this->logger->info('Kein passendes Tool gefunden. Starte Tool-Generierung...');

        // 1. Tool-Name aus der User-Nachricht extrahieren
        $toolName = $this->extractToolNameFromRequest($userMessage);
        
        // 2. Beschreibung generieren
        $description = $this->generateToolDescription($userMessage);

        // 3. Tool-Definition generieren
        $toolDefinition = $this->toolGenerator->generateToolDefinition(
            $toolName,
            $description,
            ['user_identifier' => $userIdentifier, 'original_request' => $userMessage]
        );

        // 4. HITL-Event auslösen
        $this->dispatcher->dispatch(new PendingToolApprovalEvent($toolDefinition, $userIdentifier));

        return sprintf(
            "Ich habe kein passendes Tool für deine Anfrage gefunden. \n\n" .
            "Ich habe jedoch ein neues Tool mit dem Namen **'%s'** entworfen, das diese Aufgabe erfüllen könnte. \n\n" .
            "Bitte genehmige dieses Tool im Dashboard, damit ich es zukünftig nutzen kann. \n\n" .
            "Tool-Beschreibung: %s",
            $toolDefinition->getName(),
            $toolDefinition->getDescription()
        );
    }

    /**
     * Extrahiere einen sinnvollen Tool-Namen aus der User-Anfrage.
     */
    private function extractToolNameFromRequest(string $userMessage): string
    {
        // Einfache Heuristik: Nimm die ersten 3-5 Wörter
        $words = preg_split('/\s+/', trim($userMessage));
        $toolName = implode('_', array_slice($words, 0, 3));
        
        // Bereinige den Namen
        $toolName = preg_replace('/[^a-zA-Z0-9_]/', '', $toolName);
        $toolName = strtolower($toolName);
        
        // Falls zu kurz, füge einen generischen Präfix hinzu
        if (strlen($toolName) < 3) {
            $toolName = 'custom_tool_' . $toolName;
        }

        return $toolName;
    }

    /**
     * Generiere eine Beschreibung für das neue Tool.
     */
    private function generateToolDescription(string $userMessage): string
    {
        // Kürze die Nachricht auf eine sinnvolle Länge
        $description = substr($userMessage, 0, 200);
        
        // Füge einen Kontext hinzu
        return sprintf("Tool zur Ausführung der folgenden Aufgabe: %s", $description);
    }

    /**
     * Behandelt allgemeine Fehler.
     */
    private function handleGeneralError(\Exception $e, string $userMessage): string
    {
        $this->logger->error('Allgemeiner Fehler im Orchestrator: ' . $e->getMessage(), [
            'exception' => $e,
            'user_message' => $userMessage,
        ]);

        return "Es ist ein Fehler aufgetreten: " . $e->getMessage() . "\n\n" .
               "Bitte versuche es erneut oder kontaktiere den Administrator.";
    }
}