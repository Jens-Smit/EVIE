<?php
// tests/Unit/Message/ExecuteToolMessageTest.php

namespace App\Tests\Unit\Message;

use App\Message\ExecuteToolMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ExecuteToolMessageTest extends TestCase
{
    public function testConstructor(): void
    {
        $message = new ExecuteToolMessage(
            'test_tool',
            ['arg1' => 'value1'],
            'user_123',
            'session_456'
        );

        $this->assertNotNull($message->getMessageId());
        $this->assertInstanceOf(Uuid::class, $message->getMessageId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertEquals(['arg1' => 'value1'], $message->getArguments());
        $this->assertEquals('user_123', $message->getUserIdentifier());
        $this->assertEquals('session_456', $message->getSessionId());
        $this->assertNotNull($message->getCorrelationId());
        $this->assertNotNull($message->getCreatedAt());
    }

    public function testConstructorWithCorrelationId(): void
    {
        $correlationId = Uuid::v4()->toRfc4122();
        $message = new ExecuteToolMessage(
            'test_tool',
            [],
            'user_123',
            'session_456',
            $correlationId
        );

        $this->assertEquals($correlationId, $message->getCorrelationId());
    }

    public function testToArray(): void
    {
        $message = new ExecuteToolMessage(
            'test_tool',
            ['arg1' => 'value1', 'arg2' => 'value2'],
            'user_123',
            'session_456'
        );

        $array = $message->toArray();

        $this->assertArrayHasKey('message_id', $array);
        $this->assertArrayHasKey('tool_name', $array);
        $this->assertArrayHasKey('arguments', $array);
        $this->assertArrayHasKey('user_identifier', $array);
        $this->assertArrayHasKey('session_id', $array);
        $this->assertArrayHasKey('correlation_id', $array);
        $this->assertArrayHasKey('created_at', $array);

        $this->assertEquals('test_tool', $array['tool_name']);
        $this->assertEquals(['arg1' => 'value1', 'arg2' => 'value2'], $array['arguments']);
        $this->assertEquals('user_123', $array['user_identifier']);
        $this->assertEquals('session_456', $array['session_id']);
    }

    public function testFromArray(): void
    {
        $data = [
            'tool_name' => 'test_tool',
            'arguments' => ['arg1' => 'value1'],
            'user_identifier' => 'user_123',
            'session_id' => 'session_456',
            'correlation_id' => 'corr_789',
        ];

        $message = ExecuteToolMessage::fromArray($data);

        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertEquals(['arg1' => 'value1'], $message->getArguments());
        $this->assertEquals('user_123', $message->getUserIdentifier());
        $this->assertEquals('session_456', $message->getSessionId());
        $this->assertEquals('corr_789', $message->getCorrelationId());
    }

    public function testFromArrayWithMissingFields(): void
    {
        $data = [
            'tool_name' => 'test_tool',
            'arguments' => [],
            'user_identifier' => 'user_123',
            'session_id' => 'session_456',
        ];

        $message = ExecuteToolMessage::fromArray($data);

        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertEquals([], $message->getArguments());
        $this->assertEquals('user_123', $message->getUserIdentifier());
        $this->assertEquals('session_456', $message->getSessionId());
        $this->assertNotNull($message->getCorrelationId()); // Auto-generated
    }

    public function testUniqueMessageIds(): void
    {
        $message1 = new ExecuteToolMessage('tool1', [], 'user1', 'session1');
        $message2 = new ExecuteToolMessage('tool2', [], 'user2', 'session2');

        $this->assertNotEquals(
            $message1->getMessageId()->toRfc4122(),
            $message2->getMessageId()->toRfc4122()
        );
    }

    public function testCreatedAtIsImmutable(): void
    {
        $message = new ExecuteToolMessage('test_tool', [], 'user_123', 'session_456');
        $createdAt = $message->getCreatedAt();

        // Warte eine Millisekunde
        usleep(1000);

        // Hole CreatedAt erneut
        $createdAt2 = $message->getCreatedAt();

        $this->assertSame($createdAt, $createdAt2);
    }
}
