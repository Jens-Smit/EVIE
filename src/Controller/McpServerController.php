<?php
// src/Controller/McpServerController.php

namespace App\Controller;

use App\AI\Mcp\McpServerFactory;
use App\Entity\McpServerDefinition;
use App\Form\McpServerDefinitionType;
use App\Repository\McpServerDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller für die Verwaltung von MCP-Servern.
 * Bietet eine Benutzeroberfläche zum Anzeigen, Erstellen, Bearbeiten und Löschen
 * von MCP-Server-Definitionen.
 */
class McpServerController extends AbstractController
{
    private McpServerFactory $mcpServerFactory;
    private McpServerDefinitionRepository $mcpServerDefinitionRepo;
    private EntityManagerInterface $entityManager;

    public function __construct(
        McpServerFactory $mcpServerFactory,
        McpServerDefinitionRepository $mcpServerDefinitionRepo,
        EntityManagerInterface $entityManager
    ) {
        $this->mcpServerFactory = $mcpServerFactory;
        $this->mcpServerDefinitionRepo = $mcpServerDefinitionRepo;
        $this->entityManager = $entityManager;
    }

    /**
     * Listet alle MCP-Server auf.
     */
    #[Route('/mcp/servers', name: 'mcp_servers_list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function listServers(Request $request): Response
    {
        $definitions = $this->mcpServerDefinitionRepo->findAllActive();

        return $this->render('mcp/servers.html.twig', [
            'servers' => $definitions,
            'availableTypes' => $this->getAvailableServerTypes(),
        ]);
    }

    /**
     * Zeigt die Details eines MCP-Servers an.
     */
    #[Route('/mcp/servers/{name}', name: 'mcp_server_show', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function showServer(string $name): Response
    {
        $definition = $this->mcpServerDefinitionRepo->findOneByName($name);

        if ($definition === null) {
            throw $this->createNotFoundException('MCP-Server nicht gefunden.');
        }

        try {
            $server = $this->mcpServerFactory->createFromDefinition($definition);
            $tools = $server->getAvailableTools();
        } catch (\Exception $e) {
            $this->addFlash('error', sprintf(
                'Fehler beim Laden des MCP-Servers: %s',
                $e->getMessage()
            ));
            $tools = [];
        }

        return $this->render('mcp/server_show.html.twig', [
            'server' => $definition,
            'tools' => $tools,
        ]);
    }

    /**
     * Zeigt das Formular zum Erstellen eines neuen MCP-Servers an.
     */
    #[Route('/mcp/servers/new', name: 'mcp_server_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function newServer(Request $request): Response
    {
        $definition = new McpServerDefinition();
        $form = $this->createForm(McpServerDefinitionType::class, $definition);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Setze den Ersteller
            $user = $this->getUser();
            if ($user !== null) {
                $definition->setCreatedBy($user);
            }

            // Speichere die Definition
            $this->entityManager->persist($definition);
            $this->entityManager->flush();

            $this->addFlash('success', 'MCP-Server wurde erfolgreich erstellt.');
            return $this->redirectToRoute('mcp_servers_list');
        }

        return $this->render('mcp/server_new.html.twig', [
            'form' => $form->createView(),
            'availableTypes' => $this->getAvailableServerTypes(),
        ]);
    }

    /**
     * Zeigt das Formular zum Bearbeiten eines MCP-Servers an.
     */
    #[Route('/mcp/servers/{name}/edit', name: 'mcp_server_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function editServer(string $name, Request $request): Response
    {
        $definition = $this->mcpServerDefinitionRepo->findOneByName($name);

        if ($definition === null) {
            throw $this->createNotFoundException('MCP-Server nicht gefunden.');
        }

        $form = $this->createForm(McpServerDefinitionType::class, $definition);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'MCP-Server wurde erfolgreich aktualisiert.');
            return $this->redirectToRoute('mcp_server_show', ['name' => $name]);
        }

        return $this->render('mcp/server_edit.html.twig', [
            'form' => $form->createView(),
            'server' => $definition,
            'availableTypes' => $this->getAvailableServerTypes(),
        ]);
    }

    /**
     * Löscht einen MCP-Server.
     */
    #[Route('/mcp/servers/{name}/delete', name: 'mcp_server_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteServer(string $name, Request $request): Response
    {
        $definition = $this->mcpServerDefinitionRepo->findOneByName($name);

        if ($definition === null) {
            throw $this->createNotFoundException('MCP-Server nicht gefunden.');
        }

        // Überprüfe CSRF-Token
        if (!$this->isCsrfTokenValid('delete' . $definition->getId()->toRfc4122(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('mcp_server_show', ['name' => $name]);
        }

        $this->entityManager->remove($definition);
        $this->entityManager->flush();

        $this->addFlash('success', 'MCP-Server wurde erfolgreich gelöscht.');
        return $this->redirectToRoute('mcp_servers_list');
    }

    /**
     * Aktiviert oder deaktiviert einen MCP-Server.
     */
    #[Route('/mcp/servers/{name}/toggle', name: 'mcp_server_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleServer(string $name, Request $request): Response
    {
        $definition = $this->mcpServerDefinitionRepo->findOneByName($name);

        if ($definition === null) {
            throw $this->createNotFoundException('MCP-Server nicht gefunden.');
        }

        // Überprüfe CSRF-Token
        if (!$this->isCsrfTokenValid('toggle' . $definition->getId()->toRfc4122(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('mcp_server_show', ['name' => $name]);
        }

        // Toggle den Status
        $definition->setIsActive(!$definition->isActive());
        $this->entityManager->flush();

        $status = $definition->isActive() ? 'aktiviert' : 'deaktiviert';
        $this->addFlash('success', sprintf('MCP-Server wurde erfolgreich %s.', $status));
        return $this->redirectToRoute('mcp_server_show', ['name' => $name]);
    }

    /**
     * Gibt die verfügbaren Server-Typen zurück.
     */
    private function getAvailableServerTypes(): array
    {
        return [
            'filesystem' => 'Filesystem',
            'playwright' => 'Playwright',
            'github' => 'GitHub',
            'custom' => 'Custom',
        ];
    }

    /**
     * API-Endpoint: Gibt alle MCP-Server als JSON zurück.
     */
    #[Route('/api/mcp/servers', name: 'api_mcp_servers_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function apiListServers(): JsonResponse
    {
        $definitions = $this->mcpServerDefinitionRepo->findAllActive();

        $servers = [];
        foreach ($definitions as $definition) {
            $servers[] = [
                'name' => $definition->getName(),
                'type' => $definition->getType(),
                'description' => $definition->getDescription(),
                'is_active' => $definition->isActive(),
                'allowed_tools' => $definition->getAllowedTools(),
                'blocked_resources' => $definition->getBlockedResources(),
                'created_at' => $definition->getCreatedAt()->format('c'),
            ];
        }

        return $this->json([
            'servers' => $servers,
            'count' => count($servers),
        ]);
    }

    /**
     * API-Endpoint: Gibt die Tools eines MCP-Servers als JSON zurück.
     */
    #[Route('/api/mcp/servers/{name}/tools', name: 'api_mcp_server_tools', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function apiGetServerTools(string $name): JsonResponse
    {
        try {
            $server = $this->mcpServerFactory->createByName($name);
            $tools = $server->getAvailableTools();

            return $this->json([
                'server' => $name,
                'tools' => $tools,
                'count' => count($tools),
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * API-Endpoint: Führt ein MCP-Tool aus.
     */
    #[Route('/api/mcp/servers/{serverName}/tools/{toolName}/execute', name: 'api_mcp_tool_execute', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function apiExecuteTool(string $serverName, string $toolName, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $arguments = $data['arguments'] ?? [];

            $result = $this->mcpServerFactory->createByName($serverName)
                ->executeTool($toolName, $arguments);

            return $this->json([
                'server' => $serverName,
                'tool' => $toolName,
                'result' => $result,
                'status' => 'success',
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage(),
                'status' => 'error',
            ], 400);
        }
    }

    /**
     * Lädt alle MCP-Server neu (z. B. nach Änderungen in der Datenbank).
     */
    #[Route('/mcp/servers/reload', name: 'mcp_servers_reload', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reloadServers(Request $request): Response
    {
        // Überprüfe CSRF-Token
        if (!$this->isCsrfTokenValid('reload_servers', $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('mcp_servers_list');
        }

        // Lade alle Server neu
        $servers = $this->mcpServerFactory->getAvailableServers();

        $this->addFlash('success', sprintf(
            '%d MCP-Server wurden neu geladen.',
            count($servers)
        ));

        return $this->redirectToRoute('mcp_servers_list');
    }
}
