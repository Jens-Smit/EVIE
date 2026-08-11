<?php

namespace App\Controller\Frontend;

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
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(
        AgentHistoryRepository $agentHistoryRepository,
        ToolDefinitionRepository $toolDefinitionRepository,
        DocumentRepository $documentRepository,
        SubAgentRepository $subAgentRepository,
        UserProfileRepository $userRepository
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            // Default-User laden
            $user = $userRepository->find(1); // oder eine andere ID
        }

        if ($user) {
            $recentActions = $agentHistoryRepository->findBy(
                ['user' => $user],
                ['createdAt' => 'DESC'],
                10
            );

            $subAgents = $subAgentRepository->findByUser($user->getId());
        } else {
            $recentActions = [];
            $subAgents = [];
        }

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
