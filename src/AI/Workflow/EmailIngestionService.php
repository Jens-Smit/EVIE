<?php

declare(strict_types=1);

namespace App\AI\Workflow;

use App\AI\Platform\TenantPlatformContext;
use App\Entity\AgentHistory;
use App\Entity\Document;
use App\Entity\UserProfile;
use App\Repository\DocumentRepository;
use App\Repository\UserProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * E-Mail-Postfach-Verarbeitung (Inbound) inkl. Dokumentenauswertung.
 *
 * ingestEmail() nimmt eine abgerufene Nachricht (Betreff, Text, Absender,
 * Anhaenge als bereits extrahierter Text) entgegen, klassifiziert die
 * Anhaenge per LLM (Typ, Daten, Handlungsempfehlung) und speichert:
 *
 *  - pro Anhang eine Document-Entity beim Tenant (UserProfile),
 *  - eine AgentHistory 'document_analysis' mit dem Analyseergebnis
 *    (Traceability im Frontend),
 *  - eine AgentHistory 'email_ingested' fuer die verarbeitete Nachricht,
 *  - einen Antwort-Entwurf als MailDraft mit status=pending_approval
 *    (HITL: die Antwort geht nur nach Freigabe raus).
 *
 * Keine Halluzination: schlaegt der LLM-Abruf fehl oder liefert er kein
 * gueltiges JSON, greift eine heuristische Klassifikation und die Analyse
 * wird als 'source: heuristic' markiert. Es werden keine Objekte erfunden.
 */
final class EmailIngestionService
{
    public function __construct(
        private AgentInterface $documentAgent,
        private UserProfileRepository $userProfileRepository,
        private DocumentRepository $documentRepository,
        private MailDraftHitlService $mailDraftHitlService,
        private TenantPlatformContext $tenantPlatformContext,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{subject: string, body: string, from?: string, attachments?: array<int, array{filename: string, content: string}>} $email
     *
     * @return array{processed_attachments: int, documents: array<int, array{document_id: int, filename: string, type: string, recommendation: string, source: 'llm'|'heuristic'}>, reply_draft_id: ?int}
     */
    public function ingestEmail(string $userIdentifier, array $email): array
    {
        $userProfile = $this->userProfileRepository->findOneBy(['userIdentifier' => $userIdentifier]);
        if ($userProfile === null) {
            throw new \InvalidArgumentException(sprintf('UserProfile fuer user_identifier "%s" nicht gefunden.', $userIdentifier));
        }

        $attachments = array_values($email['attachments'] ?? []);
        $documents = [];
        $recommendations = [];

        foreach ($attachments as $attachment) {
            $filename = (string) ($attachment['filename'] ?? '');
            $content = (string) ($attachment['content'] ?? '');
            if ($filename === '' || $content === '') {
                continue;
            }

            $analysis = $this->analyzeAttachment($userIdentifier, $filename, $content, (string) ($email['subject'] ?? ''));

            $historyEntry = new AgentHistory();
            $historyEntry->setAction('document_analysis');
            $historyEntry->setDetails(json_encode([
                'agent' => 'document_processor',
                'status' => 'success',
                'input' => ['filename' => $filename, 'email_subject' => $email['subject'] ?? ''],
                'output' => $analysis,
            ], \JSON_THROW_ON_ERROR));
            $historyEntry->setUser($userProfile);
            $this->entityManager->persist($historyEntry);

            $document = new Document();
            $document->setName($filename);
            $document->setContent($content);
            $document->setUser($userProfile);
            $document->setAgentHistory($historyEntry);
            $this->documentRepository->save($document, true);

            $documents[] = [
                'document_id' => (int) $document->getId(),
                'filename' => $filename,
                'type' => (string) $analysis['type'],
                'recommendation' => (string) $analysis['recommendation'],
                'source' => (string) $analysis['source'],
            ];
            $recommendations[] = $filename . ': ' . (string) $analysis['recommendation'];
        }

        $ingestEntry = new AgentHistory();
        $ingestEntry->setAction('email_ingested');
        $ingestEntry->setDetails(json_encode([
            'agent' => 'email_ingestion',
            'status' => 'success',
            'input' => ['subject' => $email['subject'] ?? '', 'from' => $email['from'] ?? ''],
            'output' => ['processed_attachments' => count($documents)],
        ], \JSON_THROW_ON_ERROR));
        $ingestEntry->setUser($userProfile);
        $this->entityManager->persist($ingestEntry);
        $this->entityManager->flush();

        $replyDraftId = null;
        if ($documents !== []) {
            $replyBody = sprintf(
                "Hallo,\n\nwir haben Ihre E-Mail \"%s\" samt Anhang erhalten und ausgewertet.\n\n%s\n\nDas EVIE-Team",
                (string) ($email['subject'] ?? ''),
                implode("\n", $recommendations),
            );
            $replyDraft = $this->mailDraftHitlService->prepareDraft(
                $userIdentifier,
                'Aw: ' . (string) ($email['subject'] ?? 'Ihre E-Mail'),
                $replyBody,
                [(string) ($email['from'] ?? 'unknown@example.com')],
                metadata: ['source' => 'email_ingestion', 'documents' => $documents],
            );
            $replyDraftId = $replyDraft->getId();
        }

        return [
            'processed_attachments' => count($documents),
            'documents' => $documents,
            'reply_draft_id' => $replyDraftId,
        ];
    }

    /**
     * LLM-gestuetzte Analyse eines Anhangs mit strikter Validierung und
     * heuristischem Fallback.
     *
     * @return array{type: string, data: array<string, mixed>, recommendation: string, source: 'llm'|'heuristic'}
     */
    private function analyzeAttachment(string $userIdentifier, string $filename, string $content, string $emailSubject): array
    {
        $payload = json_encode([
            'action' => 'analyze_document',
            'filename' => $filename,
            'email_subject' => $emailSubject,
            'document_text' => mb_substr($content, 0, 4000),
            'response_format' => [
                'type' => 'string: Dokumententyp (z.B. Rechnung, Vertrag, Bestellung, Anfrage)',
                'data' => 'object mit wichtigen Daten (betrag, faelligkeit, artikel, etc.)',
                'recommendation' => 'string: kurze Handlungsempfehlung (Antworten, Speichern, Weiterleiten)',
            ],
        ], \JSON_THROW_ON_ERROR);

        $heuristic = $this->heuristicAnalysis($filename, $content);

        try {
            $this->tenantPlatformContext->setUserIdentifier($userIdentifier);
            try {
                $messages = new MessageBag(Message::ofUser($payload));
                $result = $this->documentAgent->call($messages);
            } finally {
                $this->tenantPlatformContext->clear();
            }
            $decoded = $this->decodeLlmJson($result->getContent());
        } catch (\Throwable $e) {
            $this->logger->warning('Dokumentenanalyse-LLM-Abruf fehlgeschlagen, nutze Heuristik', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
            return $heuristic;
        }

        if (!is_array($decoded)) {
            return $heuristic;
        }

        // Der document_processor-Agent (ai.yaml) antwortet im Format
        // {"type":"document_result","extracted_data":{},"summary":"..."}.
        // Solche Antworten werden auf das interne Analyse-Schema abgebildet.
        if (isset($decoded['extracted_data']) || isset($decoded['summary'])) {
            $extractedData = is_array($decoded['extracted_data'] ?? null) ? $decoded['extracted_data'] : [];
            $summary = trim((string) ($decoded['summary'] ?? ''));
            return [
                'type' => $this->typeFromExtractedData($extractedData) ?? $heuristic['type'],
                'data' => $extractedData,
                'recommendation' => $summary !== '' ? $summary : $heuristic['recommendation'],
                'source' => 'llm',
            ];
        }

        $type = trim((string) ($decoded['type'] ?? ''));
        $recommendation = trim((string) ($decoded['recommendation'] ?? ''));
        if ($type === '') {
            return $heuristic;
        }

        return [
            'type' => $type,
            'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
            'recommendation' => $recommendation !== '' ? $recommendation : $heuristic['recommendation'],
            'source' => 'llm',
        ];
    }

    /**
     * Leistet den Dokumententyp aus den extrahierten Daten des
     * document_processor-Agenten ab (nur bekannte Schluessel, keine Erfindung).
     *
     * @param array<string, mixed> $extractedData
     */
    private function typeFromExtractedData(array $extractedData): ?string
    {
        foreach (['dokumententyp', 'document_type', 'type', 'kategorie'] as $key) {
            $value = trim((string) ($extractedData[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Deterministische Basis-Analyse: keine Erfindung, nur Ableitung aus
     * Dateiname und Dokumenttext.
     *
     * @return array{type: string, data: array<string, mixed>, recommendation: string, source: 'llm'|'heuristic'}
     */
    private function heuristicAnalysis(string $filename, string $content): array
    {
        $type = 'Dokument';
        foreach (['rechnung' => 'Rechnung', 'vertrag' => 'Vertrag', 'bestellung' => 'Bestellung', 'angebot' => 'Angebot'] as $needle => $label) {
            if (mb_stripos($filename . ' ' . $content, $needle) !== false) {
                $type = $label;
                break;
            }
        }

        $data = [];
        if (preg_match('/(\d{1,3}(?:\.\d{3})*,\d{2})\s*(?:€|EUR)/u', $content, $matches) === 1) {
            $data['betrag'] = $matches[1];
        }
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $content, $matches) === 1) {
            $data['datum'] = $matches[1];
        }

        return [
            'type' => $type,
            'data' => $data,
            'recommendation' => 'Dokument pruefen, abspeichern und bei Bedarf antworten.',
            'source' => 'heuristic',
        ];
    }

    /**
     * Dekodiert die LLM-Antwort als JSON (inkl. Markdown-Code-Fence-Removal).
     *
     * @return array<string, mixed>|null
     */
    private function decodeLlmJson(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $content, $matches) === 1) {
            $content = $matches[1];
        }
        $firstBrace = strpos($content, '{');
        $lastBrace = strrpos($content, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $content = substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
        }
        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
