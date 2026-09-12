<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Tool;

use App\AI\Skills\Tool\OAuthTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit-Tests fuer OAuthTool.
 *
 * Hinweis: OAuthTool nutzt eine proprietäre Cache-API (set/get/listKeys/delete),
 * die nicht dem Symfony CacheInterface entspricht. Daher wird ein PHPUnit-Mock
 * verwendet, das beliebige Methodenaufrufe schluckt.
 */
final class OAuthToolTest extends TestCase
{
    private AdapterInterface $cache;
    private OAuthTool $tool;

    protected function setUp(): void
    {
        $this->cache = $this->createCacheMock();
        $this->tool = new OAuthTool(new MockHttpClient(), $this->cache);
    }

    private function createCacheMock(): AdapterInterface
    {
        return $this->getMockBuilder(AdapterInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getItem', 'getItems', 'hasItem', 'clear', 'deleteItem', 'deleteItems', 'save', 'saveDeferred', 'commit'])
            ->addMethods(['set', 'get', 'listKeys', 'delete'])
            ->getMock();
    }

    private function invoke(array $params): array
    {
        return ($this->tool)($params);
    }

    public function testUnknownActionReturnsErrorWithAvailable(): void
    {
        $result = $this->invoke(['action' => 'does_not_exist']);

        self::assertSame('error', $result['status']);
        self::assertStringContainsString('Unknown action', $result['message']);
        self::assertContains('list_providers', $result['available']);
    }

    public function testListProvidersReturnsConfigured(): void
    {
        $result = $this->invoke(['action' => 'list_providers']);

        self::assertSame('success', $result['status']);
        self::assertSame(['linkedin', 'google', 'microsoft'], $result['providers']);
        self::assertSame(3, $result['count']);
    }

    public function testGetProviderConfigKnown(): void
    {
        $result = $this->invoke(['action' => 'get_provider_config', 'provider' => 'google']);

        self::assertSame('success', $result['status']);
        self::assertStringContainsString('accounts.google.com', $result['config']['auth_url']);
    }

    public function testGetProviderConfigUnknown(): void
    {
        $result = $this->invoke(['action' => 'get_provider_config', 'provider' => 'nope']);

        self::assertSame('error', $result['status']);
        self::assertSame(['linkedin', 'google', 'microsoft'], $result['available']);
    }

    public function testAuthenticateReturnsAuthorizationUrl(): void
    {
        $result = $this->invoke([
            'action' => 'authenticate',
            'provider' => 'linkedin',
            'clientId' => 'cid',
            'redirectUri' => 'https://app/cb',
            'state' => 'st123',
        ]);

        self::assertSame('success', $result['status']);
        self::assertStringContainsString('https://www.linkedin.com/oauth/v2/authorization', $result['authorization_url']);
        self::assertSame('st123', $result['state']);
    }

    public function testAuthenticateUnknownProvider(): void
    {
        $result = $this->invoke([
            'action' => 'authenticate',
            'provider' => 'nope',
            'clientId' => 'cid',
            'redirectUri' => 'https://app/cb',
            'state' => 'st123',
        ]);

        self::assertSame('error', $result['status']);
        self::assertSame(['linkedin', 'google', 'microsoft'], $result['available']);
    }

    public function testGetAccessTokenStoresAndReturnsToken(): void
    {
        $cache = $this->createCacheMock();
        $client = new MockHttpClient(
            new MockResponse('{"access_token":"tok","refresh_token":"rt","expires_in":3600,"token_type":"Bearer"}', ['http_code' => 200]),
        );
        $tool = new OAuthTool($client, $cache);

        $result = ($tool)([
            'action' => 'get_access_token',
            'provider' => 'google',
            'clientId' => 'cid',
            'clientSecret' => 'cs',
            'code' => 'code123',
            'redirectUri' => 'https://app/cb',
        ]);

        self::assertSame('success', $result['status']);
        self::assertSame('tok', $result['access_token']);
    }

    public function testGetAccessTokenWithoutTokenReturnsError(): void
    {
        $cache = $this->createCacheMock();
        $client = new MockHttpClient(
            new MockResponse('{"error":"denied"}', ['http_code' => 200]),
        );
        $tool = new OAuthTool($client, $cache);

        $result = ($tool)([
            'action' => 'get_access_token',
            'provider' => 'google',
            'clientId' => 'cid',
            'clientSecret' => 'cs',
            'code' => 'code123',
            'redirectUri' => 'https://app/cb',
        ]);

        self::assertSame('error', $result['status']);
        self::assertStringContainsString('Failed to get access token', $result['message']);
    }

    public function testGetAccessTokenUnknownProvider(): void
    {
        $result = $this->invoke(['action' => 'get_access_token', 'provider' => 'nope']);

        self::assertSame('error', $result['status']);
    }

    public function testRefreshTokenReturnsNewToken(): void
    {
        $cache = $this->createCacheMock();
        $client = new MockHttpClient(
            new MockResponse('{"access_token":"newtok","expires_in":3600,"token_type":"Bearer"}', ['http_code' => 200]),
        );
        $tool = new OAuthTool($client, $cache);

        $result = ($tool)([
            'action' => 'refresh_token',
            'provider' => 'google',
            'clientId' => 'cid',
            'clientSecret' => 'cs',
            'refreshToken' => 'rt',
        ]);

        self::assertSame('success', $result['status']);
        self::assertSame('newtok', $result['access_token']);
    }

    public function testRefreshTokenUnknownProvider(): void
    {
        $result = $this->invoke(['action' => 'refresh_token', 'provider' => 'nope']);
        self::assertSame('error', $result['status']);
    }

    public function testGetCurrentTokenEmpty(): void
    {
        $cache = $this->createCacheMock();
        $cache->method('get')->willReturn([]);
        $tool = new OAuthTool(new MockHttpClient(), $cache);

        $result = ($tool)(['action' => 'get_current_token', 'provider' => 'google', 'clientId' => 'cid']);

        self::assertSame('error', $result['status']);
        self::assertSame('No token found', $result['message']);
    }

    public function testGetCurrentTokenValid(): void
    {
        $cache = $this->createCacheMock();
        $cache->method('get')->willReturn([
            'access_token' => 'tok',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'email',
            'created_at' => time(),
        ]);
        $tool = new OAuthTool(new MockHttpClient(), $cache);

        $result = ($tool)(['action' => 'get_current_token', 'provider' => 'google', 'clientId' => 'cid']);

        self::assertSame('success', $result['status']);
        self::assertSame('tok', $result['access_token']);
    }

    public function testGetCurrentTokenExpired(): void
    {
        $cache = $this->createCacheMock();
        $cache->method('get')->willReturn([
            'access_token' => 'tok',
            'expires_in' => 60,
            'created_at' => time() - 120,
        ]);
        $tool = new OAuthTool(new MockHttpClient(), $cache);

        $result = ($tool)(['action' => 'get_current_token', 'provider' => 'google', 'clientId' => 'cid']);

        self::assertSame('expired', $result['status']);
    }
}
