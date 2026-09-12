<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Document;
use App\Entity\AgentHistory;
use App\Entity\UserProfile;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer Document-Entity.
 */
final class DocumentTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $document = new Document();
        $date = new DateTimeImmutable('2025-01-01');
        $user = $this->createMock(UserProfile::class);
        $history = $this->createMock(AgentHistory::class);

        $document
            ->setName('report.pdf')
            ->setContent('file content')
            ->setCreatedAt($date)
            ->setUser($user)
            ->setAgentHistory($history)
            ->setFilePath('/tmp/report.pdf');

        self::assertSame('report.pdf', $document->getName());
        self::assertSame('file content', $document->getContent());
        self::assertSame($date, $document->getCreatedAt());
        self::assertSame($user, $document->getUser());
        self::assertSame($history, $document->getAgentHistory());
        self::assertSame('/tmp/report.pdf', $document->getFilePath());
    }

    public function testNullableFields(): void
    {
        $document = new Document();
        self::assertNull($document->getContent());
        self::assertNull($document->getAgentHistory());
        self::assertNull($document->getFilePath());
        self::assertInstanceOf(DateTimeImmutable::class, $document->getCreatedAt());
    }
}
