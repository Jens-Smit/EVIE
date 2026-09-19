<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Onboarding;

use App\AI\Onboarding\OnboardingStrategyService;
use App\Entity\SubAgentDefinition;
use App\Repository\AgentGoalRepository;
use App\Repository\SubAgentDefinitionRepository;
use App\Tests\Stub\StubAgent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit-Tests fuer den OnboardingStrategyService: Strategie-Entwurf mit
 * deterministischem StubAgent (keine echten LLM-Calls im CI), strikte
 * Validierung von LLM-Antworten (nur Katalog-Sub-Agenten) und
 * Sub-Agent-Anlage ausschliesslich aus dem existierenden Katalog.
 */
final class OnboardingStrategyServiceTest extends TestCase
{
    private AgentGoalRepository&MockObject $goalRepo;
    private SubAgentDefinitionRepository&MockObject $subAgentRepo;

    protected function setUp(): void
    {
        $this->goalRepo = $this->createMock(AgentGoalRepository::class);
        $this->subAgentRepo = $this->createMock(SubAgentDefinitionRepository::class);
    }

    private function service(StubAgent $agent): OnboardingStrategyService
    {
        return new OnboardingStrategyService(
            $agent,
            $this->goalRepo,
            $this->subAgentRepo,
            new NullLogger()
        );
    }

    public function testDraftStrategyHeuristicWithoutMission(): void
    {
        $service = $this->service(new StubAgent('{}'));
        $draft = $service->draftStrategy('user-1', []);

        self::assertSame('heuristic', $draft['source']);
        self::assertSame('Initial-Strategie fuer EVIE', $draft['title']);
        self::assertNotSame('', $draft['goal']);
        self::assertNotSame([], $draft['steps']);
        self::assertContains('ceo_assistant', $draft['sub_agents']);
    }

    public function testDraftStrategyMergesValidLlmDraft(): void
    {
        $llm = json_encode([
            'title' => 'Vertriebsautomatisierung',
            'goal' => 'Vertriebsmails automatisch beantworten',
            'steps' => ['Mailbox anbinden', 'Antwortentwuerefe pruefen'],
            'success_metric' => '80% der Mails beantwortet',
            'capabilities' => ['research', 'business_automation'],
            'sub_agents' => ['communication_manager', 'fantasy_agent'],
        ]);
        $service = $this->service(new StubAgent((string) $llm));

        $draft = $service->draftStrategy('user-1', [
            'mission_statement' => 'Vertriebsmails automatisieren',
            'use_cases' => ['research'],
        ]);

        self::assertSame('llm', $draft['source']);
        self::assertSame('Vertriebsautomatisierung', $draft['title']);
        self::assertContains('communication_manager', $draft['sub_agents']);
        // Fantasie-Sub-Agenten werden verworfen (nur Katalog erlaubt).
        self::assertNotContains('fantasy_agent', $draft['sub_agents']);
        // Heuristik-Empfehlungen bleiben erhalten (research -> website_researcher).
        self::assertContains('website_researcher', $draft['sub_agents']);
    }

    public function testDraftStrategyFallsBackOnInvalidLlmJson(): void
    {
        $service = $this->service(new StubAgent('kein json'));
        $draft = $service->draftStrategy('user-1', ['mission_statement' => 'Etwas tun']);

        self::assertSame('heuristic', $draft['source']);
    }

    public function testPersistStrategyCreatesActiveAgentGoalFromOnboarding(): void
    {
        $service = $this->service(new StubAgent('{}'));

        $this->goalRepo->expects(self::once())->method('save');

        $data = $service->persistStrategy('user-1', [], [
            'title' => 'T',
            'goal' => 'G',
            'steps' => ['s1'],
            'success_metric' => 'm',
            'capabilities' => ['research'],
            'sub_agents' => ['website_researcher'],
        ]);

        self::assertTrue($data['strategy_confirmed']);
        self::assertSame('T', $data['strategy']['title']);
        self::assertSame('G', $data['strategy']['goal']);
    }

    public function testEnsureSubAgentsInstantiatesOnlyCatalogRoles(): void
    {
        $service = $this->service(new StubAgent('{}'));

        $this->subAgentRepo->method('findOneByName')->willReturn(null);
        $this->subAgentRepo->expects(self::once())->method('save');

        $created = $service->ensureSubAgents(['data_analyst', 'fantasy_role']);

        self::assertCount(1, $created);
        self::assertSame('data_analyst', $created[0]['name']);
        self::assertTrue($created[0]['created']);
    }
}
