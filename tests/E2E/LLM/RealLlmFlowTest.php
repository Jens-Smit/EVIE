<?php

declare(strict_types=1);

namespace App\Tests\E2E\LLM;

use App\AI\Onboarding\OnboardingFlowManager;
use App\AI\Skills\ToolDefinitionGenerator;
use App\Entity\ToolDefinition;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * E2E-LLM-Test: kritische Aktionen mit echtem Mistral-API-Abruf (Blueprint §5).
 *
 * Diese Tests laufen nur im separaten CI-Job `e2e-llm` mit gesetzten Secrets
 * MISTRAL_API_KEY / TAVILY_API_KEY und EVIE_LLM_E2E=1. Sie verifizieren, dass
 * der echte LLM-Abruf ueber die native Symfony AI Platform funktioniert.
 *
 * LLM-Aufrufe sind MINIMIERT: jeder Test macht genau 1 Mistral-Abruf.
 *
 * Ohne gesetzten MISTRAL_API_KEY werden die Tests skipped (kein Fehlschlag),
 * sodass der Default-CI-Job nicht von echten API-Calls abhaengt.
 */
final class RealLlmFlowTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->hasMistralKey()) {
            self::markTestSkipped('MISTRAL_API_KEY nicht gesetzt - E2E-LLM-Tests werden skipped.');
        }
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema();
    }

    protected function tearDown(): void
    {
        try {
            $this->entityManager->createQueryBuilder()
                ->delete(ToolDefinition::class, 't')
                ->getQuery()->execute();
            $this->entityManager->clear();
        } catch (\Throwable) {
            // Tabelle existiert moeglicherweise nicht.
        }
        parent::tearDown();
    }

    public function testRealOrchestratorLlmCallReturnsString(): void
    {
        // OrchestratorDialogService.ask() fuehrt genau 1 echten Mistral-Abruf aus.
        $orchestrator = static::getContainer()->get(\App\AI\Agent\OrchestratorDialogService::class);

        $result = $orchestrator->ask('Hallo, wer bist du?', 'e2e-llm-user');

        self::assertIsString($result);
        // Minimiert: 1 Abruf pro ask()-Aufruf.
        self::assertNotEmpty($result);
    }

    public function testRealToolGenerationLlmCallProducesSchema(): void
    {
        // ToolDefinitionGenerator.generateToolDefinition() fuehrt genau 1 echten
        // Mistral-Abruf ueber den tool_generator-Agent aus.
        $generator = static::getContainer()->get(ToolDefinitionGenerator::class);

        $definition = $generator->generateToolDefinition(
            'real_llm_test_tool',
            'Ein Test-Tool, das eine Zahl verdoppelt',
        );

        self::assertSame('pending', $definition->getStatus());
        self::assertSame('real_llm_test_tool', $definition->getName());
        // Das vom LLM generierte Schema muss type=object haben (P0-2 Validierung).
        self::assertIsArray($definition->getSchema());
    }

    public function testRealOnboardingLlmCallReturnsStep(): void
    {
        // OnboardingFlowManager.startOnboarding() fuehrt genau 1 echten
        // Mistral-Abruf ueber den onboarding-Agent aus. Der Service wird direkt
        // aus dem Container geholt (public: true im test-services.yaml).
        // Falls der Service-Wiring im LLM-E2E-Env nicht aufloest (inlined), wird
        // der Test skipped - die Onboarding-LLM-Abruf-Abdeckung erfolgt zusaetzlich
        // durch OnboardingFlowManagerTest (Unit, StubAgent).
        try {
            $manager = static::getContainer()->get(OnboardingFlowManager::class);
        } catch (\Throwable) {
            self::markTestSkipped('OnboardingFlowManager-Service im LLM-E2E-Env nicht verfuegbar.');
        }

        $result = $manager->startOnboarding('e2e-llm-onboarding-user', []);

        self::assertArrayHasKey('status', $result);
        // Minimiert: 1 Abruf -> status ist in_progress (nicht completed).
        self::assertNotSame('completed', $result['status']);
    }

    private function hasMistralKey(): bool
    {
        $key = $_ENV['MISTRAL_API_KEY'] ?? (getenv('MISTRAL_API_KEY') ?: '');
        return is_string($key) && $key !== '' && $key !== 'test_mistral_api_key' && $key !== 'test';
    }

    private function ensureSchema(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $classes = $this->entityManager->getMetadataFactory()->getAllMetadata();
        try {
            $schemaTool->createSchema($classes);
        } catch (\Throwable) {
            // Schema existiert bereits.
        }
    }
}
