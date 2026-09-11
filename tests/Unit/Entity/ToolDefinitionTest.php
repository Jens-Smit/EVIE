<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ToolCategory;
use App\Entity\ToolDefinition;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ToolDefinitionTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $tool = new ToolDefinition();

        self::assertNull($tool->getId());
        self::assertNull($tool->getName());
        self::assertNull($tool->getDescription());
        self::assertSame([], $tool->getSchema());
        self::assertNull($tool->getCategory());
        self::assertSame(1, $tool->getComplexity());
        self::assertNull($tool->getDependencies());
        self::assertSame('low', $tool->getSecurityLevel());
        self::assertFalse($tool->getRequiresHitl());
        self::assertNull($tool->getMetadata());
        self::assertSame('pending', $tool->getStatus());
        self::assertInstanceOf(DateTimeImmutable::class, $tool->getCreatedAt());
        self::assertNull($tool->getUpdatedAt());
        self::assertNull($tool->getExecutorType());
        self::assertNull($tool->getExecutorConfig());
        self::assertNull($tool->getSecurityPolicy());
        self::assertNull($tool->getHitlPolicy());
        self::assertSame('1.0', $tool->getVersion());
        self::assertNull($tool->getUserIdentifier());
        self::assertNull($tool->getApprovedAt());
        self::assertNull($tool->getRejectedAt());
        self::assertNull($tool->getRejectionReason());
    }

    public function testGettersAndSetters(): void
    {
        $tool = new ToolDefinition();
        $category = new ToolCategory();
        $category->setName('search');
        $createdAt = new DateTimeImmutable('2024-01-01 00:00:00');
        $updatedAt = new DateTimeImmutable('2024-02-01 00:00:00');
        $approvedAt = new DateTimeImmutable('2024-03-01 00:00:00');
        $rejectedAt = new DateTimeImmutable('2024-04-01 00:00:00');

        $tool
            ->setName('web_search')
            ->setDescription('Search the web')
            ->setSchema(['type' => 'object'])
            ->setCategory($category)
            ->setComplexity(3)
            ->setDependencies(['http'])
            ->setSecurityLevel('high')
            ->setRequiresHitl(true)
            ->setMetadata(['author' => 'evie'])
            ->setStatus('approved')
            ->setCreatedAt($createdAt)
            ->setUpdatedAt($updatedAt)
            ->setExecutorType('rest_api')
            ->setExecutorConfig(['url' => 'https://api.example.com'])
            ->setSecurityPolicy(['allow' => ['GET']])
            ->setHitlPolicy(['timeout' => 30])
            ->setVersion('2.0')
            ->setUserIdentifier('tenant-a')
            ->setApprovedAt($approvedAt)
            ->setRejectedAt($rejectedAt)
            ->setRejectionReason('unsafe');

        self::assertSame('web_search', $tool->getName());
        self::assertSame('Search the web', $tool->getDescription());
        self::assertSame(['type' => 'object'], $tool->getSchema());
        self::assertSame($category, $tool->getCategory());
        self::assertSame(3, $tool->getComplexity());
        self::assertSame(['http'], $tool->getDependencies());
        self::assertSame('high', $tool->getSecurityLevel());
        self::assertTrue($tool->getRequiresHitl());
        self::assertSame(['author' => 'evie'], $tool->getMetadata());
        self::assertSame('approved', $tool->getStatus());
        self::assertSame($createdAt, $tool->getCreatedAt());
        self::assertSame($updatedAt, $tool->getUpdatedAt());
        self::assertSame('rest_api', $tool->getExecutorType());
        self::assertSame(['url' => 'https://api.example.com'], $tool->getExecutorConfig());
        self::assertSame(['allow' => ['GET']], $tool->getSecurityPolicy());
        self::assertSame(['timeout' => 30], $tool->getHitlPolicy());
        self::assertSame('2.0', $tool->getVersion());
        self::assertSame('tenant-a', $tool->getUserIdentifier());
        self::assertSame($approvedAt, $tool->getApprovedAt());
        self::assertSame($rejectedAt, $tool->getRejectedAt());
        self::assertSame('unsafe', $tool->getRejectionReason());
    }

    public function testSetNullableFieldsToNull(): void
    {
        $tool = new ToolDefinition();
        $tool
            ->setCategory(new ToolCategory())
            ->setDependencies(['x'])
            ->setSecurityLevel('medium')
            ->setMetadata(['y'])
            ->setUpdatedAt(new DateTimeImmutable())
            ->setExecutorType('rest_api')
            ->setExecutorConfig(['z'])
            ->setSecurityPolicy(['p'])
            ->setHitlPolicy(['h'])
            ->setVersion('1.0')
            ->setUserIdentifier('t')
            ->setApprovedAt(new DateTimeImmutable())
            ->setRejectedAt(new DateTimeImmutable())
            ->setRejectionReason('r');

        $tool
            ->setCategory(null)
            ->setDependencies(null)
            ->setSecurityLevel(null)
            ->setMetadata(null)
            ->setUpdatedAt(null)
            ->setExecutorType(null)
            ->setExecutorConfig(null)
            ->setSecurityPolicy(null)
            ->setHitlPolicy(null)
            ->setVersion(null)
            ->setUserIdentifier(null)
            ->setApprovedAt(null)
            ->setRejectedAt(null)
            ->setRejectionReason(null);

        self::assertNull($tool->getCategory());
        self::assertNull($tool->getDependencies());
        self::assertNull($tool->getSecurityLevel());
        self::assertNull($tool->getMetadata());
        self::assertNull($tool->getUpdatedAt());
        self::assertNull($tool->getExecutorType());
        self::assertNull($tool->getExecutorConfig());
        self::assertNull($tool->getSecurityPolicy());
        self::assertNull($tool->getHitlPolicy());
        self::assertNull($tool->getVersion());
        self::assertNull($tool->getUserIdentifier());
        self::assertNull($tool->getApprovedAt());
        self::assertNull($tool->getRejectedAt());
        self::assertNull($tool->getRejectionReason());
    }

    public function testToArrayWithCategory(): void
    {
        $tool = new ToolDefinition();
        $category = new ToolCategory();
        $category->setName('search');

        $tool
            ->setName('web_search')
            ->setDescription('Search the web')
            ->setSchema(['type' => 'object'])
            ->setCategory($category)
            ->setComplexity(3)
            ->setDependencies(['http'])
            ->setSecurityLevel('high')
            ->setRequiresHitl(true)
            ->setMetadata(['author' => 'evie'])
            ->setStatus('approved')
            ->setExecutorType('rest_api')
            ->setExecutorConfig(['url' => 'x'])
            ->setSecurityPolicy(['allow' => ['GET']])
            ->setHitlPolicy(['timeout' => 30])
            ->setVersion('2.0')
            ->setUserIdentifier('tenant-a');

        $array = $tool->toArray();

        self::assertSame('web_search', $array['name']);
        self::assertSame('Search the web', $array['description']);
        self::assertSame(['type' => 'object'], $array['schema']);
        self::assertSame('search', $array['category']);
        self::assertSame(3, $array['complexity']);
        self::assertSame(['http'], $array['dependencies']);
        self::assertSame('high', $array['securityLevel']);
        self::assertTrue($array['requiresHitl']);
        self::assertSame(['author' => 'evie'], $array['metadata']);
        self::assertSame('approved', $array['status']);
        self::assertSame('rest_api', $array['executorType']);
        self::assertSame(['url' => 'x'], $array['executorConfig']);
        self::assertSame(['allow' => ['GET']], $array['securityPolicy']);
        self::assertSame(['timeout' => 30], $array['hitlPolicy']);
        self::assertSame('2.0', $array['version']);
        self::assertSame('tenant-a', $array['userIdentifier']);
        self::assertNull($array['id']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $array['createdAt']);
        self::assertNull($array['updatedAt']);
    }

    public function testToArrayWithoutCategory(): void
    {
        $tool = new ToolDefinition();
        $tool->setName('noop');

        $array = $tool->toArray();

        self::assertNull($array['category']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $array['createdAt']);
    }
}
