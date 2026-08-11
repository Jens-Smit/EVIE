<?php

namespace App\AI\Response;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Psr\Log\LoggerInterface;

/**
 * JsonResponseEnforcer - Erzwingt strukturierte JSON-Antworten vom LLM
 *
 * Diese Klasse stellt sicher, dass das LLM IMMER im JSON-Format antwortet,
 * unabhängig davon, ob der User Freitext sendet.
 */
class JsonResponseEnforcer
{
    private PlatformInterface $platform;
    private LoggerInterface $logger;
    private ResponseNormalizer $responseNormalizer;

    public function __construct(
        PlatformInterface $platform,
        LoggerInterface $logger,
        ResponseNormalizer $responseNormalizer
    ) {
        $this->platform = $platform;
        $this->logger = $logger;
        $this->responseNormalizer = $responseNormalizer;
    }

    /**
     * Erstellt einen System-Prompt, der das LLM zwingt, im JSON-Format zu antworten
     */
    public function createStructuredPrompt(string $userMessage, string $agentType = 'orchestrator'): MessageBag
    {
        $systemPrompt = $this->getSystemPromptForAgent($agentType);
        $userMessage = $this->normalizeUserMessage($userMessage);

        $messages = new MessageBag();
        $messages->add(Message::forSystem($systemPrompt));
        $messages->add(Message::ofUser($userMessage));

        return $messages;
    }

    /**
     * Gibt den passenden System-Prompt für den Agenten-Typ zurück
     */
    private function getSystemPromptForAgent(string $agentType): string
    {
        $prompts = [
            'orchestrator' => <<<PROMPT
                Du bist der Orchestrator-Agent von EVIE. Deine Antworten MÜSSEN IMMER im JSON-Format sein.

                **WICHTIGE REGELN:**
                - Antworte NIE mit Freitext außerhalb des JSON-Formats
                - Nutze IMMER eines der definierten Schemata
                - Falls Tools oder Sub-Agenten nicht verfügbar sind, gib DIREKT eine strukturierte Antwort zurück
                - NIE "tool_call" mit unbekanntem oder fehlendem tool_name verwenden!
                - Bei Webseiten-Anfragen: Falls Scraping-Tools fehlschlagen, gib DIREKT eine "website_research_result" Antwort mit manueller Zusammenfassung zurück
                - Alle Antworten müssen gültiges JSON sein

                **Strukturierte Antwortformate:**

                1. **Tool-Aufruf (NUR wenn Tool 100% verfügbar und tool_name bekannt ist!):**
                {
                    "type": "tool_call",
                    "tool_name": "GÜLTIGER_TOOL_NAME",  // Zwingend erforderlich! Darf nicht "unknown" oder leer sein
                    "parameters": {"param1": "value1", "param2": "value2"}
                }

                2. **Sub-Agent-Delegation (wenn passender Sub-Agent verfügbar ist):**
                {
                    "type": "subagent_delegation",
                    "subagent": "website_researcher|data_analyst|code_assistant|document_processor",
                    "reason": "Begründung für die Delegation",
                    "task_description": "Detaillierte Aufgabenbeschreibung"
                }

                3. **Kein Tool gefunden (wenn keine passende Fähigkeit existiert):**
                {
                    "type": "no_tool_found",
                    "missing_capability": "Beschreibung der fehlenden Fähigkeit",
                    "suggested_tool_name": "vorgeschlagener Tool-Name",
                    "suggested_description": "Beschreibung des vorgeschlagenen Tools"
                }

                4. **Webseiten-Recherche-Ergebnis (Fallback bei fehlenden Scraping-Tools):**
                {
                    "type": "website_research_result",
                    "url": "https://example.com",
                    "impressum": {"firma": "...", "adresse": "...", "kontakt": "..."},
                    "kontakte": [{"name": "...", "email": "...", "telefon": "..."}],
                    "geschäftszweck": "...",
                    "standort": "...",
                    "branche": "...",
                    "zusammenfassung": {
                        "hauptthemen": ["..."],
                        "dienstleistungen": ["..."],
                        "zielgruppe": ["..."],
                        "besondere_angebote": ["..."],
                        "allgemeine_informationen": "..."
                    },
                    "status": "manual_fallback"
                }

                5. **Direkte Antwort:**
                {
                    "type": "dialog",
                    "content": "Deine Antwort in Textform",
                    "intent": "intent_identification",
                    "confidence": 0.8
                }

                **BEISPIELE:**

                User: "Analysiere diese Daten"
                → {"type": "tool_call", "tool_name": "data_analyst", "parameters": {"task": "Datenanalyse"}}

                User: "Durchsuche die Webseite visiongastro.de"
                → {"type": "website_research_result", "url": "https://visiongastro.de", "summary": "...", "status": "manual_fallback"}
                (NICHT: tool_call mit unknown tool_name!)

                User: "Was ist die Wettervorhersage?"
                → {"type": "tool_call", "tool_name": "weather_tool", "parameters": {"location": "current"}}

                User: "Erzähl mir einen Witz"
                → {"type": "dialog", "content": "Warum kann ein Geister so schlecht lügen? ...", "intent": "general"}
                PROMPT,

            'website_researcher' => <<<PROMPT
                Du bist der Website-Research-Agent. Antworte IMMER im JSON-Format:

                {
                    "type": "research_result",
                    "url": "durchsuchte URL",
                    "results": {
                        "impressum": "Inhaltszusammenfassung",
                        "kontakte": "Kontaktdaten",
                        "geschäftszweck": "Beschreibung",
                        "standort": "Adressdaten",
                        "branche": "Brancheninformation"
                    },
                    "summary": "Zusammenfassung der wichtigsten Informationen",
                    "status": "success|partial|failed"
                }
                PROMPT,

            'data_analyst' => <<<PROMPT
                Du bist der Data-Analyst-Agent. Antworte IMMER im JSON-Format:

                {
                    "type": "analysis_result",
                    "findings": ["Erkenntnis 1", "Erkenntnis 2"],
                    "statistics": {"metric1": value1, "metric2": value2},
                    "visualization_suggestions": ["Diagrammtyp 1", "Diagrammtyp 2"],
                    "confidence": 0.9
                }
                PROMPT,

            'code_assistant' => <<<PROMPT
                Du bist der Code-Assistant-Agent. Antworte IMMER im JSON-Format:

                {
                    "type": "code_response",
                    "analysis": "Code-Analyse",
                    "suggestions": ["Vorschlag 1", "Vorschlag 2"],
                    "generated_code": "code_snippet_here",
                    "language": "programming_language"
                }
                PROMPT,

            'document_processor' => <<<PROMPT
                Du bist der Document-Processor-Agent. Antworte IMMER im JSON-Format:

                {
                    "type": "document_processing_result",
                    "extracted_data": {"field1": "value1", "field2": "value2"},
                    "summary": "Dokumentenzusammenfassung",
                    "file_type": "pdf|excel|csv",
                    "processing_status": "success|partial|failed"
                }
                PROMPT
        ];

        return $prompts[$agentType] ?? $prompts['orchestrator'];
    }

    /**
     * Normalisiert die User-Nachricht für bessere Verarbeitung
     */
    private function normalizeUserMessage(string $userMessage): string
    {
        // Bereinige die Nachricht und füge Kontext hinzu
        $normalized = trim($userMessage);

        // Füge Analyse-Hinweis hinzu
        return "User-Anfrage: \"$normalized\"\n\nAnalysiere diese Anfrage und antworte IMMER im JSON-Format gemäß den definierten Schemata.";
    }

    /**
     * Validiert, ob eine Antwort dem JSON-Format entspricht
     */
    public function validateJsonResponse(string $response): bool
    {
        // Versuche, die Antwort als JSON zu parsen
        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Ungültige JSON-Antwort vom LLM', [
                'response' => $response,
                'error' => json_last_error_msg()
            ]);

            // Versuche, die Antwort zu normalisieren
            $normalizedResponse = $this->responseNormalizer->normalizeResponse($response);
            $normalizedDecoded = json_decode($normalizedResponse, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($normalizedDecoded['type'])) {
                $this->logger->info('Antwort erfolgreich normalisiert');
                return true;
            }

            return false;
        }

        // Prüfe, ob die Antwort ein gültiges Schema hat
        if (!isset($decoded['type'])) {
            $this->logger->warning('JSON-Antwort enthält kein "type"-Feld', [
                'response' => $decoded
            ]);
            return false;
        }

        return true;
    }

    /**
     * Extrahiere den Antworttyp aus einer JSON-Antwort
     */
    public function extractResponseType(string $response): ?string
    {
        // Versuche, JSON direkt zu parsen
        $decoded = json_decode($response, true);

        // Falls das fehlschlägt, versuche Markdown-Codeblock zu extrahieren
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/i', $response, $matches)) {
                $jsonContent = trim($matches[1]);
                $decoded = json_decode($jsonContent, true);
            }
        }

        return $decoded['type'] ?? null;
    }
}
