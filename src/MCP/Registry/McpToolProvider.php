<?php
// src/MCP/Registry/McpToolProvider.php
namespace App\MCP\Registry;

use Symfony\AI\Agent\Toolbox\ToolProviderInterface;
use Symfony\AI\Agent\Toolbox\Tool\ToolInterface;

/**
 * Provides MCP tools to the Symfony AI agent.
 */
final class McpToolProvider implements ToolProviderInterface
{
    public function __construct(
        private McpToolAdapterFactory $adapterFactory,
        private ToolRegistry $toolRegistry,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getTools(): iterable
    {
        $tools = $this->toolRegistry->listTools();
        foreach ($tools as $tool) {
            yield $this->adapterFactory->createAdapter($tool['name']);
        }
    }
}
