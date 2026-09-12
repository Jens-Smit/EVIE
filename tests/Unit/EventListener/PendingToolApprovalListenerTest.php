<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\ToolDefinition;
use App\Event\PendingToolApprovalEvent;
use App\EventListener\PendingToolApprovalListener;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Unit-Tests fuer PendingToolApprovalListener.
 */
final class PendingToolApprovalListenerTest extends TestCase
{
    private NotifierInterface&MockObject $notifier;
    private UrlGeneratorInterface&MockObject $urlGenerator;
    private PendingToolApprovalListener $listener;

    protected function setUp(): void
    {
        $this->notifier = $this->createMock(NotifierInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->listener = new PendingToolApprovalListener(
            $this->notifier,
            $this->urlGenerator,
            new NullLogger(),
        );
    }

    public function testOnPendingWithoutUserIdentifierSkipsNotification(): void
    {
        $tool = $this->createToolDefinition('ToolA', 'desc', 'pending', 1);
        $event = new PendingToolApprovalEvent($tool, null);

        $this->urlGenerator->expects(self::never())->method('generate');
        $this->notifier->expects(self::never())->method('send');

        $this->listener->onPendingToolApproval($event);
    }

    public function testOnPendingSendsNotification(): void
    {
        $tool = $this->createToolDefinition('ToolA', 'A description', 'pending', 42);
        $event = new PendingToolApprovalEvent($tool, 'user@example.com');

        $this->urlGenerator->method('generate')->willReturn('http://localhost/approve');
        $this->notifier->expects(self::once())->method('send');

        $this->listener->onPendingToolApproval($event);
    }

    public function testOnPendingNotificationExceptionIsCaught(): void
    {
        $tool = $this->createToolDefinition('ToolA', 'desc', 'pending', 1);
        $event = new PendingToolApprovalEvent($tool, 'user@example.com');

        $this->urlGenerator->method('generate')->willReturn('http://localhost/x');
        $this->notifier->method('send')
            ->willThrowException(new \RuntimeException('boom'));

        $this->listener->onPendingToolApproval($event);
        $this->addToAssertionCount(1);
    }

    public function testOnToolApprovedLogsForApprovedTool(): void
    {
        $tool = $this->createToolDefinition('ToolA', 'desc', 'approved', 5);
        $event = new PendingToolApprovalEvent($tool, 'u', true);

        $this->notifier->expects(self::never())->method('send');

        $this->listener->onToolApproved($event);
        $this->addToAssertionCount(1);
    }

    public function testOnToolApprovedIgnoresNonApprovedTool(): void
    {
        $tool = $this->createToolDefinition('ToolA', 'desc', 'pending', 5);
        $event = new PendingToolApprovalEvent($tool, 'u');

        $this->listener->onToolApproved($event);
        $this->addToAssertionCount(1);
    }

    private function createToolDefinition(string $name, string $description, string $status, int $id): ToolDefinition
    {
        $tool = new ToolDefinition();
        $reflection = new \ReflectionClass(ToolDefinition::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($tool, $id);
        $nameProp = $reflection->getProperty('name');
        $nameProp->setValue($tool, $name);
        $descProp = $reflection->getProperty('description');
        $descProp->setValue($tool, $description);
        $statusProp = $reflection->getProperty('status');
        $statusProp->setValue($tool, $status);

        return $tool;
    }
}
