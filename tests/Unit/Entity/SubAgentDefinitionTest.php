<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\SubAgentDefinition;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SubAgentDefinitionTest extends TestCase
{
    public function testConstructorSetsDefaults(): void
    {
        $def = new SubAgentDefinition();

        self::assertInstanceOf(Uuid::class, $def->getId());
        self::assertInstanceOf(DateTimeImmutable::class, $def->getCreatedAt());
        self::assertTrue($def->isActive());
        self::assertNull($def->getUpdatedAt());
        self::assertNull($def->getCreatedBy());
    }

    public function testGettersAndSetters(): void
    {
        $def = new SubAgentDefinition();
        $user = new User();
        $createdAt = new DateTimeImmutable('2024-01-01 00:00:00');
        $updatedAt = new DateTimeImmutable('2024-02-01 00:00:00');

        $def
            ->setName('Researcher')
            ->setDescription('Analyses data')
            ->setClassName('App\AI\Agent\ResearcherAgent')
            ->setConfiguration(['model' => 'mistral'])
            ->setIsActive(false)
            ->setCreatedAt($createdAt)
            ->setUpdatedAt($updatedAt)
            ->setCreatedBy($user);

        self::assertSame('Researcher', $def->getName());
        self::assertSame('Analyses data', $def->getDescription());
        self::assertSame('App\AI\Agent\ResearcherAgent', $def->getClassName());
        self::assertSame(['model' => 'mistral'], $def->getConfiguration());
        self::assertFalse($def->isActive());
        self::assertSame($createdAt, $def->getCreatedAt());
        self::assertSame($updatedAt, $def->getUpdatedAt());
        self::assertSame($user, $def->getCreatedBy());
    }

    public function testSetNullableFieldsToNull(): void
    {
        $def = new SubAgentDefinition();
        $def->setUpdatedAt(new DateTimeImmutable());
        $def->setCreatedBy(new User());

        $def->setUpdatedAt(null);
        $def->setCreatedBy(null);

        self::assertNull($def->getUpdatedAt());
        self::assertNull($def->getCreatedBy());
    }
}
