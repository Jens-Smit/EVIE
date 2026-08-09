<?php
// src/AI/Decision/DecisionManager.php

namespace App\AI\Decision;

use App\Entity\DecisionLog;
use App\Repository\DecisionLogRepository;
use Psr\Log\LoggerInterface;

/**
 * Verwaltet Entscheidungen, die eine Human-in-the-Loop Freigabe erfordern.
 * Protokolliert alle Entscheidungen und ermöglicht Genehmigung/Ablehnung.
 */
class DecisionManager
{
    public function __construct(
        private DecisionLogRepository $decisionLogRepo,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Protokolliert eine Entscheidung zur Freigabe
     */
    public function logDecision(
        string $decisionType,
        string $description,
        array $context = [],
        array $options = [],
        string $userIdentifier = null
    ): DecisionLog {
        $decisionLog = new DecisionLog();
        $decisionLog->setDecisionType($decisionType);
        $decisionLog->setDescription($description);
        $decisionLog->setContext($context);
        $decisionLog->setOptions($options);
        $decisionLog->setStatus('pending');

        // Wenn UserProfile verfügbar, verknüpfen
        if ($userIdentifier) {
            // Hier würde man normalerweise das UserProfile-Entity suchen
            // Für jetzt: nur den UserIdentifier in Metadata speichern
            $decisionLog->setMetadata([
                'user_identifier' => $userIdentifier,
            ]);
        }

        $this->decisionLogRepo->save($decisionLog, true);

        $this->logger->info('Entscheidung protokolliert', [
            'decision_id' => $decisionLog->getId(),
            'type' => $decisionType,
            'description' => substr($description, 0, 100),
        ]);

        return $decisionLog;
    }

    /**
     * Genehmigt eine Entscheidung
     */
    public function approveDecision(DecisionLog $decision, string $approvedBy): void
    {
        $decision->setStatus('approved');
        $decision->setApprovedBy($approvedBy);
        $decision->setApprovedAt(new \DateTimeImmutable());

        $metadata = $decision->getMetadata() ?? [];
        $metadata['approved_at'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $metadata['approved_by'] = $approvedBy;
        $decision->setMetadata($metadata);

        $this->decisionLogRepo->save($decision, true);

        $this->logger->info('Entscheidung genehmigt', [
            'decision_id' => $decision->getId(),
            'type' => $decision->getDecisionType(),
            'approved_by' => $approvedBy,
        ]);
    }

    /**
     * Lehnt eine Entscheidung ab
     */
    public function rejectDecision(DecisionLog $decision, string $rejectedBy, string $reason = null): void
    {
        $decision->setStatus('rejected');
        $decision->setApprovedBy($rejectedBy);
        $decision->setApprovedAt(new \DateTimeImmutable());

        $metadata = $decision->getMetadata() ?? [];
        $metadata['rejected_at'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $metadata['rejected_by'] = $rejectedBy;
        $metadata['rejection_reason'] = $reason;
        $decision->setMetadata($metadata);

        $this->decisionLogRepo->save($decision, true);

        $this->logger->info('Entscheidung abgelehnt', [
            'decision_id' => $decision->getId(),
            'type' => $decision->getDecisionType(),
            'rejected_by' => $rejectedBy,
            'reason' => $reason,
        ]);
    }

    /**
     * Gibt alle ausstehenden Entscheidungen zurück
     */
    public function getPendingDecisions(string $userIdentifier = null): array
    {
        $decisions = $this->decisionLogRepo->findAllPending();

        // Filter nach User, falls angegeben
        if ($userIdentifier) {
            $decisions = array_filter($decisions, function($decision) use ($userIdentifier) {
                $metadata = $decision->getMetadata() ?? [];
                return ($metadata['user_identifier'] ?? null) === $userIdentifier;
            });
        }

        return array_map(function($decision) {
            return [
                'id' => $decision->getId(),
                'type' => $decision->getDecisionType(),
                'description' => $decision->getDescription(),
                'context' => $decision->getContext(),
                'options' => $decision->getOptions(),
                'created_at' => $decision->getCreatedAt()->format('Y-m-d H:i:s'),
                'metadata' => $decision->getMetadata(),
            ];
        }, $decisions);
    }

    /**
     * Gibt eine bestimmte Entscheidung zurück
     */
    public function getDecision(int $decisionId): ?DecisionLog
    {
        return $this->decisionLogRepo->find($decisionId);
    }

    /**
     * Gibt Entscheidungsstatistiken zurück
     */
    public function getDecisionStatistics(string $userIdentifier = null): array
    {
        $stats = $this->decisionLogRepo->getStatistics();

        // Filter nach User, falls angegeben
        if ($userIdentifier) {
            $userDecisions = $this->decisionLogRepo->findByUser($userIdentifier);
            $stats['user_total'] = count($userDecisions);
            $stats['user_pending'] = count(array_filter($userDecisions, fn($d) => $d->isPending()));
            $stats['user_approved'] = count(array_filter($userDecisions, fn($d) => $d->isApproved()));
            $stats['user_rejected'] = count(array_filter($userDecisions, fn($d) => $d->isRejected()));
        }

        return $stats;
    }

    /**
     * Gibt Entscheidungen nach Typ zurück
     */
    public function getDecisionsByType(string $type, string $userIdentifier = null): array
    {
        $decisions = $this->decisionLogRepo->findByType($type);

        // Filter nach User, falls angegeben
        if ($userIdentifier) {
            $decisions = array_filter($decisions, function($decision) use ($userIdentifier) {
                $metadata = $decision->getMetadata() ?? [];
                return ($metadata['user_identifier'] ?? null) === $userIdentifier;
            });
        }

        return array_map(function($decision) {
            return [
                'id' => $decision->getId(),
                'type' => $decision->getDecisionType(),
                'description' => $decision->getDescription(),
                'status' => $decision->getStatus(),
                'created_at' => $decision->getCreatedAt()->format('Y-m-d H:i:s'),
                'approved_at' => $decision->getApprovedAt()?->format('Y-m-d H:i:s'),
                'approved_by' => $decision->getApprovedBy(),
            ];
        }, $decisions);
    }

    /**
     * Gibt die neuesten Entscheidungen zurück
     */
    public function getRecentDecisions(int $limit = 10, string $userIdentifier = null): array
    {
        $decisions = $this->decisionLogRepo->findRecent($limit);

        // Filter nach User, falls angegeben
        if ($userIdentifier) {
            $decisions = array_filter($decisions, function($decision) use ($userIdentifier) {
                $metadata = $decision->getMetadata() ?? [];
                return ($metadata['user_identifier'] ?? null) === $userIdentifier;
            });
        }

        return array_map(function($decision) {
            return [
                'id' => $decision->getId(),
                'type' => $decision->getDecisionType(),
                'description' => $decision->getDescription(),
                'status' => $decision->getStatus(),
                'created_at' => $decision->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }, $decisions);
    }

    /**
     * Erstellt eine Tool-Freigabe-Entscheidung
     */
    public function createToolApprovalDecision(
        int $toolId,
        string $toolName,
        string $description,
        string $requester,
        string $userIdentifier = null
    ): DecisionLog {
        return $this->logDecision(
            decisionType: 'tool_approval',
            description: "Freigabe für Tool: {$toolName}",
            context: [
                'tool_id' => $toolId,
                'tool_name' => $toolName,
                'description' => $description,
                'requester' => $requester,
            ],
            options: [
                'action' => 'approve',
                'alternative' => 'reject',
            ],
            userIdentifier: $userIdentifier
        );
    }

    /**
     * Erstellt eine API-Zugriffs-Entscheidung
     */
    public function createApiAccessDecision(
        string $apiName,
        string $endpoint,
        string $method,
        string $userIdentifier = null
    ): DecisionLog {
        return $this->logDecision(
            decisionType: 'api_access',
            description: "Zugriff auf API: {$apiName} ({$method} {$endpoint})",
            context: [
                'api_name' => $apiName,
                'endpoint' => $endpoint,
                'method' => $method,
            ],
            options: [
                'allow' => true,
                'deny' => false,
            ],
            userIdentifier: $userIdentifier
        );
    }

    /**
     * Erstellt eine Datenlösch-Entscheidung
     */
    public function createDataDeletionDecision(
        string $dataType,
        int $recordId,
        string $userIdentifier = null
    ): DecisionLog {
        return $this->logDecision(
            decisionType: 'data_deletion',
            description: "Löschen von {$dataType} mit ID: {$recordId}",
            context: [
                'data_type' => $dataType,
                'record_id' => $recordId,
            ],
            options: [
                'confirm' => true,
                'cancel' => false,
            ],
            userIdentifier: $userIdentifier
        );
    }

    /**
     * Erstellt eine Kommunikations-Entscheidung
     */
    public function createCommunicationDecision(
        string $communicationType,
        string $recipient,
        string $content,
        string $userIdentifier = null
    ): DecisionLog {
        return $this->logDecision(
            decisionType: 'communication',
            description: "Kommunikation: {$communicationType} an {$recipient}",
            context: [
                'type' => $communicationType,
                'recipient' => $recipient,
                'content_preview' => substr($content, 0, 100),
            ],
            options: [
                'send' => true,
                'cancel' => false,
            ],
            userIdentifier: $userIdentifier
        );
    }

    /**
     * Prüft, ob eine Entscheidung aussteht
     */
    public function hasPendingDecisions(string $userIdentifier = null): bool
    {
        $pendingDecisions = $this->getPendingDecisions($userIdentifier);
        return count($pendingDecisions) > 0;
    }

    /**
     * Gibt die Anzahl ausstehender Entscheidungen zurück
     */
    public function countPendingDecisions(string $userIdentifier = null): int
    {
        return count($this->getPendingDecisions($userIdentifier));
    }
}
