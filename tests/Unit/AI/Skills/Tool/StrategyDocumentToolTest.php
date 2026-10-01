<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Tool;

use App\AI\Skills\Tool\StrategyDocumentTool;
use App\Entity\Document;
use App\Entity\UserProfile;
use App\Repository\DocumentRepository;
use App\Repository\UserProfileRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer StrategyDocumentTool.
 *
 * Deckt den Log-Fall "create_strategy_document" ab: Der Orchestrator
 * uebergibt template + verschachteltes content-Objekt statt flacher
 * skalarer name/content-Parameter. Das Tool leitet name aus template ab
 * und serialisiert das content-Objekt als Markdown.
 */
final class StrategyDocumentToolTest extends TestCase
{
    public function testAcceptsNestedContentObjectAndDerivesNameFromTemplate(): void
    {
        [$tool, $documentRepo] = $this->buildTool();

        $result = $tool([
            'template' => 'business_plan',
            'content' => [
                'executive_summary' => str_repeat('Gastro-Dienstleister mit Wachstumspotenzial und klarer Zielgruppe. ', 5),
                'market_analysis' => [
                    'summary' => str_repeat('Markt waechst mit 7% p.a. ', 20),
                    'trends' => str_repeat('Digitalisierung der Gastronomie. ', 10),
                ],
            ],
            'user_identifier' => 'user-1',
        ]);

        self::assertSame('success', $result['status']);
        self::assertSame('Strategy Document: business_plan', $result['document_name']);

        $document = $documentRepo->lastSaved;
        self::assertInstanceOf(Document::class, $document);
        self::assertStringContainsString('## Executive Summary', $document->getContent());
        self::assertStringContainsString('Gastro-Dienstleister mit Wachstumspotenzial und klarer Zielgruppe.', $document->getContent());
        self::assertStringContainsString('## Market Analysis', $document->getContent());
        self::assertStringContainsString('### Summary', $document->getContent());
        self::assertStringContainsString('Markt waechst mit 7% p.a.', $document->getContent());
    }

    public function testAcceptsFlatScalarNameAndContent(): void
    {
        [$tool, $documentRepo] = $this->buildTool();

        $result = $tool([
            'name' => 'Businessplan Visiongastro',
            'content' => str_repeat('Volltext des Businessplans mit ausformulierten Abschnitten. ', 10),
            'user_identifier' => 'user-1',
        ]);

        self::assertSame('success', $result['status']);
        self::assertSame('Businessplan Visiongastro', $documentRepo->lastSaved->getName());
        self::assertSame(trim(str_repeat('Volltext des Businessplans mit ausformulierten Abschnitten. ', 10)), $documentRepo->lastSaved->getContent());
    }

    public function testUsesResolvedInputParameterAsContentFallback(): void
    {
        [$tool, $documentRepo] = $this->buildTool();

        $result = $tool([
            'template' => 'business_plan',
            'input' => str_repeat('Marktanalyse: Gastro-Markt waechst mit 7% p.a. ', 12),
            'sections' => ['Executive Summary', 'Marktanalyse'],
            'user_identifier' => 'user-1',
        ]);

        self::assertSame('success', $result['status']);
        self::assertSame('Strategy Document: business_plan', $documentRepo->lastSaved->getName());
        self::assertSame(
            trim(str_repeat('Marktanalyse: Gastro-Markt waechst mit 7% p.a. ', 12)),
            $documentRepo->lastSaved->getContent()
        );
    }

    public function testSerializesArrayInputParameterAsMarkdown(): void
    {
        [$tool, $documentRepo] = $this->buildTool();

        $result = $tool([
            'template' => 'business_plan',
            'input' => ['executive_summary' => str_repeat('Wachstumsfaehiger Gastro-Dienstleister mit stabilem Markt. ', 10)],
            'user_identifier' => 'user-1',
        ]);

        self::assertSame('success', $result['status']);
        self::assertStringContainsString('## Executive Summary', $documentRepo->lastSaved->getContent());
        self::assertStringContainsString('Wachstumsfaehiger Gastro-Dienstleister mit stabilem Markt.', $documentRepo->lastSaved->getContent());
    }

    public function testMissingParametersYieldsSpeakingValidationMessage(): void
    {
        [$tool] = $this->buildTool();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Erhaltene Parameter-Keys: [template, input_from]');

        $tool([
            'template' => 'business_plan',
            'input_from' => [],
        ]);
    }

    public function testUsesInputFromAsStringContentWhenContentMissing(): void
    {
        [$tool, $documentRepo] = $this->buildTool();
        $synthesized = trim(str_repeat('Ausformulierter Fachtext zum Markt. ', 20));
        $result = $tool([
            'name' => 'Businessplan Vision Gastro',
            'input_from' => $synthesized,
            'user_identifier' => 'user-1',
        ]);
        self::assertSame('success', $result['status']);
        self::assertSame(trim($synthesized), $documentRepo->lastSaved->getContent());
        self::assertSame(Document::STATUS_COMPLETED, $documentRepo->lastSaved->getStatus());
    }

    public function testPrefersExplicitContentOverInputFrom(): void
    {
        [$tool, $documentRepo] = $this->buildTool();
        $explicit = trim(str_repeat('Bewusst uebergebener Volltext. ', 20));
        $result = $tool([
            'name' => 'Dokument',
            'content' => $explicit,
            'input_from' => 'Vorergebnis, das ignoriert werden soll.',
            'user_identifier' => 'user-1',
        ]);
        self::assertSame('success', $result['status']);
        self::assertSame(trim($explicit), $documentRepo->lastSaved->getContent());
    }

    public function testUsesInputFromArrayAsMarkdownContent(): void
    {
        [$tool, $documentRepo] = $this->buildTool();
        $sectionText = trim(str_repeat('Ausformulierte Analyse des Gastro-Markts. ', 15));
        $result = $tool([
            'name' => 'Marktanalyse',
            'input_from' => ['market_analysis' => $sectionText],
            'user_identifier' => 'user-1',
        ]);
        self::assertSame('success', $result['status']);
        self::assertStringContainsString('## Market Analysis', $documentRepo->lastSaved->getContent());
        self::assertStringContainsString(trim($sectionText), $documentRepo->lastSaved->getContent());
    }

    public function testShortStubContentIsStoredAsDraftAndFails(): void
    {
        [$tool, $documentRepo] = $this->buildTool();
        try {
            $tool([
                'name' => 'Businessplan Stub',
                'input_from' => 'Kurzer Stub-Text.',
                'user_identifier' => 'user-1',
            ]);
            self::fail('Erwartete RuntimeException wegen zu kurzen Inhalts.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('zu kurz', $e->getMessage());
        }
        self::assertSame(Document::STATUS_DRAFT, $documentRepo->lastSaved->getStatus());
    }

    public function testPlaceholderContentIsRejected(): void
    {
        [$tool, $documentRepo] = $this->buildTool();
        $stub = str_repeat('[TBD] ', 120);
        try {
            $tool([
                'name' => 'Businessplan Stub',
                'input_from' => $stub,
                'user_identifier' => 'user-1',
            ]);
            self::fail('Erwartete RuntimeException wegen Platzhaltern.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Platzhalter', $e->getMessage());
        }
        self::assertSame(Document::STATUS_DRAFT, $documentRepo->lastSaved->getStatus());
    }

    public function testHeadingWithoutBodyTextIsRejected(): void
    {
        [$tool, $documentRepo] = $this->buildTool();
        $stub = "## Marktanalyse\n\n" . str_repeat('Ausformulierter Text. ', 30) . "\n\n## Finanzplan\n\n## Wettbewerbsanalyse";
        try {
            $tool([
                'name' => 'Businessplan Stub',
                'input_from' => $stub,
                'user_identifier' => 'user-1',
            ]);
            self::fail('Erwartete RuntimeException wegen Ueberschrift ohne Fliesstext.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Ueberschrift ohne Fliesstext', $e->getMessage());
        }
        self::assertSame(Document::STATUS_DRAFT, $documentRepo->lastSaved->getStatus());
    }

    /**
     * @return array{0: StrategyDocumentTool, 1: RecordingDocumentRepository}
     */
    private function buildTool(): array
    {
        $userProfile = new UserProfile();
        $userProfile->setUserIdentifier('user-1');

        $userProfileRepo = $this->createMock(UserProfileRepository::class);
        $userProfileRepo->method('findOneBy')->willReturn($userProfile);

        $documentRepo = new RecordingDocumentRepository();
        $documentRepo->autoIncrement = 42;

        return [new StrategyDocumentTool($documentRepo, $userProfileRepo), $documentRepo];
    }
}

final class RecordingDocumentRepository extends DocumentRepository
{
    public ?Document $lastSaved = null;
    public int $autoIncrement = 0;

    public function __construct()
    {
        // Kein EntityManager noetig; nur save() wird aufgezeichnet.
    }

    public function save(object $document, bool $flush = false): void
    {
        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($document, $this->autoIncrement);
        $this->lastSaved = $document;
    }
}
