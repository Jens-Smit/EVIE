<?php
// src/Controller/HTMX/HTMXController.php

namespace App\Controller\HTMX;

use App\AI\Agent\SubAgentFactory;
use App\Repository\SubAgentDefinitionRepository;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use App\AI\Mcp\McpToolExecutor;
use App\AI\Skills\Tool\DynamicToolExecutor;
use App\AI\Skills\Tool\DynamicToolFactory;
use App\AI\Streaming\StreamingSessionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller für HTMX-Anfragen.
 * Bietet interaktive UI-Komponenten für Tool-Execution, Sub-Agenten und MCP-Tools.
 */
class HTMXController extends AbstractController
{
    private DynamicToolExecutor $toolExecutor;
    private DynamicToolFactory $toolFactory;
    private SubAgentFactory $subAgentFactory;
    private SubAgentDefinitionRepository $subAgentDefinitionRepo;
    private McpToolExecutor $mcpToolExecutor;
    private StreamingSessionManager $sessionManager;

    public function __construct(
        DynamicToolExecutor $toolExecutor,
        DynamicToolFactory $toolFactory,
        SubAgentFactory $subAgentFactory,
        SubAgentDefinitionRepository $subAgentDefinitionRepo,
        McpToolExecutor $mcpToolExecutor,
        StreamingSessionManager $sessionManager
    ) {
        $this->toolExecutor = $toolExecutor;
        $this->toolFactory = $toolFactory;
        $this->subAgentFactory = $subAgentFactory;
        $this->subAgentDefinitionRepo = $subAgentDefinitionRepo;
        $this->mcpToolExecutor = $mcpToolExecutor;
        $this->sessionManager = $sessionManager;
    }

    // ========================================================================
    // Tool Execution HTMX Endpoints
    // ========================================================================

    /**
     * Führt ein Tool aus und gibt das Ergebnis als HTMX-Response zurück.
     */
    #[Route('/htmx/tools/execute', name: 'htmx_tools_execute', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function executeTool(Request $request): Response
    {
        $toolName = $request->request->get('tool_name');
        $arguments = json_decode($request->request->get('arguments', '[]'), true);

        if (empty($toolName)) {
            return $this->render('htmx/partials/_error.html.twig', [
                'error' => 'Tool-Name ist erforderlich',
            ], new Response('', 400));
        }

        try {
            // P1-C: DynamicToolExecutor.execute() erwartet ein DynamicTool-Objekt,
            // keinen String-Namen. Tool wird ueber die Factory aufgeloest.
            $tool = $this->toolFactory->getTool($toolName);
            if ($tool === null) {
                throw new \RuntimeException(sprintf('Tool "%s" nicht gefunden.', $toolName));
            }
            $result = $this->toolExecutor->execute($tool, $arguments);

            return $this->render('htmx/partials/_tool_result.html.twig', [
                'tool_name' => $toolName,
                'result' => $result,
                'arguments' => $arguments,
                'timestamp' => new \DateTimeImmutable(),
            ]);
        } catch (\Exception $e) {
            return $this->render('htmx/partials/_error.html.twig', [
                'error' => $e->getMessage(),
                'tool_name' => $toolName,
            ], new Response('', 400));
        }
    }

    /**
     * Zeigt das Formular für Tool-Execution an.
     */
    #[Route('/htmx/tools/form', name: 'htmx_tools_form', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function toolForm(Request $request): Response
    {
        $availableTools = $this->toolFactory->getAllTools();

        return $this->render('htmx/forms/_tool_form.html.twig', [
            'available_tools' => $availableTools,
            'selected_tool' => $request->query->get('tool_name'),
        ]);
    }

    /**
     * Zeigt die Tool-Ergebnis-Liste an.
     */
    #[Route('/htmx/tools/results', name: 'htmx_tools_results', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function toolResults(Request $request): Response
    {
        // In einer echten Implementierung: Ergebnisse aus der Datenbank laden
        $recentResults = []; // TODO: Implementieren

        return $this->render('htmx/partials/_tool_results.html.twig', [
            'results' => $recentResults,
        ]);
    }

    // ========================================================================
    // Sub-Agenten HTMX Endpoints
    // ========================================================================

    /**
     * Delegiert eine Aufgabe an einen Sub-Agenten.
     */
    #[Route('/htmx/subagents/delegate', name: 'htmx_subagents_delegate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delegateToSubAgent(Request $request): Response
    {
        $task = $request->request->get('task');
        $subAgentName = $request->request->get('sub_agent_name');

        if (empty($task)) {
            return $this->render('htmx/partials/_error.html.twig', [
                'error' => 'Aufgabe ist erforderlich',
            ], new Response('', 400));
        }

        try {
            if (!empty($subAgentName)) {
                // Delegiere an bestimmten Sub-Agenten
                $subAgent = $this->subAgentFactory->createByName($subAgentName);
                $response = $subAgent->call(new MessageBag(Message::ofUser($task)));
                $result = [
                    'sub_agent' => $subAgentName,
                    'result' => $response,
                    'status' => 'completed',
                ];
            } else {
                // Delegiere an den ersten verfuegbaren Sub-Agenten
                $available = $this->subAgentFactory->getAvailableSubAgents();
                if (empty($available)) {
                    throw new \RuntimeException('Kein Sub-Agent verfuegbar.');
                }
                $subAgentName = array_key_first($available);
                $subAgent = $this->subAgentFactory->createByName($subAgentName);
                $response = $subAgent->call(new MessageBag(Message::ofUser($task)));
                $result = [
                    'sub_agent' => $subAgentName,
                    'result' => $response,
                    'status' => 'completed',
                ];
            }

            return $this->render('htmx/partials/_subagent_result.html.twig', [
                'task' => $task,
                'sub_agent' => $result['sub_agent'] ?? $subAgentName,
                'result' => $result['result'] ?? null,
                'status' => $result['status'] ?? 'success',
                'timestamp' => new \DateTimeImmutable(),
            ]);
        } catch (\Exception $e) {
            return $this->render('htmx/partials/_error.html.twig', [
                'error' => $e->getMessage(),
                'task' => $task,
            ], new Response('', 400));
        }
    }

    /**
     * Zeigt das Formular für Sub-Agenten-Delegation an.
     */
    #[Route('/htmx/subagents/form', name: 'htmx_subagents_form', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function subAgentForm(Request $request): Response
    {
        $availableSubAgents = $this->subAgentFactory->getAvailableSubAgents();

        return $this->render('htmx/forms/_subagent_form.html.twig', [
            'available_subagents' => $availableSubAgents,
            'selected_subagent' => $request->query->get('sub_agent_name'),
        ]);
    }

    /**
     * Zeigt die Sub-Agenten-Liste an.
     */
    #[Route('/htmx/subagents/list', name: 'htmx_subagents_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function subAgentList(): Response
    {
        $subAgents = $this->subAgentFactory->getAvailableSubAgents();

        return $this->render('htmx/partials/_subagent_list.html.twig', [
            'sub_agents' => $subAgents,
        ]);
    }

    /**
     * Zeigt die Sub-Agenten-Status an.
     */
    #[Route('/htmx/subagents/status', name: 'htmx_subagents_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function subAgentStatus(): Response
    {
        $definitions = $this->subAgentDefinitionRepo->findAllActive();

        return $this->render('htmx/partials/_subagent_status.html.twig', [
            'definitions' => $definitions,
        ]);
    }

    // ========================================================================
    // MCP Tools HTMX Endpoints
    // ========================================================================

    /**
     * Führt ein MCP-Tool aus.
     */
    #[Route('/htmx/mcp/tools/execute', name: 'htmx_mcp_tools_execute', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function executeMcpTool(Request $request): Response
    {
        $serverName = $request->request->get('server_name');
        $toolName = $request->request->get('tool_name');
        $arguments = json_decode($request->request->get('arguments', '[]'), true);

        if (empty($serverName) || empty($toolName)) {
            return $this->render('htmx/partials/_error.html.twig', [
                'error' => 'Server-Name und Tool-Name sind erforderlich',
            ], new Response('', 400));
        }

        try {
            $result = $this->mcpToolExecutor->execute($serverName, $toolName, $arguments);

            return $this->render('htmx/partials/_mcp_tool_result.html.twig', [
                'server_name' => $serverName,
                'tool_name' => $toolName,
                'result' => $result,
                'arguments' => $arguments,
                'timestamp' => new \DateTimeImmutable(),
            ]);
        } catch (\Exception $e) {
            return $this->render('htmx/partials/_error.html.twig', [
                'error' => $e->getMessage(),
                'server_name' => $serverName,
                'tool_name' => $toolName,
            ], new Response('', 400));
        }
    }

    /**
     * Zeigt das Formular für MCP-Tools an.
     */
    #[Route('/htmx/mcp/tools/form', name: 'htmx_mcp_tools_form', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function mcpToolForm(Request $request): Response
    {
        $availableServers = $this->mcpToolExecutor->getAvailableServers();

        return $this->render('htmx/forms/_mcp_tool_form.html.twig', [
            'available_servers' => $availableServers,
            'selected_server' => $request->query->get('server_name'),
            'selected_tool' => $request->query->get('tool_name'),
        ]);
    }

    /**
     * Zeigt die MCP-Server-Liste an.
     */
    #[Route('/htmx/mcp/servers/list', name: 'htmx_mcp_servers_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function mcpServerList(): Response
    {
        $servers = $this->mcpToolExecutor->getAvailableServers();

        return $this->render('htmx/partials/_mcp_server_list.html.twig', [
            'servers' => $servers,
        ]);
    }

    /**
     * Zeigt die Tools eines MCP-Servers an.
     */
    #[Route('/htmx/mcp/servers/{serverName}/tools', name: 'htmx_mcp_server_tools', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function mcpServerTools(string $serverName): Response
    {
        try {
            $tools = $this->mcpToolExecutor->getServerTools($serverName);

            return $this->render('htmx/partials/_mcp_server_tools.html.twig', [
                'server_name' => $serverName,
                'tools' => $tools,
            ]);
        } catch (\Exception $e) {
            return $this->render('htmx/partials/_error.html.twig', [
                'error' => $e->getMessage(),
                'server_name' => $serverName,
            ], new Response('', 404));
        }
    }

    // ========================================================================
    // Streaming HTMX Endpoints
    // ========================================================================

    /**
     * Startet eine Streaming-Session.
     */
    #[Route('/htmx/streaming/sessions/start', name: 'htmx_streaming_session_start', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function startStreamingSession(Request $request): Response
    {
        $toolName = $request->request->get('tool_name');
        $arguments = json_decode($request->request->get('arguments', '[]'), true);

        if (empty($toolName)) {
            return $this->render('htmx/partials/_error.html.twig', [
                'error' => 'Tool-Name ist erforderlich',
            ], new Response('', 400));
        }

        try {
            $session = $this->sessionManager->createSession(
                $toolName,
                $arguments,
                $this->getUser()?->getUserIdentifier() ?? 'anonymous'
            );

            return $this->render('htmx/partials/_streaming_session_started.html.twig', [
                'session_id' => $session->getSessionId(),
                'tool_name' => $toolName,
                'status' => 'pending',
            ]);
        } catch (\Exception $e) {
            return $this->render('htmx/partials/_error.html.twig', [
                'error' => $e->getMessage(),
            ], new Response('', 400));
        }
    }

    /**
     * Zeigt den Status einer Streaming-Session an.
     */
    #[Route('/htmx/streaming/sessions/{sessionId}/status', name: 'htmx_streaming_session_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function streamingSessionStatus(string $sessionId): Response
    {
        $session = $this->sessionManager->getSession($sessionId);

        if ($session === null) {
            return $this->render('htmx/partials/_error.html.twig', [
                'error' => 'Session nicht gefunden',
                'session_id' => $sessionId,
            ], new Response('', 404));
        }

        return $this->render('htmx/partials/_streaming_session_status.html.twig', [
            'session' => $session,
        ]);
    }

    /**
     * Zeigt die Streaming-Session-Liste an.
     */
    #[Route('/htmx/streaming/sessions/list', name: 'htmx_streaming_sessions_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function streamingSessionsList(): Response
    {
        $userIdentifier = $this->getUser()?->getUserIdentifier() ?? 'anonymous';
        $sessions = $this->sessionManager->getActiveSessionsByUser($userIdentifier);

        return $this->render('htmx/partials/_streaming_sessions_list.html.twig', [
            'sessions' => $sessions,
        ]);
    }

    // ========================================================================
    // Dashboard HTMX Endpoints
    // ========================================================================

    /**
     * Zeigt das Dashboard an.
     */
    #[Route('/htmx/dashboard', name: 'htmx_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function dashboard(): Response
    {
        $userIdentifier = $this->getUser()?->getUserIdentifier() ?? 'anonymous';

        // Hole Daten für das Dashboard
        $availableTools = $this->toolFactory->getAllTools();
        $availableSubAgents = $this->subAgentFactory->getAvailableSubAgents();
        $availableMcpServers = $this->mcpToolExecutor->getAvailableServers();
        $activeSessions = $this->sessionManager->getActiveSessionsByUser($userIdentifier);

        return $this->render('htmx/dashboard/_dashboard.html.twig', [
            'available_tools' => $availableTools,
            'available_subagents' => $availableSubAgents,
            'available_mcp_servers' => $availableMcpServers,
            'active_sessions' => $activeSessions,
        ]);
    }

    /**
     * Zeigt die Tool-Statistiken an.
     */
    #[Route('/htmx/dashboard/tools/stats', name: 'htmx_dashboard_tools_stats', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function toolsStats(): Response
    {
        $availableTools = $this->toolFactory->getAllTools();

        return $this->render('htmx/dashboard/_tools_stats.html.twig', [
            'available_tools' => $availableTools,
            'tool_count' => count($availableTools),
        ]);
    }

    /**
     * Zeigt die Sub-Agenten-Statistiken an.
     */
    #[Route('/htmx/dashboard/subagents/stats', name: 'htmx_dashboard_subagents_stats', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function subAgentsStats(): Response
    {
        $availableSubAgents = $this->subAgentFactory->getAvailableSubAgents();

        return $this->render('htmx/dashboard/_subagents_stats.html.twig', [
            'available_subagents' => $availableSubAgents,
            'subagent_count' => count($availableSubAgents),
        ]);
    }

    /**
     * Zeigt die MCP-Server-Statistiken an.
     */
    #[Route('/htmx/dashboard/mcp/stats', name: 'htmx_dashboard_mcp_stats', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function mcpStats(): Response
    {
        $availableServers = $this->mcpToolExecutor->getAvailableServers();

        return $this->render('htmx/dashboard/_mcp_stats.html.twig', [
            'available_servers' => $availableServers,
            'server_count' => count($availableServers),
        ]);
    }

    // ========================================================================
    // Utility HTMX Endpoints
    // ========================================================================

    /**
     * Zeigt eine Erfolgsmeldung an.
     */
    #[Route('/htmx/utils/success', name: 'htmx_utils_success', methods: ['GET'])]
    public function successMessage(Request $request): Response
    {
        $message = $request->query->get('message', 'Erfolgreich!');

        return $this->render('htmx/partials/_success.html.twig', [
            'message' => $message,
        ]);
    }

    /**
     * Zeigt eine Fehlermeldung an.
     */
    #[Route('/htmx/utils/error', name: 'htmx_utils_error', methods: ['GET'])]
    public function errorMessage(Request $request): Response
    {
        $message = $request->query->get('message', 'Fehler!');

        return $this->render('htmx/partials/_error.html.twig', [
            'error' => $message,
        ], new Response('', 400));
    }

    /**
     * Zeigt einen Lade-Indikator an.
     */
    #[Route('/htmx/utils/loading', name: 'htmx_utils_loading', methods: ['GET'])]
    public function loadingIndicator(Request $request): Response
    {
        $message = $request->query->get('message', 'Loading...');

        return $this->render('htmx/partials/_loading.html.twig', [
            'message' => $message,
        ]);
    }
}
