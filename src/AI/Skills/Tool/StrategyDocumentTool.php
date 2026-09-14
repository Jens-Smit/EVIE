<?php

declare(strict_types=1);

namespace App\AI\Skills\Tool;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use App\Repository\UserProfileRepository;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Statisches Tool zum Speichern und Aktualisieren von Strategiedokumenten.
 *
 * Ermöglicht dem Orchestrator, ein Strategiedokument/Businessplan als
 * persistente Document-Entity anzulegen. Das Tool ist nativ als #[AsTool]
 * registriert (kein Konstruktor-Injection für Tools, Blueprint §4.D) und
 * wird über die native Toolbox des Orchestrator-Agenten aufgerufen.
 *
 * SecurityGuard/HITL: das Tool greift nicht auf externe Systeme zu und ist
 * als low-security eingestuft. Die Document-Entity ist pro User/Profile
 * isoliert (Tenant-Isolation).
 */
#[AsTool(
    name: 'strategy_document',
    description: 'Speichert oder aktualisiert ein Strategiedokument (z.B. Businessplan, Strategieplan) als persistente Document-Entity. Parameter: name (Dokumentname), content (Volltext des Dokuments).'
)]
final class StrategyDocumentTool
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private UserProfileRepository $userProfileRepository,
    ) {
    }

    public function __invoke(array $parameters = []): array
    {
        $name = $parameters['name'] ?? '';
        $content = $parameters['content'] ?? '';
        $userIdentifier = $parameters['user_identifier'] ?? '';

        if ($name === '' || $content === '') {
            throw new \RuntimeException('Parameter name und content sind erforderlich.');
        }

        $userProfile = $this->userProfileRepository->findOneBy(['userIdentifier' => $userIdentifier]);
        if ($userProfile === null) {
            throw new \RuntimeException(sprintf('UserProfile fuer user_identifier "%s" nicht gefunden.', $userIdentifier));
        }

        $document = new Document();
        $document->setName($name);
        $document->setContent($content);
        $document->setUser($userProfile);

        $this->documentRepository->save($document, true);

        return [
            'status' => 'success',
            'document_id' => $document->getId(),
            'document_name' => $document->getName(),
            'message' => sprintf('Strategiedokument "%s" wurde gespeichert (ID: %d).', $name, $document->getId() ?? 0),
        ];
    }
}
