<?php

namespace App\Controller;

use App\Entity\SubAgent;
use App\Entity\UserProfile;
use App\Repository\SubAgentRepository;
use App\Repository\UserProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/subagents', name: 'api_subagents_')]
class SubAgentController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SubAgentRepository $subAgentRepository,
        private UserProfileRepository $userRepository
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            // Default-User laden
            $user = $this->userRepository->find(1); // oder eine andere ID
        }

        $subAgents = $this->subAgentRepository->findByUser($user->getId());

        $data = [];
        foreach ($subAgents as $subAgent) {
            $data[] = [
                'id' => $subAgent->getId(),
                'name' => $subAgent->getName(),
                'description' => $subAgent->getDescription(),
                'createdAt' => $subAgent->getCreatedAt()->format('Y-m-d H:i:s'),
                'status' => $subAgent->getStatus(),
                'capabilities' => $subAgent->getCapabilities()
            ];
        }

        return $this->json($data, Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function get(SubAgent $subAgent): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            // Default-User laden
            $user = $this->userRepository->find(1); // oder eine andere ID
        }

        $history = [];
        foreach ($subAgent->getHistory() as $agentHistory) {
            $history[] = [
                'id' => $agentHistory->getId(),
                'action' => $agentHistory->getAction(),
                'createdAt' => $agentHistory->getCreatedAt()->format('Y-m-d H:i:s')
            ];
        }

        return $this->json([
            'id' => $subAgent->getId(),
            'name' => $subAgent->getName(),
            'description' => $subAgent->getDescription(),
            'createdAt' => $subAgent->getCreatedAt()->format('Y-m-d H:i:s'),
            'status' => $subAgent->getStatus(),
            'capabilities' => $subAgent->getCapabilities(),
            'history' => $history
        ], Response::HTTP_OK);
    }

    #[Route('/{id}/history', name: 'history', methods: ['GET'])]
    public function history(SubAgent $subAgent): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            // Default-User laden
            $user = $this->userRepository->find(1); // oder eine andere ID
        }

        $history = [];
        foreach ($subAgent->getHistory() as $agentHistory) {
            $history[] = [
                'id' => $agentHistory->getId(),
                'action' => $agentHistory->getAction(),
                'createdAt' => $agentHistory->getCreatedAt()->format('Y-m-d H:i:s'),
                'details' => $agentHistory->getDetails()
            ];
        }

        return $this->json($history, Response::HTTP_OK);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            // Default-User laden
            $user = $this->userRepository->find(1); // oder eine andere ID
        }

        $data = json_decode($request->getContent(), true);
        if (empty($data['name']) || empty($data['description'])) {
            return $this->json(['error' => 'Name and description are required'], Response::HTTP_BAD_REQUEST);
        }

        $subAgent = new SubAgent();
        $subAgent->setName($data['name']);
        $subAgent->setDescription($data['description']);
        $subAgent->setUser($user);
        $subAgent->setCapabilities($data['capabilities'] ?? []);
        $subAgent->setStatus($data['status'] ?? 'active');

        $this->entityManager->persist($subAgent);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'subAgent' => [
                'id' => $subAgent->getId(),
                'name' => $subAgent->getName(),
                'description' => $subAgent->getDescription(),
                'createdAt' => $subAgent->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(SubAgent $subAgent): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            // Default-User laden
            $user = $this->userRepository->find(1); // oder eine andere ID
        }

        $this->entityManager->remove($subAgent);
        $this->entityManager->flush();

        return $this->json(['success' => true], Response::HTTP_NO_CONTENT);
    }
}
