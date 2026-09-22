<?php

declare(strict_types=1);

// tests/Unit/Service/ApiKeyValidatorTest.php

namespace App\Tests\Unit\Service;

use App\Service\ApiKeyValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ApiKeyValidatorTest extends TestCase
{
    public function testEmptyKeyIsRejected(): void
    {
        $validator = new ApiKeyValidator(new MockHttpClient());
        $result = $validator->validate('mistral', '   ');
        self::assertFalse($result['valid']);
        self::assertStringContainsString('leer', $result['message']);
    }

    public function testUnknownProviderIsRejected(): void
    {
        $validator = new ApiKeyValidator(new MockHttpClient());
        $result = $validator->validate('openai', 'sk-key');
        self::assertFalse($result['valid']);
        self::assertStringContainsString('Unbekannter Anbieter "openai"', $result['message']);
    }

    public function testMistralKeyIsValidOnHttp200(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://api.mistral.ai/v1/models', $url);
            self::assertContains('Authorization: Bearer test-key', $options['headers']);
            return new MockResponse('{"data":[]}', ['http_code' => 200]);
        });

        $result = (new ApiKeyValidator($client))->validate('mistral', 'test-key');
        self::assertTrue($result['valid']);
        self::assertStringContainsString('gueltig', $result['message']);
    }

    public function testGeminiKeyIsPassedAsQueryParameter(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame(['key' => 'test-key'], $options['query']);
            return new MockResponse('{"models":[]}', ['http_code' => 200]);
        });

        $result = (new ApiKeyValidator($client))->validate('gemini', 'test-key');
        self::assertTrue($result['valid']);
    }

    public function testTavilyKeyIsRejectedOn401(): void
    {
        $client = new MockHttpClient(
            new MockResponse('{"error":{"message":"invalid key"}}', ['http_code' => 401]),
        );

        $result = (new ApiKeyValidator($client))->validate('tavily', 'bad-key');
        self::assertFalse($result['valid']);
        self::assertStringContainsString('HTTP 401', $result['message']);
    }

    public function testUnexpectedStatusIncludesDetailFromErrorBody(): void
    {
        $client = new MockHttpClient(
            new MockResponse('{"error":{"message":"quota exceeded"}}', ['http_code' => 429]),
        );

        $result = (new ApiKeyValidator($client))->validate('mistral', 'test-key');
        self::assertFalse($result['valid']);
        self::assertStringContainsString('HTTP 429', $result['message']);
        self::assertStringContainsString('quota exceeded', $result['message']);
    }

    public function testNetworkFailureDoesNotBlockOnboarding(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('connection refused');
        });

        $result = (new ApiKeyValidator($client))->validate('mistral', 'test-key');
        self::assertTrue($result['valid']);
        self::assertStringContainsString('nicht moeglich', $result['message']);
    }
}
