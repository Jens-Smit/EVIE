<?php

namespace App\Controller;

use App\AI\Skills\ToolDefinitionGenerator;
use App\Repository\ToolDefinitionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/tools')]
final class ToolApprovalController
{
    public function __construct(
        private readonly ToolDefinitionRepository $toolDefinitionRepo,
        private readonly ToolDefinitionGenerator $toolGenerator,
    ) {
    }

    /**
     * Blueprint Phase 4: "simple UI/API-Route zur Genehmigung von Pending-Tools".
     */
    #[Route('/pending', name: 'tools_pending', methods: ['GET'])]
    public function pending(): JsonResponse
    {
        $pending = $this->toolDefinitionRepo->findBy(['status' => 'pending_approval']);

        return new JsonResponse(array_map(
            static fn ($tool) => [
                'id' => $tool->getId(),
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'schema' => $tool->getSchema(),
            ],
            $pending
        ));
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/{id}/approve', name: 'tools_approve', methods: ['POST'])]
    public function approve(int $id): JsonResponse
    {
        $tool = $this->toolDefinitionRepo->find($id);

        if (!$tool) {
            return new JsonResponse(['error' => 'Tool nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        $this->toolGenerator->approveTool($tool);

        return new JsonResponse(['status' => 'approved', 'name' => $tool->getName()]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/{id}/reject', name: 'tools_reject', methods: ['POST'])]
    public function reject(int $id): JsonResponse
    {
        $tool = $this->toolDefinitionRepo->find($id);

        if (!$tool) {
            return new JsonResponse(['error' => 'Tool nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        $tool->setStatus('rejected');
        $this->toolDefinitionRepo->save($tool, true);

        return new JsonResponse(['status' => 'rejected', 'name' => $tool->getName()]);
    }
}