<?php
// tests/Unit/Message/StreamChunkMessageTest.php

namespace App\Tests\Unit\Message;

use App\Message\StreamChunkMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class StreamChunkMessageTest extends TestCase
{
    public function testConstructor(): void
    {
        $message = new StreamChunkMessage(
            'session_123',
            'test_tool',
            ['data' => 'test'],
            'data',
            1
        );

        $this->assertNotNull($message->getMessageId());
        $this->assertInstanceOf(Uuid::class, $message->getMessageId());
        $this->assertEquals('session_123', $message->getSessionId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertEquals(['data' => 'test'], $message->getData());
        $this->assertEquals('data', $message->getType());
        $this->assertEquals(1, $message->getSequenceNumber());
        $this->assertNotNull($message->getCorrelationId());
        $this->assertNotNull($message->getCreatedAt());
    }

    public function testConstructorWithCorrelationId(): void
    {
        $correlationId = Uuid::v4()->toRfc4122();
        $message = new StreamChunkMessage(
            'session_123',
            'test_tool',
            [],
            'progress',
            1,
            $correlationId
        );

        $this->assertEquals($correlationId, $message->getCorrelationId());
    }

    public function testCreateProgress(): void
    {
        $message = StreamChunkMessage::createProgress(
            'session_123',
            'test_tool',
            50.5,
            'Processing...',
            1
        );

        $this->assertEquals('session_123', $message->getSessionId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertEquals('progress', $message->getType());
        $this->assertEquals([
            'percentage' => 50.5,
            'message' => 'Processing...',
        ], $message->getData());
        $this->assertEquals(1, $message->getSequenceNumber());
    }

    public function testCreateData(): void
    {
        $message = StreamChunkMessage::createData(
            'session_123',
            'test_tool',
            ['chunk' => 'data'],
            2
        );

        $this->assertEquals('session_123', $message->getSessionId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertEquals('data', $message->getType());
        $this->assertEquals(['chunk' => 'data'], $message->getData());
        $this->assertEquals(2, $message->getSequenceNumber());
    }

    public function testCreateLog(): void
    {
        $message = StreamChunkMessage::createLog(
            'session_123',
            'test_tool',
            'Log message',
            'info',
            3
        );

        $this->assertEquals('session_123', $message->getSessionId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertEquals('log', $message->getType());
        $this->assertEquals([
            'level' => 'info',
            'message' => 'Log message',
        ], $message->getData());
        $this->assertArrayHasKey('timestamp', $message->getData());
        $this->assertEquals(3, $message->getSequenceNumber());
    }

    public function testCreateStatus(): void
    {
        $message = StreamChunkMessage::createStatus(
            'session_123',
            'test_tool',
            'running',
            ['details' => 'Processing'],
            4
        );

        $this->assertEquals('session_123', $message->getSessionId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertEquals('status', $message->getType());
        $this->assertEquals([
            'status' => 'running',
            'details' => 'Processing',
        ], $message->getData());
        $this->assertEquals(4, $message->getSequenceNumber());
    }

    public function testToArray(): void
    {
        $message = new StreamChunkMessage(
            'session_123',
            'test_tool',
            ['data' => 'test'],
            'data',
            1,
            'corr_456'
        );

        $array = $message->toArray();

        $this->assertArrayHasKey('message_id', $array);
        $this->assertArrayHasKey('session_id', $array);
        $this->assertArrayHasKey('tool_name', $array);
        $this->assertArrayHasKey('data', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertArrayHasKey('sequence_number', $array);
        $this->assertArrayHasKey('correlation_id', $array);
        $this->assertArrayHasKey('created_at', $array);

        $this->assertEquals('session_123', $array['session_id']);
        $this->assertEquals('test_tool', $array['tool_name']);
        $this->assertEquals(['data' => 'test'], $array['data']);
        $this->assertEquals('data', $array['type']);
        $this->assertEquals(1, $array['sequence_number']);
        $this->assertEquals('corr_456', $array['correlation_id']);
    }

    public function testFromArray(): void
    {
        $data = [
            'session_id' => 'session_123',
            'tool_name' => 'test_tool',
            'data' => ['data' => 'test'],
            'type' => 'data',
            'sequence_number' => 1,
            'correlation_id' => 'corr_456',
        ];

        $message = StreamChunkMessage::fromArray($data);

        $this->assertEquals('session_123', $message->getSessionId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertEquals(['data' => 'test'], $message->getData());
        $this->assertEquals('data', $message->getType());
        $this->assertEquals(1, $message->getSequenceNumber());
        $this->assertEquals('corr_456', $message->getCorrelationId());
    }

    public function testFromArrayWithMissingFields(): void
    {
        $data = [
            'session_id' => 'session_123',
            'tool_name' => 'test_tool',
            'data' => [],
        ];

        $message = StreamChunkMessage::fromArray($data);

        $this->assertEquals('session_123', $message->getSessionId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertEquals([], $message->getData());
        $this->assertEquals('data', $message->getType()); // Default
        $this->assertEquals(1, $message->getSequenceNumber()); // Default
        $this->assertNotNull($message->getCorrelationId()); // Auto-generated
    }
}
