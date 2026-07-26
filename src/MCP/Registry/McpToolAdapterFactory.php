<?php
// src/MCP/Registry/McpToolAdapterFactory.php
namespace App\MCP\Registry;

use Symfony\AI\Agent\Toolbox\Tool\ToolInterface;

/**
 * Factory to create MCP tool adapters for Symfony AI.
 */
final class McpToolAdapterFactory
{
    public function __construct(
        private ToolRegistry $toolRegistry,
    ) {
    }

    /**
     * Creates an adapter for an MCP tool.
     */
    public function createAdapter(string $toolName): ToolInterface
    {
        return new McpToolAdapter($this->toolRegistry, $toolName);
    }
}
