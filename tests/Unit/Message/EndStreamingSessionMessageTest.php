<?php
// tests/Unit/Message/EndStreamingSessionMessageTest.php

namespace App\Tests\Unit\Message;

use App\Message\EndStreamingSessionMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class EndStreamingSessionMessageTest extends TestCase
{
    public function testConstructor(): void
    {
        $message = new EndStreamingSessionMessage(
            'session_123',
            'test_tool',
            true,
            'completed',
            ['result_type' => 'array']
        );

        $this->assertNotNull($message->getMessageId());
        $this->assertInstanceOf(Uuid::class, $message->getMessageId());
        $this->assertEquals('session_123', $message->getSessionId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertTrue($message->isSuccess());
        $this->assertEquals('completed', $message->getFinalStatus());
        $this->assertEquals(['result_type' => 'array'], $message->getMetadata());
        $this->assertNotNull($message->getCorrelationId());
        $this->assertNotNull($message->getCreatedAt());
    }

    public function testConstructorWithFailure(): void
    {
        $message = new EndStreamingSessionMessage(
            'session_123',
            'test_tool',
            false,
            'failed',
            ['error' => 'Test error']
        );

        $this->assertFalse($message->isSuccess());
        $this->assertEquals('failed', $message->getFinalStatus());
        $this->assertEquals(['error' => 'Test error'], $message->getMetadata());
    }

    public function testCreateSuccess(): void
    {
        $message = EndStreamingSessionMessage::createSuccess(
            'session_123',
            'test_tool',
            ['result_type' => 'array']
        );

        $this->assertTrue($message->isSuccess());
        $this->assertEquals('completed', $message->getFinalStatus());
        $this->assertEquals(['result_type' => 'array'], $message->getMetadata());
    }

    public function testCreateFailure(): void
    {
        $message = EndStreamingSessionMessage::createFailure(
            'session_123',
            'test_tool',
            'Test error',
            ['code' => 500]
        );

        $this->assertFalse($message->isSuccess());
        $this->assertEquals('failed', $message->getFinalStatus());
        $this->assertEquals([
            'error' => 'Test error',
            'code' => 500
        ], $message->getMetadata());
    }

    public function testCreateCancelled(): void
    {
        $message = EndStreamingSessionMessage::createCancelled(
            'session_123',
            'test_tool',
            'User cancelled'
        );

        $this->assertFalse($message->isSuccess());
        $this->assertEquals('cancelled', $message->getFinalStatus());
        $this->assertEquals(['reason' => 'User cancelled'], $message->getMetadata());
    }

    public function testToArray(): void
    {
        $message = new EndStreamingSessionMessage(
            'session_123',
            'test_tool',
            true,
            'completed',
            ['result_type' => 'array'],
            'corr_456'
        );

        $array = $message->toArray();

        $this->assertArrayHasKey('message_id', $array);
        $this->assertArrayHasKey('session_id', $array);
        $this->assertArrayHasKey('tool_name', $array);
        $this->assertArrayHasKey('success', $array);
        $this->assertArrayHasKey('final_status', $array);
        $this->assertArrayHasKey('metadata', $array);
        $this->assertArrayHasKey('correlation_id', $array);
        $this->assertArrayHasKey('created_at', $array);

        $this->assertEquals('session_123', $array['session_id']);
        $this->assertEquals('test_tool', $array['tool_name']);
        $this->assertTrue($array['success']);
        $this->assertEquals('completed', $array['final_status']);
        $this->assertEquals(['result_type' => 'array'], $array['metadata']);
        $this->assertEquals('corr_456', $array['correlation_id']);
    }

    public function testFromArray(): void
    {
        $data = [
            'session_id' => 'session_123',
            'tool_name' => 'test_tool',
            'success' => true,
            'final_status' => 'completed',
            'metadata' => ['result_type' => 'array'],
            'correlation_id' => 'corr_456',
        ];

        $message = EndStreamingSessionMessage::fromArray($data);

        $this->assertEquals('session_123', $message->getSessionId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertTrue($message->isSuccess());
        $this->assertEquals('completed', $message->getFinalStatus());
        $this->assertEquals(['result_type' => 'array'], $message->getMetadata());
        $this->assertEquals('corr_456', $message->getCorrelationId());
    }

    public function testFromArrayWithMissingFields(): void
    {
        $data = [
            'session_id' => 'session_123',
            'tool_name' => 'test_tool',
        ];

        $message = EndStreamingSessionMessage::fromArray($data);

        $this->assertEquals('session_123', $message->getSessionId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertTrue($message->isSuccess()); // Default
        $this->assertEquals('completed', $message->getFinalStatus()); // Default
        $this->assertEquals([], $message->getMetadata()); // Default
        $this->assertNotNull($message->getCorrelationId()); // Auto-generated
    }
}
