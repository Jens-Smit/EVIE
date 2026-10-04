<?php

declare(strict_types=1);

namespace App\AI\Skills\Tool;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use App\Repository\UserProfileRepository;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Statisches Tool zum Speichern und Aktualisieren von Strategiedokumenten.
 *
 * Ermöglicht dem Orchestrator, ein Strategiedokument/Businessplan als
 * persistente Document-Entity anzulegen. Das Tool ist nativ als #[AsTool]
 * registriert (kein Konstruktor-Injection für Tools, Blueprint §4.D) und
 * wird über die native Toolbox des Orchestrator-Agenten aufgerufen.
 *
 * SecurityGuard/HITL: das Tool greift nicht auf externe Systeme zu und ist
 * als low-security eingestuft. Die Document-Entity ist pro User/Profile
 * isoliert (Tenant-Isolation).
 */
#[AsTool(
    name: 'strategy_document',
    description: 'Speichert ein Dokument (z.B. Businessplan, Marketingplan, Marktanalyse, Strategieplan) als persistente Document-Entity. Parameter: name (Dokumentname, optional; wird aus template abgeleitet, wenn fehlend), template (optionaler Dokumenttyp als Namensbestandteil). Der Inhalt kommt bevorzugt aus den Vorergebnissen via input_from (z.B. der Output des content_synthesizer); ein content-Parameter ist nur fuer bewusst uebergebenen Volltext vorgesehen.'
)]
final class StrategyDocumentTool
{
    private const MIN_CONTENT_LENGTH = 500;

    public function __construct(
        private DocumentRepository $documentRepository,
        private UserProfileRepository $userProfileRepository,
    ) {
    }

    public function __invoke(array $parameters = []): array
    {
        $name = $this->resolveName($parameters);
        $content = $this->normalizeMarkdown($this->resolveContent($parameters));
        $userIdentifier = $parameters['user_identifier'] ?? '';

        if ($name === '' || $content === '') {
            throw new \RuntimeException(sprintf(
                'Parameter name und content sind erforderlich. Erwartet werden flache, skalare Parameter '
                . '"name" (string) und "content" (string) oder alternativ ein strukturiertes '
                . '"content"-Objekt mit Abschnitten wie executive_summary, company_description, '
                . 'market_analysis. Erhaltene Parameter-Keys: [%s].',
                implode(', ', array_keys($parameters))
            ));
        }

        $userProfile = $this->userProfileRepository->findOneBy(['userIdentifier' => $userIdentifier]);
        if ($userProfile === null) {
            throw new \RuntimeException(sprintf('UserProfile fuer user_identifier "%s" nicht gefunden.', $userIdentifier));
        }

        $qualityIssue = $this->validateQuality($content);

        $document = new Document();
        $document->setName($name);
        $document->setContent($content);
        $document->setUser($userProfile);
        $document->setStatus($qualityIssue === null ? Document::STATUS_COMPLETED : Document::STATUS_DRAFT);

        $this->documentRepository->save($document, true);

        if ($qualityIssue !== null) {
            throw new \RuntimeException(sprintf(
                'Dokument "%s" wurde nur als Entwurf (status=draft) gespeichert: Qualitaetspruefung fehlgeschlagen - %s',
                $name,
                $qualityIssue
            ));
        }

        return [
            'status' => 'success',
            'document_id' => $document->getId(),
            'document_name' => $document->getName(),
            'message' => sprintf('Strategiedokument "%s" wurde gespeichert (ID: %d).', $name, $document->getId() ?? 0),
        ];
    }

    /**
     * Normalisiert die Rohtextantwort vor dem Speichern: LLMs antworten
     * gelegentlich mit einem Antwort-Wrapper (uebergeordnete Ueberschrift
     * wie "Business Plan Content" und/oder der Inhalt liegt in einem
     * Markdown-Code-Fence). Beides darf nicht in document.content landen
     * (Log-Fall visiongastro: gespeicherter Plan begann mit "## Business
     * Plan Content" gefolgt von ```markdown ... ```).
     */
    private function normalizeMarkdown(string $raw): string
    {
        if (preg_match('/```(?:markdown|md)?\s*\n(.*?)```/s', $raw, $m) === 1) {
            $raw = $m[1];
        }
        $raw = preg_replace('/^#{1,2}\s*(?:Business Plan Content|Content)\s*$/im', '', $raw, 1) ?? $raw;
        return trim($raw);
    }

    /**
     * Leitet den Dokumentnamen ab: expliziter name > template > leer.
     */
    private function resolveName(array $parameters): string
    {
        $name = $parameters['name'] ?? '';
        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }
        $template = $parameters['template'] ?? '';
        if (is_string($template) && trim($template) !== '') {
            return sprintf('Strategy Document: %s', trim($template));
        }
        return '';
    }

    /**
     * Leitet den Dokumentinhalt ab (Rangfolge):
     *  1. content: bewusst uebergebener Volltext (String oder strukturiertes
     *     Objekt, das als Markdown serialisiert wird)
     *  2. input_from: Vorergebnisse referenzierter Schritte (der regulaere
     *     Weg; der ToolStepExecutor uebergibt sie unter diesem Key, z.B.
     *     der Output des content_synthesizer)
     *  3. input: Legacy-Fallback (String-Verweis wird vom ToolStepExecutor
     *     vorher aufgeloest)
     */
    private function resolveContent(array $parameters): string
    {
        $content = $parameters['content'] ?? '';
        if (is_string($content) && trim($content) !== '') {
            return trim($content);
        }
        if (is_array($content) && $content !== []) {
            return trim($this->renderMarkdown($content));
        }

        $inputFrom = $parameters['input_from'] ?? '';
        if (is_string($inputFrom) && trim($inputFrom) !== '') {
            return trim($inputFrom);
        }
        if (is_array($inputFrom) && $inputFrom !== []) {
            return trim($this->renderMarkdown($inputFrom));
        }

        $input = $parameters['input'] ?? '';
        if (is_string($input) && trim($input) !== '') {
            return trim($input);
        }
        if (is_array($input) && $input !== []) {
            return trim($this->renderMarkdown($input));
        }
        return '';
    }

    /**
     * Serialisiert ein verschachteltes content-Objekt als Markdown:
     * Schluessel werden zu Ueberschriften, skalare Werte zu Absaetzen,
     * verschachtelte Werte rekursiv als Unter-Abschnitte.
     *
     * @param array<string|int, mixed> $sections
     */
    private function renderMarkdown(array $sections, int $level = 2): string
    {
        $lines = [];
        foreach ($sections as $key => $value) {
            if (is_array($value)) {
                $lines[] = sprintf('%s %s', str_repeat('#', min(6, $level)), $this->headingize((string) $key));
                $lines[] = $this->renderMarkdown($value, $level + 1);
                continue;
            }
            $text = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            if (is_int($key)) {
                $lines[] = $text;
                continue;
            }
            $lines[] = sprintf('%s %s', str_repeat('#', min(6, $level)), $this->headingize((string) $key));
            $lines[] = $text;
        }
        return trim(implode("\n\n", array_filter($lines, static fn (string $line): bool => trim($line) !== '')));
    }

    private function headingize(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }

    /**
     * Qualitaetspruefung vor der Auslieferung: Mindestlaenge, keine
     * Ueberschrift ohne Fliesstext, keine Platzhalter. Liefert null bei
     * bestandener Pruefung, sonst einen sprechenden Fehler, damit der
     * Schritt als fehlgeschlagen gemeldet wird statt Erfolg zu simulieren.
     */
    private function validateQuality(string $content): ?string
    {
        if (mb_strlen($content) < self::MIN_CONTENT_LENGTH) {
            return sprintf(
                'Inhalt mit %d Zeichen ist zu kurz (Mindestlaenge %d Zeichen). Erwartet wird ein ausformuliertes Fachergebnis, kein Stub.',
                mb_strlen($content),
                self::MIN_CONTENT_LENGTH
            );
        }
        if (preg_match('/\[(?:TBD|TODO|Platzhalter|Placeholder|Beispieltext|\.{3,})\]/iu', $content) === 1
            || preg_match('/^\s*(?:TBD|TODO)\s*$/imu', $content) === 1
        ) {
            return 'Inhalt enthaelt Platzhalter (z.B. [TBD], [TODO], ...). Der Synthese-Schritt muss ein ausformuliertes Ergebnis liefern.';
        }
        $headingLines = 0;
        $bodyLines = 0;
        $lastMeaningfulLine = '';
        foreach (explode("\n", $content) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (str_starts_with($trimmed, '#')) {
                $headingLines++;
                $lastMeaningfulLine = $trimmed;
                continue;
            }
            $bodyLines++;
            $lastMeaningfulLine = $trimmed;
        }
        if ($headingLines > 0 && $bodyLines === 0) {
            return 'Inhalt besteht nur aus Ueberschriften ohne Fliesstext (Stub-Gliederung).';
        }
        if ($headingLines > 0 && str_starts_with($lastMeaningfulLine, '#')) {
            return sprintf(
                "Abschnitt '%s' enthaelt nur eine Ueberschrift ohne Fliesstext.",
                $lastMeaningfulLine
            );
        }
        return null;
    }
}
