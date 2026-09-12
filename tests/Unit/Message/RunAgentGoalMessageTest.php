<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\RunAgentGoalMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RunAgentGoalMessageTest extends TestCase
{
    public function testConstructorWithoutCapabilityConstraints(): void
    {
        $message = new RunAgentGoalMessage(42, 'user-1', 'Daily report');

        self::assertInstanceOf(Uuid::class, $message->getMessageId());
        self::assertSame(42, $message->getGoalId());
        self::assertSame('user-1', $message->getUserIdentifier());
        self::assertSame('Daily report', $message->getGoalTitle());
        self::assertNull($message->getCapabilityConstraints());
        self::assertInstanceOf(\DateTimeImmutable::class, $message->getCreatedAt());
    }

    public function testConstructorWithCapabilityConstraints(): void
    {
        $message = new RunAgentGoalMessage(7, 'user-2', 'Goal', ['web_search', 'http_fetch']);

        self::assertSame(7, $message->getGoalId());
        self::assertSame('user-2', $message->getUserIdentifier());
        self::assertSame(['web_search', 'http_fetch'], $message->getCapabilityConstraints());
    }

    public function testToArray(): void
    {
        $message = new RunAgentGoalMessage(99, 'user-3', 'Strategy', ['a' => 'b']);

        $array = $message->toArray();

        self::assertSame($message->getMessageId()->toRfc4122(), $array['message_id']);
        self::assertSame(99, $array['goal_id']);
        self::assertSame('user-3', $array['user_identifier']);
        self::assertSame('Strategy', $array['goal_title']);
        self::assertSame(['a' => 'b'], $array['capability_constraints']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $array['created_at']);
    }

    public function testToArrayWithNullConstraints(): void
    {
        $message = new RunAgentGoalMessage(1, 'u', 'g');

        $array = $message->toArray();

        self::assertNull($array['capability_constraints']);
    }
}
