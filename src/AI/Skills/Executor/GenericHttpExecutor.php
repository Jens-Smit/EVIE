<?php

namespace App\AI\Skills\Executor;

use App\AI\Skills\Tool\DynamicTool;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
            // SSRF-Defense: Redirects duerfen nicht automatisch gefolgt werden.
            // Die URL wurde vorab durch SecurityGuard::isUrlSafe() geprueft;
            // ein serverseitiger 302 auf z. B. http://169.254.169.254/ wuerde
            // diese Pruefung umgehen, weshalb Redirects hier deaktiviert sind.
            'max_redirects' => 0,
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
