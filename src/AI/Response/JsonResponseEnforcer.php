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
 * unabhaengig davon, ob der User Freitext sendet.
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
     * Gibt den passenden System-Prompt fuer den Agenten-Typ zurueck
     */
    private function getSystemPromptForAgent(string $agentType): string
    {
        $prompts = [
            'orchestrator' => $this->getOrchestratorPrompt(),
            'website_researcher' => $this->getWebsiteResearcherPrompt(),
            'data_analyst' => $this->getDataAnalystPrompt(),
            'code_assistant' => $this->getCodeAssistantPrompt(),
            'document_processor' => $this->getDocumentProcessorPrompt()
        ];

        return $prompts[$agentType] ?? $prompts['orchestrator'];
    }

    private function getOrchestratorPrompt(): string
    {
        return 'Du bist der Orchestrator-Agent von EVIE. Deine Antworten MUSSSEN IMMER im JSON-Format sein.' . PHP_EOL . PHP_EOL .
            '**WICHTIGE REGELN:**' . PHP_EOL .
            '- Antworte NIE mit Freitext ausserhalb des JSON-Formats' . PHP_EOL .
            '- Nutze IMMER eines der definierten Schemata' . PHP_EOL .
            '- Falls Tools oder Sub-Agenten nicht veruegbar sind, gib DIREKT eine strukturierte Antwort zurueck' . PHP_EOL .
            '- NIE "tool_call" mit unbekanntem oder fehlendem tool_name verwenden!' . PHP_EOL .
            '- Bei Webseiten-Anfragen: Falls Scraping-Tools fehlschlagen, gib DIREKT eine "website_research_result" Antwort mit manueller Zusammenfassung zurueck' . PHP_EOL .
            '- Alle Antworten muessen gueltiges JSON sein' . PHP_EOL . PHP_EOL .
            '**Strukturierte Antwortformate:**' . PHP_EOL . PHP_EOL .
            '1. **Tool-Aufruf (NUR wenn Tool 100% veruegbar und tool_name bekannt ist!):**' . PHP_EOL .
            '{"type": "tool_call", "tool_name": "GUELTIGER_TOOL_NAME", "parameters": {"param1": "value1", "param2": "value2"}}' . PHP_EOL .
            '   // Zwingend erforderlich! Darf nicht "unknown" oder leer sein' . PHP_EOL . PHP_EOL .
            '2. **Sub-Agent-Delegation (wenn passender Sub-Agent veruegbar ist):**' . PHP_EOL .
            '{"type": "subagent_delegation", "subagent": "website_researcher|data_analyst|code_assistant|document_processor", "reason": "Begruendung fuer die Delegation", "task_description": "Detaillierte Aufgabenbeschreibung"}' . PHP_EOL . PHP_EOL .
            '3. **Kein Tool gefunden (wenn keine passende Faehigkeit existiert):**' . PHP_EOL .
            '{"type": "no_tool_found", "missing_capability": "Beschreibung der fehlenden Faehigkeit", "suggested_tool_name": "vorgeschlagener Tool-Name", "suggested_description": "Beschreibung des vorgeschlagenen Tools"}' . PHP_EOL . PHP_EOL .
            '4. **Webseiten-Recherche-Ergebnis (Fallback bei fehlenden Scraping-Tools):**' . PHP_EOL .
            '{"type": "website_research_result", "url": "https://example.com", "impressum": {"firma": "...", "adresse": "...", "kontakt": "..."}, "kontakte": [{"name": "...", "email": "...", "telefon": "..."}], "geschaeftszweck": "...", "standort": "...", "branche": "...", "zusammenfassung": {"hauptthemen": ["..."], "dienstleistungen": ["..."], "zielgruppe": ["..."], "besondere_angebote": ["..."], "allgemeine_informationen": "..."}, "status": "manual_fallback"}' . PHP_EOL . PHP_EOL .
            '5. **Direkte Antwort:**' . PHP_EOL .
            '{"type": "dialog", "content": "Deine Antwort in Textform", "intent": "intent_identification", "confidence": 0.8}' . PHP_EOL . PHP_EOL .
            '**BEISPIELE:**' . PHP_EOL . PHP_EOL .
            'User: "Analysiere diese Daten"' . PHP_EOL .
            '-> {"type": "tool_call", "tool_name": "data_analyst", "parameters": {"task": "Datenanalyse"}}' . PHP_EOL . PHP_EOL .
            'User: "Durchsuche die Webseite visiongastro.de"' . PHP_EOL .
            '-> {"type": "website_research_result", "url": "https://visiongastro.de", "summary": "...", "status": "manual_fallback"}' . PHP_EOL .
            '(NICHT: tool_call mit unknown tool_name!)' . PHP_EOL . PHP_EOL .
            'User: "Was ist die Wettervorhersage?"' . PHP_EOL .
            '-> {"type": "tool_call", "tool_name": "weather_tool", "parameters": {"location": "current"}}' . PHP_EOL . PHP_EOL .
            'User: "Erzaehl mir einen Witz"' . PHP_EOL .
            '-> {"type": "dialog", "content": "Warum kann ein Geister so schlecht luegen? ...", "intent": "general"}';
    }

    private function getWebsiteResearcherPrompt(): string
    {
        return 'Du bist der Website-Research-Agent. Antworte IMMER im JSON-Format:' . PHP_EOL . PHP_EOL .
            '{"type": "research_result", "url": "durchsuchte URL", "results": {"impressum": "Inhaltszusammenfassung", "kontakte": "Kontaktdaten", "geschaeftszweck": "Beschreibung", "standort": "Adressdaten", "branche": "Brancheninformation"}, "summary": "Zusammenfassung der wichtigsten Informationen", "status": "success|partial|failed"}';
    }

    private function getDataAnalystPrompt(): string
    {
        return 'Du bist der Data-Analyst-Agent. Antworte IMMER im JSON-Format:' . PHP_EOL . PHP_EOL .
            '{"type": "analysis_result", "findings": ["Erkenntnis 1", "Erkenntnis 2"], "statistics": {"metric1": "value1", "metric2": "value2"}, "visualization_suggestions": ["Diagrammtyp 1", "Diagrammtyp 2"], "confidence": 0.9}';
    }

    private function getCodeAssistantPrompt(): string
    {
        return 'Du bist der Code-Assistant-Agent. Antworte IMMER im JSON-Format:' . PHP_EOL . PHP_EOL .
            '{"type": "code_response", "analysis": "Code-Analyse", "suggestions": ["Vorschlag 1", "Vorschlag 2"], "generated_code": "code_snippet_here", "language": "programming_language"}';
    }

    private function getDocumentProcessorPrompt(): string
    {
        return 'Du bist der Document-Processor-Agent. Antworte IMMER im JSON-Format:' . PHP_EOL . PHP_EOL .
            '{"type": "document_processing_result", "extracted_data": {"field1": "value1", "field2": "value2"}, "summary": "Dokumentenzusammenfassung", "file_type": "pdf|excel|csv", "processing_status": "success|partial|failed"}';
    }

    /**
     * Normalisiert die User-Nachricht fuer bessere Verarbeitung
     */
    private function normalizeUserMessage(string $userMessage): string
    {
        $normalized = trim($userMessage);
        return "User-Anfrage: \"$normalized\"\n\nAnalysiere diese Anfrage und antworte IMMER im JSON-Format gemaess den definierten Schemata.";
    }

    /**
     * Validiert, ob eine Antwort dem JSON-Format entspricht
     */
    public function validateJsonResponse(string $response): bool
    {
        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Ungueltige JSON-Antwort vom LLM', [
                'response' => $response,
                'error' => json_last_error_msg()
            ]);

            $normalizedResponse = $this->responseNormalizer->normalizeResponse($response);
            $normalizedDecoded = json_decode($normalizedResponse, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($normalizedDecoded['type'])) {
                $this->logger->info('Antwort erfolgreich normalisiert');
                return true;
            }

            return false;
        }

        if (!isset($decoded['type'])) {
            $this->logger->warning('JSON-Antwort enthaelt kein "type"-Feld', [
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
        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/i', $response, $matches)) {
                $jsonContent = trim($matches[1]);
                $decoded = json_decode($jsonContent, true);
            }
        }

        return $decoded['type'] ?? null;
    }
}
