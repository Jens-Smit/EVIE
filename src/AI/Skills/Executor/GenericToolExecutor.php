<?php

namespace App\AI\Skills\Executor;

use App\AI\Skills\Tool\ToolInterface;
use App\AI\Skills\Tool\ToolRegistry;
use App\AI\Security\SecurityGuard;

/**
 * Dispatcher für dynamisch registrierte Tools.
 * Wird vom Agenten aufgerufen, um Tools auszuführen.
 */
final class GenericToolExecutor implements ToolInterface
{
    public function __construct(
        private ToolRegistry $toolRegistry,
        private SecurityGuard $securityGuard
    ) {}

    /**
     * Wird vom Symfony AI-Agenten aufgerufen.
     */
    public function __invoke(array $parameters = []): array
    {
        // Tool-Name aus den Parametern extrahieren
        $toolName = $parameters['tool_name'] ?? ($parameters['name'] ?? null);
        if (!$toolName) {
            throw new \InvalidArgumentException('Parameter "tool_name" ist erforderlich.');
        }

        // Validate the tool configuration
        if (!$this->securityGuard->validateToolConfiguration($parameters)) {
            throw new \RuntimeException('Tool configuration failed security validation.');
        }

        $tool = $this->toolRegistry->get($toolName);
        return $tool($parameters);
    }

    /**
     * Gibt den Namen des Tools zurück.
     */
    public function getName(): string
    {
        return 'generic_tool_executor';
    }

    /**
     * Gibt eine Beschreibung des Tools zurück.
     */
    public function getDescription(): string
    {
        return 'Führt dynamisch registrierte Tools aus.';
    }

    /**
     * Executes a generic tool with the given parameters.
     * @deprecated Use __invoke() instead for Symfony AI compatibility.
     */
    public function execute(string $toolName, array $parameters = []): array
    {
        return $this->__invoke(['tool_name' => $toolName, ...$parameters]);
    }

    /**
     * Executes an API call.
     */
    private function executeApiCall(array $parameters): array
    {
        if (!isset($parameters['url'])) {
            throw new \InvalidArgumentException('API call requires a URL.');
        }

        if ($this->securityGuard->isResourceBlocked($parameters['url'])) {
            throw new \RuntimeException('Access to this URL is blocked by security policies.');
        }

        // Simulate API call
        return [
            'status' => 'success',
            'url' => $parameters['url'],
            'method' => $parameters['method'] ?? 'GET',
            'response' => ['data' => 'Sample API response'],
        ];
    }

    /**
     * Executes a file read operation.
     */
    private function executeFileRead(array $parameters): array
    {
        if (!isset($parameters['file_path'])) {
            throw new \InvalidArgumentException('File read requires a file path.');
        }

        if ($this->securityGuard->isResourceBlocked($parameters['file_path'])) {
            throw new \RuntimeException('Access to this file path is blocked by security policies.');
        }

        // Simulate file read
        return [
            'status' => 'success',
            'file_path' => $parameters['file_path'],
            'content' => 'Sample file content',
        ];
    }

    /**
     * Executes a database query.
     */
    private function executeDatabaseQuery(array $parameters): array
    {
        if (!isset($parameters['query'])) {
            throw new \InvalidArgumentException('Database query requires a query string.');
        }

        // Simulate database query
        return [
            'status' => 'success',
            'query' => $parameters['query'],
            'results' => [['id' => 1, 'name' => 'Sample result']],
        ];
    }
}