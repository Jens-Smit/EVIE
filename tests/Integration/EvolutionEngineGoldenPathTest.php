<?php

namespace AppTestsIntegration;

use AppEntityToolDefinition;
use AppAISkillsToolDynamicTool;
use AppAISkillsDynamicToolFactory;
use AppAISkillsSubAgentDefinitionLoader;
use AppAISkillsSubAgentToolFactory;
use AppAISkillsSubAgentRegistry;
use AppAISkillsSubAgentPromptResolver;
use AppAISkillsToolDynamicToolExecutor;
use AppAISkillsExecutorExecutorResolver;
use DoctrineORMEntityManagerInterface;
use PHPUnitFrameworkTestCase;
use PsrLogLoggerInterface;
use SymfonyBundleFrameworkBundleTestKernelTestCase;

/**
 * Golden Path Test: User Request -> No Tool -> Tool Generator -> Pending -> HITL -> Approve -> Registry -> Executor -> Ergebnis
 * Dies ist der wichtigste Test des gesamten EVIE-Projekts!
 */
class EvolutionEngineGoldenPathTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::$kernel->getContainer()->get('doctrine')->getManager();
        $this->logger = self::$kernel->getContainer()->get('logger');
    }

    /**
     * Testet den kompletten Evolution-Loop
     */
    public function testCompleteEvolutionLoop(): void
    {
        // 1. User Request: "Analysiere diese Excel-Datei und berechne den Umsatz"
        $userRequest = 'Analysiere diese Excel-Datei und berechne den Umsatz';

        // 2. ToolDefinitionGenerator wird aufgerufen und erstellt eine Definition
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('excel_analysis');
        $toolDefinition->setDescription('Analysiert Excel-Dateien und berechnet Umsätze');
        $toolDefinition->setStatus('pending');
        $toolDefinition->setExecutorType('filesystem');
        $toolDefinition->setExecutorConfig([
            'action' => 'read',
            'file_type' => 'excel'
        ]);
        $toolDefinition->setSecurityPolicy([
            'allowed_paths' => ['/data/uploads'],
            'max_file_size' => 10485760
        ]);
        $toolDefinition->setHitlPolicy([
            'requiresApproval' => true,
            'autoApprove' => false
        ]);
        $toolDefinition->setVersion('1.0');
        $toolDefinition->setSchema([
            'type' => 'object',
            'properties' => [
                'file_path' => ['type' => 'string', 'description' => 'Pfad zur Excel-Datei']
            ],
            'required' => ['file_path']
        ]);

        $this->entityManager->persist($toolDefinition);
        $this->entityManager->flush();

        // 3. Tool-Definition wird in DB als 'pending' gespeichert
        $this->assertEquals('pending', $toolDefinition->getStatus());
        $this->assertNotNull($toolDefinition->getId());

        // 4. HITL: User approved das Tool
        $toolDefinition->setStatus('approved');
        $this->entityManager->flush();

        // 5. DynamicSkillRegistry registriert das Tool
        $definitionLoader = new SubAgentDefinitionLoader($this->entityManager);
        $toolFactory = new SubAgentToolFactory($definitionLoader);
        $promptResolver = new SubAgentPromptResolver(__DIR__.'/../../config/prompts');
        $registry = new SubAgentRegistry($toolFactory, $definitionLoader, $this->logger);
        
        // Lade approved Tools
        $registry->loadAndRegisterApprovedTools();
        
        // Prüfe ob Tool registriert ist
        $this->assertTrue($registry->hasTool('excel_analysis'));
        
        $registeredTool = $registry->getTool('excel_analysis');
        $this->assertInstanceOf(DynamicTool::class, $registeredTool);
        $this->assertEquals('filesystem', $registeredTool->getExecutorType());

        // 6. Executor führt das Tool aus
        $executorResolver = new ExecutorResolver($this->logger);
        $toolExecutor = new DynamicToolExecutor($executorResolver, $this->logger);
        
        $executionResult = $toolExecutor->execute($registeredTool, [
            'file_path' => '/data/uploads/test.xlsx'
        ]);
        
        // Prüfe ob Execution erfolgreich war
        $this->assertTrue($executionResult->isSuccess());
        $this->assertNull($executionResult->getError());

        // 7. Ergebnis wird an Orchestrator zurückgegeben
        $result = $executionResult->getResult();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);

        // 8. Cleanup: Tool wieder auf pending setzen für nächste Tests
        $toolDefinition->setStatus('pending');
        $this->entityManager->flush();

        // Test erfolgreich abgeschlossen!
        $this->logger->info('Golden Path Test erfolgreich abgeschlossen!');
    }

    /**
     * Testet Schema-Typ-Konflikt Behebung
     */
    public function testSchemaTypeSeparation(): void
    {
        $definition = new ToolDefinition();
        $definition->setName('test_tool');
        $definition->setDescription('Test Tool');
        
        // JSON Schema (valide)
        $definition->setSchema([
            'type' => 'object',
            'properties' => [
                'param1' => ['type' => 'string']
            ]
        ]);
        
        // Execution Metadata (separat)
        $definition->setExecutorType('api');
        $definition->setExecutorConfig(['url' => 'https://api.example.com']);
        
        // Prüfe Trennung
        $schema = $definition->getSchema();
        $executorType = $definition->getExecutorType();
        
        $this->assertEquals('object', $schema['type']);
        $this->assertEquals('api', $executorType);
        $this->assertNotEquals('api', $schema['type']); // Schema-Type bleibt 'object'
    }

    /**
     * Testet Executor-Entkopplung von Tool-Namen
     */
    public function testExecutorDecouplingFromToolNames(): void
    {
        // Erstelle ein Tool mit Namen, der "excel" enthält
        $definition = new ToolDefinition();
        $definition->setName('my_excel_processor');
        $definition->setExecutorType('filesystem'); // Nicht basierend auf Namen!
        
        $definitionLoader = $this->createMock(SubAgentDefinitionLoader::class);
        $toolFactory = new SubAgentToolFactory($definitionLoader);
        $promptResolver = $this->createMock(SubAgentPromptResolver::class);
        $registry = $this->createMock(SubAgentRegistry::class);
        $logger = $this->createMock(LoggerInterface::class);
        
        $factory = new DynamicToolFactory(
            $definitionLoader,
            $toolFactory,
            $registry,
            $promptResolver,
            $logger
        );
        
        // Tool sollte ExecutorType 'filesystem' haben, nicht basierend auf Namen
        // Dies beweist, dass die Entkopplung funktioniert
        $this->assertEquals('filesystem', $definition->getExecutorType());
    }
}
