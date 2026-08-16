<?php

namespace App\AI\Skills\Executor;

use App\AI\Security\SecurityGuard;
use App\AI\Skills\Tool\DynamicTool;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GenericHttpExecutor implements ExecutorInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ?SecurityGuard $securityGuard = null,
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

        // Defense-in-Depth: unabhaengige Pruefung als letzte Verteidigungslinie.
        // Der HitlListener scannt nur die Tool-Call-Argumente; eine URL, die
        // in der ToolDefinition (executorConfig) steht, sieht er nicht. Zudem
        // schliesst dieser Aufruf den Fall ab, dass die Policy im Executor
        // selbst konsultiert werden muss (SSRF, private Netze, DNS-Auflösung).
        if (null !== $this->securityGuard && !$this->securityGuard->isUrlSafe($url)) {
            throw new RuntimeException('HTTP-Executor: URL durch SecurityGuard blockiert.');
        }

        $response = $this->httpClient->request($method, $url, [
            'headers' => $headers,
            'body' => $body,
            // SSRF-Defense: Redirects duerfen nicht automatisch gefolgt werden.
            // Ein serverseitiger 302 auf z. B. http://169.254.169.254/ wuerde
            // die vorab gepruefte URL umgehen, weshalb Redirects deaktiviert sind.
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
