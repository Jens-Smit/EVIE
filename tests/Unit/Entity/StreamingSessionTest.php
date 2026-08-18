<?php
// tests/Unit/Entity/StreamingSessionTest.php

namespace App\Tests\Unit\Entity;

use App\Entity\StreamingSession;
use App\Entity\UserProfile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class StreamingSessionTest extends TestCase
{
    public function testConstructor(): void
    {
        $session = new StreamingSession();

        $this->assertNotNull($session->getId());
        $this->assertInstanceOf(Uuid::class, $session->getId());
        $this->assertNotNull($session->getSessionId());
        $this->assertNotNull($session->getCreatedAt());
        $this->assertNull($session->getStartedAt());
        $this->assertNull($session->getCompletedAt());
        $this->assertNull($session->getUpdatedAt());
        $this->assertEquals(StreamingSession::STATUS_PENDING, $session->getStatus());
    }

    public function testGettersAndSetters(): void
    {
        $session = new StreamingSession();

        // Test sessionId
        $session->setSessionId('test_session_id');
        $this->assertEquals('test_session_id', $session->getSessionId());

        // Test toolName
        $session->setToolName('test_tool');
        $this->assertEquals('test_tool', $session->getToolName());

        // Test initialArguments
        $arguments = ['arg1' => 'value1', 'arg2' => 'value2'];
        $session->setInitialArguments($arguments);
        $this->assertEquals($arguments, $session->getInitialArguments());

        // Test userIdentifier
        $session->setUserIdentifier('user_123');
        $this->assertEquals('user_123', $session->getUserIdentifier());

        // Test status
        $session->setStatus(StreamingSession::STATUS_RUNNING);
        $this->assertEquals(StreamingSession::STATUS_RUNNING, $session->getStatus());

        // Test currentProgress
        $session->setCurrentProgress('Processing...');
        $this->assertEquals('Processing...', $session->getCurrentProgress());

        // Test progressPercentage
        $session->setProgressPercentage(50.5);
        $this->assertEquals(50.5, $session->getProgressPercentage());

        // Test partialResults
        $partialResults = ['result1', 'result2'];
        $session->setPartialResults($partialResults);
        $this->assertEquals($partialResults, $session->getPartialResults());

        // Test addPartialResult
        $session->addPartialResult('result3');
        $this->assertCount(3, $session->getPartialResults());
        $this->assertContains('result3', $session->getPartialResults());

        // Test finalResult
        $finalResult = ['final' => 'data'];
        $session->setFinalResult($finalResult);
        $this->assertEquals($finalResult, $session->getFinalResult());

        // Test errorData
        $errorData = ['error' => 'message'];
        $session->setErrorData($errorData);
        $this->assertEquals($errorData, $session->getErrorData());

        // Test startedAt
        $startedAt = new \DateTimeImmutable();
        $session->setStartedAt($startedAt);
        $this->assertEquals($startedAt, $session->getStartedAt());

        // Test completedAt
        $completedAt = new \DateTimeImmutable();
        $session->setCompletedAt($completedAt);
        $this->assertEquals($completedAt, $session->getCompletedAt());

        // Test updatedAt
        $updatedAt = new \DateTimeImmutable();
        $session->setUpdatedAt($updatedAt);
        $this->assertEquals($updatedAt, $session->getUpdatedAt());

        // Test correlationId
        $session->setCorrelationId('corr_123');
        $this->assertEquals('corr_123', $session->getCorrelationId());

        // Test user
        $user = new User();
        $session->setUser($user);
        $this->assertSame($user, $session->getUser());
    }

    public function testIsActive(): void
    {
        $session = new StreamingSession();

        $session->setStatus(StreamingSession::STATUS_PENDING);
        $this->assertTrue($session->isActive());

        $session->setStatus(StreamingSession::STATUS_RUNNING);
        $this->assertTrue($session->isActive());

        $session->setStatus(StreamingSession::STATUS_COMPLETED);
        $this->assertFalse($session->isActive());

        $session->setStatus(StreamingSession::STATUS_FAILED);
        $this->assertFalse($session->isActive());

        $session->setStatus(StreamingSession::STATUS_CANCELLED);
        $this->assertFalse($session->isActive());
    }

    public function testIsFinished(): void
    {
        $session = new StreamingSession();

        $session->setStatus(StreamingSession::STATUS_PENDING);
        $this->assertFalse($session->isFinished());

        $session->setStatus(StreamingSession::STATUS_RUNNING);
        $this->assertFalse($session->isFinished());

        $session->setStatus(StreamingSession::STATUS_COMPLETED);
        $this->assertTrue($session->isFinished());

        $session->setStatus(StreamingSession::STATUS_FAILED);
        $this->assertTrue($session->isFinished());

        $session->setStatus(StreamingSession::STATUS_CANCELLED);
        $this->assertTrue($session->isFinished());
    }

    public function testIsSuccessful(): void
    {
        $session = new StreamingSession();

        $session->setStatus(StreamingSession::STATUS_PENDING);
        $this->assertFalse($session->isSuccessful());

        $session->setStatus(StreamingSession::STATUS_RUNNING);
        $this->assertFalse($session->isSuccessful());

        $session->setStatus(StreamingSession::STATUS_COMPLETED);
        $this->assertTrue($session->isSuccessful());

        $session->setStatus(StreamingSession::STATUS_FAILED);
        $this->assertFalse($session->isSuccessful());

        $session->setStatus(StreamingSession::STATUS_CANCELLED);
        $this->assertFalse($session->isSuccessful());
    }

    public function testGetDuration(): void
    {
        $session = new StreamingSession();

        // Ohne startedAt und completedAt
        $this->assertNull($session->getDuration());

        // Mit startedAt und completedAt
        $startedAt = new \DateTimeImmutable('2026-08-12 10:00:00');
        $completedAt = new \DateTimeImmutable('2026-08-12 10:05:00');

        $session->setStartedAt($startedAt);
        $session->setCompletedAt($completedAt);

        $this->assertEquals(300, $session->getDuration()); // 5 Minuten = 300 Sekunden
    }

    public function testToArray(): void
    {
        $session = new StreamingSession();
        $session->setToolName('test_tool');
        $session->setInitialArguments(['arg1' => 'value1']);
        $session->setUserIdentifier('user_123');
        $session->setStatus(StreamingSession::STATUS_RUNNING);
        $session->setCurrentProgress('Processing...');
        $session->setProgressPercentage(50.0);
        $session->setPartialResults(['result1']);
        $session->setCorrelationId('corr_123');

        $array = $session->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('session_id', $array);
        $this->assertArrayHasKey('tool_name', $array);
        $this->assertArrayHasKey('initial_arguments', $array);
        $this->assertArrayHasKey('user_identifier', $array);
        $this->assertArrayHasKey('status', $array);
        $this->assertArrayHasKey('current_progress', $array);
        $this->assertArrayHasKey('progress_percentage', $array);
        $this->assertArrayHasKey('partial_results', $array);
        $this->assertArrayHasKey('final_result', $array);
        $this->assertArrayHasKey('error_data', $array);
        $this->assertArrayHasKey('created_at', $array);
        $this->assertArrayHasKey('started_at', $array);
        $this->assertArrayHasKey('completed_at', $array);
        $this->assertArrayHasKey('updated_at', $array);
        $this->assertArrayHasKey('correlation_id', $array);
        $this->assertArrayHasKey('duration', $array);

        $this->assertEquals('test_tool', $array['tool_name']);
        $this->assertEquals(['arg1' => 'value1'], $array['initial_arguments']);
        $this->assertEquals('user_123', $array['user_identifier']);
        $this->assertEquals(StreamingSession::STATUS_RUNNING, $array['status']);
        $this->assertEquals('Processing...', $array['current_progress']);
        $this->assertEquals(50.0, $array['progress_percentage']);
        $this->assertEquals(['result1'], $array['partial_results']);
        $this->assertEquals('corr_123', $array['correlation_id']);
    }

    public function testStatusConstants(): void
    {
        $this->assertEquals('pending', StreamingSession::STATUS_PENDING);
        $this->assertEquals('running', StreamingSession::STATUS_RUNNING);
        $this->assertEquals('completed', StreamingSession::STATUS_COMPLETED);
        $this->assertEquals('failed', StreamingSession::STATUS_FAILED);
        $this->assertEquals('cancelled', StreamingSession::STATUS_CANCELLED);
    }
}
