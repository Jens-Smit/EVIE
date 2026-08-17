<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Executor;

use App\AI\Security\OutboundRequestPolicy;
use App\AI\Security\SecurityGuard;
use App\AI\Skills\Executor\GenericHttpExecutor;
use App\AI\Skills\Tool\DynamicTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * P1-2 Test: verifiziert, dass OutboundRequestPolicy::resolveAllowedIp()
 * tatsaechlich in GenericHttpExecutor verdrahtet ist und die geloeste IP
 * fuer den Verbindungsaufbau verwendet wird (DNS-Rebinding-/TOCTOU-Schutz).
 */
final class GenericHttpExecutorResolveIpTest extends TestCase
{
    public function testResolveAllowedIpIsUsedForRequest(): void
    {
        $resolvedIp = null;
        $resolveOption = null;

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturnCallback(
            function (string $method, string $url, array $options) use (&$resolvedIp, &$resolveOption, $httpClient): ResponseInterface {
                // Capture die resolve-Option, die die fest gebundene IP enthaelt
                if (isset($options['resolve'])) {
                    $resolveOption = $options['resolve'];
                }
                $response = $this->createMock(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn(200);
                $response->method('getHeaders')->willReturn([]);
                $response->method('getContent')->willReturn('OK');

                return $response;
            }
        );

        // OutboundRequestPolicy-Mock: resolveAllowedIp gibt eine feste IP zurueck
        $policy = $this->createMock(OutboundRequestPolicy::class);
        $policy->method('resolveAllowedIp')->willReturn('93.184.216.34'); // example.com IP

        $executor = new GenericHttpExecutor(
            $httpClient,
            null, // SecurityGuard nicht noetig fuer diesen Test
            $policy,
        );

        $tool = new DynamicTool(
            'test_http_tool',
            'Test HTTP Tool',
            [],
            'http',
            ['url' => 'https://example.com/api', 'method' => 'GET'],
        );

        $result = $executor->execute($tool, []);

        self::assertSame(200, $result['status']);
        self::assertNotNull($resolveOption, 'GenericHttpExecutor muss die resolve-Option setzen.');
        self::assertArrayHasKey(
            'example.com',
            $resolveOption,
            'resolve-Option muss den Hostnamen als Key enthalten.'
        );
        self::assertSame(
            '93.184.216.34',
            $resolveOption['example.com'],
            'resolve-Option muss die von resolveAllowedIp() gelieferte IP enthalten.'
        );
    }

    public function testBlockedIpThrowsRuntimeException(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request'); // Request darf nicht ausgefuehrt werden

        // OutboundRequestPolicy-Mock: resolveAllowedIp gibt null (blockiert)
        $policy = $this->createMock(OutboundRequestPolicy::class);
        $policy->method('resolveAllowedIp')->willReturn(null);

        $executor = new GenericHttpExecutor(
            $httpClient,
            null,
            $policy,
        );

        $tool = new DynamicTool(
            'test_http_tool',
            'Test HTTP Tool',
            [],
            'http',
            ['url' => 'https://evil.com/api', 'method' => 'GET'],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('blockierten/privaten IP');

        $executor->execute($tool, []);
    }
}
