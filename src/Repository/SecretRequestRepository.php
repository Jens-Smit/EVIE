<?php

namespace App\Repository;

use App\Entity\SecretRequest;
use App\Entity\ToolDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecretRequest>
 */
class SecretRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecretRequest::class);
    }

    /**
     * Findet alle SecretRequests für ein Tool
     */
    public function findByTool(ToolDefinition $tool): array
    {
        return $this->findBy([
            'tool' => $tool
        ], ['isRequired' => 'DESC', 'createdAt' => 'ASC']);
    }

    /**
     * Findet alle benötigten SecretRequests für ein Tool
     */
    public function findRequiredByTool(ToolDefinition $tool): array
    {
        return $this->findBy([
            'tool' => $tool,
            'isRequired' => true
        ], ['createdAt' => 'ASC']);
    }

    /**
     * Findet alle optionalen SecretRequests für ein Tool
     */
    public function findOptionalByTool(ToolDefinition $tool): array
    {
        return $this->findBy([
            'tool' => $tool,
            'isRequired' => false
        ], ['createdAt' => 'ASC']);
    }

    /**
     * Prüft, ob ein Tool SecretRequests hat
     */
    public function hasRequestsForTool(ToolDefinition $tool): bool
    {
        return $this->count(['tool' => $tool]) > 0;
    }

    /**
     * Findet SecretRequests nach SecretKey
     */
    public function findBySecretKey(string $secretKey): array
    {
        return $this->findBy(['secretKey' => $secretKey]);
    }

    /**
     * Löscht alle SecretRequests für ein Tool
     */
    public function deleteAllForTool(ToolDefinition $tool): void
    {
        $requests = $this->findBy(['tool' => $tool]);
        
        foreach ($requests as $request) {
            $this->_em->remove($request);
        }
        
        $this->_em->flush();
    }

    /**
     * Zählt die Anzahl der SecretRequests für ein Tool
     */
    public function countForTool(ToolDefinition $tool): int
    {
        return $this->count(['tool' => $tool]);
    }

    /**
     * Findet SecretRequests, die einen bestimmten Text enthalten
     */
    public function search(string $query): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.secretKey LIKE :query OR r.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
