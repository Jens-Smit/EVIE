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

        return $this->render('dashboard/index.html.twig', [
            'recentActions' => $recentActions,
            'pendingTools' => $pendingTools,
            'recentDocuments' => $recentDocuments,
            'subAgents' => $subAgents
        ]);
    }
}
