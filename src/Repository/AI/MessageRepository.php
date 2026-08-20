<?php

namespace App\Repository\AI;

use App\Entity\AI\Message;
use App\Entity\AI\Conversation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 *
 * @method Message|null find($id, $lockMode = null, $lockVersion = null)
 * @method Message|null findOneBy(array $criteria, array $orderBy = null)
 * @method Message[]    findAll()
 * @method Message[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    public function save(Message $message, bool $flush = false): void
    {
        $this->_em->persist($message);
        if ($flush) {
            $this->_em->flush();
        }
    }

    public function remove(Message $message, bool $flush = false): void
    {
        $this->_em->remove($message);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * Find messages by conversation ID with tenant isolation.
     */
    public function findByConversation(string $conversationId): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.conversation = :conversationId')
            ->setParameter('conversationId', $conversationId)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find messages by user ID.
     */
    public function findByUser(string $userId): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a message by ID with tenant isolation check.
     */
    public function findOneByIdAndTenant(string $id, string $tenantId): ?Message
    {
        return $this->createQueryBuilder('m')
            ->join('m.conversation', 'c')
            ->join('c.tenant', 't')
            ->andWhere('m.id = :id')
            ->andWhere('t.id = :tenantId')
            ->setParameter('id', $id)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find a message by ID with user isolation check.
     */
    public function findOneByIdAndUser(string $id, string $userId): ?Message
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id = :id')
            ->andWhere('m.user = :userId')
            ->setParameter('id', $id)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find the first message in a conversation.
     */
    public function findFirstByConversation(string $conversationId): ?Message
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.conversation = :conversationId')
            ->orderBy('m.createdAt', 'ASC')
            ->setMaxResults(1)
            ->setParameter('conversationId', $conversationId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find the last message in a conversation.
     */
    public function findLastByConversation(string $conversationId): ?Message
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.conversation = :conversationId')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(1)
            ->setParameter('conversationId', $conversationId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get message count for a conversation.
     */
    public function countByConversation(string $conversationId): int
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.conversation = :conversationId')
            ->setParameter('conversationId', $conversationId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find messages by role in a conversation.
     */
    public function findByRoleAndConversation(string $conversationId, string $role): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.conversation = :conversationId')
            ->andWhere('m.role = :role')
            ->setParameter('conversationId', $conversationId)
            ->setParameter('role', $role)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find messages by execution ID.
     */
    public function findByExecution(string $executionId): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.executionId = :executionId')
            ->setParameter('executionId', $executionId)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if a message exists for a conversation.
     */
    public function existsByIdAndConversation(string $id, string $conversationId): bool
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.id = :id')
            ->andWhere('m.conversation = :conversationId')
            ->setParameter('id', $id)
            ->setParameter('conversationId', $conversationId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Get the total token count for a conversation.
     */
    public function getTokenCountByConversation(string $conversationId): int
    {
        return $this->createQueryBuilder('m')
            ->select('SUM(m.tokenCount)')
            ->andWhere('m.conversation = :conversationId')
            ->setParameter('conversationId', $conversationId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
