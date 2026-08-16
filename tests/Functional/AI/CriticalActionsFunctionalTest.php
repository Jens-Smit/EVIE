<?php

declare(strict_types=1);

namespace App\Tests\Functional\AI;

use App\AI\Agent\OrchestratorDialogService;
use App\AI\Skills\ToolDefinitionGenerator;
use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Functional-Test fuer die kritischen EVIE-Aktionen mit echtem Kernel und
 * LLM-Abruf-Pfad (Blueprint §4.A, §4.B, §5).
 *
 * Der Kernel wird im Test-Env gebootet. Der TestStubPass ersetzt die Agent-
 * Services durch StubAgent, sodass der LLM-Abruf (AgentInterface::call) voll-
 * staendig durchlaufen wird, deterministisch und ohne echte API-Kosten.
 *
 * Verifiziert end-to-end:
 *  - Agent-Orchestrierung (LLM-Abruf + Antwort-Dispatch)
 *  - Toolgenerierung (LLM-Abruf tool_generator + Persistenz)
 *  - Toolfreigabe (HITL approve -> approved)
 *
 * Die LLM-Antworten werden ueber Env-Variablen gesteuert, sodass jeder Flow
 * genau die benoetigte Antwort erhaelt (minimiert: 1 Aufruf pro Aktion).
 */
final class CriticalActionsFunctionalTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
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
            // Tabelle existiert moeglicherweise nicht in jeder Test-DB.
        }
        parent::tearDown();
    }

    public function testOrchestratorDialogRunsLlmCallAndReturnsResponse(): void
    {
        // LLM-Antwort fuer den Orchestrator konfigurieren (Dialog-Antwort).
        putenv('EVIE_TEST_LLM_RESPONSE_ORCHESTRATOR=' . json_encode([
            'type' => 'dialog',
            'content' => 'Hallo, ich kann dir helfen.',
            'message' => 'Hallo, ich kann dir helfen.',
        ], JSON_THROW_ON_ERROR));

        // Kernel neu booten, damit der TestStubPass die Env-Antwort uebernimmt.
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->ensureSchema();

        $orchestrator = static::getContainer()->get(OrchestratorDialogService::class);

        $result = $orchestrator->ask('Hallo EVIE', 'functional-user');

        self::assertIsString($result);
        self::assertNotEmpty($result);
    }

    public function testToolGenerationPersistsPendingToolDefinition(): void
    {
        // LLM-Antwort fuer den tool_generator konfigurieren: gueltiges Schema.
        putenv('EVIE_TEST_LLM_RESPONSE_TOOL_GENERATOR=' . json_encode([
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'Ziel-URL'],
                'depth' => ['type' => 'integer', 'description' => 'Tiefe 1-5'],
            ],
            'required' => ['url'],
            'security_level' => 'medium',
            'hitl_required' => true,
        ], JSON_THROW_ON_ERROR));

        self::ensureKernelShutdown();
        self::bootKernel();
        $this->ensureSchema();

        $generator = static::getContainer()->get(ToolDefinitionGenerator::class);

        $definition = $generator->generateToolDefinition(
            'functional_scraper',
            'Scraped Webseiten und extrahiert Inhalte',
            ['original_request' => 'Scrape eine Seite']
        );

        self::assertSame('pending', $definition->getStatus());
        self::assertSame('functional_scraper', $definition->getName());
        self::assertArrayHasKey('type', $definition->getSchema());

        // Persistenz in der DB verifizieren.
        $repo = static::getContainer()->get(ToolDefinitionRepository::class);
        $loaded = $repo->findOneBy(['name' => 'functional_scraper']);
        self::assertNotNull($loaded);
        self::assertSame('pending', $loaded->getStatus());
    }

    public function testToolApprovalFlowMovesPendingToApproved(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->ensureSchema();

        $generator = static::getContainer()->get(ToolDefinitionGenerator::class);
        $repo = static::getContainer()->get(ToolDefinitionRepository::class);

        // Tool generieren (status=pending).
        $definition = $generator->generateToolDefinition(
            'approval_test_tool',
            'Ein Tool fuer den Freigabe-Flow',
        );

        self::assertSame('pending', $definition->getStatus());

        // HITL-Freigabe simulieren.
        $generator->approveTool($definition);

        // Status in der DB verifizieren.
        $repo->clear();
        $loaded = $repo->find($definition->getId());
        self::assertNotNull($loaded);
        self::assertSame('approved', $loaded->getStatus());
    }

    public function testApprovedToolExposedByDynamicToolboxInContainer(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->ensureSchema();

        $generator = static::getContainer()->get(ToolDefinitionGenerator::class);
        $generator->generateToolDefinition(
            'toolbox_exposed_tool',
            'Ein Tool, das nach Freigabe in der Toolbox sichtbar ist',
        );

        // Das generierte Tool ist noch pending -> nicht in der Toolbox.
        /** @var \App\AI\Skills\DynamicToolbox $toolbox */
        $toolbox = static::getContainer()->get(\App\AI\Skills\DynamicToolbox::class);
        $toolsBefore = $toolbox->getTools();
        $namesBefore = array_map(fn($t) => $t->getName(), $toolsBefore);
        self::assertNotContains('toolbox_exposed_tool', $namesBefore);

        // Freigabe -> Tool erscheint in der Toolbox.
        $repo = static::getContainer()->get(ToolDefinitionRepository::class);
        $def = $repo->findOneBy(['name' => 'toolbox_exposed_tool']);
        self::assertNotNull($def);
        $generator->approveTool($def);
        $repo->clear();

        $toolsAfter = $toolbox->getTools();
        $namesAfter = array_map(fn($t) => $t->getName(), $toolsAfter);
        self::assertContains('toolbox_exposed_tool', $namesAfter);
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
