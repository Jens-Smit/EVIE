<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills;

use App\AI\Skills\ToolDefinitionGenerator;
use App\Entity\ToolCategory;
use App\Repository\ToolCategoryRepository;
use App\Repository\ToolDefinitionRepository;
use App\Tests\Stub\StubAgent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Unit-Tests fuer den ToolDefinitionGenerator (Blueprint §4.B / §5, Phase 3 M8).
 *
 * Verifiziert die Tool-Generierung inklusive des LLM-Abrufs ueber den
 * tool_generator-Agent. Der StubAgent ersetzt den echten Mistral-Aufruf
 * deterministisch, sodass der LLM-Abruf-Pfad (AgentInterface::call) vollstaendig
 * durchlaufen wird, ohne echte API-Kosten zu verursachen.
 *
 * Die Anzahl der LLM-Aufrufe wird assertionsgeprueft (minimiert: genau 1 Abruf
 * pro Generierung, kein Doppell-Abruf bei Treffer aehnlicher Tools).
 */
final class ToolDefinitionGeneratorTest extends TestCase
{
    private ToolDefinitionRepository&MockObject $toolDefinitionRepo;
    private ToolCategoryRepository&MockObject $toolCategoryRepo;
    private PlatformInterface&MockObject $platform;

    protected function setUp(): void
    {
        $this->toolDefinitionRepo = $this->createMock(ToolDefinitionRepository::class);
        $this->toolCategoryRepo = $this->createMock(ToolCategoryRepository::class);
        $this->platform = $this->createMock(PlatformInterface::class);
    }

    public function testGenerateToolDefinitionInvokesLlmOnceAndPersistsPendingTool(): void
    {
        $validSchema = json_encode([
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'URL'],
            ],
            'required' => ['url'],
        ], JSON_THROW_ON_ERROR);

        $agent = new StubAgent($validSchema);

        // Kein aehnliches Tool -> Generierung wird angestossen.
        $this->toolDefinitionRepo->method('findAll')->willReturn([]);
        $this->toolDefinitionRepo->expects(self::once())->method('save')
            ->willReturnCallback(function ($definition, $flush) {
                // Tool wird als pending gespeichert (HITL noetig vor Nutzung).
                self::assertSame('pending', $definition->getStatus());
                return null;
            });

        $generator = $this->buildGenerator($agent);

        $definition = $generator->generateToolDefinition(
            'web_scraper',
            'Scraped Webseiten und extrahiert Inhalte',
            ['original_request' => 'Scrape example.com']
        );

        // Genau 1 LLM-Abruf (minimiert).
        self::assertSame(1, $agent->getCallCount());
        self::assertSame('web_scraper', $definition->getName());
        self::assertSame('pending', $definition->getStatus());
        self::assertArrayHasKey('type', $definition->getSchema());
    }

    public function testSimilarToolReusedWithoutLlmCall(): void
    {
        // Beschreibungen muessen >70% Jaccard-Aehnlichkeit haben, damit
        // findSimilarTool ein Match findet (extractKeywords filtert Worte <=3 Zeichen).
        $existing = $this->createConfiguredToolDefinition('csv_analyzer', 'analysiert verkaufsdaten monatlich quartal');
        // findSimilarTool findet ein Match -> kein LLM-Abruf.
        $this->toolDefinitionRepo->method('findAll')->willReturn([$existing]);
        $this->toolDefinitionRepo->expects(self::never())->method('save');

        $agent = new StubAgent('{"type":"object","properties":[]}');
        $generator = $this->buildGenerator($agent);

        $definition = $generator->generateToolDefinition(
            'csv_processor',
            'analysiert verkaufsdaten monatlich quartal zusammenfassung'
        );

        // Kein LLM-Abruf, da existierendes Tool wiederverwendet wird.
        self::assertSame(0, $agent->getCallCount());
        self::assertSame($existing, $definition);
    }

    public function testInvalidSchemaFromAgentFallsBackToFallbackSchema(): void
    {
        // Der prim tool_generator-Agent-Pfad validiert das Schema (P0-2). Ein
        // invalides Schema (type fehlt) loest den Fallback aus. Da der Fallback
        // generateSchemaWithLLM() nutzt (Platform::invoke -> DeferredResult, final
        // und nicht mockbar), liefert der Fallback schliesslich createFallbackSchema()
        // ein valides Minimal-Schema. Wir verifizieren, dass trotzdem eine gueltige
        // ToolDefinition mit status=pending entsteht (Resilienz gegen LLM-Fehler).
        $invalidSchema = json_encode(['properties' => ['x' => ['type' => 'string']]], JSON_THROW_ON_ERROR);

        $agent = new StubAgent($invalidSchema);

        // Platform::invoke() im Fallback wirft (ohne echte API) -> createFallbackSchema.
        $this->platform->method('invoke')->willThrowException(new \RuntimeException('no real API'));

        $this->toolDefinitionRepo->method('findAll')->willReturn([]);
        $this->toolDefinitionRepo->expects(self::once())->method('save');

        $generator = $this->buildGenerator($agent);
        $definition = $generator->generateToolDefinition('bad_tool', 'Ein Test-Tool');

        self::assertSame('bad_tool', $definition->getName());
        self::assertSame('pending', $definition->getStatus());
        // Fallback-Schema ist type=object mit properties.input (createFallbackSchema).
        self::assertSame('object', $definition->getSchema()['type']);
    }

    public function testGeneratedToolHasSecurityMetadata(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => ['url' => ['type' => 'string']],
            'required' => ['url'],
            'security_level' => 'high',
            'hitl_required' => true,
        ], JSON_THROW_ON_ERROR);

        $agent = new StubAgent($schema);
        $this->toolDefinitionRepo->method('findAll')->willReturn([]);
        $this->toolDefinitionRepo->method('save');

        $generator = $this->buildGenerator($agent);
        $definition = $generator->generateToolDefinition('high_sec_tool', 'API-Integration');

        self::assertSame('high', $definition->getSecurityLevel());
        self::assertTrue($definition->getRequiresHitl());
    }

    public function testCategoryDeterminedFromDescription(): void
    {
        $category = new ToolCategory();
        $category->setName('data_analysis');

        $schema = json_encode([
            'type' => 'object',
            'properties' => ['data' => ['type' => 'string']],
        ], JSON_THROW_ON_ERROR);

        $agent = new StubAgent($schema);
        $this->toolDefinitionRepo->method('findAll')->willReturn([]);
        $this->toolDefinitionRepo->method('save');
        $this->toolCategoryRepo->method('findOneByName')->willReturn($category);

        $generator = $this->buildGenerator($agent);
        $definition = $generator->generateToolDefinition(
            'data_processor',
            'Datenanalyse und statistische Auswertung'
        );

        self::assertSame($category, $definition->getCategory());
    }

    public function testLlmPromptContainsStructuredRequest(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => ['x' => ['type' => 'string']],
        ], JSON_THROW_ON_ERROR);

        $agent = new StubAgent($schema);
        $this->toolDefinitionRepo->method('findAll')->willReturn([]);
        $this->toolDefinitionRepo->method('save');

        $generator = $this->buildGenerator($agent);
        $generator->generateToolDefinition('my_tool', 'Ein Tool', ['original_request' => 'Tu etwas']);

        $sent = $agent->getSentMessages();
        self::assertNotEmpty($sent);

        // Der Prompt muss strukturierte JSON-Anfrage enthalten (nicht bloss Freitext).
        $content = '';
        foreach ($sent[0]->getMessages() as $message) {
            $content .= $message->asText();
        }
        self::assertStringContainsString('tool_name', $content);
        self::assertStringContainsString('description', $content);
    }

    private function buildGenerator(StubAgent $agent): ToolDefinitionGenerator
    {
        return new ToolDefinitionGenerator(
            $this->toolDefinitionRepo,
            $this->toolCategoryRepo,
            $this->platform,
            $agent,
            new NullLogger()
        );
    }

private function createConfiguredToolDefinition(string $name, string $description): \App\Entity\ToolDefinition
    {
        return (new \App\Entity\ToolDefinition())
            ->setName($name)
            ->setDescription($description)
            ->setStatus('approved')
            ->setExecutorType('generic');
    }
}
