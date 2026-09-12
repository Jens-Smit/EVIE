<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AgentHistory;
use App\Entity\SubAgent;
use App\Entity\UserProfile;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SubAgentTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $subAgent = new SubAgent();
        self::assertInstanceOf(DateTimeImmutable::class, $subAgent->getCreatedAt());
        self::assertNull($subAgent->getId());
        self::assertSame('active', $subAgent->getStatus());
        self::assertSame([], $subAgent->getCapabilities());
        self::assertCount(0, $subAgent->getHistory());
    }

    public function testGettersAndSetters(): void
    {
        $user = new UserProfile();
        $subAgent = new SubAgent();
        $subAgent->setName('Research Agent')
            ->setDescription('Researches topics')
            ->setCapabilities(['web_search', 'summarize'])
            ->setStatus('inactive')
            ->setUser($user);

        self::assertSame('Research Agent', $subAgent->getName());
        self::assertSame('Researches topics', $subAgent->getDescription());
        self::assertSame(['web_search', 'summarize'], $subAgent->getCapabilities());
        self::assertSame('inactive', $subAgent->getStatus());
        self::assertSame($user, $subAgent->getUser());
    }

    public function testSetCreatedAt(): void
    {
        $subAgent = new SubAgent();
        $date = new DateTimeImmutable('2025-01-01');
        $subAgent->setCreatedAt($date);
        self::assertSame($date, $subAgent->getCreatedAt());
    }

    public function testAddHistory(): void
    {
        $subAgent = new SubAgent();
        $history = new AgentHistory();
        $history->setAction('test')->setUser(new UserProfile());

        $subAgent->addHistory($history);
        self::assertTrue($subAgent->getHistory()->contains($history));
        self::assertSame($subAgent, $history->getSubAgent());
    }

    public function testAddHistoryDoesNotDuplicate(): void
    {
        $subAgent = new SubAgent();
        $history = new AgentHistory();
        $history->setAction('test')->setUser(new UserProfile());

        $subAgent->addHistory($history);
        $subAgent->addHistory($history);

        self::assertCount(1, $subAgent->getHistory());
    }

    public function testRemoveHistory(): void
    {
        $subAgent = new SubAgent();
        $history = new AgentHistory();
        $history->setAction('test')->setUser(new UserProfile());

        $subAgent->addHistory($history);
        $subAgent->removeHistory($history);

        self::assertFalse($subAgent->getHistory()->contains($history));
        self::assertNull($history->getSubAgent());
    }

    public function testRemoveHistoryNotPresent(): void
    {
        $subAgent = new SubAgent();
        $history = new AgentHistory();
        $history->setAction('test')->setUser(new UserProfile());

        $subAgent->removeHistory($history);

        self::assertCount(0, $subAgent->getHistory());
    }
}
