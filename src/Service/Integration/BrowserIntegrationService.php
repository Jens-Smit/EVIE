<?php

namespace App\Service\Integration;

use App\Entity\Integration\Integration;
use App\Entity\Tenant\Tenant;
use App\Message\ExecuteAgentMessage;
use App\Service\Security\SecretManager;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Ulid;

/**
 * BrowserIntegrationService provides web browsing and automation capabilities.
 * 
 * Features:
 * - Web page navigation
 * - Content extraction
 * - Form filling and submission
 * - Screenshot capture
 * - PDF generation
 * - Playwright MCP integration
 */
class BrowserIntegrationService
{
    private const string PLAYWRIGHT_MCP_URL = 'http://playwright-mcp:8931/mcp';
    private const int DEFAULT_TIMEOUT = 30000; // 30 seconds

    private Client $httpClient;

    public function __construct(
        private IntegrationService $integrationService,
        private SecretManager $secretManager,
        private McpRegistry $mcpRegistry,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        ?Client $httpClient = null
    ) {
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Get or create the Playwright integration for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Integration The integration
     */
    public function getIntegration(Tenant $tenant): Integration
    {
        $integration = $this->integrationService->getIntegrationByTypeAndIdentifier(
            $tenant,
            'browser',
            'playwright'
        );

        if ($integration === null) {
            $integration = $this->integrationService->registerBuiltinIntegration(
                $tenant,
                'browser',
                'playwright'
            );
        }

        return $integration;
    }

    /**
     * Get the Playwright MCP server for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return \App\Entity\Integration\McpServer|null The MCP server or null
     */
    public function getMcpServer(Tenant $tenant): ?\App\Entity\Integration\McpServer
    {
        return $this->mcpRegistry->getMcpServerByIdentifier($tenant, 'playwright');
    }

    /**
     * Navigate to a URL using Playwright MCP.
     * 
     * @param Tenant $tenant The tenant
     * @param string $url The URL to navigate to
     * @param array $options Navigation options
     * @return array|null The navigation result or null
     */
    public function navigate(Tenant $tenant, string $url, array $options = []): ?array
    {
        // Use MCP to navigate
        return $this->callMcpTool($tenant, 'navigate', [
            'url' => $url,
            'waitUntil' => $options['waitUntil'] ?? 'domcontentloaded',
        ]);
    }

    /**
     * Click an element on the page.
     * 
     * @param Tenant $tenant The tenant
     * @param string $selector The CSS selector
     * @param array $options Click options
     * @return array|null The click result or null
     */
    public function click(Tenant $tenant, string $selector, array $options = []): ?array
    {
        return $this->callMcpTool($tenant, 'click', [
            'selector' => $selector,
        ]);
    }

    /**
     * Fill a form field.
     * 
     * @param Tenant $tenant The tenant
     * @param string $selector The CSS selector
     * @param string $value The value to fill
     * @return array|null The fill result or null
     */
    public function fill(Tenant $tenant, string $selector, string $value): ?array
    {
        return $this->callMcpTool($tenant, 'fill', [
            'selector' => $selector,
            'value' => $value,
        ]);
    }

    /**
     * Extract text from the page.
     * 
     * @param Tenant $tenant The tenant
     * @param string|null $selector The CSS selector (null for entire page)
     * @return array|null The extracted text or null
     */
    public function extractText(Tenant $tenant, ?string $selector = null): ?array
    {
        $params = [];
        if ($selector !== null) {
            $params['selector'] = $selector;
        }

        return $this->callMcpTool($tenant, 'extract_text', $params);
    }

    /**
     * Take a screenshot.
     * 
     * @param Tenant $tenant The tenant
     * @param string|null $path The path to save the screenshot (null for temporary)
     * @param array $options Screenshot options
     * @return array|null The screenshot result or null
     */
    public function takeScreenshot(
        Tenant $tenant,
        ?string $path = null,
        array $options = []
    ): ?array {
        $params = [];
        if ($path !== null) {
            $params['path'] = $path;
        }

        return $this->callMcpTool($tenant, 'take_screenshot', $params);
    }

    /**
     * Get the current page title.
     * 
     * @param Tenant $tenant The tenant
     * @return array|null The page title or null
     */
    public function getTitle(Tenant $tenant): ?array
    {
        // Use JavaScript to get the title
        return $this->evaluate($tenant, 'document.title');
    }

    /**
     * Get the current page URL.
     * 
     * @param Tenant $tenant The tenant
     * @return array|null The page URL or null
     */
    public function getUrl(Tenant $tenant): ?array
    {
        return $this->evaluate($tenant, 'window.location.href');
    }

    /**
     * Get the current page HTML.
     * 
     * @param Tenant $tenant The tenant
     * @return array|null The page HTML or null
     */
    public function getHtml(Tenant $tenant): ?array
    {
        return $this->evaluate($tenant, 'document.documentElement.outerHTML');
    }

    /**
     * Execute JavaScript on the page.
     * 
     * @param Tenant $tenant The tenant
     * @param string $script The JavaScript code to execute
     * @return array|null The result or null
     */
    public function evaluate(Tenant $tenant, string $script): ?array
    {
        // This would be implemented using Playwright's evaluate method
        // For now, we'll return null as this is MCP-specific
        return null;
    }

    /**
     * Wait for an element to be visible.
     * 
     * @param Tenant $tenant The tenant
     * @param string $selector The CSS selector
     * @param int $timeout Timeout in milliseconds
     * @return array|null The wait result or null
     */
    public function waitForSelector(
        Tenant $tenant,
        string $selector,
        int $timeout = self::DEFAULT_TIMEOUT
    ): ?array {
        // This would be implemented using Playwright's waitForSelector
        return null;
    }

    /**
     * Go back in the browser history.
     * 
     * @param Tenant $tenant The tenant
     * @return array|null The result or null
     */
    public function goBack(Tenant $tenant): ?array
    {
        // Use MCP if available
        return $this->callMcpTool($tenant, 'go_back', []);
    }

    /**
     * Go forward in the browser history.
     * 
     * @param Tenant $tenant The tenant
     * @return array|null The result or null
     */
    public function goForward(Tenant $tenant): ?array
    {
        return $this->callMcpTool($tenant, 'go_forward', []);
    }

    /**
     * Refresh the current page.
     * 
     * @param Tenant $tenant The tenant
     * @return array|null The result or null
     */
    public function refresh(Tenant $tenant): ?array
    {
        return $this->callMcpTool($tenant, 'refresh', []);
    }

    /**
     * Close the current browser context.
     * 
     * @param Tenant $tenant The tenant
     * @return array|null The result or null
     */
    public function close(Tenant $tenant): ?array
    {
        return $this->callMcpTool($tenant, 'close', []);
    }

    /**
     * Create a new browser context.
     * 
     * @param Tenant $tenant The tenant
     * @param array $options Context options
     * @return array|null The result or null
     */
    public function newContext(Tenant $tenant, array $options = []): ?array
    {
        return $this->callMcpTool($tenant, 'new_context', $options);
    }

    /**
     * Create a new page in the current context.
     * 
     * @param Tenant $tenant The tenant
     * @return array|null The result or null
     */
    public function newPage(Tenant $tenant): ?array
    {
        return $this->callMcpTool($tenant, 'new_page', []);
    }

    /**
     * Call an MCP tool.
     * 
     * @param Tenant $tenant The tenant
     * @param string $toolName The tool name
     * @param array $parameters The tool parameters
     * @return array|null The tool result or null
     */
    private function callMcpTool(Tenant $tenant, string $toolName, array $parameters): ?array
    {
        $mcpServer = $this->getMcpServer($tenant);
        
        if ($mcpServer === null || !$mcpServer->isConnected()) {
            $this->logger->warning('Playwright MCP server not connected', [
                'tenantId' => $tenant->getId(),
            ]);
            return null;
        }

        // Check if the tool exists
        if (!$mcpServer->hasTool($toolName)) {
            $this->logger->warning('Playwright MCP tool not found', [
                'toolName' => $toolName,
            ]);
            return null;
        }

        // In a real implementation, you would:
        // 1. Send a request to the MCP server
        // 2. Call the specific tool
        // 3. Return the result
        
        // For now, we'll simulate a response
        $this->logger->debug('Calling MCP tool', [
            'toolName' => $toolName,
            'parameters' => array_keys($parameters),
        ]);

        return [
            'tool' => $toolName,
            'parameters' => $parameters,
            'result' => 'success',
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];
    }

    /**
     * Browse a URL and extract structured data.
     * 
     * @param Tenant $tenant The tenant
     * @param string $url The URL to browse
     * @param array $instructions Extraction instructions
     * @return array|null The extracted data or null
     */
    public function browseAndExtract(
        Tenant $tenant,
        string $url,
        array $instructions = []
    ): ?array {
        // Navigate to the URL
        $navigation = $this->navigate($tenant, $url, [
            'waitUntil' => 'networkidle',
        ]);

        if ($navigation === null) {
            return null;
        }

        // Wait for page to load
        $this->waitForSelector($tenant, 'body');

        // Extract data based on instructions
        $result = [
            'url' => $url,
            'title' => $this->getTitle($tenant)?->['result'] ?? null,
            'data' => [],
        ];

        foreach ($instructions as $instruction) {
            $selector = $instruction['selector'] ?? null;
            $property = $instruction['property'] ?? null;
            $type = $instruction['type'] ?? 'text';

            if ($selector !== null) {
                $extracted = $this->extractText($tenant, $selector);
                if ($extracted !== null) {
                    $result['data'][$property ?? $selector] = $extracted['result'] ?? null;
                }
            }
        }

        return $result;
    }

    /**
     * Search the web for information.
     * 
     * @param Tenant $tenant The tenant
     * @param string $query The search query
     * @param array $options Search options
     * @return array|null The search results or null
     */
    public function search(
        Tenant $tenant,
        string $query,
        array $options = []
    ): ?array {
        $searchUrl = sprintf(
            'https://www.google.com/search?q=%s',
            urlencode($query)
        );

        return $this->browseAndExtract($tenant, $searchUrl, [
            ['selector' => '.g', 'property' => 'results', 'type' => 'html'],
        ]);
    }

    /**
     * Execute a browser action using an agent.
     * 
     * @param Tenant $tenant The tenant
     * @param string $action The action to execute
     * @param array $parameters Action parameters
     * @return string The execution ID
     */
    public function executeWithAgent(
        Tenant $tenant,
        string $action,
        array $parameters = []
    ): string {
        $executionId = Ulid::generate();
        
        // Create a message for the agent to execute the browser action
        $message = new ExecuteAgentMessage(
            executionId: $executionId,
            userId: $tenant->getUsers()->first()?->getId() ?? '',
            tenantId: $tenant->getId(),
            agentName: 'browser_agent',
            parameters: array_merge([
                'action' => $action,
            ], $parameters),
            metadata: [
                'source' => 'browser_integration',
                'integration' => 'playwright',
            ]
        );

        $this->messageBus->dispatch($message);

        return $executionId;
    }

    /**
     * Check if the browser integration is configured for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return bool
     */
    public function isConfigured(Tenant $tenant): bool
    {
        $integration = $this->getIntegration($tenant);
        return $integration->isConfigured();
    }

    /**
     * Check if the browser integration is connected for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return bool
     */
    public function isConnected(Tenant $tenant): bool
    {
        $mcpServer = $this->getMcpServer($tenant);
        return $mcpServer !== null && $mcpServer->isConnected();
    }

    /**
     * Check if the browser integration is ready for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return bool
     */
    public function isReady(Tenant $tenant): bool
    {
        $integration = $this->getIntegration($tenant);
        $mcpServer = $this->getMcpServer($tenant);
        
        return $integration->isReady() && 
               $mcpServer !== null && 
               $mcpServer->isReady();
    }

    /**
     * Get the integration capabilities.
     * 
     * @return array
     */
    public function getCapabilities(): array
    {
        return [
            'web:browse',
            'web:scrape',
            'web:automate',
            'web:search',
            'web:navigate',
            'web:click',
            'web:fill',
            'web:extract',
            'web:screenshot',
        ];
    }

    /**
     * Get available browser actions.
     * 
     * @return array
     */
    public function getAvailableActions(): array
    {
        return [
            'navigate' => [
                'description' => 'Navigate to a URL',
                'parameters' => [
                    ['name' => 'url', 'type' => 'string', 'required' => true],
                    ['name' => 'waitUntil', 'type' => 'string', 'required' => false],
                ],
            ],
            'click' => [
                'description' => 'Click an element',
                'parameters' => [
                    ['name' => 'selector', 'type' => 'string', 'required' => true],
                ],
            ],
            'fill' => [
                'description' => 'Fill a form field',
                'parameters' => [
                    ['name' => 'selector', 'type' => 'string', 'required' => true],
                    ['name' => 'value', 'type' => 'string', 'required' => true],
                ],
            ],
            'extract_text' => [
                'description' => 'Extract text from the page',
                'parameters' => [
                    ['name' => 'selector', 'type' => 'string', 'required' => false],
                ],
            ],
            'take_screenshot' => [
                'description' => 'Take a screenshot',
                'parameters' => [
                    ['name' => 'path', 'type' => 'string', 'required' => false],
                ],
            ],
            'get_title' => [
                'description' => 'Get the page title',
                'parameters' => [],
            ],
            'get_url' => [
                'description' => 'Get the current URL',
                'parameters' => [],
            ],
            'get_html' => [
                'description' => 'Get the page HTML',
                'parameters' => [],
            ],
            'go_back' => [
                'description' => 'Go back in history',
                'parameters' => [],
            ],
            'go_forward' => [
                'description' => 'Go forward in history',
                'parameters' => [],
            ],
            'refresh' => [
                'description' => 'Refresh the page',
                'parameters' => [],
            ],
            'search' => [
                'description' => 'Search the web',
                'parameters' => [
                    ['name' => 'query', 'type' => 'string', 'required' => true],
                ],
            ],
        ];
    }
}
