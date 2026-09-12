<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AuditLog;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AuditLogTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $log = new AuditLog();
        $log->setAction('tool_executed')
            ->setEntityType('ToolDefinition')
            ->setEntityId(42)
            ->setUserId(7)
            ->setDetails('Tool was executed')
            ->setContext(['tool' => 'weather', 'latency' => 0.5])
            ->setIpAddress('127.0.0.1')
            ->setUserAgent('Mozilla/5.0')
            ->setStatus('success');

        self::assertSame('tool_executed', $log->getAction());
        self::assertSame('ToolDefinition', $log->getEntityType());
        self::assertSame(42, $log->getEntityId());
        self::assertSame(7, $log->getUserId());
        self::assertSame('Tool was executed', $log->getDetails());
        self::assertSame(['tool' => 'weather', 'latency' => 0.5], $log->getContext());
        self::assertSame('127.0.0.1', $log->getIpAddress());
        self::assertSame('Mozilla/5.0', $log->getUserAgent());
        self::assertSame('success', $log->getStatus());
        self::assertInstanceOf(DateTimeImmutable::class, $log->getCreatedAt());
    }

    public function testNullableFields(): void
    {
        $log = new AuditLog();
        self::assertNull($log->getId());
        self::assertNull($log->getEntityType());
        self::assertNull($log->getEntityId());
        self::assertNull($log->getUserId());
        self::assertNull($log->getDetails());
        self::assertNull($log->getContext());
        self::assertNull($log->getIpAddress());
        self::assertNull($log->getUserAgent());
        self::assertNull($log->getStatus());
    }

    public function testSetCreatedAt(): void
    {
        $log = new AuditLog();
        $date = new DateTimeImmutable('2025-01-15 10:30:00');
        $log->setCreatedAt($date);
        self::assertSame($date, $log->getCreatedAt());
    }

    public function testToArray(): void
    {
        $log = new AuditLog();
        $log->setAction('test_action')
            ->setEntityType('TestEntity')
            ->setEntityId(5)
            ->setUserId(1)
            ->setDetails('test details')
            ->setContext(['key' => 'value'])
            ->setIpAddress('192.168.1.1')
            ->setUserAgent('TestAgent/1.0')
            ->setStatus('failure');

        $array = $log->toArray();

        self::assertSame('test_action', $array['action']);
        self::assertSame('TestEntity', $array['entity_type']);
        self::assertSame(5, $array['entity_id']);
        self::assertSame(1, $array['user_id']);
        self::assertSame('test details', $array['details']);
        self::assertSame(['key' => 'value'], $array['context']);
        self::assertSame('192.168.1.1', $array['ip_address']);
        self::assertSame('TestAgent/1.0', $array['user_agent']);
        self::assertSame('failure', $array['status']);
        self::assertNull($array['id']);
        self::assertIsString($array['created_at']);
    }

    public function testToArrayWithNullContext(): void
    {
        $log = new AuditLog();
        $log->setAction('action');

        $array = $log->toArray();

        self::assertNull($array['context']);
        self::assertNull($array['entity_type']);
    }
}
