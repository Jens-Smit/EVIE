<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Document;
use App\Repository\DocumentAssetRepository;
use App\Service\DocumentAssetStorageService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit-Tests fuer DocumentAssetStorageService (Luecke 2 Fixplan Rev. 2):
 * Base64-Dekodierung (roh + Data-URL), Format-Erkennung per Magic Bytes,
 * Datei-Persistenz und DocumentAsset-Entity-Anlage.
 */
final class DocumentAssetStorageServiceTest extends TestCase
{
    private DocumentAssetRepository&MockObject $assetRepository;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->assetRepository = $this->createMock(DocumentAssetRepository::class);
        $this->projectDir = sys_get_temp_dir() . '/evie_asset_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectDir)) {
            exec('rm -rf ' . escapeshellarg($this->projectDir));
        }
    }

    public function testStoresPngScreenshotFromRawBase64(): void
    {
        $document = new Document();
        $binary = $this->pngBytes();
        $service = $this->buildService();

        $asset = $service->storeFromParameters($document, [
            'image_base64' => base64_encode($binary),
            'alt_text' => 'Dashboard-Uebersicht',
            'section_ref' => 'login',
            'position' => 3,
        ]);

        self::assertSame('png', $this->extensionOf($asset->getFilePath()));
        self::assertSame('Dashboard-Uebersicht', $asset->getAltText());
        self::assertSame('login', $asset->getSectionRef());
        self::assertSame(3, $asset->getPosition());
        self::assertSame($document, $asset->getDocument());
        self::assertFileExists($this->projectDir . '/var/' . $asset->getFilePath());
        self::assertSame($binary, file_get_contents($this->projectDir . '/var/' . $asset->getFilePath()));
    }

    public function testStoresDataUrlScreenshot(): void
    {
        $binary = $this->pngBytes();
        $service = $this->buildService();

        $asset = $service->storeFromParameters(new Document(), [
            'image_base64' => 'data:image/png;base64,' . base64_encode($binary),
        ]);

        self::assertSame('png', $this->extensionOf($asset->getFilePath()));
        self::assertFileExists($this->projectDir . '/var/' . $asset->getFilePath());
        self::assertSame($binary, file_get_contents($this->projectDir . '/var/' . $asset->getFilePath()));
    }

    public function testAutoIncrementsPositionWhenOmitted(): void
    {
        $document = new Document();
        $service = $this->buildService();

        $first = $service->storeFromParameters($document, ['image_base64' => base64_encode($this->pngBytes())]);
        self::assertSame(0, $first->getPosition());

        $document->addAsset($first);
        $second = $service->storeFromParameters($document, ['image_base64' => base64_encode($this->pngBytes())]);
        self::assertSame(1, $second->getPosition());
    }

    public function testRejectsEmptyBase64(): void
    {
        $service = $this->buildService();
        $this->expectException(\InvalidArgumentException::class);
        $service->storeFromParameters(new Document(), ['image_base64' => '   ']);
    }

    public function testRejectsInvalidBase64(): void
    {
        $service = $this->buildService();
        $this->expectException(\InvalidArgumentException::class);
        $service->storeFromParameters(new Document(), ['image_base64' => '!!kein-base64!!']);
    }

    public function testRejectsUnknownImageFormat(): void
    {
        $service = $this->buildService();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unbekanntes Bildformat');
        $service->storeFromParameters(new Document(), ['image_base64' => base64_encode('kein Bildinhalt')]);
    }

    private function buildService(): DocumentAssetStorageService
    {
        return new DocumentAssetStorageService(
            $this->assetRepository,
            $this->projectDir,
            new NullLogger(),
        );
    }

    private function pngBytes(): string
    {
        return "\x89PNG\r\n\x1a\n" . str_repeat('bilddaten', 20);
    }

    private function extensionOf(string $filePath): string
    {
        return strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    }
}
