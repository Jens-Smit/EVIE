<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\RunAgentGoalsCommand;
use App\Entity\AgentGoal;
use App\Message\RunAgentGoalMessage;
use App\Repository\AgentGoalRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Unit-Tests fuer RunAgentGoalsCommand.
 */
final class RunAgentGoalsCommandTest extends TestCase
{
    private AgentGoalRepository&MockObject $repo;
    private MessageBusInterface&MockObject $bus;
    private RunAgentGoalsCommand $command;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(AgentGoalRepository::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->command = new RunAgentGoalsCommand($this->repo, $this->bus);
    }

    public function testNoGoalsFoundReturnsSuccess(): void
    {
        $this->repo->method('findDueGoals')->willReturn([]);
        $tester = new CommandTester($this->command);

        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Keine fälligen Ziele', $tester->getDisplay());
    }

    public function testDryRunListsGoalsWithoutDispatch(): void
    {
        $goal = $this->createMock(AgentGoal::class);
        $goal->method('getTitle')->willReturn('Daily Briefing');
        $goal->method('getId')->willReturn(7);
        $goal->method('getUserIdentifier')->willReturn('user@example.com');
        $goal->method('getNextRunAt')->willReturn(new \DateTimeImmutable('2025-01-01 10:00:00'));
        $goal->method('getExecutionCount')->willReturn(3);

        $this->repo->method('findDueGoals')->willReturn([$goal]);
        $this->bus->expects(self::never())->method('dispatch');

        $tester = new CommandTester($this->command);
        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Daily Briefing', $tester->getDisplay());
        self::assertStringContainsString('Dry-Run', $tester->getDisplay());
    }

    public function testDispatchesMessageForGoals(): void
    {
        $goal = $this->createMock(AgentGoal::class);
        $goal->method('getTitle')->willReturn('Daily Briefing');
        $goal->method('getId')->willReturn(7);
        $goal->method('getUserIdentifier')->willReturn('user@example.com');
        $goal->method('getCapabilityConstraints')->willReturn(['capability' => 'x']);

        $this->repo->method('findDueGoals')->willReturn([$goal]);
        $this->bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(fn ($msg) => new Envelope($msg));
        $this->repo->expects(self::once())->method('updateNextRunAt')->with($goal);

        $tester = new CommandTester($this->command);
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('eingereiht', $tester->getDisplay());
    }

    public function testForceFindsActiveByUserWhenUserGiven(): void
    {
        $this->repo->expects(self::once())->method('findActiveByUser')->willReturn([]);
        $this->repo->expects(self::never())->method('findDueGoalsByUser');

        $tester = new CommandTester($this->command);
        $tester->execute(['--user' => 'user@example.com', '--force' => true]);
        $this->addToAssertionCount(1);
    }

    public function testUserWithoutForceFindsDueByUser(): void
    {
        $this->repo->expects(self::once())->method('findDueGoalsByUser')->willReturn([]);
        $this->repo->expects(self::never())->method('findActiveByUser');

        $tester = new CommandTester($this->command);
        $tester->execute(['--user' => 'user@example.com']);
        $this->addToAssertionCount(1);
    }

    public function testForceWithoutUserFindsActive(): void
    {
        $this->repo->expects(self::once())
            ->method('findBy')
            ->with(['status' => 'active', 'isApproved' => true])
            ->willReturn([]);
        $this->repo->expects(self::never())->method('findDueGoals');

        $tester = new CommandTester($this->command);
        $tester->execute(['--force' => true]);
        $this->addToAssertionCount(1);
    }
}
