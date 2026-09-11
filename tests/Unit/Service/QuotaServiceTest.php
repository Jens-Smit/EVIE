<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\TenantQuota;
use App\Repository\TenantQuotaRepository;
use App\Service\QuotaService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class QuotaServiceTest extends TestCase
{
    private TenantQuotaRepository&MockObject $quotaRepo;
    private QuotaService $service;

    protected function setUp(): void
    {
        $this->quotaRepo = $this->createMock(TenantQuotaRepository::class);
        $this->service = new QuotaService($this->quotaRepo);
    }

    public function testIsQuotaExceededDelegates(): void
    {
        $this->quotaRepo->method('isQuotaExceeded')->with('tenant-a')->willReturn(true);
        self::assertTrue($this->service->isQuotaExceeded('tenant-a'));
    }

    public function testGetQuotaUsageDelegates(): void
    {
        $this->quotaRepo->method('getQuotaUsage')->with('tenant-a')->willReturn(['used_daily_tokens' => 10]);
        self::assertSame(['used_daily_tokens' => 10], $this->service->getQuotaUsage('tenant-a'));
    }

    public function testRecordTokenUsageDelegates(): void
    {
        $this->quotaRepo->expects(self::once())->method('recordTokenUsage')->with('tenant-a', 500);
        $this->service->recordTokenUsage('tenant-a', 500);
        self::assertTrue(true);
    }

    public function testRecordRequestUsageDelegates(): void
    {
        $this->quotaRepo->expects(self::once())->method('recordRequestUsage')->with('tenant-a');
        $this->service->recordRequestUsage('tenant-a');
        self::assertTrue(true);
    }

    public function testUpdateQuotaSettingsReturnsNormalizedArray(): void
    {
        $quota = (new TenantQuota())
            ->setMaxTokensPerDay(1000)
            ->setMaxRequestsPerHour(100)
            ->setMaxConcurrentRequests(5);
        $quota->setIsCustom(true);

        $this->quotaRepo->method('updateQuotaSettings')
            ->with('tenant-a', 1000, 100, 5)
            ->willReturn($quota);

        $result = $this->service->updateQuotaSettings('tenant-a', 1000, 100, 5);

        self::assertSame('tenant-a', $result['user_identifier']);
        self::assertSame(1000, $result['max_tokens_per_day']);
        self::assertSame(100, $result['max_requests_per_hour']);
        self::assertSame(5, $result['max_concurrent_requests']);
        self::assertTrue($result['is_custom']);
    }

    public function testUpdateQuotaSettingsWithDefaults(): void
    {
        $quota = new TenantQuota();
        $quota->setMaxTokensPerDay(100000);
        $quota->setMaxRequestsPerHour(1000);
        $quota->setMaxConcurrentRequests(10);

        $this->quotaRepo->method('updateQuotaSettings')
            ->with('tenant-a', null, null, null)
            ->willReturn($quota);

        $result = $this->service->updateQuotaSettings('tenant-a');

        self::assertSame(100000, $result['max_tokens_per_day']);
        self::assertFalse($result['is_custom']);
    }

    public function testGetAllQuotasDelegates(): void
    {
        $this->quotaRepo->method('getAllQuotas')->willReturn(['a' => 1]);
        self::assertSame(['a' => 1], $this->service->getAllQuotas());
    }

    public function testCreateDefaultQuotaDelegates(): void
    {
        $quota = new TenantQuota();
        $this->quotaRepo->expects(self::once())->method('findOrCreate')->with('tenant-a')->willReturn($quota);
        $this->service->createDefaultQuota('tenant-a');
        self::assertTrue(true);
    }

    public function testResetAllDailyUsageDelegates(): void
    {
        $this->quotaRepo->method('resetAllDailyUsage')->willReturn(5);
        self::assertSame(5, $this->service->resetAllDailyUsage());
    }

    public function testResetAllHourlyUsageDelegates(): void
    {
        $this->quotaRepo->method('resetAllHourlyUsage')->willReturn(3);
        self::assertSame(3, $this->service->resetAllHourlyUsage());
    }

    public function testGetRemainingTokens(): void
    {
        $this->quotaRepo->method('getQuotaUsage')->willReturn(['remaining_daily_tokens' => 750]);
        self::assertSame(750, $this->service->getRemainingTokens('tenant-a'));
    }

    public function testGetRemainingRequests(): void
    {
        $this->quotaRepo->method('getQuotaUsage')->willReturn(['remaining_hourly_requests' => 42]);
        self::assertSame(42, $this->service->getRemainingRequests('tenant-a'));
    }
}
