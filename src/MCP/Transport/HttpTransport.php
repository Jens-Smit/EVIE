<?php
// src/MCP/Transport/HttpTransport.php
namespace App\MCP\Transport;

use App\MCP\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * HTTP transport for MCP servers.
 */
final class HttpTransport implements TransportInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $url,
        private int $timeout = 60,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function send(array $request): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($request, JSON_THROW_ON_ERROR),
                'timeout' => $this->timeout,
            ]);

            return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (
            TransportExceptionInterface |
            ClientExceptionInterface |
            RedirectionExceptionInterface |
            ServerExceptionInterface $e
        ) {
            throw TransportException::connectionFailed(
                sprintf('HTTP request failed: %s', $e->getMessage()),
                $e
            );
        } catch (\JsonException $e) {
            throw TransportException::connectionFailed(
                sprintf('JSON encoding/decoding failed: %s', $e->getMessage()),
                $e
            );
        }
    }
}
