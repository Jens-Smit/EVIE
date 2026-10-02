<?php

declare(strict_types=1);

namespace App\AI\Skills\Tool;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use App\Service\DocumentAssetStorageService;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Speichert einen Screenshot als DocumentAsset (Luecke 2 Fixplan Rev. 2).
 *
 * Der Planner kann z.B. nach einem mcp_tool_executor-Schritt
 * (playwright: browser_take_screenshot) einen save_screenshot-Schritt
 * planen, der das Base64-Bild aus den Vorergebnissen via input_from
 * uebernimmt. Das Tool:
 *  1. beschreibt den Screenshot inhaltlich (Vision: multimodaler
 *     Modellaufruf ueber die Platform, Symfony-AI-natives Image-Content)
 *  2. speichert die Datei + DocumentAsset-Entity (Alt-Text, Abschnitt,
 *     Position)
 *  3. liefert eine Markdown-Bild-Referenz, die der content_synthesizer
 *     bzw. das strategy_document-Tool in den Dokumentinhalt montiert.
 *
 * Registry: natives #[AsTool] => ai.tool-Tag => ToolRegistry/
 * AttributeToolAdapter (Phase 3/5), keine Konstruktor-Injection.
 */
#[AsTool(
    name: 'save_screenshot',
    description: 'Speichert einen Base64-Screenshot (z.B. aus playwright browser_take_screenshot via input_from) als DocumentAsset am Dokument und liefert eine Markdown-Bild-Referenz inkl. inhaltlicher Bildbeschreibung. Parameter: document_id (ID eines bereits gespeicherten Dokuments), image_base64 (Base64-Bild; alternativ via input_from), alt_text (optionaler Alternativtext), section_ref (optionaler Abschnittsbezug), position (optionale Position im Dokument).'
)]
final class SaveScreenshotTool
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentAssetStorageService $storageService,
        private readonly PlatformInterface $platform,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(array $parameters = []): array
    {
        $documentId = (int) ($parameters['document_id'] ?? 0);
        $document = $this->documentRepository->find($documentId);
        if (!$document instanceof Document) {
            throw new \RuntimeException(sprintf('Dokument mit ID %d nicht gefunden: Der Screenshot kann nur an ein bereits gespeichertes Dokument angehaengt werden.', $documentId));
        }

        $imageBase64 = $this->resolveImageBase64($parameters);
        $altText = $this->resolveAltText($parameters, $imageBase64);

        $asset = $this->storageService->storeFromParameters($document, [
            'image_base64' => $imageBase64,
            'alt_text' => $altText,
            'section_ref' => $parameters['section_ref'] ?? null,
            'position' => $parameters['position'] ?? null,
        ]);

        $markdown = sprintf(
            "![%s](/%s)",
            str_replace(['[', ']'], '', $asset->getAltText() ?? 'Screenshot'),
            $asset->getFilePath()
        );
        if ($asset->getSectionRef() !== null) {
            $markdown = sprintf('<!-- section: %s -->%s', $asset->getSectionRef(), $markdown);
        }

        return [
            'status' => 'success',
            'asset_id' => $asset->getId(),
            'document_id' => $document->getId(),
            'file_path' => $asset->getFilePath(),
            'alt_text' => $asset->getAltText(),
            'section_ref' => $asset->getSectionRef(),
            'position' => $asset->getPosition(),
            'markdown' => $markdown,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function resolveImageBase64(array $parameters): string
    {
        $candidates = [
            $parameters['image_base64'] ?? null,
            $parameters['base64'] ?? null,
            $parameters['input_from'] ?? null,
            $parameters['input'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
            if (is_array($candidate)) {
                $nested = $this->findImageIn($candidate);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        throw new \RuntimeException('Keine Base64-Bilddaten gefunden: erwartete Parameter image_base64 oder input_from (Ergebnis des browser_take_screenshot-Schritts).');
    }

    /**
     * @param array<mixed> $payload
     */
    private function findImageIn(array $payload, int $depth = 0): ?string
    {
        if ($depth > 4) {
            return null;
        }
        foreach ($payload as $value) {
            if (is_string($value)
                && (str_starts_with($value, 'data:image/') || (preg_match('/^[A-Za-z0-9+\/]{100,}={0,2}$/', trim($value)) === 1))
            ) {
                return trim($value);
            }
            if (is_array($value)) {
                $nested = $this->findImageIn($value, $depth + 1);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * Nutzt den expliziten alt_text oder erzeugt eine inhaltliche
     * Beschreibung per multimodalem Modellaufruf (Vision).
     *
     * @param array<string, mixed> $parameters
     */
    private function resolveAltText(array $parameters, string $imageBase64): string
    {
        $explicit = $parameters['alt_text'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        try {
            $image = str_starts_with($imageBase64, 'data:')
                ? Image::fromDataUrl($imageBase64)
                : new Image(base64_decode($imageBase64, true) ?: '', 'image/png');
            $prompt = new \Symfony\AI\Platform\Message\Content\Text(
                'Beschreibe diesen Screenshot in 1-2 Saetzen fuer einen Alternativtext in einer Nutz-Dokumentation. Nenne die sichtbaren UI-Elemente und den Seiteninhalt.'
            );
            $messages = new \Symfony\AI\Platform\Message\MessageBag(
                Message::ofUser($image, $prompt)
            );
            $description = trim($this->platform->invoke('mistral-small-latest', $messages)->asText());
            if ($description !== '') {
                return $description;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Vision-Beschreibung fehlgeschlagen, falle auf generischen Alt-Text zurueck', [
                'error' => $e->getMessage(),
            ]);
        }

        return sprintf('Screenshot %s', $parameters['section_ref'] ?? '');
    }
}
