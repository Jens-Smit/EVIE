<?php

namespace App\Controller;

use App\Repository\AgentHistoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\SubAgentRepository;
use App\Repository\ToolDefinitionRepository;
use App\Repository\UserProfileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/dashboard', name: 'api_dashboard_')]
class DashboardController extends AbstractController
{
    public function __construct(
        private AgentHistoryRepository $agentHistoryRepository,
        private ToolDefinitionRepository $toolDefinitionRepository,
        private DocumentRepository $documentRepository,
        private SubAgentRepository $subAgentRepository,
        private UserProfileRepository $userRepository
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $user = $this->getUser();
        $userProfile = null;
        if ($user) {
            // UserProfile ist an den userIdentifier gebunden, nicht an die
            // Security-User-Entity. Repositories erwarten ein UserProfile.
            $userProfile = $this->userRepository->findOneBy([
                'userIdentifier' => $user->getUserIdentifier(),
            ]);
        }

        $recentActions = [];
        if ($userProfile) {
            $recentActions = $this->agentHistoryRepository->findBy(
                ['user' => $userProfile],
                ['createdAt' => 'DESC'],
                10
            );
        }

        $pendingTools = $this->toolDefinitionRepository->findBy(
            ['status' => 'pending']
        );
        $recentDocuments = $this->documentRepository->findRecent(5);

        $subAgents = [];
        if ($userProfile) {
            $subAgents = $this->subAgentRepository->findByUser($userProfile->getId());
        }

        $dashboardData = [
            'recentActions' => array_map(function ($action) {
                return [
                    'id' => $action->getId(),
                    'action' => $action->getAction(),
                    'createdAt' => $action->getCreatedAt()->format('Y-m-d H:i:s'),
                    'details' => $action->getDetails()
                ];
            }, $recentActions),
            'pendingTools' => array_map(function ($tool) {
                return [
                    'id' => $tool->getId(),
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'status' => $tool->getStatus()
                ];
            }, $pendingTools),
            'recentDocuments' => array_map(function ($document) {
                return [
                    'id' => $document->getId(),
                    'name' => $document->getName(),
                    'createdAt' => $document->getCreatedAt()->format('Y-m-d H:i:s')
                ];
            }, $recentDocuments),
            'subAgents' => array_map(function ($subAgent) {
                return [
                    'id' => $subAgent->getId(),
                    'name' => $subAgent->getName(),
                    'description' => $subAgent->getDescription(),
                    'status' => $subAgent->getStatus()
                ];
            }, $subAgents)
        ];

        return $this->json($dashboardData, Response::HTTP_OK);
    }
}
