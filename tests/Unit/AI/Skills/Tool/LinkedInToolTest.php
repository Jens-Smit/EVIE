<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Tool;

use App\AI\Skills\Tool\LinkedInTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit-Tests fuer LinkedInTool.
 */
final class LinkedInToolTest extends TestCase
{
    private MockHttpClient $client;
    private LinkedInTool $tool;

    protected function setUp(): void
    {
        $this->client = new MockHttpClient();
        $this->tool = new LinkedInTool($this->client, 'access-token');
    }

    public function testUnknownActionReturnsError(): void
    {
        $result = ($this->tool)(['action' => 'nope']);

        self::assertSame('error', $result['status']);
        self::assertStringContainsString('Unbekannte LinkedIn-Aktion', $result['message']);
        self::assertSame(['get_profile', 'search_profiles', 'send_message'], $result['available_actions']);
    }

    public function testGetProfileReturnsProfileData(): void
    {
        $client = new MockHttpClient(
            new MockResponse('{"id":"123","firstName":"Jane","lastName":"Doe"}', ['http_code' => 200]),
        );
        $tool = new LinkedInTool($client, 'tok');

        $result = ($tool)(['action' => 'get_profile', 'profileIdOrUrl' => 'jane-doe']);

        self::assertSame('success', $result['status']);
        self::assertSame('jane-doe', $result['profile_id']);
        self::assertSame('123', $result['profile']['id']);
    }

    public function testGetProfileExtractsIdFromUrl(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            return new MockResponse('{"id":"123"}', ['http_code' => 200]);
        });
        $tool = new LinkedInTool($client, 'tok');

        $result = ($tool)(['action' => 'get_profile', 'profileIdOrUrl' => 'https://linkedin.com/in/jane-doe']);

        self::assertSame('jane-doe', $result['profile_id']);
    }

    public function testGetProfileHandlesException(): void
    {
        $client = new MockHttpClient(
            new MockResponse('{}', ['http_code' => 401]),
        );
        $tool = new LinkedInTool($client, 'tok');

        $result = ($tool)(['action' => 'get_profile', 'profileIdOrUrl' => 'jane-doe']);

        self::assertSame('error', $result['status']);
    }

    public function testSearchProfilesReturnsResults(): void
    {
        $client = new MockHttpClient(
            new MockResponse('{"elements":[{"id":"1"},{"id":"2"}]}', ['http_code' => 200]),
        );
        $tool = new LinkedInTool($client, 'tok');

        $result = ($tool)(['action' => 'search_profiles', 'query' => 'engineer', 'limit' => 5]);

        self::assertSame('success', $result['status']);
        self::assertCount(2, $result['results']);
        self::assertSame(2, $result['count']);
    }

    public function testSearchProfilesHandlesException(): void
    {
        $client = new MockHttpClient(
            new MockResponse('{}', ['http_code' => 500]),
        );
        $tool = new LinkedInTool($client, 'tok');

        $result = ($tool)(['action' => 'search_profiles', 'query' => 'engineer']);

        self::assertSame('error', $result['status']);
    }

    public function testSendMessageReturnsConversationId(): void
    {
        $client = new MockHttpClient(
            new MockResponse('{"id":"conv-123"}', ['http_code' => 201]),
        );
        $tool = new LinkedInTool($client, 'tok');

        $result = ($tool)([
            'action' => 'send_message',
            'recipientId' => 'recipient',
            'subject' => 'Hello',
            'message' => 'How are you?',
        ]);

        self::assertSame('success', $result['status']);
        self::assertSame('conv-123', $result['conversation_id']);
        self::assertSame('recipient', $result['recipient_id']);
    }

    public function testSendMessageHandlesException(): void
    {
        $client = new MockHttpClient(
            new MockResponse('{}', ['http_code' => 403]),
        );
        $tool = new LinkedInTool($client, 'tok');

        $result = ($tool)([
            'action' => 'send_message',
            'recipientId' => 'recipient',
            'subject' => 'Hello',
            'message' => 'How are you?',
        ]);

        self::assertSame('error', $result['status']);
        self::assertSame('recipient', $result['recipient_id']);
    }
}
