<?php

namespace App\AI\Response;

use Psr\Log\LoggerInterface;

    /**
     * ResponseNormalizer - Normalisiert und repariert LLM-Antworten
     *
     * Diese Klasse kümmert sich um:
     * 1. JSON-Reparatur von beschädigten LLM-Antworten
     * 2. Markdown-Codeblock-Extraktion
     * 3. Fallback-Schema-Generierung
     * 4. Content-Bereinigung und Strukturierung
     */
class ResponseNormalizer
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Normalisiert eine LLM-Antwort und stellt sicher, dass sie gültiges JSON ist
     */
    public function normalizeResponse(string $rawResponse): string
    {
        $this->logger->debug('ResponseNormalizer - Rohantwort empfangen', [
            'length' => strlen($rawResponse),
            'preview' => substr($rawResponse, 0, 100)
        ]);

        // Schritt 1: Markdown-Codeblöcke extrahieren
        $extractedJson = $this->extractJsonFromMarkdown($rawResponse);
        if ($extractedJson !== null) {
            $this->logger->info('JSON aus Markdown-Codeblock extrahiert');
            return $extractedJson;
        }

        // Schritt 2: JSON-Reparatur versuchen
        $repairedJson = $this->attemptJsonRepair($rawResponse);
        if ($repairedJson !== null) {
            $this->logger->info('JSON erfolgreich repariert');
            return $repairedJson;
        }

        // Schritt 3: Fallback-Schema generieren
        $fallbackResponse = $this->createFallbackResponse($rawResponse);
        $this->logger->warning('Fallback-Schema generiert für unstrukturierte Antwort');

        return $fallbackResponse;
    }

    /**
     * Extrahiere JSON aus Markdown-Codeblöcken (```json ... ```)
     */
    private function extractJsonFromMarkdown(string $content): ?string
    {
        // Suche nach Markdown-Codeblöcken mit JSON
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/i', $content, $matches)) {
            $jsonContent = trim($matches[1]);

            // Versuche, den extrahierten Inhalt als JSON zu parsen
            $decoded = json_decode($jsonContent, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        }

        return null;
    }

    /**
     * Versucht, beschädigtes JSON zu reparieren
     */
    private function attemptJsonRepair(string $content): ?string
    {
        // Common JSON repair patterns
        $repairPatterns = [
            // Entferne trailing commas
            '/,\s*([}\]])/' => '$1',
            // Füge fehlende Anführungszeichen hinzu
            '/([\{\s,])(\w+)\s*:/' => '$1"$2":',
            // Ersetze einfache durch doppelte Anführungszeichen
            "/'/i" => '"',
            // Entferne Kommentare
            '/\/\/.*$|\/\*[\s\S]*?\*\//' => '',
        ];

        $repairedContent = $content;
        foreach ($repairPatterns as $pattern => $replacement) {
            $repairedContent = preg_replace($pattern, $replacement, $repairedContent);
        }

        // Versuche, das reparierte JSON zu parsen
        $decoded = json_decode($repairedContent, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        return null;
    }

    /**
     * Erstellt ein Fallback-JSON-Schema für unstrukturierte Antworten
     */
    private function createFallbackResponse(string $rawContent): string
    {
        // Analysiere den Inhalt, um den Typ zu bestimmen
        $contentLower = strtolower($rawContent);
        $responseType = 'dialog';
        $intent = 'general';

        // Versuche, den Intent zu bestimmen
        if (preg_match('/(webseite|website|recherche|suche|research|durchsuchen|zusammenfassen)/i', $contentLower)) {
            $intent = 'website_research';
        } elseif (preg_match('/(daten|analyse|statistik|auswertung|zahlen|diagramm)/i', $contentLower)) {
            $intent = 'data_analysis';
        } elseif (preg_match('/(code|programm|skript|funktion|klassen|php|symfony|entwickeln)/i', $contentLower)) {
            $intent = 'code_assistance';
        } elseif (preg_match('/(dokument|pdf|excel|datei|verarbeiten|lesen|extrahieren)/i', $contentLower)) {
            $intent = 'document_processing';
        }

        // Bereinige den Inhalt
        $cleanedContent = $this->cleanResponseContent($rawContent);

        $fallbackSchema = [
            'type' => $responseType,
            'content' => $cleanedContent,
            'status' => 'normalized',
            'intent' => $intent,
            'confidence' => 0.6,
            'source' => 'fallback_normalization',
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'metadata' => [
                'original_length' => strlen($rawContent),
                'normalized_length' => strlen($cleanedContent),
                'normalization_method' => 'fallback'
            ]
        ];

        return json_encode($fallbackSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Bereinigt den Response-Content
     */
    private function cleanResponseContent(string $content): string
    {
        // Entferne multiple Leerzeilen und Whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);

        // Entferne unerwünschte Zeichen
        $content = preg_replace('/[^\p{L}\p{N}\s.,;:!?\-()\[\]"\'\/]/u', '', $content);

        return $content;
    }

    /**
     * Validiert, ob eine Antwort ein gültiges JSON-Schema hat
     */
    public function validateResponseSchema(string $jsonResponse): bool
    {
        $decoded = json_decode($jsonResponse, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        // Prüfe, ob die Antwort ein gültiges Schema hat
        $requiredFields = ['type', 'content'];
        foreach ($requiredFields as $field) {
            if (!isset($decoded[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extrahiere spezifische Informationen aus einer normalisierten Antwort
     */
    public function extractResponseData(string $jsonResponse, string $field): mixed
    {
        $decoded = json_decode($jsonResponse, true);
        return $decoded[$field] ?? null;
    }

    /**
     * Erstellt ein strukturiertes Fehler-Schema
     */
    public function createErrorResponse(string $errorMessage, string $originalContent = ''): string
    {
        $errorSchema = [
            'type' => 'error',
            'error_message' => $errorMessage,
            'status' => 'failed',
            'original_content' => $originalContent !== '' ? substr($originalContent, 0, 200) : '',
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'suggested_action' => 'retry_with_different_prompt'
        ];

        return json_encode($errorSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}