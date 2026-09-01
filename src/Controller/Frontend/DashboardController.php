<?php

namespace App\Controller\Frontend;

use App\Entity\User;
use App\Repository\AgentHistoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\SubAgentRepository;
use App\Repository\ToolDefinitionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(
        AgentHistoryRepository $agentHistoryRepository,
        ToolDefinitionRepository $toolDefinitionRepository,
        DocumentRepository $documentRepository,
        SubAgentRepository $subAgentRepository
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $recentActions = $agentHistoryRepository->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
            10
        );

        $subAgents = $subAgentRepository->findByUser($user->getId());

        $pendingTools = $toolDefinitionRepository->findBy(
            ['status' => 'pending']
        );

        $recentDocuments = $documentRepository->findRecent(5);
        
        // Statistiken fuer Dashboard
        $totalTools = $toolDefinitionRepository->count(['status' => 'approved']);
        $totalAgents = $subAgentRepository->count([]);
        $totalDocuments = $documentRepository->count([]);
        $totalActions = $agentHistoryRepository->count(['user' => $user]);
        
        // Daten für Aktivitäts-Diagramm (letzte 24 Stunden)
        $activityData = $this->getActivityData($agentHistoryRepository, $user);

        return $this->render('dashboard/index.html.twig', [
            'recentActions' => $recentActions,
            'pendingTools' => $pendingTools,
            'recentDocuments' => $recentDocuments,
            'subAgents' => $subAgents,
            'pendingToolsCount' => count($pendingTools),
            'totalTools' => $totalTools,
            'totalAgents' => $totalAgents,
            'totalDocuments' => $totalDocuments,
            'totalActions' => $totalActions,
            'activityData' => $activityData,
        ]);
    }

    /**
     * Extrahiere Aktivitäts-Daten für das Diagramm.
     */
    private function getActivityData(AgentHistoryRepository $agentHistoryRepo, User $user): array
    {
        $now = new \DateTimeImmutable();
        $twentyFourHoursAgo = $now->sub(new \DateInterval('PT24H'));
        
        // Hole alle Aktionen der letzten 24 Stunden
        $actions = $agentHistoryRepo->createQueryBuilder('ah')
            ->where('ah.user = :user')
            ->andWhere('ah.createdAt >= :twentyFourHoursAgo')
            ->setParameter('user', $user)
            ->setParameter('twentyFourHoursAgo', $twentyFourHoursAgo)
            ->orderBy('ah.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
        
        $dataPoints = [];
        foreach ($actions as $action) {
            $dataPoints[] = [
                'timestamp' => $action->getCreatedAt()->getTimestamp(),
                'action' => $action->getAction(),
                'value' => 1, // Jede Aktion zählt als 1
            ];
        }
        
        return $dataPoints;
    }
}
