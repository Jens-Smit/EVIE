<?php
// src/Controller/Frontend/ToolApprovalController.php
namespace App\Controller\Frontend;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ToolApprovalController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    #[Route('/tools/pending', name: 'frontend_tools_pending', methods: ['GET'])]
    public function pending(): Response
    {
        try {
            $response = $this->httpClient->request('GET', '/api/tools/pending');
            $tools = $response->toArray();

            return $this->render('tools/pending.html.twig', [
                'tools' => $tools,
            ]);
        } catch (\Exception $e) {
            return $this->render('tools/pending.html.twig', [
                'tools' => [],
                'error' => 'Fehler beim Laden der Tools: ' . $e->getMessage(),
            ]);
        }
    }

    #[Route('/tools/{id}/approve', name: 'frontend_tools_approve', methods: ['POST'])]
    public function approve(int $id, Request $request): Response
    {
        try {
            $response = $this->httpClient->request('POST', "/api/tools/{$id}/approve");
            $data = $response->toArray();

            return $this->json([
                'success' => true,
                'message' => $data['status'] ?? 'Tool erfolgreich freigegeben.',
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/tools/{id}/reject', name: 'frontend_tools_reject', methods: ['POST'])]
    public function reject(int $id, Request $request): Response
    {
        try {
            $response = $this->httpClient->request('POST', "/api/tools/{$id}/reject");
            $data = $response->toArray();

            return $this->json([
                'success' => true,
                'message' => $data['status'] ?? 'Tool erfolgreich abgelehnt.',
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
