<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\ToolCallLimitProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Exception\RuntimeException;
use Symfony\AI\Agent\Input;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Vollstaendige Test-Abdeckung fuer ToolCallLimitProcessor.
 *
 * Verifiziert Zaehlung von Tool-Calls, Limit-Erreichung und das
 * Default-Limit.
 */
final class ToolCallLimitProcessorTest extends TestCase
{
    private function createInput(int $toolCallCount): Input
    {
        $messages = new MessageBag();
        for ($i = 0; $i < $toolCallCount; $i++) {
            $messages->add(new ToolCallMessage(new ToolCall('id-' . $i, 'tool_name', ['arg' => 1])));
        }
        return new Input('test-model', $messages);
    }

    public function testProcessInputNoToolCallsDoesNothing(): void
    {
        $processor = new ToolCallLimitProcessor(5);
        $input = $this->createInput(0);
        $processor->processInput($input);
        $this->addToAssertionCount(1);
    }

    public function testProcessInputUnderLimitDoesNotThrow(): void
    {
        $processor = new ToolCallLimitProcessor(5);
        $input = $this->createInput(3);
        $processor->processInput($input);
        $this->addToAssertionCount(1);
    }

    public function testProcessInputAtLimitDoesNotThrow(): void
    {
        $processor = new ToolCallLimitProcessor(5);
        $input = $this->createInput(5);
        $processor->processInput($input);
        $this->addToAssertionCount(1);
    }

    public function testProcessInputOverLimitThrowsRuntimeException(): void
    {
        $processor = new ToolCallLimitProcessor(5);
        $input = $this->createInput(6);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tool-Call-Limit erreicht');
        $processor->processInput($input);
    }

    public function testProcessInputDefaultLimitIs20(): void
    {
        $processor = new ToolCallLimitProcessor();
        $input = $this->createInput(21);

        $this->expectException(RuntimeException::class);
        $processor->processInput($input);
    }

    public function testProcessInputAccumulatesAcrossCalls(): void
    {
        $processor = new ToolCallLimitProcessor(5);
        $processor->processInput($this->createInput(2));
        $processor->processInput($this->createInput(2));
        self::addToAssertionCount(1);

        $this->expectException(RuntimeException::class);
        $processor->processInput($this->createInput(2));
    }

    public function testProcessInputExactlyAtLimitNoThrow(): void
    {
        $processor = new ToolCallLimitProcessor(20);
        $input = $this->createInput(20);
        $processor->processInput($input);
        $this->addToAssertionCount(1);
    }

    public function testProcessInputLimit1ThrowsWith2Calls(): void
    {
        $processor = new ToolCallLimitProcessor(1);
        $input = $this->createInput(2);

        $this->expectException(RuntimeException::class);
        $processor->processInput($input);
    }

    public function testProcessInputMessageContainsErrorMessage(): void
    {
        $processor = new ToolCallLimitProcessor(3);
        $input = $this->createInput(10);

        try {
            $processor->processInput($input);
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('10', $e->getMessage());
            self::assertStringContainsString('3', $e->getMessage());
            self::assertStringContainsString('Endlosschleifen', $e->getMessage());
        }
    }
}
