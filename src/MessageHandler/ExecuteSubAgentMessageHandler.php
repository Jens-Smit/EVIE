<?php

namespace App\MessageHandler;

use App\AI\Agent\SubAgentFactory;
use App\AI\Chat\ConversationManager;
use App\Entity\AgentHistory;
use App\Message\ExecuteSubAgentMessage;
use App\Message\SubAgentResultMessage;
use App\Repository\AgentHistoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Message Handler für die asynchrone Ausführung von Sub-Agenten
 * 
 * Dieser Handler führt den Sub-Agenten aus und sendet das Ergebnis zurück.
 */
#[AsMessageHandler]
class ExecuteSubAgentMessageHandler
{
    public function __construct(
        private SubAgentFactory $subAgentFactory,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private ConversationManager $conversationManager,
        private AgentHistoryRepository $historyRepo
    ) {
    }

    /**
     * Führt die ExecuteSubAgentMessage aus
     */
    public function __invoke(ExecuteSubAgentMessage $message)
    {
        $this->logger->info('Starte asynchrone Sub-Agenten-Ausführung', [
            'sub_agent' => $message->getSubAgentName(),
            'task' => substr($message->getTask(), 0, 100),
            'user' => $message->getUserIdentifier(),
            'conversation_id' => $message->getConversationId(),
            'parent_message_id' => $message->getParentMessageId()
        ]);

        $startedAt = new \DateTimeImmutable();

        try {
            // Sub-Agent erstellen
            $subAgent = $this->subAgentFactory->createByName($message->getSubAgentName());

            // Aufgabe ausführen
            $messages = new MessageBag(Message::ofUser($message->getTask()));
            
            // Kontext hinzufügen, falls vorhanden
            if (!empty($message->getContext())) {
                foreach ($message->getContext() as $key => $value) {
                    $messages->add(Message::ofSystem("$key: " . json_encode($value)));
                }
            }
            
            $result = $subAgent->call($messages);
            $completedAt = new \DateTimeImmutable();

            // Ergebnis verarbeiten
            $resultContent = $result->getContent();

            // Füge das Ergebnis zur Conversation hinzu, falls vorhanden
            if ($message->getConversationId()) {
                $this->addToConversation($message, $resultContent, $startedAt, $completedAt);
            }

            // Speichere in der History
            $this->saveToHistory($message, $resultContent, true, null, $startedAt, $completedAt);

            // Ergebnis-Message erstellen
            $resultMessage = new SubAgentResultMessage(
                $message->getUserIdentifier(),
                $message->getSubAgentName(),
                $resultContent,
                true,
                null,
                $message->getParentMessageId(),
                $message->getConversationId(),
                $startedAt,
                $completedAt
            );

            // Ergebnis zurück an den Message Bus
            $this->messageBus->dispatch($resultMessage);

            $this->logger->info('Sub-Agenten-Ausführung erfolgreich abgeschlossen', [
                'sub_agent' => $message->getSubAgentName(),
                'user' => $message->getUserIdentifier(),
                'conversation_id' => $message->getConversationId(),
                'duration' => $completedAt->getTimestamp() - $startedAt->getTimestamp()
            ]);

        } catch (\Exception $e) {
            $completedAt = new \DateTimeImmutable();

            $this->logger->error('Fehler bei Sub-Agenten-Ausführung: ' . $e->getMessage(), [
                'sub_agent' => $message->getSubAgentName(),
                'user' => $message->getUserIdentifier(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Speichere den Fehler in der History
            $this->saveToHistory($message, '', false, $e->getMessage(), $startedAt, $completedAt);

            // Fehler-Message erstellen
            $resultMessage = new SubAgentResultMessage(
                $message->getUserIdentifier(),
                $message->getSubAgentName(),
                '',
                false,
                $e->getMessage(),
                $message->getParentMessageId(),
                $message->getConversationId(),
                $startedAt,
                $completedAt
            );

            $this->messageBus->dispatch($resultMessage);
        }
    }

    /**
     * Fügt das Ergebnis zur Conversation hinzu
     */
    private function addToConversation(
        ExecuteSubAgentMessage $message,
        string $resultContent,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $completedAt
    ): void {
        try {
            // In einer echten Implementierung würden wir hier den User aus dem
            // UserIdentifier holen. Für jetzt speichern wir nur in der History.
            
            // Erstelle eine temporäre History für die Conversation
            $history = new AgentHistory();
            $history->setUserIdentifier($message->getUserIdentifier());
            $history->setAgentName($message->getSubAgentName());
            $history->setMessageType('sub_agent_result');
            $history->setContent($resultContent);
            $history->setIsSuccess(true);
            $history->setConversationId($message->getConversationId());
            $history->setParentMessageId($message->getParentMessageId());
            $history->setCreatedAt($completedAt);
            
            // Speichere die History
            $this->entityManager->persist($history);
            $this->entityManager->flush();

        } catch (\Exception $e) {
            $this->logger->warning('Konnte Ergebnis nicht zur Conversation hinzufügen: ' . $e->getMessage());
        }
    }

    /**
     * Speichert die Ausführung in der History
     */
    private function saveToHistory(
        ExecuteSubAgentMessage $message,
        string $resultContent,
        bool $isSuccess,
        ?string $errorMessage,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $completedAt
    ): void {
        try {
            $history = new AgentHistory();
            $history->setUserIdentifier($message->getUserIdentifier());
            $history->setAgentName($message->getSubAgentName());
            $history->setMessageType('sub_agent_execution');
            $history->setContent($isSuccess ? $resultContent : ($errorMessage ?? ''));
            $history->setIsSuccess($isSuccess);
            $history->setConversationId($message->getConversationId());
            $history->setParentMessageId($message->getParentMessageId());
            $history->setCreatedAt($startedAt);
            
            // Metadata mit Ausführungsinformationen
            $metadata = [
                'sub_agent' => $message->getSubAgentName(),
                'task' => substr($message->getTask(), 0, 200),
                'started_at' => $startedAt->format('c'),
                'completed_at' => $completedAt->format('c'),
                'duration' => $completedAt->getTimestamp() - $startedAt->getTimestamp(),
                'has_context' => !empty($message->getContext()),
                'context_keys' => array_keys($message->getContext())
            ];
            
            if (!$isSuccess) {
                $metadata['error'] = $errorMessage;
            }
            
            $history->setMetadata($metadata);
            
            $this->entityManager->persist($history);
            $this->entityManager->flush();

        } catch (\Exception $e) {
            $this->logger->error('Konnte History nicht speichern: ' . $e->getMessage());
        }
    }
}
