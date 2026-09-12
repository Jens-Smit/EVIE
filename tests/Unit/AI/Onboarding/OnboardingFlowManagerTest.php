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
 * Unit-Tests fuer den dynamischen, verzweigenden Onboarding-Flow.
 *
 * Der Flow beginnt zwingend mit den KI-Settings (Provider -> Modell -> API-Key)
 * und oeffnet danach einen verzweigenden Bedarfsdialog (Ziel -> Branche ->
 * Bereiche (multiselect) -> E-Mail-Konten / Use-Cases -> Abschluss).
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
        $this->context = [];

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

    private function setUpStatefulContext(): void
    {
        $this->contextStore = $this->createMock(ContextStoreManager::class);
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

    public function testStartOnboardingReturnsFirstPhaseStep(): void
    {
        $result = $this->manager->startOnboarding('user-123');
        self::assertSame('in_progress', $result['status']);
        self::assertSame('llm_provider', $result['step_id']);
        self::assertSame('KI-Settings', $result['phase']);
        // Basis-Schritte (4) + Abschluss (1) = 5 ohne Verzweigung/Schnittstellen.
        self::assertSame(5, $result['total_steps']);
        self::assertArrayHasKey('mistral', $result['options']);
        self::assertArrayHasKey('gemini', $result['options']);
    }

    public function testProcessResponseAdvancesStepAndPersistsLlmProvider(): void
    {
        $this->setUpStatefulContext();
        $this->manager->startOnboarding('user-123');
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);
        $this->userProfileRepo->expects(self::atLeastOnce())->method('save');

        $result = $this->manager->processResponse('user-123', 'mistral');
        self::assertSame('in_progress', $result['status']);
        self::assertSame('llm_model', $result['step_id']);
        self::assertSame('mistral', $profile->getPreferredLlmProvider());
        self::assertSame('mistral-small-latest', $profile->getPreferredLlmModel());
    }

    public function testModelOptionsDependOnSelectedProvider(): void
    {
        $this->setUpStatefulContext();
        $this->manager->startOnboarding('user-123');
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        $this->manager->processResponse('user-123', 'gemini');
        $result = $this->manager->getNextStep('user-123');
        self::assertSame('llm_model', $result['step_id']);
        self::assertArrayHasKey('gemini-1.5-flash-latest', $result['options']);
        self::assertArrayNotHasKey('mistral-small-latest', $result['options']);
    }

    public function testLlmApiKeyIsStoredAsProviderSpecificSecret(): void
    {
        $this->setUpStatefulContext();
        $this->manager->startOnboarding('user-123');
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        $this->manager->processResponse('user-123', 'gemini');
        $this->manager->processResponse('user-123', 'gemini-1.5-pro-latest');

        $this->secretService->expects(self::once())
            ->method('set')
            ->with('GEMINI_API_KEY', 'secret-gemini-key', 'user-123', 'onboarding');
        $this->manager->processResponse('user-123', 'secret-gemini-key');
    }

    public function testGoalQuestionFollowsApiKeyStep(): void
    {
        $this->setUpStatefulContext();
        $this->manager->startOnboarding('user-123');
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        $this->manager->processResponse('user-123', 'mistral');
        $this->manager->processResponse('user-123', 'mistral-small-latest');
        $this->manager->processResponse('user-123', 'test-key');

        // Nach dem API-Key-Schritt folgt die goal-Frage (Ziel-Verzweigung).
        $result = $this->manager->getNextStep('user-123');
        self::assertSame('goal', $result['step_id']);
        self::assertArrayHasKey('manage_company', $result['options']);
    }

    public function testManageCompanyBranchAsksIndustryThenAreas(): void
    {
        $this->setUpStatefulContext();
        $this->manager->startOnboarding('user-123');
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        $this->manager->processResponse('user-123', 'mistral');
        $this->manager->processResponse('user-123', 'mistral-small-latest');
        $this->manager->processResponse('user-123', 'key');
        $this->manager->processResponse('user-123', 'manage_company');

        $result = $this->manager->getNextStep('user-123');
        self::assertSame('industry', $result['step_id']);

        $this->manager->processResponse('user-123', 'software_it');
        $result = $this->manager->getNextStep('user-123');
        self::assertSame('business_areas', $result['step_id']);
        self::assertSame('multiselect', $result['type']);
        self::assertTrue($result['allow_freetext']);
    }

    public function testEmailAccountStepPerAreaAfterAreasMultiselect(): void
    {
        $this->setUpStatefulContext();
        $this->manager->startOnboarding('user-123');
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        $this->manager->processResponse('user-123', 'mistral');
        $this->manager->processResponse('user-123', 'mistral-small-latest');
        $this->manager->processResponse('user-123', 'key');
        $this->manager->processResponse('user-123', 'manage_company');
        $this->manager->processResponse('user-123', 'software_it');
        $this->manager->processResponse('user-123', ['sales', 'support']);

        $result = $this->manager->getNextStep('user-123');
        // Erster E-Mail-Konto-Schritt fuer den ersten Bereich.
        self::assertSame('email_account_sales', $result['step_id']);
        self::assertSame('email_combined', $result['type']);
        self::assertSame('sales', $result['area']);
    }

    public function testCombinedEmailStoresSmtpAndImapSecretsScopedToArea(): void
    {
        $this->setUpStatefulContext();
        $this->manager->startOnboarding('user-123');
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        $this->manager->processResponse('user-123', 'mistral');
        $this->manager->processResponse('user-123', 'mistral-small-latest');
        $this->manager->processResponse('user-123', 'key');
        $this->manager->processResponse('user-123', 'manage_company');
        $this->manager->processResponse('user-123', 'software_it');
        $this->manager->processResponse('user-123', ['sales']);

        // Kombinierte SMTP+IMAP-Eingabe.
        $this->secretService->expects(self::exactly(3))
            ->method('set')
            ->willReturnCallback(function (string $key, string $value, string $user, ?string $scope) {
                self::assertSame('user-123', $user);
                self::assertSame('email:sales', $scope);
                return null;
            });

        $this->manager->processResponse('user-123', [
            'smtp_host' => 'smtp.example.com', 'smtp_port' => '587',
            'smtp_user' => 'sales@example.com', 'smtp_pass' => 'pass',
            'smtp_encryption' => 'tls', 'from' => 'sales@example.com',
            'imap_host' => 'imap.example.com', 'imap_port' => '993',
            'imap_encryption' => 'ssl',
        ]);
    }

    public function testAssistWorkBranchAsksUseCasesMultiselect(): void
    {
        $this->setUpStatefulContext();
        $this->manager->startOnboarding('user-123');
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        $this->manager->processResponse('user-123', 'mistral');
        $this->manager->processResponse('user-123', 'mistral-small-latest');
        $this->manager->processResponse('user-123', 'key');
        $this->manager->processResponse('user-123', 'assist_work');

        $result = $this->manager->getNextStep('user-123');
        self::assertSame('use_cases', $result['step_id']);
        self::assertSame('multiselect', $result['type']);
    }

    public function testResearchUseCaseAppendsTavilyIntegrationStep(): void
    {
        $this->setUpStatefulContext();
        $this->manager->startOnboarding('user-123');
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        $this->manager->processResponse('user-123', 'mistral');
        $this->manager->processResponse('user-123', 'mistral-small-latest');
        $this->manager->processResponse('user-123', 'key');
        $this->manager->processResponse('user-123', 'assist_work');
        $this->manager->processResponse('user-123', ['research']);

        // Nach use_cases mit research erscheint der Tavily-Integrationsschritt.
        $status = $this->manager->getOnboardingStatus('user-123');
        $result = $this->manager->getNextStep('user-123');
        self::assertSame('integration_tavily_api_key', $result['step_id']);
    }

    public function testOtherGoalAsksFreitext(): void
    {
        $this->setUpStatefulContext();
        $this->manager->startOnboarding('user-123');
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);

        $this->manager->processResponse('user-123', 'mistral');
        $this->manager->processResponse('user-123', 'mistral-small-latest');
        $this->manager->processResponse('user-123', 'key');
        $this->manager->processResponse('user-123', 'other');

        $result = $this->manager->getNextStep('user-123');
        self::assertSame('goal_detail', $result['step_id']);
        self::assertSame('text', $result['type']);
    }

    public function testCompleteOnboardingSetsCompletedFlag(): void
    {
        $this->setUpStatefulContext();
        $this->manager->startOnboarding('user-123');
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);
        $this->userProfileRepo->expects(self::atLeastOnce())->method('save');

        // KI-Settings + 'other' goal + freetext -> summary -> completed.
        $this->manager->processResponse('user-123', 'mistral');
        $this->manager->processResponse('user-123', 'mistral-small-latest');
        $this->manager->processResponse('user-123', 'key');
        $this->manager->processResponse('user-123', 'other');
        $this->manager->processResponse('user-123', 'just exploring');

        $result = $this->manager->getNextStep('user-123');
        self::assertSame('summary', $result['step_id']);
        $completion = $this->manager->processResponse('user-123', 'confirm');
        self::assertSame('completed', $completion['status']);
        $onb = $profile->getOnboardingData();
        self::assertTrue($onb['completed']);
    }

    public function testGetOnboardingStatusNotStartedWithoutProfile(): void
    {
        $this->userProfileRepo->method('findOneBy')->willReturn(null);
        $status = $this->manager->getOnboardingStatus('user-123');
        self::assertSame('not_started', $status['status']);
    }

    public function testGetOnboardingStatusCompletedFromProfile(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $profile->setOnboardingData(['completed' => true, 'completed_at' => '2026-01-01T00:00:00+00:00']);
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);
        $status = $this->manager->getOnboardingStatus('user-123');
        self::assertSame('completed', $status['status']);
        self::assertSame('2026-01-01T00:00:00+00:00', $status['completed_at']);
    }

    public function testGetOnboardingStatusInProgressFromContext(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);
        $this->contextStore->method('loadContext')->willReturn([
            'onboarding_data' => ['started_at' => '2026-01-01T00:00:00+00:00'],
        ]);
        $status = $this->manager->getOnboardingStatus('user-123');
        self::assertSame('in_progress', $status['status']);
    }

    public function testResetOnboardingClearsContextAndProfile(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $profile->setOnboardingData(['completed' => true]);
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);
        $this->contextStore->method('loadContext')->willReturn([
            'onboarding_data' => ['user_type' => 'Business'],
        ]);
        $this->contextStore->expects(self::once())->method('saveContext');
        $this->userProfileRepo->expects(self::once())->method('save');
        $this->manager->resetOnboarding('user-123');
        $onb = $profile->getOnboardingData();
        self::assertFalse($onb['completed']);
        self::assertNull($onb['completed_at']);
    }

    public function testResumeOnboardingStartsFresh(): void
    {
        $this->contextStore->method('loadContext')->willReturn([]);
        $this->contextStore->expects(self::atLeastOnce())->method('saveContext');
        $this->userProfileRepo->method('findOneBy')->willReturn(null);
        $result = $this->manager->resumeOnboarding('user-123');
        self::assertSame('in_progress', $result['status']);
    }

    public function testCompleteOnboardingUpdatesUserProfileFromContext(): void
    {
        $this->contextStore->method('loadContext')->willReturn([
            'onboarding_data' => [
                'status' => 'in_progress',
                'user_type' => 'Business (CRM, Termine)',
                'user_type_detail' => 'detail',
                'technical_skills' => ['php'],
                'experience_level' => 'advanced',
                'use_cases' => ['crm'],
                'industry' => 'IT',
                'industry_detail' => 'SaaS',
                'response_style' => 'concise',
                'technical_level' => 'high',
                'language' => 'de',
                'notifications' => ['email'],
                'hitl_requirements' => ['critical'],
                'security_level' => 'strict',
            ],
        ]);
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-123');
        $this->userProfileRepo->method('findOneBy')->willReturn($profile);
        $this->userProfileRepo->expects(self::once())->method('save');
        $result = $this->manager->completeOnboarding('user-123');
        self::assertSame('completed', $result['status']);
        self::assertSame('Business (CRM, Termine)', $profile->getUserType());
        $preferences = $profile->getPreferences() ?? [];
        self::assertSame('detail', $preferences['user_type_detail']);
        self::assertSame(['php'], $preferences['technical_skills']);
        self::assertSame('IT', $preferences['industry']);
        self::assertSame('de', $preferences['language']);
    }
}
