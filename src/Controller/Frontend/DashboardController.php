<?php

namespace App\Controller\Frontend;

use App\Entity\User;
use App\Entity\UserProfile;
use App\Repository\AgentHistoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\SubAgentRepository;
use App\Repository\ToolDefinitionRepository;
use App\Repository\UserProfileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly UserProfileRepository $userProfileRepository,
    ) {
    }

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

        // Die Repositories fuer AgentHistory, SubAgent und Document sind an das
        // UserProfile-Entity gebunden (JoinColumn user -> UserProfile), nicht an
        // die Security-User-Entity. Das Profil wird daher ueber den userIdentifier
        // aufgeloest. Gibt es noch kein Profil (z.B. direkt nach Onboarding ohne
        // persistierten Profil-Datensatz), arbeiten die Abfragen mit einem leeren
        // Profil, sodass das Dashboard trotzdem rendert.
        $userProfile = $this->userProfileRepository->findOneBy([
            'userIdentifier' => $user->getUserIdentifier(),
        ]);

        $recentActions = [];
        $subAgents = [];
        if ($userProfile instanceof UserProfile) {
            $recentActions = $agentHistoryRepository->findBy(
                ['user' => $userProfile],
                ['createdAt' => 'DESC'],
                10
            );
            $subAgents = $subAgentRepository->findByUser($userProfile->getId());
        }

        $pendingTools = $toolDefinitionRepository->findBy(
            ['status' => 'pending']
        );

        $recentDocuments = $documentRepository->findRecent(5);

        // Statistiken fuer Dashboard
        $totalTools = $toolDefinitionRepository->count(['status' => 'approved']);
        $totalAgents = $subAgentRepository->count([]);
        $totalDocuments = $documentRepository->count([]);
        $totalActions = $userProfile instanceof UserProfile
            ? $agentHistoryRepository->count(['user' => $userProfile])
            : 0;

        // Daten fuer Aktivitaets-Diagramm (letzte 24 Stunden)
        $activityData = $this->getActivityData($agentHistoryRepository, $userProfile);

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
     * Extrahiere Aktivitaets-Daten fuer das Diagramm.
     */
    private function getActivityData(AgentHistoryRepository $agentHistoryRepo, ?UserProfile $userProfile): array
    {
        if (!$userProfile instanceof UserProfile) {
            return [];
        }

        $now = new \DateTimeImmutable();
        $twentyFourHoursAgo = $now->sub(new \DateInterval('PT24H'));

        $actions = $agentHistoryRepo->createQueryBuilder('ah')
            ->where('ah.user = :user')
            ->andWhere('ah.createdAt >= :twentyFourHoursAgo')
            ->setParameter('user', $userProfile)
            ->setParameter('twentyFourHoursAgo', $twentyFourHoursAgo)
            ->orderBy('ah.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $dataPoints = [];
        foreach ($actions as $action) {
            $dataPoints[] = [
                'timestamp' => $action->getCreatedAt()->getTimestamp(),
                'action' => $action->getAction(),
                'value' => 1, // Jede Aktion zaehlt als 1
            ];
        }

        return $dataPoints;
    }
}
