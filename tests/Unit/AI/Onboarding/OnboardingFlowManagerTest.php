<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Onboarding;

use App\AI\Onboarding\ContextStoreManager;
use App\AI\Onboarding\OnboardingFlowManager;
use App\Entity\UserProfile;
use App\Repository\UserProfileRepository;
use App\Tests\Stub\StubAgent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer den Onboarding-Flow (Blueprint §4.C / Phase 3 Massnahme 10).
 *
 * Verifiziert die kritischen Onboarding-Aktionen inklusive des LLM-Abrufs ueber
 * den onboarding-Agent. Der StubAgent ersetzt den echten Mistral-Aufruf
 * deterministisch, sodass der LLM-Abruf-Pfad (AgentInterface::call) vollstaendig
 * durchlaufen wird, ohne echte API-Kosten zu verursachen.
 *
 * Die Anzahl der LLM-Aufrufe wird assertionsgeprueft (Minimierung: genau 1
 * Abruf pro Onboarding-Aktion), wie in der Anforderung gefordert.
 */
final class OnboardingFlowManagerTest extends TestCase
{
    private OnboardingFlowManager $manager;
    private StubAgent $onboardingAgent;
    private ContextStoreManager&MockObject $contextStore;
    private UserProfileRepository&MockObject $userProfileRepo;

    protected function setUp(): void
    {
        $this->onboardingAgent = new StubAgent(
            json_encode([
                'status' => 'in_progress',
                'step_id' => 'user_type',
                'question' => 'Wie moechtest du den Agenten nutzen?',
                'type' => 'multiple_choice',
                'options' => ['Business (CRM, Termine)', 'Privat (Recherche, Notizen)'],
                'next_step' => 'business_type',
                'context_updates' => ['user_type' => 'Business (CRM, Termine)'],
            ], JSON_THROW_ON_ERROR)
        );

        $this->contextStore = $this->createMock(ContextStoreManager::class);
        $this->userProfileRepo = $this->createMock(UserProfileRepository::class);

        $this->manager = new OnboardingFlowManager(
            $this->contextStore,
            $this->userProfileRepo,
            $this->onboardingAgent
        );
    }

    public function testStartOnboardingTriggersSingleLlmCall(): void
    {
        $this->contextStore->method('loadContext')->willReturn([]);
        $this->contextStore->expects(self::atLeastOnce())->method('saveContext');

        $result = $this->manager->startOnboarding('user-123', ['source' => 'web']);

        // Genau 1 LLM-Abruf fuer den ersten Onboarding-Schritt (minimiert).
        self::assertSame(1, $this->onboardingAgent->getCallCount());
        self::assertSame('in_progress', $result['status']);
        self::assertArrayHasKey('question', $result);
        self::assertNotEmpty($result['question']);
    }

    public function testProcessResponseInvokesLlmAndParsesStep(): void
    {
        $this->contextStore->method('loadContext')->willReturn([
            'onboarding_data' => ['started_at' => '2026-01-01T00:00:00+00:00'],
        ]);
        $this->contextStore->expects(self::atLeastOnce())->method('saveContext');

        $result = $this->manager->processResponse('user-123', 'Business (CRM, Termine)');

        self::assertSame(1, $this->onboardingAgent->getCallCount());
        self::assertSame('in_progress', $result['status']);
        // Kontext-Update aus der Agenten-Antwort wurde uebernommen.
        self::assertArrayHasKey('context', $result);
    }

    public function testGetNextStepUsesOnboardingAgent(): void
    {
        $this->contextStore->method('loadContext')->willReturn([
            'onboarding_data' => ['user_type' => 'Business'],
        ]);
        $this->contextStore->expects(self::once())->method('saveContext');

        $result = $this->manager->getNextStep('user-123');

        self::assertSame(1, $this->onboardingAgent->getCallCount());
        self::assertSame('in_progress', $result['status']);
        self::assertArrayHasKey('question', $result);
    }

    public function testCompleteOnboardingPersistsUserProfile(): void
    {
        // Komplettierungs-Agent-Antwort: notifyAgentOfCompletion.
        $this->contextStore->method('loadContext')->willReturn([
            'onboarding_data' => [
                'status' => 'in_progress',
                'user_type' => 'Business (CRM, Termine)',
                'industry' => 'IT',
            ],
        ]);

        $userProfile = new UserProfile();
        $userProfile->setUserIdentifier('user-123');

        // Beim ersten completeOnboarding-Aufruf: kein Profil vorhanden -> neu.
        $this->userProfileRepo->method('findOneBy')->willReturn(null);
        $this->userProfileRepo->expects(self::once())->method('save')
            ->with(self::callback(function (UserProfile $p): bool {
                // Onboarding-Flags werden beim Abschluss gesetzt.
                $onb = $p->getOnboardingData() ?? [];
                return ($onb['completed'] ?? false) === true;
            }), true);

        $result = $this->manager->completeOnboarding('user-123');

        self::assertSame('completed', $result['status']);
        // notifyAgentOfCompletion fuehrt zu 1 weiteren LLM-Abruf.
        self::assertSame(1, $this->onboardingAgent->getCallCount());
    }

    public function testLlmFailureFallsBackGracefully(): void
    {
        // Agent wirft Exception -> Fallback-Pfad wird genutzt.
        $failingAgent = new class {
            public function call($input, array $options = []): \Symfony\AI\Platform\Result\TextResult
            {
                throw new \RuntimeException('Mistral API unreachable');
            }

            public function getName(): string
            {
                return 'failing_onboarding';
            }
        };

        $this->contextStore->method('loadContext')->willReturn([
            'onboarding_data' => ['started_at' => '2026-01-01T00:00:00+00:00'],
        ]);
        $this->contextStore->method('saveContext');

        // OnboardingFlowManager mit einem fehlschlagenden Agent via Reflection injizieren,
        // da der Konstruktor AgentInterface typisiert.
        $manager = new OnboardingFlowManager(
            $this->contextStore,
            $this->userProfileRepo,
            $this->buildFailingAgent()
        );

        $result = $manager->getNextStep('user-123');

        // Fallback liefert einen gueltigen Schritt zurueck, kein Absturz.
        self::assertArrayHasKey('step_id', $result);
    }

    private function buildFailingAgent(): \Symfony\AI\Agent\AgentInterface
    {
        return new class implements \Symfony\AI\Agent\AgentInterface {
            public function call(string|\Symfony\AI\Platform\Message\MessageBag|\Symfony\AI\Platform\Message\UserMessage $input, array $options = []): \Symfony\AI\Platform\Result\ResultInterface
            {
                throw new \RuntimeException('Mistral API unreachable');
            }

            public function getName(): string
            {
                return 'failing_onboarding';
            }
        };
    }
}
