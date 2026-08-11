<?php
// src/Controller/ToolListController.php

namespace App\Controller;

use App\Repository\ToolDefinitionRepository;
use App\AI\Skills\DynamicSkillRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller für die Anzeige aller verfügbaren Tools (statisch & dynamisch).
 */
final class ToolListController extends AbstractController
{
    public function __construct(
        private ToolDefinitionRepository $toolDefinitionRepo,
        private DynamicSkillRegistry $dynamicSkillRegistry,
    ) {
    }

    /**
     * Zeigt eine Übersicht aller verfügbaren Tools an.
     */
    #[Route('/tools/list', name: 'app_tools_list')]
    public function listTools(Request $request): Response
    {
        // 1. Genehmigte Tools aus der Datenbank
        $approvedTools = $this->toolDefinitionRepo->findBy([
            'status' => 'approved',
        ]);

        // 2. Dynamisch registrierte Tools aus dem Registry
        // getAvailableTools() gibt ein Array mit Tool-Namen als Keys und Metadaten als Values zurück
        $dynamicToolsMetadata = $this->dynamicSkillRegistry->getAvailableTools();

        // 3. Kombiniere und entferne Duplikate
        $allTools = [];
        $seenNames = [];

        // Füge genehmigte Datenbank-Tools hinzu
        foreach ($approvedTools as $tool) {
            if (!isset($seenNames[$tool->getName()])) {
                $seenNames[$tool->getName()] = true;
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
        }

        // Füge dynamische Tools hinzu (die nicht in der DB sind)
        foreach ($dynamicToolsMetadata as $toolName => $toolConfig) {
            if (!isset($seenNames[$toolName])) {
                $seenNames[$toolName] = true;
                $allTools[] = [
                    'id' => null,
                    'name' => $toolName,
                    'description' => $toolConfig['description'] ?? 'Keine Beschreibung verfügbar',
                    'type' => 'dynamic',
                    'status' => $toolConfig['status'] ?? 'active',
                    'schema' => $toolConfig['schema'] ?? [],
                    'created_at' => null,
                ];
            }
        }

        // Sortiere alphabetisch nach Name
        usort($allTools, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        // Gruppiere nach Typ
        $groupedTools = [
            'database' => [],
            'dynamic' => [],
        ];

        foreach ($allTools as $tool) {
            $groupedTools[$tool['type']][] = $tool;
        }

        return $this->render('tools/list.html.twig', [
            'databaseTools' => $groupedTools['database'],
            'dynamicTools' => $groupedTools['dynamic'],
            'totalCount' => count($allTools),
        ]);
    }
}
