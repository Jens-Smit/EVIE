<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Platform;

use App\AI\Platform\PlatformResolver;
use App\Entity\UserProfile;
use App\Repository\UserProfileRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Unit-Tests für PlatformResolver (Tenant-spezifische LLM-Provider-Auswahl).
 */
final class PlatformResolverTest extends TestCase
{
    private UserProfileRepository&MockObject $userProfileRepo;
    private PlatformInterface $mistralPlatform;
    private PlatformInterface $geminiPlatform;
    private PlatformResolver $resolver;

    protected function setUp(): void
    {
        $this->userProfileRepo = $this->createMock(UserProfileRepository::class);
        $this->mistralPlatform = $this->createMock(PlatformInterface::class);
        $this->geminiPlatform = $this->createMock(PlatformInterface::class);
        $this->resolver = new PlatformResolver(
            $this->userProfileRepo,
            $this->mistralPlatform,
            $this->geminiPlatform
        );
    }

    public function testResolvePlatformDefaultsToMistralWhenNoProfile(): void
    {
        $this->userProfileRepo->method('findOneBy')->willReturn(null);

        self::assertSame($this->mistralPlatform, $this->resolver->resolvePlatform('unknown-user'));
    }

    public function testResolvePlatformReturnsMistralForMistralProvider(): void
    {
        $profile = new UserProfile();
        $profile->setPreferredLlmProvider('mistral');

        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        self::assertSame($this->mistralPlatform, $this->resolver->resolvePlatform('user1'));
    }

    public function testResolvePlatformReturnsGeminiForGeminiProvider(): void
    {
        $profile = new UserProfile();
        $profile->setPreferredLlmProvider('gemini');

        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        self::assertSame($this->geminiPlatform, $this->resolver->resolvePlatform('user1'));
    }

    public function testResolvePlatformDefaultsToMistralForUnknownProvider(): void
    {
        $profile = new UserProfile();
        $profile->setPreferredLlmProvider('unknown');

        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        self::assertSame($this->mistralPlatform, $this->resolver->resolvePlatform('user1'));
    }

    public function testResolvePlatformForProfileMistral(): void
    {
        $profile = new UserProfile();
        $profile->setPreferredLlmProvider('mistral');

        self::assertSame($this->mistralPlatform, $this->resolver->resolvePlatformForProfile($profile));
    }

    public function testResolvePlatformForProfileGemini(): void
    {
        $profile = new UserProfile();
        $profile->setPreferredLlmProvider('gemini');

        self::assertSame($this->geminiPlatform, $this->resolver->resolvePlatformForProfile($profile));
    }

    public function testResolvePlatformForProfileDefaultsToMistral(): void
    {
        $profile = new UserProfile();
        $profile->setPreferredLlmProvider(null);

        self::assertSame($this->mistralPlatform, $this->resolver->resolvePlatformForProfile($profile));
    }

    public function testGetAvailableProviders(): void
    {
        $providers = $this->resolver->getAvailableProviders();

        self::assertSame('Mistral AI', $providers['mistral']);
        self::assertSame('Google Gemini', $providers['gemini']);
    }

    public function testGetAvailableModels(): void
    {
        $models = $this->resolver->getAvailableModels();

        self::assertArrayHasKey('mistral', $models);
        self::assertArrayHasKey('gemini', $models);
        self::assertArrayHasKey('mistral-large-latest', $models['mistral']);
        self::assertArrayHasKey('gemini-1.5-pro-latest', $models['gemini']);
    }

    public function testGetDefaultModelForMistral(): void
    {
        self::assertSame('mistral-large-latest', $this->resolver->getDefaultModel('mistral'));
    }

    public function testGetDefaultModelForGemini(): void
    {
        self::assertSame('gemini-1.5-pro-latest', $this->resolver->getDefaultModel('gemini'));
    }

    public function testGetDefaultModelForUnknownProvider(): void
    {
        self::assertSame('mistral-small-latest', $this->resolver->getDefaultModel('unknown'));
    }
}
