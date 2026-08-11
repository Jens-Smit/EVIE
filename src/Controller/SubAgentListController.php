<?php
// src/Controller/SubAgentListController.php

namespace App\Controller;

use App\AI\Agent\SubAgentFactory;
use App\Repository\ToolDefinitionRepository;
use App\Entity\ToolDefinition;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Controller für die Anzeige und Verwaltung von Sub-Agenten (Web-Interface).
 */
final class SubAgentListController extends AbstractController
{
    public function __construct(
        private SubAgentFactory $subAgentFactory,
        private ToolDefinitionRepository $toolDefinitionRepo,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Zeigt eine Übersicht aller verfügbaren Sub-Agenten an.
     */
    #[Route('/subagents/list', name: 'app_subagents_list')]
    public function listSubAgents(Request $request): Response
    {
        // Hole alle verfügbaren Sub-Agenten
        $availableSubAgents = $this->subAgentFactory->getAvailableSubAgents();
        
        // Hole alle genehmigten Tools aus der Datenbank
        $approvedTools = $this->toolDefinitionRepo->findBy([
            'status' => 'approved',
        ]);
        
        // Bereite die Sub-Agenten-Daten für das Template vor
        $subAgentsData = [];
        foreach ($availableSubAgents as $name => $agent) {
            // Finde Tools, die diesem Sub-Agenten zugeordnet sind
            $assignedTools = array_filter($approvedTools, function($tool) use ($name) {
                $metadata = $tool->getMetadata() ?? [];
                return isset($metadata['sub_agent']) && $metadata['sub_agent'] === $name;
            });
            
            $subAgentsData[] = [
                'name' => $name,
                'role' => $name,
                'description' => $this->getDescriptionForRole($name),
                'assigned_tools' => array_map(function($tool) {
                    return [
                        'id' => $tool->getId(),
                        'name' => $tool->getName(),
                        'description' => $tool->getDescription(),
                    ];
                }, $assignedTools),
                'all_tools' => array_map(function($tool) {
                    return [
                        'id' => $tool->getId(),
                        'name' => $tool->getName(),
                        'description' => $tool->getDescription(),
                    ];
                }, $approvedTools),
            ];
        }

        return $this->render('subagents/list.html.twig', [
            'subAgents' => $subAgentsData,
            'allTools' => $approvedTools,
        ]);
    }

    /**
     * Erstellt einen neuen Sub-Agenten über Chat-Eingabe.
     */
    #[Route('/subagents/create', name: 'app_subagents_create', methods: ['POST'])]
    public function createSubAgent(Request $request): Response
    {
        $subAgentName = trim($request->request->get('subagent_name'));
        $subAgentRole = trim($request->request->get('subagent_role', $subAgentName));
        $selectedTools = $request->request->all('tools') ?? [];
        
        if (empty($subAgentName)) {
            $this->addFlash('error', 'Bitte gib einen Namen für den Sub-Agenten an.');
            return $this->redirectToRoute('app_subagents_list');
        }
        
        try {
            // Erstelle den neuen Sub-Agenten
            $subAgent = $this->subAgentFactory->createSubAgent(
                name: $subAgentName,
                role: $subAgentRole,
                model: 'mistral-large-latest'
            );
            
            // Erstelle Tool-Definition für den Sub-Agenten
            $toolDefinition = new ToolDefinition();
            $toolDefinition->setName('sub_agent_' . $subAgentName);
            $toolDefinition->setDescription('Sub-Agent für ' . $subAgentRole);
            $toolDefinition->setStatus('approved');
            $toolDefinition->setSchema([
                'type' => 'object',
                'properties' => [
                    'task' => [
                        'type' => 'string',
                        'description' => 'Die Aufgabe, die der Sub-Agent ausführen soll',
                    ],
                    'parameters' => [
                        'type' => 'object',
                        'description' => 'Zusätzliche Parameter für die Aufgabe',
                        'additionalProperties' => true,
                    ],
                ],
                'required' => ['task'],
            ]);
            $toolDefinition->setParameters([
                ['name' => 'task', 'type' => 'string', 'required' => true, 'description' => 'Aufgabe für den Sub-Agenten'],
                ['name' => 'parameters', 'type' => 'object', 'required' => false, 'description' => 'Zusätzliche Parameter'],
            ]);
            
            // Füge die ausgewählten Tools als Metadaten hinzu
            if (!empty($selectedTools)) {
                $toolDefinition->setMetadata([
                    'sub_agent' => $subAgentName,
                    'assigned_tools' => array_values($selectedTools),
                ]);
            }
            
            $this->entityManager->persist($toolDefinition);
            $this->entityManager->flush();
            
            $this->addFlash('success', sprintf('Sub-Agent "%s" wurde erfolgreich erstellt!', $subAgentName));
            
        } catch (\Exception $e) {
            $this->addFlash('error', sprintf('Fehler beim Erstellen des Sub-Agenten: %s', $e->getMessage()));
        }
        
        return $this->redirectToRoute('app_subagents_list');
    }

    /**
     * Weist Tools einem Sub-Agenten zu.
     */
    #[Route('/subagents/{name}/assign-tools', name: 'app_subagents_assign_tools', methods: ['POST'])]
    public function assignToolsToSubAgent(string $name, Request $request): Response
    {
        $selectedTools = $request->request->all('tools') ?? [];
        
        try {
            // Finde alle Tools
            $tools = $this->toolDefinitionRepo->findBy([
                'status' => 'approved',
            ]);
            
            foreach ($tools as $tool) {
                $metadata = $tool->getMetadata() ?? [];
                if (isset($metadata['sub_agent']) && $metadata['sub_agent'] === $name) {
                    // Entferne die Zuweisung, wenn das Tool nicht mehr ausgewählt ist
                    if (!in_array($tool->getName(), $selectedTools)) {
                        unset($metadata['sub_agent']);
                        unset($metadata['assigned_tools']);
                        $tool->setMetadata($metadata);
                        $this->entityManager->persist($tool);
                    }
                } else {
                    // Füge die Zuweisung hinzu, wenn das Tool ausgewählt ist
                    if (in_array($tool->getName(), $selectedTools)) {
                        $metadata['sub_agent'] = $name;
                        $metadata['assigned_tools'] = array_values($selectedTools);
                        $tool->setMetadata($metadata);
                        $this->entityManager->persist($tool);
                    }
                }
            }
            
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Tools wurden dem Sub-Agent "%s" zugewiesen!', $name));
            
        } catch (\Exception $e) {
            $this->addFlash('error', sprintf('Fehler beim Zuweisen der Tools: %s', $e->getMessage()));
        }
        
        return $this->redirectToRoute('app_subagents_list');
    }

    /**
     * Gibt eine Beschreibung für eine Rolle zurück.
     */
    private function getDescriptionForRole(string $role): string
    {
        $descriptions = [
            'website_researcher' => 'Spezialisiert auf Webseiten-Recherche und Zusammenfassungen',
            'data_analyst' => 'Analysiert Daten und liefert Erkenntnisse',
            'code_assistant' => 'Unterstützt bei Code-Analyse und Generierung',
            'document_processor' => 'Verarbeitet Dokumente und extrahiert Daten',
            'communication_manager' => 'Verwaltet E-Mails, Nachrichten und LinkedIn-Kommunikation',
            'api_integration' => 'Bindet externe APIs an und verwaltet Authentifizierung',
            'project_manager' => 'Verwaltet Aufgaben, Termine und Projekte',
            'finance_manager' => 'Verantwortlich für Buchhaltung, Rechnungen und Zahlungen',
            'hr_manager' => 'Verwaltet Mitarbeiterdaten, Verträge und Personalangelegenheiten',
            'marketing_manager' => 'Verantwortlich für Kampagnen, Social Media und Content',
            'ceo_assistant' => 'Unterstützt bei strategischen Entscheidungen und Aufgabenpriorisierung',
        ];
        
        return $descriptions[$role] ?? 'Allgemeiner Sub-Agent';
    }
}
