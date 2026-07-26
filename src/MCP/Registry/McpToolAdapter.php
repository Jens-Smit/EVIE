<?php
// src/MCP/Registry/McpToolAdapter.php
namespace App\MCP\Registry;

use App\MCP\Exception\MCPException;
use Symfony\AI\Agent\Tool\ToolInterface;

/**
 * Adapter to expose MCP tools as Symfony AI tools.
 */
final class McpToolAdapter implements ToolInterface
{
    public function __construct(
        private ToolRegistry $toolRegistry,
        private string $toolName,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->toolName;
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        $tools = $this->toolRegistry->listTools();
        foreach ($tools as $tool) {
            if ($tool['name'] === $this->toolName) {
                return $tool['description'];
            }
        }
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function execute(array $arguments = []): mixed
    {
        try {
            return $this->toolRegistry->callTool($this->toolName, $arguments);
        } catch (MCPException $e) {
            throw new \RuntimeException(sprintf('Failed to execute MCP tool "%s": %s', $this->toolName, $e->getMessage()));
        }
    }
}
