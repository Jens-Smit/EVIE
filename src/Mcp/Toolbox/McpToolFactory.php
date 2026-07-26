<?php

namespace App\Mcp\Toolbox;

use App\Mcp\Client\McpServerManager;
use App\Mcp\Exception\McpToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolFactory\ToolFactoryInterface;
use Symfony\AI\Agent\Toolbox\Tool\Tool;
use Symfony\Contracts\Cache\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Factory for creating MCP tools as Symfony AI tools.
 * Implements ToolFactoryInterface to integrate with Symfony AI Agent toolbox.
 */
final class McpToolFactory implements ToolFactoryInterface
{
    /** @var array<string> */
    private array $serverAliases;

    /**
     * @param array<string> $serverAliases List of MCP server aliases to load tools from.
     */
    public function __construct(
        private readonly McpServerManager $serverManager,
        private readonly McpToolExecutor $toolExecutor,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        array $serverAliases = [],
        private readonly int $cacheTtl = 300,
    ) {
        $this->serverAliases = $serverAliases;
    }

    /**
     * {@inheritdoc}
     *
     * @return iterable<Tool>
     */
    public function getTools(): iterable
    {
        foreach ($this->serverAliases as $serverAlias) {
            try {
                $tools = $this->cache->get(
                    sprintf('mcp_tools_%s', $serverAlias),
                    fn () => $this->serverManager->listToolsFor($serverAlias)
                );

                foreach ($tools as $toolName => $toolData) {
                    $fullToolName = sprintf('%s_%s', $serverAlias, $toolName);
                    
                    $tool = new Tool(
                        name: $fullToolName,
                        description: $toolData['description'] ?? '',
                        parameters: $this->convertSchemaToParameters($toolData['inputSchema'] ?? []),
                        executor: fn (array $arguments) => $this->toolExecutor->execute(
                            $serverAlias,
                            $toolName,
                            $arguments
                        )
                    );

                    $this->logger->debug('Registered MCP tool: {tool}', [
                        'tool' => $fullToolName,
                        'server' => $serverAlias,
                    ]);

                    yield $tool;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to load tools for MCP server {server}: {error}', [
                    'server' => $serverAlias,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Converts JSON schema to Symfony AI Tool parameters format.
     *
     * @param array<string, mixed> $schema The JSON schema from MCP.
     * @return array<string, array{type: string, description?: string, default?: mixed}>
     */
    private function convertSchemaToParameters(array $schema): array
    {
        $parameters = [];

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $name => $property) {
                $parameters[$name] = [
                    'type' => $this->mapJsonTypeToPhpType($property['type'] ?? 'string'),
                    'description' => $property['description'] ?? '',
                    'default' => $property['default'] ?? null,
                ];
            }
        }

        return $parameters;
    }

    /**
     * Maps JSON schema types to PHP types for Symfony AI Tool.
     */
    private function mapJsonTypeToPhpType(string $jsonType): string
    {
        return match ($jsonType) {
            'string' => 'string',
            'number', 'integer' => 'float',
            'boolean' => 'bool',
            'array' => 'array',
            'object' => 'array',
            default => 'string',
        };
    }
}
