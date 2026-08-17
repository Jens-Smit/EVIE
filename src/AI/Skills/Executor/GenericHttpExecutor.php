<?php

namespace App\AI\Skills\Executor;

use App\AI\Security\OutboundRequestPolicy;
use App\AI\Security\SecurityGuard;
use App\AI\Skills\Tool\DynamicTool;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GenericHttpExecutor - fuehrt HTTP-basierte dynamische Tools aus.
 *
 * P1-2: OutboundRequestPolicy::resolveAllowedIp() ist jetzt verdrahtet.
 * Die IP wird vorab aufgeloest (DNS-Rebinding-/TOCTOU-Schutz) und via
 * Symfony HttpClient 'resolve'-Option fest an den Verbindungsaufbau
 * gebunden, sodass der Hostname beim eigentlichen Request nicht erneut
 * aufgeloest wird. Dadurch kann ein Angreifer die Policy-Pruefung nicht
 * durch eine DNS-Aenderung zwischen Pruefung und Verbindungsaufbau umgehen.
 */
class GenericHttpExecutor implements ExecutorInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ?SecurityGuard $securityGuard = null,
        private ?OutboundRequestPolicy $outboundRequestPolicy = null,
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
        if (null !== $this->securityGuard && !$this->securityGuard->isUrlSafe($url)) {
            throw new RuntimeException('HTTP-Executor: URL durch SecurityGuard blockiert.');
        }

        // P1-2: TOCTOU-/DNS-Rebinding-Schutz. Die IP wird vorab aufgeloest
        // (resolveAllowedIp) und via 'resolve'-Option fest an den Request
        // gebunden. Der Hostname wird beim eigentlichen Request nicht erneut
        // aufgeloest, sodass eine DNS-Aenderung zwischen Pruefung und
        // Verbindungsaufbau wirkungslos ist.
        $requestOptions = [
            'headers' => $headers,
            'body' => $body,
            // SSRF-Defense: Redirects duerfen nicht automatisch gefolgt werden.
            'max_redirects' => 0,
        ];

        if (null !== $this->outboundRequestPolicy) {
            $resolvedIp = $this->outboundRequestPolicy->resolveAllowedIp($url);
            if (null === $resolvedIp) {
                throw new RuntimeException(
                    'HTTP-Executor: URL aufgeloest zu einer blockierten/privaten IP (DNS-Rebinding-Schutz).'
                );
            }
            // Symfony HttpClient: 'resolve' bindet Hostname -> IP fest,
            // sodass der Client die IP direkt verwendet ohne erneute DNS-Aufloesung.
            $host = parse_url($url, PHP_URL_HOST) ?? '';
            $host = trim($host, '[]');
            if ($host !== '') {
                $requestOptions['resolve'] = [$host => $resolvedIp];
            }
        }

        $response = $this->httpClient->request($method, $url, $requestOptions);

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
