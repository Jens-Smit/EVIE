<?php

namespace App\AI\Chat;

use App\Entity\AgentHistory;
use App\Entity\User;
use App\Repository\AgentHistoryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manager für Chat-Unterhaltungen
 * 
 * Verwaltet den Kontext und Verlauf von Chat-Unterhaltungen.
 */
class ConversationManager
{
    public function __construct(
        private AgentHistoryRepository $historyRepo,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Startet eine neue Unterhaltung
     * 
     * @param User $user Der User
     * @return string Die Conversation-ID
     */
    public function startConversation(User $user): string
    {
        $conversationId = uniqid('conv_', true);

        // Erste System-Nachricht
        $history = new AgentHistory();
        $history->setUser($user);
        $history->setUserIdentifier($user->getUserIdentifier());
        $history->setAgentName('orchestrator');
        $history->setMessageType('system');
        $history->setContent('Neue Unterhaltung gestartet.');
        $history->setConversationId($conversationId);
        $history->setConversationOrder(0);
        $history->setIsUserMessage(false);
        $history->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($history);
        $this->entityManager->flush();

        return $conversationId;
    }

    /**
     * Fügt eine Nachricht zur Unterhaltung hinzu
     * 
     * @param User $user Der User
     * @param string $conversationId Die Conversation-ID
     * @param string $content Der Inhalt der Nachricht
     * @param string $agentName Der Name des Agenten
     * @param bool $isUserMessage Ob es eine User-Nachricht ist
     * @param string $messageType Der Typ der Nachricht
     * @return AgentHistory Die gespeicherte History
     */
    public function addMessageToConversation(
        User $user,
        string $conversationId,
        string $content,
        string $agentName,
        bool $isUserMessage = false,
        string $messageType = 'text'
    ): AgentHistory {
        $history = new AgentHistory();
        $history->setUser($user);
        $history->setUserIdentifier($user->getUserIdentifier());
        $history->setAgentName($agentName);
        $history->setMessageType($messageType);
        $history->setContent($content);
        $history->setConversationId($conversationId);
        $history->setIsUserMessage($isUserMessage);

        // Nächste Reihenfolge berechnen
        $lastOrder = $this->historyRepo->findMaxConversationOrder($conversationId);
        $history->setConversationOrder($lastOrder + 1);

        $history->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($history);
        $this->entityManager->flush();

        return $history;
    }

    /**
     * Holt den Kontext einer Unterhaltung
     * 
     * @param string $conversationId Die Conversation-ID
     * @param int $limit Die maximale Anzahl von Nachrichten
     * @return array Array von AgentHistory-Entitäten
     */
    public function getConversationContext(string $conversationId, int $limit = 10): array
    {
        return $this->historyRepo->findBy([
            'conversationId' => $conversationId
        ], [
            'conversationOrder' => 'ASC'
        ], $limit);
    }

    /**
     * Holt die aktuelle Unterhaltung des Users
     * 
     * @param User $user Der User
     * @return string|null Die Conversation-ID oder null
     */
    public function getCurrentConversation(User $user): ?string
    {
        // Hole die letzte Unterhaltung des Users
        $lastHistory = $this->historyRepo->findOneBy([
            'user' => $user
        ], [
            'createdAt' => 'DESC'
        ]);

        return $lastHistory?->getConversationId();
    }

    /**
     * Holt alle Unterhaltungen eines Users
     * 
     * @param User $user Der User
     * @return array Array von Conversation-Informationen
     */
    public function getUserConversations(User $user): array
    {
        $histories = $this->historyRepo->findBy([
            'user' => $user
        ], [
            'createdAt' => 'DESC'
        ]);

        $conversations = [];
        foreach ($histories as $history) {
            if ($history->getConversationId() && !isset($conversations[$history->getConversationId()])) {
                $conversations[$history->getConversationId()] = [
                    'id' => $history->getConversationId(),
                    'first_message' => $history->getCreatedAt(),
                    'last_message' => $history->getCreatedAt(),
                    'message_count' => 1
                ];
            } else {
                if ($history->getConversationId() && isset($conversations[$history->getConversationId()])) {
                    $conversations[$history->getConversationId()]['last_message'] = $history->getCreatedAt();
                    $conversations[$history->getConversationId()]['message_count']++;
                }
            }
        }

        return array_values($conversations);
    }

    /**
     * Holt eine spezifische Unterhaltung
     * 
     * @param string $conversationId Die Conversation-ID
     * @param User $user Der User (für Berechtigungsprüfung)
     * @return array Array von AgentHistory-Entitäten
     */
    public function getConversation(string $conversationId, User $user): array
    {
        return $this->historyRepo->findBy([
            'conversationId' => $conversationId,
            'user' => $user
        ], [
            'conversationOrder' => 'ASC'
        ]);
    }

    /**
     * Beendet eine Unterhaltung
     * 
     * @param string $conversationId Die Conversation-ID
     */
    public function endConversation(string $conversationId): void
    {
        // Optional: Spezielles Ende-Markierung hinzufügen
        // In einer echten Implementierung könnten wir hier eine
        // spezielle Nachricht hinzufügen, die das Ende der Unterhaltung markiert
    }

    /**
     * Löscht eine Unterhaltung
     * 
     * @param string $conversationId Die Conversation-ID
     */
    public function deleteConversation(string $conversationId): void
    {
        $histories = $this->historyRepo->findBy([
            'conversationId' => $conversationId
        ]);

        foreach ($histories as $history) {
            $this->entityManager->remove($history);
        }

        $this->entityManager->flush();
    }

    /**
     * Setzt eine Unterhaltung als aktiv/aktuelle
     * 
     * @param User $user Der User
     * @param string $conversationId Die Conversation-ID
     */
    public function setActiveConversation(User $user, string $conversationId): void
    {
        // Speichere die aktuelle Conversation-ID im User-Profile
        // In einer echten Implementierung würden wir hier das UserProfile aktualisieren
    }

    /**
     * Gibt die letzte Nachricht einer Unterhaltung zurück
     * 
     * @param string $conversationId Die Conversation-ID
     * @return AgentHistory|null Die letzte Nachricht oder null
     */
    public function getLastMessage(string $conversationId): ?AgentHistory
    {
        return $this->historyRepo->findOneBy([
            'conversationId' => $conversationId
        ], [
            'conversationOrder' => 'DESC'
        ]);
    }

    /**
     * Gibt die erste Nachricht einer Unterhaltung zurück
     * 
     * @param string $conversationId Die Conversation-ID
     * @return AgentHistory|null Die erste Nachricht oder null
     */
    public function getFirstMessage(string $conversationId): ?AgentHistory
    {
        return $this->historyRepo->findOneBy([
            'conversationId' => $conversationId
        ], [
            'conversationOrder' => 'ASC'
        ]);
    }

    /**
     * Zählt die Anzahl der Nachrichten in einer Unterhaltung
     * 
     * @param string $conversationId Die Conversation-ID
     * @return int Die Anzahl der Nachrichten
     */
    public function countMessages(string $conversationId): int
    {
        return $this->historyRepo->count([
            'conversationId' => $conversationId
        ]);
    }

    /**
     * Prüft, ob eine Unterhaltung existiert
     * 
     * @param string $conversationId Die Conversation-ID
     * @return bool True, falls die Unterhaltung existiert
     */
    public function conversationExists(string $conversationId): bool
    {
        return $this->historyRepo->count([
            'conversationId' => $conversationId
        ]) > 0;
    }
}
