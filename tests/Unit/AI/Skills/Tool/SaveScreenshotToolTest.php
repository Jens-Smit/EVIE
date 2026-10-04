<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Tool;

use App\AI\Skills\Tool\SaveScreenshotTool;
use App\Entity\Document;
use App\Entity\DocumentAsset;
use App\Repository\DocumentRepository;
use App\Service\DocumentAssetStorageService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

/**
 * Unit-Tests fuer SaveScreenshotTool (Luecke 2 Fixplan Rev. 2):
 * Screenshot-Persistierung mit Vision-Alt-Text, input_from-Bildauflösung
 * und sprechenden Fehlern (kein Stub-Erfolg).
 */
final class SaveScreenshotToolTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private PlatformInterface&MockObject $platform;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->platform = $this->createMock(PlatformInterface::class);
        $this->projectDir = sys_get_temp_dir() . '/evie_screenshot_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectDir)) {
            exec('rm -rf ' . escapeshellarg($this->projectDir));
        }
    }

    public function testStoresScreenshotWithVisionAltText(): void
    {
        $document = new Document();
        $this->documentRepository->method('find')->willReturn($document);
        $this->platform->method('invoke')->willReturn($this->deferredText('Login-Maske der Anwendung mit E-Mail-Feld'));

        $tool = $this->buildTool();
        $result = $tool([
            'document_id' => 42,
            'image_base64' => base64_encode($this->pngBytes()),
            'section_ref' => 'login',
        ]);

        self::assertSame('success', $result['status']);
        self::assertSame('Login-Maske der Anwendung mit E-Mail-Feld', $result['alt_text']);
        self::assertSame('login', $result['section_ref']);
        self::assertMatchesRegularExpression('#^<!-- section: login -->!\[Login-Maske der Anwendung mit E-Mail-Feld\]\(/documents/assets/.+#', $result['markdown']);
        self::assertFileExists($this->projectDir . '/var/' . $result['file_path']);
        self::assertCount(1, $document->getAssets());
    }

    public function testPrefersExplicitAltTextOverVision(): void
    {
        $this->documentRepository->method('find')->willReturn(new Document());
        $this->platform->expects(self::never())->method('invoke');

        $tool = $this->buildTool();
        $result = $tool([
            'document_id' => 42,
            'image_base64' => base64_encode($this->pngBytes()),
            'alt_text' => 'Expliziter Alternativtext',
        ]);

        self::assertSame('Expliziter Alternativtext', $result['alt_text']);
    }

    public function testResolvesImageFromInputFromPayload(): void
    {
        $this->documentRepository->method('find')->willReturn(new Document());
        $this->platform->method('invoke')->willReturn($this->deferredText('Beschreibung'));

        $tool = $this->buildTool();
        $result = $tool([
            'document_id' => 42,
            'input_from' => [
                'browser_take_screenshot' => [
                    'content' => ['data' => 'data:image/png;base64,' . base64_encode($this->pngBytes())],
                ],
            ],
        ]);

        self::assertSame('success', $result['status']);
        self::assertFileExists($this->projectDir . '/var/' . $result['file_path']);
    }

    public function testFallsBackToGenericAltTextWhenVisionFails(): void
    {
        $this->documentRepository->method('find')->willReturn(new Document());
        $this->platform->method('invoke')->willThrowException(new \RuntimeException('Vision nicht verfuegbar'));

        $tool = $this->buildTool();
        $result = $tool([
            'document_id' => 42,
            'image_base64' => base64_encode($this->pngBytes()),
            'section_ref' => 'login',
        ]);

        self::assertSame('success', $result['status']);
        self::assertSame('Screenshot login', $result['alt_text']);
    }

    public function testFailsWhenDocumentMissing(): void
    {
        $this->documentRepository->method('find')->willReturn(null);

        $tool = $this->buildTool();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dokument mit ID 42 nicht gefunden');
        $tool(['document_id' => 42, 'image_base64' => base64_encode($this->pngBytes())]);
    }

    public function testFailsWhenNoImageData(): void
    {
        $this->documentRepository->method('find')->willReturn(new Document());

        $tool = $this->buildTool();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Keine Base64-Bilddaten');
        $tool(['document_id' => 42]);
    }

    private function buildTool(): SaveScreenshotTool
    {
        $assetRepository = $this->createMock(\App\Repository\DocumentAssetRepository::class);
        $assetRepository->method('save')->willReturnCallback(
            static function (DocumentAsset $asset): void {
                $reflection = new \ReflectionProperty(DocumentAsset::class, 'id');
                $reflection->setAccessible(true);
                $reflection->setValue($asset, 7);
            }
        );

        return new SaveScreenshotTool(
            $this->documentRepository,
            new DocumentAssetStorageService($assetRepository, $this->projectDir, new NullLogger()),
            $this->platform,
            new NullLogger(),
        );
    }

    private function pngBytes(): string
    {
        return "\x89PNG\r\n\x1a\n" . str_repeat('bilddaten', 20);
    }

    private function deferredText(string $text): DeferredResult
    {
        return new DeferredResult(
            new \Symfony\AI\Platform\Test\MockResultConverter(),
            new \Symfony\AI\Platform\Result\InMemoryRawResult(
                ['text' => $text],
                [],
                new \Symfony\AI\Platform\Result\TextResult($text),
            ),
        );
    }
}
