<?php

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\HitlInterceptor;
use App\Entity\ToolDefinition;
use App\Event\PendingToolApprovalEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class HitlInterceptorTest extends TestCase
{
    private HitlInterceptor $interceptor;
    private EventDispatcherInterface $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->interceptor = new HitlInterceptor($this->dispatcher);
    }

    public function testInterceptApprovedTool(): void
    {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('ApprovedTool');
        $toolDefinition->setStatus('approved');

        $tool = new class($toolDefinition) {
            private ToolDefinition $definition;

            public function __construct(ToolDefinition $definition)
            {
                $this->definition = $definition;
            }

            public function getDefinition(): ToolDefinition
            {
                return $this->definition;
            }
        };

        $result = $this->interceptor->interceptToolExecution($tool, 'test prompt', 'user123');

        $this->assertEquals('approved', $result['status']);
        $this->assertEquals('ApprovedTool', $result['tool']);
    }

    public function testInterceptPendingTool(): void
    {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('PendingTool');
        $toolDefinition->setStatus('pending');

        $tool = new class($toolDefinition) {
            private ToolDefinition $definition;

            public function __construct(ToolDefinition $definition)
            {
                $this->definition = $definition;
            }

            public function getDefinition(): ToolDefinition
            {
                return $this->definition;
            }
        };

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PendingToolApprovalEvent::class));

        $result = $this->interceptor->interceptToolExecution($tool, 'test prompt', 'user123');

        $this->assertEquals('blocked', $result['status']);
        $this->assertEquals('Tool not approved', $result['reason']);
        $this->assertEquals('pending_approval', $result['action']);
    }

    public function testInterceptToolWithoutDefinition(): void
    {
        $tool = new class() {
            // No getDefinition method
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tool does not have a getDefinition method.');
        $this->interceptor->interceptToolExecution($tool, 'test prompt', 'user123');
    }
}
