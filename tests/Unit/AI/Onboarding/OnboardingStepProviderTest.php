<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Onboarding;

use App\AI\Onboarding\IntegrationRequirementMapper;
use App\AI\Onboarding\OnboardingReadinessChecker;
use App\AI\Onboarding\OnboardingStepProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer die neue, phasenbasierte Schrittfolge (A-F) des
 * OnboardingStepProvider: Brainstorming (mission_statement), Strategie-Review,
 * Sub-Agent-/Tool-Review, tool-getriebene Credentials und Mapper-Fallback.
 */
final class OnboardingStepProviderTest extends TestCase
{
    private OnboardingStepProvider $provider;
    private IntegrationRequirementMapper $mapper;

    protected function setUp(): void
    {
        $this->provider = new OnboardingStepProvider();
        $this->mapper = new IntegrationRequirementMapper();
    }

    public function testBaseStepsContainMissionStatementBeforeGoal(): void
    {
        $steps = $this->provider->baseStepsWithMission();
        $ids = array_map(static fn (array $s) => $s['id'], $steps);

        self::assertSame(
            ['llm_provider', 'llm_model', 'llm_api_key', 'mission_statement', 'goal'],
            $ids
        );
        $missionIdx = array_search('mission_statement', $ids, true);
        $mission = $steps[$missionIdx];
        self::assertSame('freeform_dialog', $mission['type']);
        self::assertTrue($mission['required']);
    }

    public function testStrategyReviewOnlyAfterMissionStatement(): void
    {
        self::assertSame([], $this->provider->strategySteps([]));

        $steps = $this->provider->strategySteps(['mission_statement' => 'Vertrieb automatisieren']);
        self::assertCount(1, $steps);
        self::assertSame('strategy_review', $steps[0]['id']);
        self::assertSame('strategy_review', $steps[0]['type']);

        $confirmed = $this->provider->strategySteps([
            'mission_statement' => 'Vertrieb automatisieren',
            'strategy_confirmed' => true,
        ]);
        self::assertSame([], $confirmed);
    }

    public function testCapabilityStepsRequireConfirmedStrategy(): void
    {
        self::assertSame([], $this->provider->capabilitySteps(['strategy_confirmed' => false]));

        $steps = $this->provider->capabilitySteps(['strategy_confirmed' => true]);
        $ids = array_map(static fn (array $s) => $s['id'], $steps);
        self::assertSame(['sub_agent_review', 'tool_review'], $ids);
    }

    public function testCredentialStepsPreferToolSecretsOverMapperFallback(): void
    {
        $toolDriven = $this->provider->credentialSteps([
            'tool_secrets' => [
                ['tool_id' => 7, 'key' => 'TAVILY_API_KEY', 'label' => 'web_research', 'done' => false],
                ['tool_id' => 7, 'key' => 'ALREADY_DONE', 'done' => true],
            ],
        ], $this->mapper);

        self::assertCount(1, $toolDriven);
        self::assertSame('tool_secret_7_TAVILY_API_KEY', $toolDriven[0]['id']);
        self::assertSame('secret', $toolDriven[0]['type']);
        self::assertSame(7, $toolDriven[0]['tool_id']);

        // Fallback: ohne Tools greift der RequirementMapper (research -> Tavily).
        $fallback = $this->provider->credentialSteps(['use_cases' => ['research']], $this->mapper);
        self::assertSame('integration_tavily_api_key', $fallback[0]['id']);
    }

    public function testAllStepsPhaseOrder(): void
    {
        $steps = $this->provider->allSteps([
            'mission_statement' => 'Alles automatisieren',
            'strategy_confirmed' => true,
        ], $this->mapper);

        $ids = array_map(static fn (array $s) => $s['id'], $steps);
        self::assertSame('llm_provider', $ids[0]);
        self::assertContains('mission_statement', $ids);
        self::assertContains('sub_agent_review', $ids);
        self::assertContains('tool_review', $ids);
        self::assertSame('summary', $ids[count($ids) - 1]);
    }

    public function testReadinessCheckerFlagsBlockingFields(): void
    {
        $checker = new OnboardingReadinessChecker();

        $empty = $checker->check([]);
        self::assertFalse($empty['ready']);
        self::assertContains('llm_api_key', $empty['missing_blocking']);
        self::assertContains('mission_statement', $empty['missing_blocking']);
        self::assertContains('strategy_confirmed', $empty['missing_blocking']);

        $ready = $checker->check([
            'llm_provider' => 'mistral',
            'llm_model' => 'mistral-small-latest',
            'llm_api_key' => 'key',
            'mission_statement' => 'Vertrieb automatisieren',
            'strategy_confirmed' => true,
        ]);
        self::assertTrue($ready['ready']);
    }
}
