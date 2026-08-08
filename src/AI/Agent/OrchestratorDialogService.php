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
        try {
            $messages = new MessageBag(
                Message::ofUser($userMessage),
            );

            $result = $this->agent->call($messages, ['user_identifier' => $userIdentifier]);
            return $result->getContent();

        } catch (\Exception $e) {
            $this->logger->error('Orchestrator error: ' . $e->getMessage());

            // Prüfe, ob es sich um ein "Tool nicht gefunden"-Problem handelt
            if ($this->isToolNotFoundError($e)) {
                return $this->handleToolNotFound($userMessage, $userIdentifier);
            }

            // Für andere Fehler: Standard-Fallback
            return $this->handleGeneralError($e, $userMessage);
        }
    }

    /**
     * Prüft, ob der Fehler darauf hindeutet, dass kein passendes Tool gefunden wurde.
     */
    private function isToolNotFoundError(\Exception $e): bool
    {
        $errorMessage = strtolower($e->getMessage());
        
        // Prüfe auf typische Fehlertexte
        $toolNotFoundIndicators = [
            'no tool found',
            'kein tool gefunden',
            'tool not available',
            'unknown tool',
            'cannot find tool',
            'no matching tool',
        ];

        foreach ($toolNotFoundIndicators as $indicator) {
            if (str_contains($errorMessage, $indicator)) {
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