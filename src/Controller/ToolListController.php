<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ToolDefinitionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller für die Anzeige aller verfügbaren Tools (statisch & dynamisch).
 *
 * Dynamische Tools werden über die native DynamicToolbox zur Laufzeit aus der
 * Datenbank geladen (Blueprint §4.B) — dieser Controller zeigt die in der DB
 * persistierten ToolDefinitions an.
 */
final class ToolListController extends AbstractController
{
    public function __construct(
        private ToolDefinitionRepository $toolDefinitionRepo,
    ) {
    }

    /**
     * Zeigt eine Übersicht aller verfügbaren Tools an.
     */
    #[Route('/tools/list', name: 'app_tools_list')]
    public function listTools(Request $request): Response
    {
        $approvedTools = $this->toolDefinitionRepo->findBy([
            'status' => 'approved',
        ]);

        $allTools = [];
        foreach ($approvedTools as $tool) {
            $allTools[] = [
                'id' => $tool->getId(),
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'type' => 'database',
                'status' => $tool->getStatus(),
                'schema' => $tool->getSchema(),
                'created_at' => $tool->getCreatedAt(),
            ];
        }

        usort($allTools, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });

        return $this->render('tools/list.html.twig', [
            'databaseTools' => $allTools,
            'dynamicTools' => [],
            'totalCount' => count($allTools),
        ]);
    }
}
