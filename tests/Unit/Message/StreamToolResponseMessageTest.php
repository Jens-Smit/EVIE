<?php
// tests/Unit/Message/StreamToolResponseMessageTest.php

namespace App\Tests\Unit\Message;

use App\Message\StreamToolResponseMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class StreamToolResponseMessageTest extends TestCase
{
    public function testConstructor(): void
    {
        $message = new StreamToolResponseMessage(
            'session_123',
            'test_tool',
            ['data' => 'test'],
            'partial_result',
            false,
            1,
            10
        );

        $this->assertNotNull($message->getMessageId());
        $this->assertInstanceOf(Uuid::class, $message->getMessageId());
        $this->assertEquals('session_123', $message->getSessionId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertEquals(['data' => 'test'], $message->getChunk());
        $this->assertEquals('partial_result', $message->getChunkType());
        $this->assertFalse($message->isFinal());
        $this->assertEquals(1, $message->getChunkNumber());
        $this->assertEquals(10, $message->getTotalChunks());
        $this->assertNotNull($message->getCorrelationId());
        $this->assertNotNull($message->getCreatedAt());
    }

    public function testProgressCalculation(): void
    {
        $message1 = new StreamToolResponseMessage(
            'session_123',
            'test_tool',
            [],
            'progress',
            false,
            5,
            10
        );

        $this->assertEquals(50.0, $message1->getProgress());

        $message2 = new StreamToolResponseMessage(
            'session_123',
            'test_tool',
            [],
            'progress',
            false,
            0,
            10
        );

        $this->assertEquals(0.0, $message2->getProgress());

        $message3 = new StreamToolResponseMessage(
            'session_123',
            'test_tool',
            [],
            'progress',
            false,
            10,
            10
        );

        $this->assertEquals(100.0, $message3->getProgress());

        $message4 = new StreamToolResponseMessage(
            'session_123',
            'test_tool',
            [],
            'progress',
            false,
            15,
            10
        );

        $this->assertEquals(100.0, $message4->getProgress()); // Capped at 100%
    }

    public function testProgressCalculationWithZeroTotalChunks(): void
    {
        $message = new StreamToolResponseMessage(
            'session_123',
            'test_tool',
            [],
            'progress',
            false,
            1,
            0
        );

        $this->assertEquals(0.0, $message->getProgress());
    }

    public function testCreateProgress(): void
    {
        $message = StreamToolResponseMessage::createProgress(
            'session_123',
            'test_tool',
            50.5,
            'Processing...',
            1
        );

        $this->assertEquals('session_123', $message->getSessionId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertEquals('progress', $message->getChunkType());
        $this->assertFalse($message->isFinal());
        $this->assertEquals(['type' => 'progress', 'progress' => 50.5, 'message' => 'Processing...'], $message->getChunk());
    }

    public function testCreatePartialResult(): void
    {
        $message = StreamToolResponseMessage::createPartialResult(
            'session_123',
            'test_tool',
            ['partial' => 'data'],
            2,
            5
        );

        $this->assertEquals('session_123', $message->getSessionId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertEquals('partial_result', $message->getChunkType());
        $this->assertFalse($message->isFinal());
        $this->assertEquals(['partial' => 'data'], $message->getChunk());
        $this->assertEquals(2, $message->getChunkNumber());
        $this->assertEquals(5, $message->getTotalChunks());
    }

    public function testCreateFinalResult(): void
    {
        $message = StreamToolResponseMessage::createFinalResult(
            'session_123',
            'test_tool',
            ['final' => 'result']
        );

        $this->assertEquals('session_123', $message->getSessionId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertEquals('final_result', $message->getChunkType());
        $this->assertTrue($message->isFinal());
        $this->assertEquals(['final' => 'result'], $message->getChunk());
    }

    public function testCreateError(): void
    {
        $message = StreamToolResponseMessage::createError(
            'session_123',
            'test_tool',
            'Test error',
            ['code' => 500]
        );

        $this->assertEquals('session_123', $message->getSessionId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertEquals('error', $message->getChunkType());
        $this->assertTrue($message->isFinal());
        $this->assertEquals([
            'type' => 'error',
            'error' => 'Test error',
            'details' => ['code' => 500],
        ], $message->getChunk());
    }

    public function testToArray(): void
    {
        $message = new StreamToolResponseMessage(
            'session_123',
            'test_tool',
            ['data' => 'test'],
            'partial_result',
            false,
            1,
            10
        );

        $array = $message->toArray();

        $this->assertArrayHasKey('message_id', $array);
        $this->assertArrayHasKey('session_id', $array);
        $this->assertArrayHasKey('tool_name', $array);
        $this->assertArrayHasKey('chunk', $array);
        $this->assertArrayHasKey('chunk_type', $array);
        $this->assertArrayHasKey('is_final', $array);
        $this->assertArrayHasKey('chunk_number', $array);
        $this->assertArrayHasKey('total_chunks', $array);
        $this->assertArrayHasKey('correlation_id', $array);
        $this->assertArrayHasKey('created_at', $array);
        $this->assertArrayHasKey('progress', $array);

        $this->assertEquals('session_123', $array['session_id']);
        $this->assertEquals('test_tool', $array['tool_name']);
        $this->assertEquals(['data' => 'test'], $array['chunk']);
        $this->assertEquals('partial_result', $array['chunk_type']);
        $this->assertFalse($array['is_final']);
        $this->assertEquals(1, $array['chunk_number']);
        $this->assertEquals(10, $array['total_chunks']);
        $this->assertEquals(10.0, $array['progress']);
    }

    public function testFromArray(): void
    {
        $data = [
            'session_id' => 'session_123',
            'tool_name' => 'test_tool',
            'chunk' => ['data' => 'test'],
            'chunk_type' => 'partial_result',
            'is_final' => false,
            'chunk_number' => 1,
            'total_chunks' => 10,
            'correlation_id' => 'corr_456',
        ];

        $message = StreamToolResponseMessage::fromArray($data);

        $this->assertEquals('session_123', $message->getSessionId());
        $this->assertEquals('test_tool', $message->getToolName());
        $this->assertEquals(['data' => 'test'], $message->getChunk());
        $this->assertEquals('partial_result', $message->getChunkType());
        $this->assertFalse($message->isFinal());
        $this->assertEquals(1, $message->getChunkNumber());
        $this->assertEquals(10, $message->getTotalChunks());
        $this->assertEquals('corr_456', $message->getCorrelationId());
    }
}
