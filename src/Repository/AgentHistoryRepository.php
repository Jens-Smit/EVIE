<?php

namespace App\Repository;

use App\Entity\AgentHistory;
use App\Entity\UserProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentHistory>
 */
class AgentHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentHistory::class);
    }

    public function save(AgentHistory $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AgentHistory $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Finds all actions by a specific action/agent.
     */
    public function findByAction(string $action): array
    {
        return $this->findBy(['action' => $action]);
    }

    /**
     * Finds all actions for a specific user.
     */
    public function findByUserIdentifier(string $userIdentifier): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.user', 'u')
            ->where('u.userIdentifier = :userIdentifier')
            ->setParameter('userIdentifier', $userIdentifier)
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds a UserProfile by its identifier.
     */
    public function findUserByIdentifier(string $userIdentifier): ?UserProfile
    {
        $userProfileRepo = $this->getEntityManager()->getRepository(UserProfile::class);

        return $userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);
    }

    // NEUE METHODEN FÜR CONVERSATION SUPPORT (v0.9.5)

    /**
     * Findet die maximale Conversation-Order für eine Conversation
     * 
     * @param string $conversationId Die Conversation-ID
     * @return int Die maximale Order (oder 0, falls keine Nachrichten vorhanden sind)
     */
    public function findMaxConversationOrder(string $conversationId): int
    {
        $result = $this->createQueryBuilder('a')
            ->select('MAX(a.conversationOrder) as max_order')
            ->where('a.conversationId = :conversationId')
            ->setParameter('conversationId', $conversationId)
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? (int)$result : 0;
    }

    /**
     * Findet alle Nachrichten einer Conversation
     * 
     * @param string $conversationId Die Conversation-ID
     * @param int|null $limit Maximale Anzahl von Nachrichten
     * @return array Array von AgentHistory-Entitäten
     */
    public function findByConversation(string $conversationId, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.conversationId = :conversationId')
            ->orderBy('a.conversationOrder', 'ASC')
            ->setParameter('conversationId', $conversationId);

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Findet die letzte Nachricht einer Conversation
     * 
     * @param string $conversationId Die Conversation-ID
     * @return AgentHistory|null Die letzte Nachricht oder null
     */
    public function findLastByConversation(string $conversationId): ?AgentHistory
    {
        return $this->createQueryBuilder('a')
            ->where('a.conversationId = :conversationId')
            ->orderBy('a.conversationOrder', 'DESC')
            ->setMaxResults(1)
            ->setParameter('conversationId', $conversationId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Findet die erste Nachricht einer Conversation
     * 
     * @param string $conversationId Die Conversation-ID
     * @return AgentHistory|null Die erste Nachricht oder null
     */
    public function findFirstByConversation(string $conversationId): ?AgentHistory
    {
        return $this->createQueryBuilder('a')
            ->where('a.conversationId = :conversationId')
            ->orderBy('a.conversationOrder', 'ASC')
            ->setMaxResults(1)
            ->setParameter('conversationId', $conversationId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Zählt die Anzahl der Nachrichten in einer Conversation
     * 
     * @param string $conversationId Die Conversation-ID
     * @return int Die Anzahl der Nachrichten
     */
    public function countByConversation(string $conversationId): int
    {
        return $this->count(['conversationId' => $conversationId]);
    }

    /**
     * Findet alle Conversations für einen User
     * 
     * @param string $userIdentifier Der User-Identifier
     * @return array Array von Conversation-IDs
     */
    public function findConversationIdsByUser(string $userIdentifier): array
    {
        $results = $this->createQueryBuilder('a')
            ->select('DISTINCT a.conversationId')
            ->where('a.userIdentifier = :userIdentifier')
            ->andWhere('a.conversationId IS NOT NULL')
            ->setParameter('userIdentifier', $userIdentifier)
            ->getQuery()
            ->getSingleColumnResult();

        return array_filter($results, fn($id) => $id !== null);
    }

    /**
     * Findet die letzte Conversation für einen User
     * 
     * @param string $userIdentifier Der User-Identifier
     * @return string|null Die Conversation-ID oder null
     */
    public function findLastConversationIdByUser(string $userIdentifier): ?string
    {
        $result = $this->createQueryBuilder('a')
            ->select('a.conversationId')
            ->where('a.userIdentifier = :userIdentifier')
            ->andWhere('a.conversationId IS NOT NULL')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->setParameter('userIdentifier', $userIdentifier)
            ->getQuery()
            ->getOneOrNullResult();

        return $result?->getConversationId();
    }

    /**
     * Findet Nachrichten nach Parent Message ID
     * 
     * @param int $parentMessageId Die Parent Message ID
     * @return array Array von AgentHistory-Entitäten
     */
    public function findByParentMessageId(int $parentMessageId): array
    {
        return $this->findBy(['parentMessageId' => $parentMessageId]);
    }

    /**
     * Findet User-Nachrichten in einer Conversation
     * 
     * @param string $conversationId Die Conversation-ID
     * @return array Array von AgentHistory-Entitäten
     */
    public function findUserMessagesByConversation(string $conversationId): array
    {
        return $this->findBy([
            'conversationId' => $conversationId,
            'isUserMessage' => true
        ], ['conversationOrder' => 'ASC']);
    }

    /**
     * Findet Agent-Nachrichten in einer Conversation
     * 
     * @param string $conversationId Die Conversation-ID
     * @return array Array von AgentHistory-Entitäten
     */
    public function findAgentMessagesByConversation(string $conversationId): array
    {
        return $this->findBy([
            'conversationId' => $conversationId,
            'isUserMessage' => false
        ], ['conversationOrder' => 'ASC']);
    }

    /**
     * Findet System-Nachrichten in einer Conversation
     * 
     * @param string $conversationId Die Conversation-ID
     * @return array Array von AgentHistory-Entitäten
     */
    public function findSystemMessagesByConversation(string $conversationId): array
    {
        return $this->findBy([
            'conversationId' => $conversationId,
            'messageType' => 'system'
        ], ['conversationOrder' => 'ASC']);
    }

    /**
     * Findet Benachrichtigungen in einer Conversation
     * 
     * @param string $conversationId Die Conversation-ID
     * @return array Array von AgentHistory-Entitäten
     */
    public function findNotificationsByConversation(string $conversationId): array
    {
        return $this->findBy([
            'conversationId' => $conversationId,
            'messageType' => 'notification'
        ], ['conversationOrder' => 'ASC']);
    }

    /**
     * Findet Tool-Nachrichten in einer Conversation
     * 
     * @param string $conversationId Die Conversation-ID
     * @return array Array von AgentHistory-Entitäten
     */
    public function findToolMessagesByConversation(string $conversationId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.conversationId = :conversationId')
            ->andWhere('a.messageType LIKE :toolPattern')
            ->setParameter('conversationId', $conversationId)
            ->setParameter('toolPattern', 'tool_%')
            ->orderBy('a.conversationOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Findet erfolgreiche Nachrichten in einer Conversation
     * 
     * @param string $conversationId Die Conversation-ID
     * @return array Array von AgentHistory-Entitäten
     */
    public function findSuccessfulByConversation(string $conversationId): array
    {
        return $this->findBy([
            'conversationId' => $conversationId,
            'isSuccess' => true
        ], ['conversationOrder' => 'ASC']);
    }

    /**
     * Findet fehlgeschlagene Nachrichten in einer Conversation
     * 
     * @param string $conversationId Die Conversation-ID
     * @return array Array von AgentHistory-Entitäten
     */
    public function findFailedByConversation(string $conversationId): array
    {
        return $this->findBy([
            'conversationId' => $conversationId,
            'isSuccess' => false
        ], ['conversationOrder' => 'ASC']);
    }

    /**
     * Löscht alle Nachrichten einer Conversation
     * 
     * @param string $conversationId Die Conversation-ID
     */
    public function deleteByConversation(string $conversationId): void
    {
        $messages = $this->findBy(['conversationId' => $conversationId]);
        
        foreach ($messages as $message) {
            $this->getEntityManager()->remove($message);
        }
        
        $this->getEntityManager()->flush();
    }

    /**
     * Findet Nachrichten nach Agent-Namen
     * 
     * @param string $agentName Der Agent-Name
     * @param string|null $conversationId Die Conversation-ID (optional)
     * @return array Array von AgentHistory-Entitäten
     */
    public function findByAgentName(string $agentName, ?string $conversationId = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.agentName = :agentName')
            ->setParameter('agentName', $agentName)
            ->orderBy('a.createdAt', 'DESC');

        if ($conversationId !== null) {
            $qb->andWhere('a.conversationId = :conversationId')
                ->setParameter('conversationId', $conversationId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Findet Nachrichten nach Message Type
     * 
     * @param string $messageType Der Message Type
     * @return array Array von AgentHistory-Entitäten
     */
    public function findByMessageType(string $messageType): array
    {
        return $this->findBy(['messageType' => $messageType], ['createdAt' => 'DESC']);
    }

    /**
     * Findet die letzten N Nachrichten für einen User
     * 
     * @param string $userIdentifier Der User-Identifier
     * @param int $limit Die maximale Anzahl von Nachrichten
     * @return array Array von AgentHistory-Entitäten
     */
    public function findRecentByUser(string $userIdentifier, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.userIdentifier = :userIdentifier')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('userIdentifier', $userIdentifier)
            ->getQuery()
            ->getResult();
    }
}
