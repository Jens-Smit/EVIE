<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Onboarding;

use App\AI\Onboarding\ContextStoreManager;
use App\AI\Onboarding\IntegrationRequirementMapper;
use App\AI\Onboarding\OnboardingFlowManager;
use App\AI\Onboarding\OnboardingStepProvider;
use App\Entity\UserProfile;
use App\Repository\UserProfileRepository;
use App\Service\SecretService;
use App\Tests\Stub\StubAgent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer den phasenbasierten Onboarding-Flow.
 *
 * Der Flow ist deterministisch (Phase A: KI-Settings -> Phase B: Profil ->
 * Phase C: Schnittstellen -> Phase D: Abschluss). Die Tests verifizieren die
 * Schrittfolge, die dynamische Ableitung von Schnittstellen aus Use-Cases,
 * die Persistierung der LLM-Praeferenz und die verschluesselte Ablage von
 * API-Keys/Email-Verbindungen als Secrets.
 */
final class OnboardingFlowManagerTest extends TestCase
{
    private OnboardingFlowManager $manager;
    private StubAgent $onboardingAgent;
    private ContextStoreManager&MockObject $contextStore;
    private UserProfileRepository&MockObject $userProfileRepo;
    private SecretService&MockObject $secretService;
    private OnboardingStepProvider $stepProvider;
    private IntegrationRequirementMapper $requirementMapper;
    private array $context = [];

    protected function setUp(): void
    {
        $this->onboardingAgent = new StubAgent('{}');
        $this->contextStore = $this->createMock(ContextStoreManager::class);
        $this->userProfileRepo = $this->createMock(UserProfileRepository::class);
        $this->secretService = $this->createMock(SecretService::class);
        $this->stepProvider = new OnboardingStepProvider();
        $this->requirementMapper = new IntegrationRequirementMapper();

        // ContextStore laedt/speichert gegen ein lokales Array, damit der
        // schrittuebergreifende Zustand (current_step, use_cases) erhalten bleibt.
        $this->context = [];
        $this->contextStore->method('loadContext')->willReturnCallback(fn () => $this->context);
        $this->contextStore->method('saveContext')->willReturnCallback(function (string $id, array $ctx): void {
            $this->context = $ctx;
        });

        $this->manager = new OnboardingFlowManager(
            $this->contextStore,
            $this->userProfileRepo,
            $this->onboardingAgent,
            $this->stepProvider,
            $this->requirementMapper,
            $this->secretService
        );
    }

    public function testStartOnboardingReturnsFirstPhaseStepWithTotalSteps(): void
    {
        $result = $this->manager->startOnboarding('user-123');

        self::assertSame('in_progress', $result['status']);
        self::assertSame('llm_provider', $result['step_id']);
        self::assertSame('KI-Settings', $result['phase']);
        // Basis-Schritte (6) + Abschluss (1) = 7 ohne Schnittstellen.
        self::assertSame(7, $result['total_steps']);
        self::assertArrayHasKey('mistral', $result['options']);
        self::assertArrayHasKey('gemini', $result['options']);
    }

    public function testProcessResponseAdvancesStepAndPersistsLlmProvider(): void
    {
        $this->manager->startOnboarding('user-123');

        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);
        $this->userProfileRepo->expects(self::atLeastOnce())->method('save');

        $result = $this->manager->processResponse('user-123', 'mistral');

        self::assertSame('in_progress', $result['status']);
        self::assertSame('llm_model', $result['step_id']);
        // Provider wurde im Profil persistiert.
        self::assertSame('mistral', $profile->getPreferredLlmProvider());
        // Default-Modell fuer Mistral gesetzt.
        self::assertSame('mistral-small-latest', $profile->getPreferredLlmModel());
    }

    public function testModelOptionsDependOnSelectedProvider(): void
    {
        $this->manager->startOnboarding('user-123');

        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        $this->manager->processResponse('user-123', 'gemini');

        $result = $this->manager->getNextStep('user-123');
        self::assertSame('llm_model', $result['step_id']);
        // Gemini-Modelle werden geliefert.
        self::assertArrayHasKey('gemini-1.5-flash-latest', $result['options']);
        self::assertArrayNotHasKey('mistral-small-latest', $result['options']);
    }

    public function testLlmApiKeyIsStoredAsProviderSpecificSecret(): void
    {
        // Bis zum llm_api_key-Schritt vorspulen (provider + model).
        $this->manager->startOnboarding('user-123');
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        $this->manager->processResponse('user-123', 'gemini');
        $this->manager->processResponse('user-123', 'gemini-1.5-pro-latest');

        // API-Key-Schritt: Secret wird als GEMINI_API_KEY gespeichert.
        $this->secretService->expects(self::once())
            ->method('set')
            ->with('GEMINI_API_KEY', 'secret-gemini-key', 'user-123', 'onboarding');

        $this->manager->processResponse('user-123', 'secret-gemini-key');
    }

    public function testUseCasesAppendIntegrationSteps(): void
    {
        // Basis-Schritte durchlaufen bis use_cases.
        $this->manager->startOnboarding('user-123');
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        $this->manager->processResponse('user-123', 'mistral');
        $this->manager->processResponse('user-123', 'mistral-small-latest');
        $this->manager->processResponse('user-123', 'test-key');
        $this->manager->processResponse('user-123', 'Developer');

        // use_cases mit research -> Tavily-Step wird angehaengt.
        $result = $this->manager->processResponse('user-123', ['research']);

        // 6 Basis + 1 Tavily-Integration + 1 Abschluss = 8.
        self::assertSame(8, $result['total_steps']);
        // Naechster Schritt nach use_cases ist industry (Basis), erst danach
        // kommen die Integrationen.
        self::assertSame('industry', $result['step_id']);
    }

    public function testEmailConnectionIsStoredAsMailerDsnSecret(): void
    {
        // Vollstaendigen Basis-Flow mit business_automation durchlaufen, damit
        // SMTP/IMAP-Schritte aktiv werden.
        $this->manager->startOnboarding('user-123');
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        $this->manager->processResponse('user-123', 'mistral');
        $this->manager->processResponse('user-123', 'mistral-small-latest');
        $this->manager->processResponse('user-123', 'test-key');
        $this->manager->processResponse('user-123', 'Business User');
        $this->manager->processResponse('user-123', ['business_automation']);
        $this->manager->processResponse('user-123', 'technology');

        // SMTP-Schritt: Secret wird als MAILER_DSN gespeichert.
        $this->secretService->expects(self::once())
            ->method('set')
            ->with('MAILER_DSN', self::stringStartsWith('smtp://user:pass@smtp.example.com:587'), 'user-123', 'onboarding');

        $this->manager->processResponse('user-123', [
            'host' => 'smtp.example.com',
            'port' => '587',
            'user' => 'user',
            'pass' => 'pass',
            'encryption' => 'tls',
        ]);
    }

    public function testCompleteOnboardingSetsCompletedFlag(): void
    {
        $this->manager->startOnboarding('user-123');
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);
        $this->userProfileRepo->expects(self::atLeastOnce())->method('save');

        // Nur die zwingenden Basis-Schritte (keine Use-Cases -> keine Integrationen).
        $this->manager->processResponse('user-123', 'mistral');
        $this->manager->processResponse('user-123', 'mistral-small-latest');
        $this->manager->processResponse('user-123', 'key');
        $this->manager->processResponse('user-123', 'Developer');
        $this->manager->processResponse('user-123', []);
        $result = $this->manager->processResponse('user-123', 'technology');

        // Abschluss-Schritt (summary) -> bestaetigen -> completed.
        self::assertSame('summary', $result['step_id']);
        $completion = $this->manager->processResponse('user-123', 'confirm');

        self::assertSame('completed', $completion['status']);
        $onb = $profile->getOnboardingData();
        self::assertTrue($onb['completed']);
    }

    public function testGetNextStepAfterCompletionReturnsCompleted(): void
    {
        $this->manager->startOnboarding('user-123');
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        $this->manager->processResponse('user-123', 'mistral');
        $this->manager->processResponse('user-123', 'mistral-small-latest');
        $this->manager->processResponse('user-123', 'key');
        $this->manager->processResponse('user-123', 'Developer');
        $this->manager->processResponse('user-123', []);
        $this->manager->processResponse('user-123', 'technology');
        $this->manager->processResponse('user-123', 'confirm');

        $status = $this->manager->getOnboardingStatus('user-123');
        self::assertSame('completed', $status['status']);
    }
}
