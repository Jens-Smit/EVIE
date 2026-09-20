<?php

// src/Controller/ApprovalInboxController.php

namespace App\Controller;

use App\AI\Decision\DecisionManager;
use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Zentrale Freigabe-Inbox (Human-in-the-Loop) fuer EVIE.
 *
 * Alle Freigaben, die eine Entscheidung des Benutzers erfordern, laufen hier
 * zusammen: neue Faehigkeiten (ToolDefinition pending) und Entscheidungen
 * (DecisionLog pending). Fuer den Benutzer fuehlt sich HITL damit wie ein
 * einziges System an, statt wie fuenf parallele Mechanismen.
 */
final class ApprovalInboxController extends AbstractController
{
    public function __construct(
        private readonly ToolDefinitionRepository $toolDefinitionRepository,
        private readonly DecisionManager $decisionManager,
    ) {
    }

    #[Route('/approvals', name: 'app_approvals', methods: ['GET'])]
    public function inbox(#[CurrentUser] ?UserInterface $user = null): Response
    {
        if (null === $user) {
            return $this->redirectToRoute('app_login');
        }

        $userIdentifier = $user->getUserIdentifier();

        $pendingTools = $this->toolDefinitionRepository->findBy([
            'status' => ['pending', 'pending_approval'],
        ], ['createdAt' => 'DESC']);

        usort($pendingTools, static fn (ToolDefinition $a, ToolDefinition $b): int => $b->getCreatedAt() <=> $a->getCreatedAt());

        $pendingDecisions = $this->decisionManager->getPendingDecisions($userIdentifier);

        $toolInbox = array_map(static fn (ToolDefinition $tool): array => [
            'kind' => 'tool',
            'id' => $tool->getId(),
            'title' => $tool->getName() ?? 'Unbenannte Fähigkeit',
            'summary' => $tool->getDescription() ?? '',
            'detail' => sprintf(
                'EVIE benötigt die Fähigkeit „%s“, um deine Aufgabe auszuführen.',
                $tool->getName() ?? 'Unbenannte Fähigkeit',
            ),
            'risk' => $tool->getSecurityLevel(),
            'created_at' => $tool->getCreatedAt(),
            'show_path' => 'app_tool_pending_show',
        ], $pendingTools);

        $decisionInbox = array_map(static function (array $decision): array {
            return [
                'kind' => 'decision',
                'id' => $decision['id'],
                'title' => $decision['description'] ?? $decision['type'] ?? 'Entscheidung',
                'summary' => $decision['type'] ?? '',
                'detail' => $decision['description'] ?? '',
                'risk' => 'medium',
                'created_at' => $decision['created_at'] ?? null,
                'show_path' => 'app_decisions',
            ];
        }, $pendingDecisions);

        // Aelteste Freigaben zuerst: Der Benutzer bearbeitet Warteschlangen in
        // der Reihenfolge, in der EVIE sie angefragt hat.
        $items = array_merge($toolInbox, $decisionInbox);
        usort($items, static function (array $a, array $b): int {
            $aTime = $a['created_at'] instanceof \DateTimeInterface
                ? $a['created_at']->getTimestamp()
                : (is_string($a['created_at']) ? strtotime($a['created_at']) ?: 0 : 0);
            $bTime = $b['created_at'] instanceof \DateTimeInterface
                ? $b['created_at']->getTimestamp()
                : (is_string($b['created_at']) ? strtotime($b['created_at']) ?: 0 : 0);

            return $aTime <=> $bTime;
        });

        return $this->render('approvals/index.html.twig', [
            'items' => $items,
            'toolCount' => count($toolInbox),
            'decisionCount' => count($decisionInbox),
            'userIdentifier' => $userIdentifier,
        ]);
    }
}
