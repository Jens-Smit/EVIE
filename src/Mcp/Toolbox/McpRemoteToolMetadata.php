<?php

namespace App\Mcp\Toolbox;

/**
 * Represents metadata for a remote MCP tool.
 * This class holds all necessary information to register an MCP tool
 * in the Symfony AI Agent toolbox.
 */
final readonly class McpRemoteToolMetadata
{
    /**
     * @param string $name The full name of the tool (server_prefix_toolname).
     * @param string $description The tool description.
     * @param array<string, mixed> $inputSchema The JSON schema for tool input.
     * @param string $serverAlias The alias of the MCP server providing this tool.
     * @param string $remoteName The original name of the tool on the MCP server.
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public string $serverAlias,
        public string $remoteName,
    ) {
    }

    /**
     * Gets the server alias.
     */
    public function getServerAlias(): string
    {
        return $this->serverAlias;
    }

    /**
     * Gets the remote tool name.
     */
    public function getRemoteName(): string
    {
        return $this->remoteName;
    }
}
