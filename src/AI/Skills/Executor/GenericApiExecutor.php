<?php

namespace AppAISkillsExecutor;

use AppAISkillsToolDynamicTool;

class GenericApiExecutor implements ExecutorInterface
{
    public function execute(DynamicTool $tool, array $parameters): mixed
    {
        $config = $tool->getExecutorConfig();
        $url = $config['url'] ?? null;
        $method = $config['method'] ?? 'GET';
        $headers = $config['headers'] ?? [];
        $body = $config['body'] ?? null;

        if (!$url) {
            throw new RuntimeException('API-Executor: URL ist erforderlich');
        }

        // Hier würde der echte HTTP-Call stattfinden
        // Für jetzt: Simuliere eine API-Antwort
        return [
            'status' => 'success',
            'data' => $parameters,
            'url' => $url,
            'method' => $method,
            'message' => 'API-Aufruf erfolgreich simuliert'
        ];
    }

    public function getType(): string
    {
        return 'api';
    }
}
