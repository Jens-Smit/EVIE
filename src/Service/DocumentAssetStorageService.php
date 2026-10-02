<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentAsset;
use App\Repository\DocumentAssetRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use function Symfony\Component\String\u;

/**
 * Speichert Bild-/Screenshot-Assets (Luecke 2 Fixplan Rev. 2).
 *
 * Playwright-MCP liefert Screenshots als Base64-Bild. Dieser Service
 * dekodiert die Daten (Base64-String oder Data-URL), schreibt sie als
 * Datei unter documents/assets/ und legt eine DocumentAsset-Entity
 * mit Pfad, Alternativtext, Abschnittsbezug und Position an, sodass
 * der Screenshot persistent und dokumentiert abgelegt wird.
 */
final class DocumentAssetStorageService
{
    private const ALLOWED_FORMATS = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    public function __construct(
        private readonly DocumentAssetRepository $assetRepository,
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Speichert einen Base64-Screenshot (Data-URL oder roher Base64-String)
     * als Datei und verknuepft ihn mit dem Dokument.
     *
     * @param array<string, mixed> $parameters Keys: image_base64, alt_text,
     *                                          section_ref, position, format
     */
    public function storeFromParameters(Document $document, array $parameters): DocumentAsset
    {
        $imageData = $this->decodeBase64((string) ($parameters['image_base64'] ?? ($parameters['base64'] ?? '')));
        $format = $this->resolveFormat($parameters['format'] ?? null, $imageData);

        $relativeDir = 'documents/assets/' . ($document->getId() ?? 'doc_' . bin2hex(random_bytes(4)));
        $absoluteDir = $this->projectDir . '/var/' . $relativeDir;
        $filename = 'asset_' . bin2hex(random_bytes(8)) . '.' . $format;

        $filesystem = new Filesystem();
        $filesystem->mkdir($absoluteDir);
        $filesystem->dumpFile($absoluteDir . '/' . $filename, $imageData);

        $asset = new DocumentAsset();
        $asset->setFilePath($relativeDir . '/' . $filename);
        $asset->setAltText($this->normalizeText($parameters['alt_text'] ?? null));
        $asset->setSectionRef($this->normalizeText($parameters['section_ref'] ?? null));
        $asset->setPosition((int) ($parameters['position'] ?? $this->nextPosition($document)));
        $document->addAsset($asset);

        $this->assetRepository->save($asset, true);
        $this->logger->info('Document-Asset gespeichert', [
            'document_id' => $document->getId(),
            'file' => $asset->getFilePath(),
            'bytes' => strlen($imageData),
        ]);

        return $asset;
    }

    /**
     * Dekodiert Base64-Bilddaten; akzeptiert rohes Base64 und Data-URLs
     * (data:image/png;base64,...), wie sie Playwright-MCP liefert.
     */
    private function decodeBase64(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('image_base64 ist leer: ohne Bilddaten kann kein Screenshot gespeichert werden.');
        }
        if (str_starts_with($trimmed, 'data:')) {
            $after = u($trimmed)->after('base64,');
            if ($after->toString() === '') {
                throw new \InvalidArgumentException('image_base64 Data-URL enthaelt keine Base64-Bilddaten.');
            }
            $trimmed = $after->toString();
        }
        $decoded = base64_decode($trimmed, true);
        if ($decoded === false || $decoded === '') {
            throw new \InvalidArgumentException('image_base64 enthaelt keine gueltigen Base64-Bilddaten.');
        }

        return $decoded;
    }

    private function resolveFormat(mixed $declared, string $binary): string
    {
        if (is_string($declared) && isset(self::ALLOWED_FORMATS[strtolower(trim($declared))])) {
            return strtolower(trim($declared));
        }
        $magic = substr($binary, 0, 12);
        if (str_starts_with($magic, "\x89PNG")) {
            return 'png';
        }
        if (str_starts_with(substr($binary, 0, 3), "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($magic, 'RIFF') && substr($binary, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (str_starts_with($magic, 'GIF8')) {
            return 'gif';
        }

        throw new \InvalidArgumentException('Unbekanntes Bildformat: nur PNG, JPEG, GIF und WebP sind erlaubt.');
    }

    private function nextPosition(Document $document): int
    {
        return $document->getAssets()->count();
    }

    private function normalizeText(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim(mb_substr($value, 0, 1000));
    }
}
