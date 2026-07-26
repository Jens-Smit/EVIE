<?php
// src/MCP/Client/JsonRpcClient.php
namespace App\MCP\Client;

use App\MCP\Exception\MCPException;
use App\MCP\Transport\TransportInterface;

/**
 * JSON-RPC 2.0 client for MCP servers.
 */
final class JsonRpcClient implements MCPClientInterface
{
    private int $requestId = 1;

    public function __construct(
        private TransportInterface $transport,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function initialize(): void
    {
        // MCP servers typically don't require explicit initialization.
        // This method is here for compatibility with the interface.
    }

    /**
     * {@inheritdoc}
     */
    public function listTools(): array
    {
        $request = $this->createRequest('tools/list');
        $response = $this->transport->send($request);

        if (isset($response['error'])) {
            throw MCPException::server(
                sprintf('Failed to list tools: %s', $response['error']['message'] ?? 'Unknown error')
            );
        }

        return $response['result']['tools'] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function callTool(string $toolName, array $arguments = []): mixed
    {
        $request = $this->createRequest('tools/call', [
            'name' => $toolName,
            'arguments' => $arguments,
        ]);

        $response = $this->transport->send($request);

        if (isset($response['error'])) {
            throw MCPException::server(
                sprintf('Failed to call tool "%s": %s', $toolName, $response['error']['message'] ?? 'Unknown error')
            );
        }

        return $response['result'] ?? null;
    }

    /**
     * Creates a JSON-RPC 2.0 request.
     *
     * @param string $method The method to call.
     * @param array<string, mixed> $params The parameters for the method.
     * @return array<string, mixed> The JSON-RPC request.
     */
    private function createRequest(string $method, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $this->requestId++,
            'method' => $method,
            'params' => $params,
        ];
    }
}
