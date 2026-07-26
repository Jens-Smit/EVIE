<?php
// src/MCP/Transport/TransportInterface.php
namespace App\MCP\Transport;

use App\MCP\Exception\TransportException;

/**
 * Interface for MCP transport layers (HTTP, STDIO).
 */
interface TransportInterface
{
    /**
     * Sends a request to the MCP server and returns the response.
     *
     * @param array<string, mixed> $request The JSON-RPC request.
     * @return array<string, mixed> The JSON-RPC response.
     * @throws TransportException If the request fails.
     */
    public function send(array $request): array;
}
