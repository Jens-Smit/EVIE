<?php
// src/MCP/Model/ServerConfig.php
namespace App\MCP\Model;

/**
 * Configuration for an MCP server.
 */
final readonly class ServerConfig
{
    public function __construct(
        public string $name,
        public string $transport,
        public ?string $url = null,
        public ?string $command = null,
        public array $arguments = [],
        public int $timeout = 60,
    ) {
    }

    /**
     * Creates a ServerConfig from an array (e.g., from YAML config).
     */
    public static function fromArray(string $name, array $config): self
    {
        return new self(
            name: $name,
            transport: $config['transport'],
            url: $config['url'] ?? null,
            command: $config['command'] ?? null,
            arguments: $config['arguments'] ?? [],
            timeout: $config['timeout'] ?? 60,
        );
    }
}
