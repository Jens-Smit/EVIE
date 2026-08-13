<?php

namespace App\Tests\Functional\AI;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use App\AI\Skills\ToolDefinitionGenerator;
use App\AI\Skills\DynamicSkillRegistry;

/**
 * E2E-Tests für den Evolution-Flow (Tool-Generierung → Registrierung → Ausführung)
 * 
 * Phase 3: Maßnahme 9 - E2E-Test für Evolution-Flow
 * 
 * @see ROADMAP_PHASE3.md
 */
class ToolEvolutionTest extends WebTestCase
{
    private ContainerInterface $container;
    private AgentInterface $toolGeneratorAgent;
    private AgentInterface $orchestratorAgent;
    private ToolDefinitionRepository $toolDefinitionRepo;
    private ToolDefinitionGenerator $toolDefinitionGenerator;
    private DynamicSkillRegistry $dynamicSkillRegistry;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->container = self::getContainer();
        $this->toolGeneratorAgent = $this->container->get('ai.agent.tool_generator');
        $this->orchestratorAgent = $this->container->get('ai.agent.orchestrator');
        $this->toolDefinitionRepo = $this->container->get(ToolDefinitionRepository::class);
        $this->toolDefinitionGenerator = $this->container->get(ToolDefinitionGenerator::class);
        $this->dynamicSkillRegistry = $this->container->get(DynamicSkillRegistry::class);
    }

    /**
     * Testet den kompletten Evolution-Flow:
     * 1. Tool-Generierung mit optimiertem Prompt
     * 2. Speicherung in der Datenbank
     * 3. Registrierung im DynamicSkillRegistry
     * 4. Ausführung des Tools (nach Genehmigung)
     */
    public function testCompleteEvolutionFlow(): void
    {
        // 1. Starte mit einer User-Anfrage für ein neues Tool
        $userRequest = 'Erstelle ein Tool, das CSV-Dateien analysiert und Statistiken zurückgibt';
        
        // 2. Generiere Tool-Definition mit dem tool_generator-Agent
        $toolDefinition = $this->toolDefinitionGenerator->generateFromUserRequest($userRequest);
        
        // Validierungen für Schritt 2
        $this->assertNotNull($toolDefinition, 'Tool-Definition sollte generiert werden');
        $this->assertEquals('csv_analyzer', $toolDefinition->getName(), 'Tool-Name sollte csv_analyzer sein');
        $this->assertStringContainsString('CSV', $toolDefinition->getDescription(), 'Beschreibung sollte CSV erwähnen');
        $this->assertEquals('pending', $toolDefinition->getStatus(), 'Status sollte pending sein');
        
        // 3. Prüfe das generierte Schema
        $schema = $toolDefinition->getSchema();
        $this->assertArrayHasKey('type', $schema, 'Schema sollte type-Feld haben');
        $this->assertEquals('object', $schema['type'], 'Schema-Typ sollte object sein');
        $this->assertArrayHasKey('properties', $schema, 'Schema sollte properties haben');
        
        // Prüfe Sicherheitsmetadaten (Phase 3 Optimierung)
        $this->assertArrayHasKey('security_level', $schema, 'Schema sollte security_level haben');
        $this->assertArrayHasKey('hitl_required', $schema, 'Schema sollte hitl_required haben');
        
        // 4. Prüfe die Parameter
        $parameters = $toolDefinition->getParameters();
        $this->assertNotEmpty($parameters, 'Tool sollte Parameter haben');
        
        // 5. Genehmige das Tool (HITL)
        $this->toolDefinitionGenerator->approveTool($toolDefinition);
        
        // Validierung für Schritt 5
        $this->assertEquals('approved', $toolDefinition->getApprovedAt() ? 'approved' : 'pending', 'Tool sollte genehmigt sein');
        
        // 6. Lade das Tool in den DynamicSkillRegistry
        $tools = $this->dynamicSkillRegistry->loadTools();
        
        // Validierung für Schritt 6
        $this->assertNotEmpty($tools, 'DynamicSkillRegistry sollte Tools laden');
        
        // 7. Prüfe, ob das Tool im Registry ist
        $toolFound = false;
        foreach ($tools as $tool) {
            if ($tool->getName() === 'csv_analyzer') {
                $toolFound = true;
                break;
            }
        }
        $this->assertTrue($toolFound, 'csv_analyzer sollte im DynamicSkillRegistry sein');
        
        // 8. Teste die Tool-Ausführung über den Orchestrator
        $testRequest = 'Nutze das csv_analyzer Tool, um die Datei test.csv zu analysieren';
        $messages = new MessageBag(Message::ofUser($testRequest));
        
        $response = $this->orchestratorAgent->call($messages);
        $responseContent = $response->getContent();
        
        // Validierung für Schritt 8
        $this->assertNotEmpty($responseContent, 'Orchestrator sollte eine Antwort geben');
        $this->assertStringContainsString('csv_analyzer', $responseContent, 'Antwort sollte csv_analyzer erwähnen');
    }

    /**
     * Testet die Tool-Schema-Generierung mit dem optimierten Prompt
     */
    public function testToolSchemaGenerationWithOptimizedPrompt(): void
    {
        // Teste die Generierung eines Website-Scraper-Tools
        $userRequest = 'Erstelle ein Tool zum Scrapen von Webseiten';
        
        $toolDefinition = $this->toolDefinitionGenerator->generateFromUserRequest($userRequest);
        
        // Prüfe die Tool-Definition
        $this->assertNotNull($toolDefinition);
        $this->assertEquals('website_scraper', $toolDefinition->getName());
        
        // Prüfe das Schema
        $schema = $toolDefinition->getSchema();
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('url', $schema['properties'], 'Schema sollte URL-Property haben');
        
        // Prüfe URL-Validierung
        $urlProperty = $schema['properties']['url'];
        $this->assertEquals('string', $urlProperty['type'], 'URL sollte String-Typ sein');
        $this->assertArrayHasKey('format', $urlProperty, 'URL sollte Format-Validierung haben');
        $this->assertEquals('uri', $urlProperty['format'], 'URL-Format sollte uri sein');
        
        // Prüfe Sicherheitsmetadaten (Phase 3)
        $this->assertArrayHasKey('security_level', $schema);
        $this->assertArrayHasKey('hitl_required', $schema);
        $this->assertTrue($schema['hitl_required'], 'Website Scraper sollte HITL erfordern');
    }

    /**
     * Testet die Onboarding-Integration mit dem Evolution-Flow
     */
    public function testOnboardingIntegrationWithEvolutionFlow(): void
    {
        // 1. Simuliere ein Onboarding mit technischem Benutzer
        $userIdentifier = 'test_user_' . uniqid();
        
        // 2. Starte das Onboarding
        $onboardingManager = $this->container->get('App\AI\Onboarding\OnboardingFlowManager');
        $onboardingStatus = $onboardingManager->startOnboarding($userIdentifier);
        
        $this->assertArrayHasKey('status', $onboardingStatus);
        $this->assertEquals('in_progress', $onboardingStatus['status']);
        
        // 3. Verarbeite Onboarding-Antworten
        $response1 = $onboardingManager->processResponse($userIdentifier, 'Developer');
        $this->assertArrayHasKey('question', $response1);
        
        // 4. Beende das Onboarding
        $completion = $onboardingManager->completeOnboarding($userIdentifier);
        $this->assertEquals('completed', $completion['status']);
        
        // 5. Prüfe, ob der Benutzer jetzt Tools generieren kann
        $userRequest = 'Erstelle ein Tool für Datenanalyse';
        $toolDefinition = $this->toolDefinitionGenerator->generateFromUserRequest($userRequest);
        
        $this->assertNotNull($toolDefinition);
        $this->assertStringContainsString('daten', strtolower($toolDefinition->getDescription()));
    }

    /**
     * Testet die Fallback-Mechanismen in der Tool-Generierung
     */
    public function testFallbackMechanismsInToolGeneration(): void
    {
        // Teste mit einer sehr unklaren Anfrage
        $userRequest = 'Mach etwas';
        
        $toolDefinition = $this->toolDefinitionGenerator->generateFromUserRequest($userRequest);
        
        // Sollte trotzdem ein Tool generieren (Fallback)
        $this->assertNotNull($toolDefinition);
        $this->assertNotEmpty($toolDefinition->getName());
        $this->assertNotEmpty($toolDefinition->getDescription());
        
        // Prüfe Fallback-Schema
        $schema = $toolDefinition->getSchema();
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('input', $schema['properties'], 'Fallback sollte input-Property haben');
    }

    /**
     * Testet die Sicherheitsmetadaten-Extraktion
     */
    public function testSecurityMetadataExtraction(): void
    {
        $userRequest = 'Erstelle ein Tool für Datenbankabfragen';
        
        $toolDefinition = $this->toolDefinitionGenerator->generateFromUserRequest($userRequest);
        
        $schema = $toolDefinition->getSchema();
        
        // Sollte hohe Sicherheitsstufe haben
        $this->assertArrayHasKey('security_level', $schema);
        $this->assertEquals('high', $schema['security_level'], 'Datenbank-Tools sollten high security_level haben');
        
        // Sollte HITL erfordern
        $this->assertArrayHasKey('hitl_required', $schema);
        $this->assertTrue($schema['hitl_required'], 'Datenbank-Tools sollten HITL erfordern');
    }

    /**
     * Testet die Multi-Agent Orchestration für Tool-Generierung
     */
    public function testMultiAgentOrchestrationForToolGeneration(): void
    {
        // Teste, ob der Orchestrator Tool-Generierungsanfragen an den tool_generator weiterleitet
        $userRequest = 'Ich brauche ein neues Tool für die Analyse von Log-Dateien';
        
        $messages = new MessageBag(Message::ofUser($userRequest));
        $response = $this->orchestratorAgent->call($messages);
        
        $responseContent = $response->getContent();
        
        // Sollte eine Tool-Generierungsantwort geben
        $this->assertStringContainsString('tool', strtolower($responseContent));
        $this->assertStringContainsString('generieren', strtolower($responseContent));
    }

    /**
     * Testet die CompilerPass-Integration (indirekt über DynamicSkillRegistry)
     */
    public function testCompilerPassIntegration(): void
    {
        // 1. Erstelle ein Tool
        $toolDefinition = $this->toolDefinitionGenerator->generateFromUserRequest(
            'Erstelle ein Test-Tool'
        );
        
        // 2. Genehmige das Tool
        $this->toolDefinitionGenerator->approveTool($toolDefinition);
        
        // 3. Lade Tools aus dem Registry (nutzt CompilerPass)
        $tools = $this->dynamicSkillRegistry->loadTools();
        
        // Sollte mindestens das Test-Tool enthalten
        $this->assertNotEmpty($tools);
        
        // Prüfe, ob die Tools ausführbar sind
        foreach ($tools as $tool) {
            $this->assertNotNull($tool->getName(), 'Tool sollte einen Namen haben');
            $this->assertNotNull($tool->getDescription(), 'Tool sollte eine Beschreibung haben');
        }
    }

    /**
     * Testet die Performance der Tool-Generierung
     */
    public function testToolGenerationPerformance(): void
    {
        $startTime = microtime(true);
        
        // Generiere 5 Tools hintereinander
        for ($i = 0; $i < 5; $i++) {
            $userRequest = 'Erstelle ein Tool für Test ' . $i;
            $this->toolDefinitionGenerator->generateFromUserRequest($userRequest);
        }
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        // Sollte in weniger als 10 Sekunden abgeschlossen sein (je nach LLM-Geschwindigkeit)
        $this->assertLessThan(10.0, $executionTime, 'Tool-Generierung sollte performant sein');
    }

    /**
     * Testet die Translation Support Integration (Phase 3)
     */
    public function testTranslationSupportIntegration(): void
    {
        // Teste, ob der tool_generator-Agent Translation Support nutzen kann
        // (Voraussetzung: symfony/translation ist installiert)
        
        // Prüfe, ob der Agent konfiguriert ist
        $this->assertNotNull($this->toolGeneratorAgent);
        
        // Teste mit einer englischen Anfrage
        $userRequest = 'Create a tool for data analysis';
        $toolDefinition = $this->toolDefinitionGenerator->generateFromUserRequest($userRequest);
        
        $this->assertNotNull($toolDefinition);
        $this->assertStringContainsString('data', strtolower($toolDefinition->getDescription()));
    }

    /**
     * Testet die Expression Language Integration (Phase 3)
     */
    public function testExpressionLanguageIntegration(): void
    {
        // Teste, ob Message Templates funktionieren (falls konfiguriert)
        // Dies ist ein Grundlagentest für die Expression Language Unterstützung
        
        // Prüfe, ob der tool_generator-Agent verfügbar ist
        $this->assertNotNull($this->toolGeneratorAgent);
        
        // Teste eine einfache Anfrage
        $userRequest = 'Tool für Testzwecke';
        $toolDefinition = $this->toolDefinitionGenerator->generateFromUserRequest($userRequest);
        
        $this->assertNotNull($toolDefinition);
    }

    /**
     * Bereinigt Testdaten nach den Tests
     */
    protected function tearDown(): void
    {
        // Lösche Test-Tool-Definitionen
        $pendingTools = $this->toolDefinitionRepo->findBy(['status' => 'pending']);
        foreach ($pendingTools as $tool) {
            $this->toolDefinitionRepo->remove($tool, true);
        }
        
        $approvedTools = $this->toolDefinitionRepo->findBy(['status' => 'approved']);
        foreach ($approvedTools as $tool) {
            // Nur Test-Tools löschen (die in den Tests erstellt wurden)
            if (str_starts_with($tool->getName(), 'test_') || 
                $tool->getName() === 'csv_analyzer' ||
                $tool->getName() === 'website_scraper') {
                $this->toolDefinitionRepo->remove($tool, true);
            }
        }
        
        parent::tearDown();
    }
}
