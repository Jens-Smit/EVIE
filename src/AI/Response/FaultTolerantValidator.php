<?php

namespace App\AI\Response;

use Psr\Log\LoggerInterface;

    /**
     * FaultTolerantValidator - Robuste Validierung und Fehlerbehandlung
     *
     * Diese Klasse implementiert ein fehlertolerantes Validierungssystem, das:
     * 1. Graceful Degradation bei ungültigen Antworten
     * 2. Automatische Fehlerklassifizierung
     * 3. Intelligente Fallback-Strategien
     * 4. Detailliertes Monitoring und Logging
     */
class FaultTolerantValidator
{
    private LoggerInterface $logger;
    private ResponseNormalizer $responseNormalizer;

    public function __construct(
        LoggerInterface $logger,
        ResponseNormalizer $responseNormalizer
    ) {
        $this->logger = $logger;
        $this->responseNormalizer = $responseNormalizer;
    }

    /**
     * Validiert eine LLM-Antwort mit fehlertoleranter Logik
     */
    public function validateWithFallback(string $rawResponse, string $userMessage, string $context = 'orchestrator'): array
    {
        $validationResult = [
            'is_valid' => false,
            'normalized_response' => null,
            'error_type' => null,
            'error_message' => null,
            'recovery_action' => null,
            'confidence' => 0.0
        ];

        $this->logger->debug('FaultTolerantValidator - Validierung gestartet', [
            'context' => $context,
            'response_length' => strlen($rawResponse)
        ]);

        try {
            // Schritt 1: Versuche, die Antwort direkt zu parsen
            $directParseResult = $this->tryDirectJsonParse($rawResponse);
            if ($directParseResult['is_valid']) {
                $validationResult = array_merge($validationResult, $directParseResult);
                return $validationResult;
            }

            // Schritt 2: Versuche Normalisierung
            $normalizationResult = $this->tryResponseNormalization($rawResponse);
            if ($normalizationResult['is_valid']) {
                $validationResult = array_merge($validationResult, $normalizationResult);
                return $validationResult;
            }

            // Schritt 3: Fallback-Strategie
            $fallbackResult = $this->applyFallbackStrategy($rawResponse, $userMessage, $context);
            $validationResult = array_merge($validationResult, $fallbackResult);

        } catch (\Exception $e) {
            $this->logger->error('Validierungsfehler im FaultTolerantValidator', [
                'error' => $e->getMessage(),
                'context' => $context
            ]);

            $validationResult['error_type'] = 'validation_exception';
            $validationResult['error_message'] = $e->getMessage();
            $validationResult['recovery_action'] = 'system_retry';
            $validationResult['confidence'] = 0.1;
        }

        return $validationResult;
    }

    /**
     * Versucht, die Antwort direkt als JSON zu parsen
     */
    private function tryDirectJsonParse(string $response): array
    {
        $result = [
            'is_valid' => false,
            'normalized_response' => null,
            'error_type' => null,
            'error_message' => null,
            'recovery_action' => null,
            'confidence' => 0.0
        ];

        $decoded = json_decode($response, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            // Validiere das Schema
            $schemaValidation = $this->validateResponseSchema($decoded);
            if ($schemaValidation['is_valid']) {
                $result['is_valid'] = true;
                $result['normalized_response'] = $response;
                $result['confidence'] = 1.0;
                $result['recovery_action'] = 'direct_parse_success';
                $this->logger->info('Direktes JSON-Parsing erfolgreich');
                return $result;
            } else {
                $result['error_type'] = 'invalid_schema';
                $result['error_message'] = $schemaValidation['error_message'];
                $result['recovery_action'] = 'schema_repair';
                $result['confidence'] = 0.5;
                return $result;
            }
        } else {
            $result['error_type'] = 'json_parse_error';
            $result['error_message'] = json_last_error_msg();
            $result['recovery_action'] = 'attempt_normalization';
            $result['confidence'] = 0.3;
            $this->logger->warning('JSON-Parsing fehlgeschlagen', [
                'error' => json_last_error_msg()
            ]);
            return $result;
        }
    }

    /**
     * Versucht, die Antwort durch Normalisierung zu validieren
     */
    private function tryResponseNormalization(string $response): array
    {
        $result = [
            'is_valid' => false,
            'normalized_response' => null,
            'error_type' => null,
            'error_message' => null,
            'recovery_action' => null,
            'confidence' => 0.0
        ];

        try {
            $normalizedResponse = $this->responseNormalizer->normalizeResponse($response);
            $normalizedDecoded = json_decode($normalizedResponse, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $schemaValidation = $this->validateResponseSchema($normalizedDecoded);
                if ($schemaValidation['is_valid']) {
                    $result['is_valid'] = true;
                    $result['normalized_response'] = $normalizedResponse;
                    $result['confidence'] = 0.8;
                    $result['recovery_action'] = 'normalization_success';
                    $this->logger->info('Normalisierung erfolgreich');
                    return $result;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Normalisierungsfehler', [
                'error' => $e->getMessage()
            ]);
        }

        $result['error_type'] = 'normalization_failed';
        $result['error_message'] = 'Konnte Antwort nicht normalisieren';
        $result['recovery_action'] = 'apply_fallback';
        $result['confidence'] = 0.2;
        return $result;
    }

    /**
     * Wendet eine Fallback-Strategie an
     */
    private function applyFallbackStrategy(string $rawResponse, string $userMessage, string $context): array
    {
        $result = [
            'is_valid' => true, // Fallback ist immer "gültig"
            'normalized_response' => null,
            'error_type' => 'fallback_applied',
            'error_message' => 'Fallback-Strategie angewendet',
            'recovery_action' => 'fallback_response',
            'confidence' => 0.6
        ];

        try {
            // Analysiere den Intent der User-Nachricht
            $intent = $this->analyzeUserIntent($userMessage);

            // Erstelle eine strukturierte Fallback-Antwort
            $fallbackResponse = $this->createStructuredFallback($rawResponse, $userMessage, $intent, $context);
            $result['normalized_response'] = $fallbackResponse;

            $this->logger->info('Fallback-Strategie erfolgreich angewendet', [
                'intent' => $intent,
                'context' => $context
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Fallback-Strategie fehlgeschlagen', [
                'error' => $e->getMessage()
            ]);

            // Erstelle eine minimale Fehlerantwort
            $result['normalized_response'] = $this->createErrorResponse($e->getMessage());
            $result['confidence'] = 0.2;
        }

        return $result;
    }

    /**
     * Analysiert den Intent einer User-Nachricht
     */
    private function analyzeUserIntent(string $message): string
    {
        $messageLower = strtolower($message);

        if (preg_match('/(webseite|website|recherche|suche|research|durchsuchen|zusammenfassen)/i', $messageLower)) {
            return 'website_research';
        }

        if (preg_match('/(daten|analyse|statistik|auswertung|zahlen|diagramm)/i', $messageLower)) {
            return 'data_analysis';
        }

        if (preg_match('/(code|programm|skript|funktion|klassen|php|symfony|entwickeln)/i', $messageLower)) {
            return 'code_assistance';
        }

        if (preg_match('/(dokument|pdf|excel|datei|verarbeiten|lesen|extrahieren)/i', $messageLower)) {
            return 'document_processing';
        }

        return 'general_query';
    }

    /**
     * Erstellt eine strukturierte Fallback-Antwort
     */
    private function createStructuredFallback(string $rawResponse, string $userMessage, string $intent, string $context): string
    {
        // Bereinige den Raw-Content
        $cleanedContent = $this->cleanResponseContent($rawResponse);

        $fallbackSchema = [
            'type' => 'dialog',
            'content' => $cleanedContent,
            'status' => 'fallback',
            'intent' => $intent,
            'context' => $context,
            'confidence' => 0.6,
            'source' => 'fault_tolerant_fallback',
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'metadata' => [
                'original_message' => substr($userMessage, 0, 100),
                'raw_response_length' => strlen($rawResponse),
                'cleaned_response_length' => strlen($cleanedContent),
                'processing_steps' => ['intent_analysis', 'content_cleaning', 'schema_generation']
            ],
            'suggested_actions' => $this->getSuggestedActions($intent)
        ];

        return json_encode($fallbackSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Gibt vorgeschlagene Aktionen basierend auf dem Intent zurück
     */
    private function getSuggestedActions(string $intent): array
    {
        $actions = [
            'website_research' => ['delegate_to_website_researcher', 'create_web_research_tool'],
            'data_analysis' => ['delegate_to_data_analyst', 'create_data_analysis_tool'],
            'code_assistance' => ['delegate_to_code_assistant', 'create_code_tool'],
            'document_processing' => ['delegate_to_document_processor', 'create_document_tool'],
            'general_query' => ['ask_for_clarification', 'search_knowledge_base']
        ];

        return $actions[$intent] ?? $actions['general_query'];
    }

    /**
     * Bereinigt Response-Content
     */
    private function cleanResponseContent(string $content): string
    {
        // Entferne multiple Leerzeilen und Whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);

        // Entferne unerwünschte Zeichen
        $content = preg_replace('/[^\p{L}\p{N}\s.,;:!?\-()\[\]"\'\/]/u', '', $content);

        // Kürze sehr lange Inhalte
        if (strlen($content) > 1000) {
            $content = substr($content, 0, 1000) . '...';
        }

        return $content;
    }

    /**
     * Erstellt eine Fehlerantwort
     */
    private function createErrorResponse(string $errorMessage): string
    {
        $errorSchema = [
            'type' => 'error',
            'error_message' => $errorMessage,
            'status' => 'failed',
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'recovery_suggestion' => 'Please retry your request or contact support',
            'error_code' => 'FTV-001'
        ];

        return json_encode($errorSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Validiert das Schema einer dekodierten JSON-Antwort
     */
    private function validateResponseSchema(array $decoded): array
    {
        $result = [
            'is_valid' => false,
            'error_message' => null
        ];

        // Prüfe, ob die Antwort ein gültiges Schema hat
        $requiredFields = ['type', 'content'];
        foreach ($requiredFields as $field) {
            if (!isset($decoded[$field])) {
                $result['error_message'] = "Fehlendes Feld: $field";
                return $result;
            }
        }

        // Prüfe den Antworttyp
        $validTypes = ['tool_call', 'subagent_delegation', 'no_tool_found', 'dialog', 'research_result', 'analysis_result', 'code_response', 'document_processing_result'];
        if (!in_array($decoded['type'], $validTypes)) {
            $result['error_message'] = "Ungültiger Antworttyp: {$decoded['type']}";
            return $result;
        }

        $result['is_valid'] = true;
        return $result;
    }

    /**
     * Klassifiziert Fehler basierend auf dem Antwortmuster
     */
    public function classifyErrorPattern(string $response): string
    {
        $responseLower = strtolower($response);

        // Häufige Fehlermuster
        $errorPatterns = [
            'json_syntax_error' => '/[{\[][^}]*$/',
            'markdown_codeblock' => '/```[json]?\s*[^`]+```/i',
            'llm_hallucination' => '/(ich weiß nicht|keine information|unbekannt)/i',
            'tool_not_found' => '/(kein tool|keine funktion|nicht verfügbar)/i',
            'partial_json' => '/[{\[][^}]{50,}[}\]]/',
            'mixed_content' => '/[{\[][^}]*[^}]+[}\]]/'
        ];

        foreach ($errorPatterns as $errorType => $pattern) {
            if (preg_match($pattern, $responseLower)) {
                return $errorType;
            }
        }

        return 'unknown_error';
    }
}