<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Tool;

use App\AI\Skills\Tool\RestApiTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit-Tests fuer RestApiTool.
 */
final class RestApiToolTest extends TestCase
{
    public function testGetRequestReturnsParsedJson(): void
    {
        $client = new MockHttpClient(
            new MockResponse('{"hello":"world"}', ['http_code' => 200]),
        );
        $tool = new RestApiTool($client);

        $result = $tool(['method' => 'GET', 'url' => 'https://api.example.com/items']);

        self::assertSame('success', $result['status']);
        self::assertSame(200, $result['status_code']);
        self::assertSame(['hello' => 'world'], $result['data']);
    }

    public function testNonJsonContentReturnedRaw(): void
    {
        $client = new MockHttpClient(new MockResponse('plain text', ['http_code' => 200]));
        $tool = new RestApiTool($client);

        $result = $tool(['method' => 'GET', 'url' => 'https://api.example.com/']);

        self::assertSame('plain text', $result['data']);
    }

    public function testBearerAuthAddsAuthorizationHeader(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('Bearer abc', $options['headers']['Authorization']);

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });
        $tool = new RestApiTool($client);

        $tool(['method' => 'GET', 'url' => 'https://api.example.com/', 'authType' => 'bearer', 'token' => 'abc']);
        $this->addToAssertionCount(1);
    }

    public function testBasicAuthAddsBase64AuthorizationHeader(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('Basic ' . base64_encode('user:pass'), $options['headers']['Authorization']);

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });
        $tool = new RestApiTool($client);

        $tool(['method' => 'GET', 'url' => 'https://api.example.com/', 'authType' => 'basic', 'token' => 'user:pass']);
        $this->addToAssertionCount(1);
    }

    public function testApiKeyAuthAddsXApiKeyHeader(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('secret', $options['headers']['X-API-Key']);

            return new MockResponse('{}', ['http_code' => 200]);
        });
        $tool = new RestApiTool($client);

        $tool(['method' => 'GET', 'url' => 'https://api.example.com/', 'authType' => 'api_key', 'token' => 'secret']);
        $this->addToAssertionCount(1);
    }

    public function testUnknownAuthTypeUsesRawToken(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('rawtoken', $options['headers']['Authorization']);

            return new MockResponse('{}', ['http_code' => 200]);
        });
        $tool = new RestApiTool($client);

        $tool(['method' => 'GET', 'url' => 'https://api.example.com/', 'authType' => 'custom', 'token' => 'rawtoken']);
        $this->addToAssertionCount(1);
    }

    public function testDefaultBaseUrlIsPrepended(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('https://api.example.com/items', $url);

            return new MockResponse('{}', ['http_code' => 200]);
        });
        $tool = new RestApiTool($client, 'https://api.example.com');

        $tool(['method' => 'GET', 'url' => '/items']);
        $this->addToAssertionCount(1);
    }

    public function testAbsoluteUrlIgnoresDefaultBaseUrl(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('https://other.com/x', $url);

            return new MockResponse('{}', ['http_code' => 200]);
        });
        $tool = new RestApiTool($client, 'https://api.example.com');

        $tool(['method' => 'GET', 'url' => 'https://other.com/x']);
        $this->addToAssertionCount(1);
    }

    public function testPostSendsJsonBody(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);

            return new MockResponse('{}', ['http_code' => 201]);
        });
        $tool = new RestApiTool($client);

        $result = $tool(['method' => 'POST', 'url' => 'https://api.example.com/items', 'data' => ['name' => 'X']]);
        self::assertSame('success', $result['status']);
        self::assertSame(201, $result['status_code']);
    }

    public function testClientErrorReturnsStatusCodeWithContent(): void
    {
        // Das Tool nutzt getContent(false), sodass 4xx nicht wirft und als
        // (erfolgreich abgerufene) Response mit Statuscode zurueckkommt.
        $client = new MockHttpClient(
            new MockResponse('{"error":"bad"}', ['http_code' => 400]),
        );
        $tool = new RestApiTool($client);

        $result = $tool(['method' => 'GET', 'url' => 'https://api.example.com/']);

        self::assertSame('success', $result['status']);
        self::assertSame(400, $result['status_code']);
        self::assertSame(['error' => 'bad'], $result['data']);
    }

    public function testTransportErrorReturnsErrorResponse(): void
    {
        $client = new MockHttpClient(
            new MockResponse('', ['error' => 'DNS resolution failed']),
        );
        $tool = new RestApiTool($client);

        $result = $tool(['method' => 'GET', 'url' => 'https://api.example.com/']);

        self::assertSame('error', $result['status']);
        self::assertStringContainsString('Request Error', $result['message']);
    }

    public function testQueryAndHeadersArePassed(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame(['q' => 'test'], $options['query']);
            self::assertSame('application/json', $options['headers']['Accept']);
            self::assertSame('custom-value', $options['headers']['X-Custom']);

            return new MockResponse('{}', ['http_code' => 200]);
        });
        $tool = new RestApiTool($client);

        $tool(['method' => 'GET', 'url' => 'https://api.example.com/', 'query' => ['q' => 'test'], 'headers' => ['X-Custom' => 'custom-value']]);
        $this->addToAssertionCount(1);
    }
}
