<?php
// src/Controller/Frontend/ToolApprovalController.php
namespace App\Controller\Frontend;

use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Attribute\Route;

class ToolApprovalController extends AbstractController
{
    public function __construct(
        private ToolDefinitionRepository $toolDefinitionRepo,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/tools/pending', name: 'frontend_tools_pending', methods: ['GET'])]
    public function pending(): Response
    {
        // Hole alle ausstehenden Tools aus der Datenbank
        $pendingTools = $this->toolDefinitionRepo->findBy([
            'status' => ['pending', 'pending_approval'],
        ]);

        $this->logger->debug('Ausstehende Tools geladen', ['count' => count($pendingTools)]);

        // Transformiere die Tools in das Format, das das Template erwartet
        $tools = array_map(function (ToolDefinition $tool) {
            return [
                'id' => $tool->getId(),
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'requester' => 'System', // Standardwert, da die Entität kein requester-Feld hat
                'timestamp' => $tool->getCreatedAt(), // Verwende das Erstellungsdatum
                'schema' => $tool->getSchema(),
                'status' => $tool->getStatus(),
            ];
        }, $pendingTools);

        $this->logger->debug('Tools fuer Template transformiert', ['count' => count($tools)]);

        return $this->render('tools/pending.html.twig', [
            'tools' => $tools,
        ]);
    }
}
