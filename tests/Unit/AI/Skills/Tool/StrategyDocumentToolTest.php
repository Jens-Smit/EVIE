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
                'executive_summary' => 'Gastro-Dienstleister mit Wachstumspotenzial.',
                'market_analysis' => [
                    'summary' => 'Markt waechst mit 7% p.a.',
                    'trends' => 'Digitalisierung der Gastronomie.',
                ],
            ],
            'user_identifier' => 'user-1',
        ]);

        self::assertSame('success', $result['status']);
        self::assertSame('Strategy Document: business_plan', $result['document_name']);

        $document = $documentRepo->lastSaved;
        self::assertInstanceOf(Document::class, $document);
        self::assertStringContainsString('## Executive Summary', $document->getContent());
        self::assertStringContainsString('Gastro-Dienstleister mit Wachstumspotenzial.', $document->getContent());
        self::assertStringContainsString('## Market Analysis', $document->getContent());
        self::assertStringContainsString('### Summary', $document->getContent());
        self::assertStringContainsString('Markt waechst mit 7% p.a.', $document->getContent());
    }

    public function testAcceptsFlatScalarNameAndContent(): void
    {
        [$tool, $documentRepo] = $this->buildTool();

        $result = $tool([
            'name' => 'Businessplan Visiongastro',
            'content' => 'Volltext des Businessplans.',
            'user_identifier' => 'user-1',
        ]);

        self::assertSame('success', $result['status']);
        self::assertSame('Businessplan Visiongastro', $documentRepo->lastSaved->getName());
        self::assertSame('Volltext des Businessplans.', $documentRepo->lastSaved->getContent());
    }

    public function testUsesResolvedInputParameterAsContentFallback(): void
    {
        [$tool, $documentRepo] = $this->buildTool();

        $result = $tool([
            'template' => 'business_plan',
            'input' => 'Marktanalyse: Gastro-Markt waechst mit 7% p.a.',
            'sections' => ['Executive Summary', 'Marktanalyse'],
            'user_identifier' => 'user-1',
        ]);

        self::assertSame('success', $result['status']);
        self::assertSame('Strategy Document: business_plan', $documentRepo->lastSaved->getName());
        self::assertSame(
            'Marktanalyse: Gastro-Markt waechst mit 7% p.a.',
            $documentRepo->lastSaved->getContent()
        );
    }

    public function testSerializesArrayInputParameterAsMarkdown(): void
    {
        [$tool, $documentRepo] = $this->buildTool();

        $result = $tool([
            'template' => 'business_plan',
            'input' => ['executive_summary' => 'Wachstumsfaehiger Gastro-Dienstleister.'],
            'user_identifier' => 'user-1',
        ]);

        self::assertSame('success', $result['status']);
        self::assertStringContainsString('## Executive Summary', $documentRepo->lastSaved->getContent());
        self::assertStringContainsString('Wachstumsfaehiger Gastro-Dienstleister.', $documentRepo->lastSaved->getContent());
    }

    public function testMissingParametersYieldsSpeakingValidationMessage(): void
    {
        [$tool] = $this->buildTool();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Erhaltene Parameter-Keys: [template, input_from]');

        $tool([
            'template' => 'business_plan',
            'input_from' => ['business_model_analysis' => ['executive_summary' => '...']],
        ]);
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
