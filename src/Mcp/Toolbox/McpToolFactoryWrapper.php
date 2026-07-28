<?php
// src/Mcp/Toolbox/McpToolFactoryWrapper.php

namespace App\Mcp\Toolbox;

use Symfony\AI\Agent\Toolbox\Exception\ToolException;

final class McpToolFactoryWrapper
{
    public function __construct(private readonly McpToolFactory $mcpToolFactory) {}

    /**
     * @param object|string $reference
     * @return iterable<McpRemoteToolMetadata>
     */
    public function getTool(object|string $reference): iterable
    {
        // Falls die Referenz ein Tool-Name ist (z. B. "filesystem_list_files"),
        // gebe das spezifische Tool zurück.
        if (is_string($reference) && str_contains($reference, '_')) {
            [$serverAlias, $toolName] = explode('_', $reference, 2);
            foreach ($this->mcpToolFactory->getTools() as $tool) {
                if ($tool->getName() === $reference) {
                    yield $tool;
                    return;
                }
            }
        }

        // Falls keine spezifische Referenz gefunden wurde, gebe alle Tools zurück.
        foreach ($this->mcpToolFactory->getTools() as $tool) {
            yield $tool;
        }
    }
}
