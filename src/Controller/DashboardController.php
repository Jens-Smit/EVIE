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
        if (!$user) {
            // Default-User laden
            $user = $this->userRepository->find(1); // oder eine andere ID
        }

        $recentActions = $this->agentHistoryRepository->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
            10
        );

        $pendingTools = $this->toolDefinitionRepository->findBy(
            ['status' => 'pending']
        );

        $recentDocuments = $this->documentRepository->findRecent(5);
        $subAgents = $this->subAgentRepository->findByUser($user->getId());

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
