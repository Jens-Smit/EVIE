<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Workflow;

use App\AI\Skills\Tool\DynamicTool;
use App\AI\Workflow\PendingExecution;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Vollstaendige Test-Abdeckung fuer PendingExecution.
 */
final class PendingExecutionTest extends TestCase
{
    private function createTool(string $name): DynamicTool
    {
        $tool = $this->createMock(DynamicTool::class);
        $tool->method('getName')->willReturn($name);
        return $tool;
    }

    private function createUser(int $id, string $email): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($user, $id);
        $user->setEmail($email);
        return $user;
    }

    public function testGetters(): void
    {
        $tool = $this->createTool('search');
        $user = $this->createUser(5, 'user@test.de');
        $pending = new PendingExecution('exec-1', $tool, ['q' => 'test'], $user, 'original-request');

        self::assertSame('exec-1', $pending->getExecutionId());
        self::assertSame($tool, $pending->getTool());
        self::assertSame(['q' => 'test'], $pending->getParameters());
        self::assertSame($user, $pending->getUser());
        self::assertSame('original-request', $pending->getOriginalRequest());
        self::assertInstanceOf(\DateTimeImmutable::class, $pending->getCreatedAt());
    }

    public function testCreatedAtIsRecent(): void
    {
        $before = new \DateTimeImmutable('-1 second');
        $pending = new PendingExecution('e', $this->createTool('t'), [], new User(), 'r');
        $after = new \DateTimeImmutable('+1 second');
        self::assertGreaterThanOrEqual($before, $pending->getCreatedAt());
        self::assertLessThanOrEqual($after, $pending->getCreatedAt());
    }

    public function testToArray(): void
    {
        $tool = $this->createTool('my-tool');
        $user = $this->createUser(42, 'user@test.de');
        $pending = new PendingExecution('exec-2', $tool, ['a' => 1], $user, 'orig-req');

        $array = $pending->toArray();
        self::assertSame('exec-2', $array['execution_id']);
        self::assertSame('my-tool', $array['tool_name']);
        self::assertSame(42, $array['user_id']);
        self::assertSame('user@test.de', $array['user_email']);
        self::assertSame('orig-req', $array['original_request']);
        self::assertIsString($array['created_at']);
        self::assertSame(['a' => 1], $array['parameters']);
    }

    public function testToArrayWithUserWithoutId(): void
    {
        $user = new User();
        $user->setEmail('no-id@test.de');
        $pending = new PendingExecution('exec-3', $this->createTool('t'), [], $user, 'r');

        $array = $pending->toArray();
        self::assertNull($array['user_id']);
        self::assertSame('no-id@test.de', $array['user_email']);
    }

    public function testGetParametersWithEmptyArray(): void
    {
        $pending = new PendingExecution('e', $this->createTool('t'), [], new User(), 'r');
        self::assertSame([], $pending->getParameters());
    }
}
