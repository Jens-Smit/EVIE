<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AgentHistory;
use App\Entity\SubAgent;
use App\Entity\UserProfile;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AgentHistoryTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $user = new UserProfile();
        $history = new AgentHistory();
        $history->setAction('tool_call')
            ->setDetails('{"result":"ok"}')
            ->setTokenUsage(100)
            ->setInputTokens(60)
            ->setOutputTokens(40)
            ->setLatencySeconds(1.5)
            ->setModel('gpt-4')
            ->setUser($user);

        self::assertSame('tool_call', $history->getAction());
        self::assertSame('{"result":"ok"}', $history->getDetails());
        self::assertSame(100, $history->getTokenUsage());
        self::assertSame(60, $history->getInputTokens());
        self::assertSame(40, $history->getOutputTokens());
        self::assertSame(1.5, $history->getLatencySeconds());
        self::assertSame('gpt-4', $history->getModel());
        self::assertSame($user, $history->getUser());
        self::assertInstanceOf(DateTimeImmutable::class, $history->getCreatedAt());
        self::assertNull($history->getId());
        self::assertNull($history->getSubAgent());
    }

    public function testAddTokenUsage(): void
    {
        $history = new AgentHistory();
        self::assertSame(0, $history->getTokenUsage());
        $history->addTokenUsage(50);
        self::assertSame(50, $history->getTokenUsage());
        $history->addTokenUsage(30);
        self::assertSame(80, $history->getTokenUsage());
    }

    public function testAddInputTokens(): void
    {
        $history = new AgentHistory();
        self::assertSame(0, $history->getInputTokens());
        $history->addInputTokens(25);
        self::assertSame(25, $history->getInputTokens());
        $history->addInputTokens(25);
        self::assertSame(50, $history->getInputTokens());
    }

    public function testAddOutputTokens(): void
    {
        $history = new AgentHistory();
        self::assertSame(0, $history->getOutputTokens());
        $history->addOutputTokens(15);
        self::assertSame(15, $history->getOutputTokens());
        $history->addOutputTokens(35);
        self::assertSame(50, $history->getOutputTokens());
    }

    public function testSetCreatedAt(): void
    {
        $history = new AgentHistory();
        $date = new DateTimeImmutable('2025-01-01');
        $history->setCreatedAt($date);
        self::assertSame($date, $history->getCreatedAt());
    }

    public function testSetSubAgent(): void
    {
        $history = new AgentHistory();
        $subAgent = new SubAgent();
        $history->setSubAgent($subAgent);
        self::assertSame($subAgent, $history->getSubAgent());

        $history->setSubAgent(null);
        self::assertNull($history->getSubAgent());
    }

    public function testAddAndRemoveDocument(): void
    {
        $history = new AgentHistory();
        $document = new \App\Entity\Document();

        $history->addDocument($document);
        self::assertContains($document, $history->getDocuments());
        self::assertSame($history, $document->getAgentHistory());

        $history->removeDocument($document);
        self::assertNotContains($document, $history->getDocuments());
        self::assertNull($document->getAgentHistory());
    }
}
