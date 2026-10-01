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
    description: 'Speichert oder aktualisiert ein Strategiedokument (z.B. Businessplan, Strategieplan) als persistente Document-Entity. Parameter: name (Dokumentname, optional; wird aus template abgeleitet, wenn fehlend), content (Volltext als String oder verschachteltes Objekt mit strukturierten Abschnitten wie executive_summary, company_description, market_analysis usw., das als Markdown serialisiert wird).'
)]
final class StrategyDocumentTool
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private UserProfileRepository $userProfileRepository,
    ) {
    }

    public function __invoke(array $parameters = []): array
    {
        $name = $this->resolveName($parameters);
        $content = $this->resolveContent($parameters);
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

        $document = new Document();
        $document->setName($name);
        $document->setContent($content);
        $document->setUser($userProfile);

        $this->documentRepository->save($document, true);

        return [
            'status' => 'success',
            'document_id' => $document->getId(),
            'document_name' => $document->getName(),
            'message' => sprintf('Strategiedokument "%s" wurde gespeichert (ID: %d).', $name, $document->getId() ?? 0),
        ];
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
     * Leitet den Dokumentinhalt ab: skalarer content-String oder
     * verschachteltes content-Objekt, dessen Abschnitte als Markdown
     * serialisiert werden.
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
        // Fallback: Der Planner uebergibt das Vorgaenger-Ergebnis gelegentlich
        // als 'input'-Parameter (String-Verweis wird vom ToolStepExecutor
        // vorher aufgeloest) statt als 'content'.
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
}
