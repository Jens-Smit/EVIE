<?php

namespace AppAISkillsExecutor;

use AppAISkillsToolDynamicTool;
use SymfonyContractsHttpClientHttpClientInterface;

class GenericHttpExecutor implements ExecutorInterface
{
    public function __construct(
        private HttpClientInterface $httpClient
    ) {
    }

    public function execute(DynamicTool $tool, array $parameters): mixed
    {
        $config = $tool->getExecutorConfig();
        $url = $config['url'] ?? $parameters['url'] ?? null;
        $method = $config['method'] ?? $parameters['method'] ?? 'GET';
        $headers = $config['headers'] ?? [];
        $body = $config['body'] ?? ($parameters['body'] ?? null);

        if (!$url) {
            throw new RuntimeException('HTTP-Executor: URL ist erforderlich');
        }

        $response = $this->httpClient->request($method, $url, [
            'headers' => $headers,
            'body' => $body,
        ]);

        return [
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'content' => $response->getContent(),
        ];
    }

    public function getType(): string
    {
        return 'http';
    }
}
