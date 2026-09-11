<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\StartStreamingSessionMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class StartStreamingSessionMessageTest extends TestCase
{
    public function testConstructorGeneratesIds(): void
    {
        $message = new StartStreamingSessionMessage(
            'session-1',
            'web_search',
            ['query' => 'evie'],
            'user-1'
        );

        self::assertInstanceOf(Uuid::class, $message->getMessageId());
        self::assertSame('session-1', $message->getSessionId());
        self::assertSame('web_search', $message->getToolName());
        self::assertSame(['query' => 'evie'], $message->getInitialArguments());
        self::assertSame('user-1', $message->getUserIdentifier());
        self::assertNotNull($message->getCorrelationId());
        self::assertNotEmpty($message->getCorrelationId());
        self::assertInstanceOf(\DateTimeImmutable::class, $message->getCreatedAt());
    }

    public function testConstructorWithExplicitCorrelationId(): void
    {
        $correlationId = Uuid::v4()->toRfc4122();
        $message = new StartStreamingSessionMessage(
            'session-1',
            'web_search',
            [],
            'user-1',
            $correlationId
        );

        self::assertSame($correlationId, $message->getCorrelationId());
    }

    public function testToArray(): void
    {
        $correlationId = Uuid::v4()->toRfc4122();
        $message = new StartStreamingSessionMessage(
            'session-1',
            'web_search',
            ['query' => 'evie'],
            'user-1',
            $correlationId
        );

        $array = $message->toArray();

        self::assertSame($message->getMessageId()->toRfc4122(), $array['message_id']);
        self::assertSame('session-1', $array['session_id']);
        self::assertSame('web_search', $array['tool_name']);
        self::assertSame(['query' => 'evie'], $array['initial_arguments']);
        self::assertSame('user-1', $array['user_identifier']);
        self::assertSame($correlationId, $array['correlation_id']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $array['created_at']);
    }

    public function testFromArrayRoundTrip(): void
    {
        $correlationId = Uuid::v4()->toRfc4122();
        $original = new StartStreamingSessionMessage(
            'session-2',
            'http_fetch',
            ['url' => 'https://example.com'],
            'user-2',
            $correlationId
        );
        $array = $original->toArray();
        $restored = StartStreamingSessionMessage::fromArray($array);

        self::assertSame('session-2', $restored->getSessionId());
        self::assertSame('http_fetch', $restored->getToolName());
        self::assertSame(['url' => 'https://example.com'], $restored->getInitialArguments());
        self::assertSame('user-2', $restored->getUserIdentifier());
        self::assertSame($correlationId, $restored->getCorrelationId());
    }

    public function testFromArrayWithoutCorrelationId(): void
    {
        $restored = StartStreamingSessionMessage::fromArray([
            'session_id' => 'session-3',
            'tool_name' => 'noop',
            'initial_arguments' => [],
            'user_identifier' => 'user-3',
        ]);

        self::assertNotEmpty($restored->getCorrelationId());
    }
}
