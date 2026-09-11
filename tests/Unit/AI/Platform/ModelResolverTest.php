<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Platform;

use App\AI\Platform\ModelResolver;
use App\AI\Platform\PlatformResolver;
use App\Entity\UserProfile;
use App\Repository\UserProfileRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer ModelResolver (tenant-spezifische Modell-Auswahl).
 */
final class ModelResolverTest extends TestCase
{
    private UserProfileRepository&MockObject $userProfileRepo;
    private PlatformResolver&MockObject $platformResolver;
    private ModelResolver $resolver;

    protected function setUp(): void
    {
        $this->userProfileRepo = $this->createMock(UserProfileRepository::class);
        $this->platformResolver = $this->createMock(PlatformResolver::class);
        $this->resolver = new ModelResolver($this->userProfileRepo, $this->platformResolver);
    }

    public function testResolveModelReturnsDefaultWhenProfileNotFound(): void
    {
        $this->userProfileRepo->method('findOneBy')->willReturn(null);

        self::assertSame('mistral-small-latest', $this->resolver->resolveModel('unknown-user'));
    }

    public function testResolveModelReturnsPreferredModel(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('u');
        $profile->setPreferredLlmModel('mistral-large-latest');

        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        self::assertSame('mistral-large-latest', $this->resolver->resolveModel('u'));
    }

    public function testResolveModelFallsBackToProviderDefault(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('u');
        $profile->setPreferredLlmProvider('gemini');

        $this->userProfileRepo->method('findOneBy')->willReturn($profile);
        $this->platformResolver->method('getDefaultModel')->with('gemini')->willReturn('gemini-1.5-pro');

        self::assertSame('gemini-1.5-pro', $this->resolver->resolveModel('u'));
    }

    public function testResolveModelFallsBackToRoleDefaultWhenNoProvider(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('u');

        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        self::assertSame('mistral-small-latest', $this->resolver->resolveModel('u', 'sub_agent'));
    }

    public function testResolveModelForProfileReturnsPreferredModel(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('u');
        $profile->setPreferredLlmModel('open-mistral-7b');

        self::assertSame('open-mistral-7b', $this->resolver->resolveModelForProfile($profile));
    }

    public function testResolveModelForProfileFallsBackToProviderDefault(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('u');
        $profile->setPreferredLlmProvider('mistral');

        $this->platformResolver->method('getDefaultModel')->with('mistral')->willReturn('mistral-medium-latest');

        self::assertSame('mistral-medium-latest', $this->resolver->resolveModelForProfile($profile));
    }

    public function testResolveModelForProfileFallsBackToRoleDefault(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('u');

        self::assertSame('mistral-small-latest', $this->resolver->resolveModelForProfile($profile, 'tool_generator'));
    }

    public function testGetDefaultModelForRoleReturnsMappedDefault(): void
    {
        self::assertSame('mistral-small-latest', $this->resolver->getDefaultModelForRole('orchestrator'));
        self::assertSame('mistral-small-latest', $this->resolver->getDefaultModelForRole('tool_generator'));
        self::assertSame('mistral-small-latest', $this->resolver->getDefaultModelForRole('onboarding'));
        self::assertSame('mistral-small-latest', $this->resolver->getDefaultModelForRole('sub_agent'));
    }

    public function testGetDefaultModelForRoleFallsBackToOrchestratorForUnknownRole(): void
    {
        self::assertSame('mistral-small-latest', $this->resolver->getDefaultModelForRole('unknown_role'));
    }

    public function testSetPreferredModelThrowsWhenProfileNotFound(): void
    {
        $this->userProfileRepo->method('findOneBy')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->resolver->setPreferredModel('unknown-user', 'mistral-large-latest');
    }

    public function testSetPreferredModelUpdatesProfile(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('u');

        $this->userProfileRepo->method('findOneBy')->willReturn($profile);
        $this->userProfileRepo->expects(self::once())->method('save')->with($profile, true);

        $this->resolver->setPreferredModel('u', 'mistral-large-latest');

        self::assertSame('mistral-large-latest', $profile->getPreferredLlmModel());
        self::assertNotNull($profile->getUpdatedAt());
    }

    public function testSetPreferredProviderThrowsWhenProfileNotFound(): void
    {
        $this->userProfileRepo->method('findOneBy')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->resolver->setPreferredProvider('unknown-user', 'gemini');
    }

    public function testSetPreferredProviderUpdatesProfile(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('u');

        $this->userProfileRepo->method('findOneBy')->willReturn($profile);
        $this->userProfileRepo->expects(self::once())->method('save')->with($profile, true);

        $this->resolver->setPreferredProvider('u', 'gemini');

        self::assertSame('gemini', $profile->getPreferredLlmProvider());
        self::assertNotNull($profile->getUpdatedAt());
    }
}
