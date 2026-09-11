<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\TenantQuota;
use App\Entity\UserProfile;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TenantQuotaTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $quota = new TenantQuota();
        self::assertSame(100000, $quota->getMaxTokensPerDay());
        self::assertSame(1000, $quota->getMaxRequestsPerHour());
        self::assertSame(100, $quota->getMaxConcurrentRequests());
        self::assertFalse($quota->isCustom());
        self::assertSame(0, $quota->getCurrentDayUsage());
        self::assertSame(0, $quota->getCurrentHourUsage());
        self::assertInstanceOf(DateTimeImmutable::class, $quota->getCreatedAt());
        self::assertNull($quota->getId());
        self::assertNull($quota->getLastResetAt());
        self::assertNull($quota->getUpdatedAt());
        self::assertNull($quota->getUserProfile());
    }

    public function testGettersAndSetters(): void
    {
        $quota = new TenantQuota();
        $quota->setUserIdentifier('tenant1')
            ->setMaxTokensPerDay(50000)
            ->setMaxRequestsPerHour(500)
            ->setMaxConcurrentRequests(50)
            ->setIsCustom(true)
            ->setCurrentDayUsage(10000)
            ->setCurrentHourUsage(100);

        self::assertSame('tenant1', $quota->getUserIdentifier());
        self::assertSame(50000, $quota->getMaxTokensPerDay());
        self::assertSame(500, $quota->getMaxRequestsPerHour());
        self::assertSame(50, $quota->getMaxConcurrentRequests());
        self::assertTrue($quota->isCustom());
        self::assertSame(10000, $quota->getCurrentDayUsage());
        self::assertSame(100, $quota->getCurrentHourUsage());
    }

    public function testAddToCurrentDayUsage(): void
    {
        $quota = new TenantQuota();
        self::assertSame(0, $quota->getCurrentDayUsage());
        $quota->addToCurrentDayUsage(500);
        self::assertSame(500, $quota->getCurrentDayUsage());
        $quota->addToCurrentDayUsage(250);
        self::assertSame(750, $quota->getCurrentDayUsage());
    }

    public function testAddToCurrentHourUsage(): void
    {
        $quota = new TenantQuota();
        self::assertSame(0, $quota->getCurrentHourUsage());
        $quota->addToCurrentHourUsage(5);
        self::assertSame(5, $quota->getCurrentHourUsage());
        $quota->addToCurrentHourUsage(3);
        self::assertSame(8, $quota->getCurrentHourUsage());
    }

    public function testIsDailyTokensExceededTrue(): void
    {
        $quota = new TenantQuota();
        $quota->setMaxTokensPerDay(1000);
        $quota->setCurrentDayUsage(1000);
        self::assertTrue($quota->isDailyTokensExceeded());
    }

    public function testIsDailyTokensExceededFalse(): void
    {
        $quota = new TenantQuota();
        $quota->setMaxTokensPerDay(1000);
        $quota->setCurrentDayUsage(999);
        self::assertFalse($quota->isDailyTokensExceeded());
    }

    public function testIsHourlyRequestsExceededTrue(): void
    {
        $quota = new TenantQuota();
        $quota->setMaxRequestsPerHour(100);
        $quota->setCurrentHourUsage(100);
        self::assertTrue($quota->isHourlyRequestsExceeded());
    }

    public function testIsHourlyRequestsExceededFalse(): void
    {
        $quota = new TenantQuota();
        $quota->setMaxRequestsPerHour(100);
        $quota->setCurrentHourUsage(99);
        self::assertFalse($quota->isHourlyRequestsExceeded());
    }

    public function testGetRemainingDailyTokens(): void
    {
        $quota = new TenantQuota();
        $quota->setMaxTokensPerDay(1000)->setCurrentDayUsage(300);
        self::assertSame(700, $quota->getRemainingDailyTokens());
    }

    public function testGetRemainingDailyTokensDoesNotGoNegative(): void
    {
        $quota = new TenantQuota();
        $quota->setMaxTokensPerDay(1000)->setCurrentDayUsage(1500);
        self::assertSame(0, $quota->getRemainingDailyTokens());
    }

    public function testGetRemainingHourlyRequests(): void
    {
        $quota = new TenantQuota();
        $quota->setMaxRequestsPerHour(100)->setCurrentHourUsage(30);
        self::assertSame(70, $quota->getRemainingHourlyRequests());
    }

    public function testGetRemainingHourlyRequestsDoesNotGoNegative(): void
    {
        $quota = new TenantQuota();
        $quota->setMaxRequestsPerHour(100)->setCurrentHourUsage(150);
        self::assertSame(0, $quota->getRemainingHourlyRequests());
    }

    public function testResetDailyUsage(): void
    {
        $quota = new TenantQuota();
        $quota->setCurrentDayUsage(5000);
        $quota->resetDailyUsage();
        self::assertSame(0, $quota->getCurrentDayUsage());
        self::assertInstanceOf(DateTimeImmutable::class, $quota->getLastResetAt());
    }

    public function testResetHourlyUsage(): void
    {
        $quota = new TenantQuota();
        $quota->setCurrentHourUsage(50);
        $quota->resetHourlyUsage();
        self::assertSame(0, $quota->getCurrentHourUsage());
    }

    public function testGetDailyUsagePercentage(): void
    {
        $quota = new TenantQuota();
        $quota->setMaxTokensPerDay(1000)->setCurrentDayUsage(250);
        self::assertSame(25.0, $quota->getDailyUsagePercentage());
    }

    public function testGetDailyUsagePercentageCappedAt100(): void
    {
        $quota = new TenantQuota();
        $quota->setMaxTokensPerDay(1000)->setCurrentDayUsage(2000);
        self::assertSame(100.0, $quota->getDailyUsagePercentage());
    }

    public function testGetDailyUsagePercentageZeroWhenMaxIsZero(): void
    {
        $quota = new TenantQuota();
        $quota->setMaxTokensPerDay(0)->setCurrentDayUsage(500);
        self::assertSame(0.0, $quota->getDailyUsagePercentage());
    }

    public function testGetHourlyUsagePercentage(): void
    {
        $quota = new TenantQuota();
        $quota->setMaxRequestsPerHour(100)->setCurrentHourUsage(50);
        self::assertSame(50.0, $quota->getHourlyUsagePercentage());
    }

    public function testGetHourlyUsagePercentageCappedAt100(): void
    {
        $quota = new TenantQuota();
        $quota->setMaxRequestsPerHour(100)->setCurrentHourUsage(150);
        self::assertSame(100.0, $quota->getHourlyUsagePercentage());
    }

    public function testGetHourlyUsagePercentageZeroWhenMaxIsZero(): void
    {
        $quota = new TenantQuota();
        $quota->setMaxRequestsPerHour(0)->setCurrentHourUsage(50);
        self::assertSame(0.0, $quota->getHourlyUsagePercentage());
    }

    public function testSetUserProfile(): void
    {
        $quota = new TenantQuota();
        $profile = new UserProfile();
        $quota->setUserProfile($profile);
        self::assertSame($profile, $quota->getUserProfile());
    }

    public function testSetCreatedAt(): void
    {
        $quota = new TenantQuota();
        $date = new DateTimeImmutable('2025-01-01');
        $quota->setCreatedAt($date);
        self::assertSame($date, $quota->getCreatedAt());
    }

    public function testSetUpdatedAt(): void
    {
        $quota = new TenantQuota();
        $date = new DateTimeImmutable('2025-01-01');
        $quota->setUpdatedAt($date);
        self::assertSame($date, $quota->getUpdatedAt());
    }

    public function testSetLastResetAt(): void
    {
        $quota = new TenantQuota();
        $date = new DateTimeImmutable('2025-01-01');
        $quota->setLastResetAt($date);
        self::assertSame($date, $quota->getLastResetAt());
    }
}
