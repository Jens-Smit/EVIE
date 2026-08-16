<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\AgentHistoryRepository;
use App\Repository\AuditLogRepository;
use App\Repository\DecisionLogRepository;
use App\Repository\DocumentRepository;
use App\Repository\EmbeddingRepository;
use App\Repository\SubAgentRepository;
use App\Repository\UserProfileRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * DSGVO-Datenschutz-Service (Art. 15 Auskunft, Art. 17 Loeschung).
 *
 * Sammelt alle personenbezogenen Daten eines Nutzers fuer einen Export
 * (Recht auf Auskunft) und loescht sie komplett (Recht auf Vergessenwerden).
 *
 * Tenant-Isolation: alle Operationen sind auf den authenticated User
 * beschraenkt; es wird niemals ueber Tenant-Grenzen hinweg zugegriffen.
 */
final class DataPrivacyService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserProfileRepository $userProfileRepository,
        private readonly AgentHistoryRepository $agentHistoryRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly DecisionLogRepository $decisionLogRepository,
        private readonly SubAgentRepository $subAgentRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly EmbeddingRepository $embeddingRepository,
    ) {
    }

    /**
     * Exportiert alle personenbezogenen Daten eines Nutzers (Art. 15).
     *
     * @return array<string, mixed>
     */
    public function exportUserData(User $user): array
    {
        $profile = $this->userProfileRepository->findOneBy(['user' => $user]);

        return [
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'first_name' => $user->getFirstName(),
                'last_name' => $user->getLastName(),
                'is_active' => $user->isActive(),
                'created_at' => $user->getCreatedAt()?->format('c'),
                'last_login_at' => $user->getLastLoginAt()?->format('c'),
            ],
            'profile' => $profile ? [
                'id' => $profile->getId(),
                'name' => $profile->getName(),
                'user_identifier' => $profile->getUserIdentifier(),
                'email' => $profile->getEmail(),
                'user_type' => $profile->getUserType(),
                'preferences' => $profile->getPreferences(),
                'onboarding_data' => $profile->getOnboardingData(),
            ] : null,
            'agent_history' => $profile
                ? $this->agentHistoryRepository->findBy(['user' => $profile])
                : [],
            'documents' => $profile
                ? $this->documentRepository->findBy(['user' => $profile])
                : [],
            'decisions' => $profile
                ? $this->decisionLogRepository->findBy(['user' => $profile])
                : [],
            'sub_agents' => $profile
                ? $this->subAgentRepository->findBy(['user' => $profile])
                : [],
            'audit_logs' => $this->auditLogRepository->findBy(['userId' => $user->getId()]),
        ];
    }

    /**
     * Loescht alle personenbezogenen Daten eines Nutzers (Art. 17).
     *
     * Cascade-Loeschung ueber alle verknuepften Entities. Der User-Account
     * selbst wird deaktiviert (nicht hart geloescht), um Audit-Trail-
     * Integritaet zu wahren; alle personenbezogenen Inhalte werden entfernt.
     *
     * @return int Anzahl geloeschter Datensaetze
     */
    public function deleteUserData(User $user): int
    {
        $deleted = 0;
        $em = $this->entityManager;

        $profile = $this->userProfileRepository->findOneBy(['user' => $user]);

        if ($profile) {
            // Verknuepfte Entities loeschen (FK auf UserProfile)
            foreach ($this->agentHistoryRepository->findBy(['user' => $profile]) as $entity) {
                $em->remove($entity);
                $deleted++;
            }
            foreach ($this->documentRepository->findBy(['user' => $profile]) as $entity) {
                $em->remove($entity);
                $deleted++;
            }
            foreach ($this->decisionLogRepository->findBy(['user' => $profile]) as $entity) {
                $em->remove($entity);
                $deleted++;
            }
            foreach ($this->subAgentRepository->findBy(['user' => $profile]) as $entity) {
                $em->remove($entity);
                $deleted++;
            }

            // Embeddings mit Tenant-Bezug loeschen (user_identifier)
            $userIdentifier = $profile->getUserIdentifier();
            $embeddings = $this->embeddingRepository
                ->createQueryBuilder('e')
                ->where("e.metadata LIKE :identifier")
                ->setParameter('identifier', '%user_identifier%' . $userIdentifier . '%')
                ->getQuery()
                ->getResult();
            foreach ($embeddings as $entity) {
                $em->remove($entity);
                $deleted++;
            }

            // Profile selbst loeschen
            $em->remove($profile);
            $deleted++;
        }

        // Audit-Logs des Users loeschen
        foreach ($this->auditLogRepository->findBy(['userId' => $user->getId()]) as $entity) {
            $em->remove($entity);
            $deleted++;
        }

        // User deaktivieren (nicht hart loeschen: Audit-Trail-Integritaet)
        $user->setIsActive(false);
        $em->persist($user);

        $em->flush();

        return $deleted;
    }
}
