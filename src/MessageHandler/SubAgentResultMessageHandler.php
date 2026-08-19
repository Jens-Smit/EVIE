<?php

namespace App\MessageHandler;

use App\AI\Chat\ConversationManager;
use App\Entity\AgentHistory;
use App\Message\SubAgentResultMessage;
use App\Repository\AgentHistoryRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Message Handler für die Verarbeitung von Sub-Agenten-Ergebnissen
 * 
 * Dieser Handler verarbeitet das Ergebnis einer asynchronen Sub-Agenten-Ausführung
 * und benachrichtigt den User.
 */
#[AsMessageHandler]
class SubAgentResultMessageHandler
{
    public function __construct(
        private AgentHistoryRepository $historyRepo,
        private NotificationService $notificationService,
        private LoggerInterface $logger,
        private ConversationManager $conversationManager,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Verarbeitet die SubAgentResultMessage
     */
    public function __invoke(SubAgentResultMessage $message)
    {
        $this->logger->info('Verarbeite Sub-Agenten-Ergebnis', [
            'sub_agent' => $message->getSubAgentName(),
            'user' => $message->getUserIdentifier(),
            'success' => $message->isSuccess(),
            'conversation_id' => $message->getConversationId(),
            'duration' => $message->getExecutionDuration()
        ]);

        // Speichere das Ergebnis in der History
        $this->saveToHistory($message);

        // Benachrichtigung an den User senden
        $this->sendNotification($message);

        // Falls es eine Conversation gibt, füge die Benachrichtigung auch dort hinzu
        if ($message->getConversationId()) {
            $this->addToConversation($message);
        }
    }

    /**
     * Speichert das Ergebnis in der History
     */
    private function saveToHistory(SubAgentResultMessage $message): void
    {
        try {
            $history = new AgentHistory();
            $history->setUserIdentifier($message->getUserIdentifier());
            $history->setAgentName($message->getSubAgentName());
            $history->setMessageType('sub_agent_result');
            $history->setContent($message->isSuccess() ? $message->getResult() : ($message->getErrorMessage() ?? ''));
            $history->setIsSuccess($message->isSuccess());
            $history->setParentMessageId($message->getParentMessageId());
            $history->setConversationId($message->getConversationId());
            $history->setCreatedAt($message->getCreatedAt() ?? new \DateTimeImmutable());

            // Metadata mit Ausführungsinformationen
            $metadata = [
                'sub_agent' => $message->getSubAgentName(),
                'success' => $message->isSuccess(),
                'result_length' => strlen($message->getResult()),
                'has_error' => $message->getErrorMessage() !== null,
                'duration' => $message->getExecutionDuration()
            ];
            
            if ($message->getStartedAt()) {
                $metadata['started_at'] = $message->getStartedAt()->format('c');
            }
            if ($message->getCompletedAt()) {
                $metadata['completed_at'] = $message->getCompletedAt()->format('c');
            }
            
            $history->setMetadata($metadata);

            $this->entityManager->persist($history);
            $this->entityManager->flush();

            $this->logger->debug('Sub-Agenten-Ergebnis in History gespeichert', [
                'history_id' => $history->getId(),
                'sub_agent' => $message->getSubAgentName()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Konnte Ergebnis nicht in History speichern: ' . $e->getMessage());
        }
    }

    /**
     * Sendet eine Benachrichtigung an den User
     */
    private function sendNotification(SubAgentResultMessage $message): void
    {
        try {
            $notificationText = $message->getUserMessage();
            
            $this->notificationService->sendToUser(
                $message->getUserIdentifier(),
                $notificationText,
                'Sub-Agenten-Ergebnis'
            );

            $this->logger->debug('Benachrichtigung an User gesendet', [
                'user' => $message->getUserIdentifier(),
                'sub_agent' => $message->getSubAgentName()
            ]);

        } catch (\Exception $e) {
            $this->logger->warning('Konnte Benachrichtigung nicht senden: ' . $e->getMessage());
        }
    }

    /**
     * Fügt das Ergebnis zur Conversation hinzu
     */
    private function addToConversation(SubAgentResultMessage $message): void
    {
        try {
            // In einer echten Implementierung würden wir hier den User aus dem
            // UserIdentifier holen und die Nachricht zur Conversation hinzufügen.
            
            // Für jetzt speichern wir nur eine zusätzliche History-Einträge
            // für die Conversation-Ansicht
            
            $history = new AgentHistory();
            $history->setUserIdentifier($message->getUserIdentifier());
            $history->setAgentName('system');
            $history->setMessageType('notification');
            $history->setContent($message->getUserMessage());
            $history->setIsSuccess($message->isSuccess());
            $history->setConversationId($message->getConversationId());
            $history->setParentMessageId($message->getParentMessageId());
            $history->setCreatedAt($message->getCreatedAt() ?? new \DateTimeImmutable());
            
            // Metadata
            $history->setMetadata([
                'type' => 'sub_agent_notification',
                'sub_agent' => $message->getSubAgentName()
            ]);
            
            $this->entityManager->persist($history);
            $this->entityManager->flush();

            $this->logger->debug('Ergebnis zur Conversation hinzugefügt', [
                'conversation_id' => $message->getConversationId(),
                'sub_agent' => $message->getSubAgentName()
            ]);

        } catch (\Exception $e) {
            $this->logger->warning('Konnte Ergebnis nicht zur Conversation hinzufügen: ' . $e->getMessage());
        }
    }
}
