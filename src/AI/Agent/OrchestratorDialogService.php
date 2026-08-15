<?php
// src/AI/Agent/OrchestratorDialogService.php

namespace App\AI\Agent;

use App\AI\Skills\ToolDefinitionGenerator;
use App\AI\Response\JsonResponseEnforcer;
use App\AI\Response\FaultTolerantValidator;
use App\AI\Response\ResponseNormalizer;
use App\Entity\ToolDefinition;
use App\Event\PendingToolApprovalEvent;
use App\Repository\ToolDefinitionRepository;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class OrchestratorDialogService
{
    public function __construct(
        #[Autowire(service: 'ai.agent.orchestrator')]
        private AgentInterface $agent,
        private ToolDefinitionGenerator $toolGenerator,
        private SubAgentFactory $subAgentFactory,
        private EventDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
        private PlatformInterface $platform,
        private UrlGeneratorInterface $urlGenerator,
        private JsonResponseEnforcer $jsonResponseEnforcer,
        private FaultTolerantValidator $faultTolerantValidator,
        private ResponseNormalizer $responseNormalizer,
        private ToolDefinitionRepository $toolDefinitionRepo,
    ) {
    }

    /**
     * Sendet eine Nachricht an den Orchestrator-Agenten.
     * Falls kein passendes Tool gefunden wird, wird automatisch eine Tool-Generierung ausgelöst.
     */
    public function ask(string $userMessage, string $userIdentifier): string
    {
        // Temporär deaktiviert, da der Orchestrator-Prompt in ai.yaml jetzt JSON erzwingt
        $messages = new MessageBag(Message::ofUser($userMessage));

        try {
            $result = $this->agent->call($messages);
            $responseContent = $result->getContent();

            $this->logger->debug('Orchestrator-Agent Antwort (Rohdaten)', [
                'response' => $responseContent,
                'length' => strlen($responseContent)
            ]);

            // NORMALISIERE die Antwort SOFORT, bevor weitere Verarbeitung
            $normalizedResponse = $this->responseNormalizer->normalizeResponse($responseContent);
            
            $this->logger->debug('Orchestrator-Agent Antwort (normalisiert)', [
                'response' => $normalizedResponse,
                'is_valid_json' => $this->jsonResponseEnforcer->validateJsonResponse($normalizedResponse)
            ]);

            // Prüfe, ob die normalisierte Antwort gültiges JSON ist
            if (!$this->jsonResponseEnforcer->validateJsonResponse($normalizedResponse)) {
                $this->logger->warning('Ungültige JSON-Antwort vom LLM auch nach Normalisierung, starte Fallback-Handling');
                return $this->handleUnstructuredResponse($normalizedResponse, $userMessage, $userIdentifier);
            }

            // Extrahiere den Antworttyp aus der NORMALISIERTEN Antwort
            $responseType = $this->jsonResponseEnforcer->extractResponseType($normalizedResponse);

            // Behandle verschiedene Antworttypen
            switch ($responseType) {
                case 'tool_call':
                    return $this->handleToolCallResponse($normalizedResponse, $userMessage, $userIdentifier);

                case 'subagent_delegation':
                    return $this->handleSubAgentDelegation($normalizedResponse, $userMessage, $userIdentifier);

                case 'no_tool_found':
                    return $this->handleToolNotFound($userMessage, $userIdentifier);

                case 'dialog':
                    return $this->handleDialogResponse($normalizedResponse, $userMessage, $userIdentifier);

                case 'website_research_result':
                    return $this->handleWebsiteResearchResponse($normalizedResponse);

                default:
                    $this->logger->warning('Unbekannter Antworttyp', ['type' => $responseType]);
                    return $this->handleUnstructuredResponse($normalizedResponse, $userMessage, $userIdentifier);
            }
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Aufruf des Orchestrator-Agenten: ' . $e->getMessage());
            return $this->handleToolNotFound($userMessage, $userIdentifier);
        }
    }

    /**
     * Prüft, ob die Antwort darauf hindeutet, dass kein Tool gefunden wurde.
     * Nutzt LLM-basierte Klassifizierung für robustere Erkennung.
     */
    private function isNoToolFoundResponse(string $response): bool
    {
        // Zuerst: Prüfe auf explizite TOOL_NOT_FOUND-Markierung
        if (str_contains(strtolower($response), 'tool_not_found:')) {
            return true;
        }

        // Zweitens: Prüfe auf klassische Indikatoren
        $noToolIndicators = [
            'ich kann diese anfrage nicht ausführen',
            'es tut mir leid, aber ich kann',
            'kein passendes tool',
            'keine passende funktion',
            'kann diese aufgabe nicht erledigen',
            'nicht verfügbar',
            'keine ressourcen',
            'keine schnittstellen',
            'ich kann kein tool entwickeln',
            'kein tool verfügbar',
            'ich habe kein passendes tool',
            'kein tool gefunden',
            'ich verstehe deine anfrage, aber',
            'ich brauche mehr informationen',
            'könntest du bitte genauer beschreiben',
            'was das tool können soll',
        ];

        $responseLower = strtolower($response);
        foreach ($noToolIndicators as $indicator) {
            if (str_contains($responseLower, $indicator)) {
                return true;
            }
        }

        // Drittes: LLM-basierte Klassifizierung für komplexere Fälle
        return $this->classifyWithLLM($response);
    }

    /**
     * Prüft, ob eine Dialog-Antwort darauf hindeutet, dass kein Tool verfügbar ist.
     */
    private function isNoToolAvailableResponse(string $reason, string $content): bool
    {
        $text = $reason . ' ' . $content;
        $textLower = strtolower($text);
        
        $indicators = [
            'kein tool verfügbar',
            'keine funktionierenden tools',
            'keine tools zur verfügung',
            'kein passendes tool',
            'keine ressourcen',
            'nicht verfügbar',
            'kann diese anfrage nicht ausführen',
            'manuelle zusammenfassung ist erforderlich',
            'keine automatisierten tools',
            'kein direktes tool',
            'es stehen keine funktionierenden tools zur verfügung',
        ];

        foreach ($indicators as $indicator) {
            if (str_contains($textLower, $indicator)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nutzt LLM, um zu klassifizieren, ob ein Tool fehlt.
     */
    private function classifyWithLLM(string $response): bool
    {
        try {
            $prompt = <<<PROMPT
            Analysiere die folgende Antwort eines AI-Agenten und entscheide, ob der Agent kein passendes Tool für die User-Anfrage hatte.
            
            **Kriterien für "Tool fehlt":**
            - Der Agent sagt explizit, dass er die Aufgabe nicht ausführen kann
            - Der Agent bittet um mehr Informationen, weil ihm die Fähigkeiten fehlen
            - Der Agent schlägt vor, ein neues Tool zu erstellen
            - Der Agent erklärt, dass er die Anfrage nicht versteht
            
            **Antworte nur mit "YES" oder "NO".**
            
            Agenten-Antwort: """$response"""
            PROMPT;

            $messages = new MessageBag(Message::ofUser($prompt));
            $result = $this->platform->invoke('mistral-small-latest', $messages)->asText();
            
            return trim($result) === 'YES';
        } catch (\Exception $e) {
            $this->logger->warning('LLM-Klassifizierung fehlgeschlagen, verwende Fallback: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Prüft, ob der Orchestrator einen Sub-Agenten vorschlägt.
     */
    private function isSubAgentSuggested(string $response): bool
    {
        $suggestionIndicators = [
            'ich werde den website_researcher verwenden',
            'der data_analyst kann das übernehmen',
            'ich delegiere an den code_assistant',
            'der document_processor ist dafür zuständig',
            'ich empfehle den sub-agenten',
            'ein spezialisierter agent könnte',
        ];

        $responseLower = strtolower($response);
        foreach ($suggestionIndicators as $indicator) {
            if (str_contains($responseLower, $indicator)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Behandelt den Fall, wenn kein passendes Tool gefunden wurde.
     * Erstellt automatisch einen passenden Sub-Agenten und ein neues Tool.
     */
    private function handleToolNotFound(string $userMessage, string $userIdentifier): string
    {
        $this->logger->info('Kein passendes Tool gefunden. Starte Tool-Generierung...');

        // 1. Bestimme den passenden Sub-Agenten basierend auf der User-Anfrage
        $subAgent = $this->determineAndCreateSubAgent($userMessage);
        $subAgentName = $subAgent->getName();
        
        // 2. Tool-Name aus der User-Nachricht extrahieren (intelligente Analyse)
        $toolName = $this->extractToolNameFromRequest($userMessage);
        
        // 3. Prüfe, ob ein Tool mit diesem Namen schon existiert (pending oder approved)
        $existingTool = $this->toolDefinitionRepo->findOneBy([
            'name' => $toolName,
            'status' => ['pending', 'pending_approval', 'approved']
        ]);
        
        if ($existingTool) {
            $this->logger->info('Tool existiert bereits, verwende bestehende Definition', [
                'tool_name' => $toolName,
                'tool_id' => $existingTool->getId(),
                'status' => $existingTool->getStatus()
            ]);
            
            // Falls das Tool noch pending ist, löse HITL-Event aus
            if (in_array($existingTool->getStatus(), ['pending', 'pending_approval'])) {
                $this->dispatcher->dispatch(new PendingToolApprovalEvent($existingTool, $userIdentifier));
            }
            
            return $this->generateUserResponse($existingTool, $subAgent);
        }
        
        // 4. Bessere Beschreibung generieren (nicht die User-Nachricht direkt)
        $description = $this->generateBetterToolDescription($userMessage, $subAgentName);
        
        // 5. Tool-Definition generieren
        $toolDefinition = $this->toolGenerator->generateToolDefinition(
            $toolName,
            $description,
            [
                'user_identifier' => $userIdentifier,
                'original_request' => $userMessage,
                'suggested_sub_agent' => $subAgentName,
            ]
        );

        // 6. Sub-Agenten mit dem Tool verknüpfen
        $this->linkSubAgentToTool($toolDefinition, $subAgent);

        // 7. HITL-Event auslösen
        $this->dispatcher->dispatch(new PendingToolApprovalEvent($toolDefinition, $userIdentifier));

        // 8. User-Freundliche Antwort generieren
        return $this->generateUserResponse($toolDefinition, $subAgent);
    }

    /**
     * Behandelt den Fall, wenn der Orchestrator einen Sub-Agenten vorschlägt.
     */
    private function handleSubAgentSuggestion(string $userMessage, string $userIdentifier): string
    {
        $this->logger->info('Orchestrator schlägt Sub-Agenten vor. Erstelle und registriere...');

        // Sub-Agenten bestimmen
        $subAgent = $this->determineAndCreateSubAgent($userMessage);

        // Tool-Definition für die Delegation erstellen
        $toolName = 'delegate_to_' . $subAgent->getName();
        $description = sprintf('Delegiert die Aufgabe an den %s Sub-Agenten', $subAgent->getName());

        $toolDefinition = $this->toolGenerator->generateToolDefinition(
            $toolName,
            $description,
            [
                'user_identifier' => $userIdentifier,
                'original_request' => $userMessage,
                'sub_agent' => $subAgent->getName(),
            ]
        );

        // Tool genehmigen, da es sich um eine Delegation handelt
        $this->toolGenerator->approveTool($toolDefinition);

        return sprintf(
            "Ich habe deine Anfrage an den **%s** Sub-Agenten delegiert, der sich um diese Aufgabe kümmern wird. " .
            "Der Agent wird die Anfrage bearbeiten und dir das Ergebnis liefern.",
            $subAgent->getName()
        );
    }

    /**
     * Bestimmt den passenden Sub-Agenten basierend auf der User-Anfrage.
     */
    private function determineAndCreateSubAgent(string $userMessage): AgentInterface
    {
        $messageLower = strtolower($userMessage);

        // Webseiten-Recherche
        if (preg_match('/(webseite|website|web page|webpage|url|link|http[s]?:\/\/|www\.)/i', $messageLower)) {
            return $this->subAgentFactory->createWebsiteResearchAgent();
        }

        // Datenanalyse
        if (preg_match('/(daten|data|analyse|analysieren|auswertung|statistik|zahlen)/i', $messageLower)) {
            return $this->subAgentFactory->createDataAnalysisAgent();
        }

        // Code-Assistenz
        if (preg_match('/(code|programm|skript|script|funktion|klassen|php|symfony|entwickeln|develop)/i', $messageLower)) {
            return $this->subAgentFactory->createCodeAssistantAgent();
        }

        // Dokumentenverarbeitung
        if (preg_match('/(dokument|pdf|excel|datei|file|verarbeiten|lesen|extrahieren)/i', $messageLower)) {
            return $this->subAgentFactory->createDocumentProcessorAgent();
        }

        // Kommunikation
        if (preg_match('/(email|e-mail|nachricht|mail|senden|send|linkedin|kommunikation)/i', $messageLower)) {
            return $this->subAgentFactory->createCommunicationManagerAgent();
        }

        // API-Integration
        if (preg_match('/(api|integration|oauth|authentifizierung|anbinden)/i', $messageLower)) {
            return $this->subAgentFactory->createApiIntegrationAgent();
        }

        // Projektmanagement
        if (preg_match('/(projekt|aufgabe|task|termin|planen|scheduling|management)/i', $messageLower)) {
            return $this->subAgentFactory->createProjectManagerAgent();
        }

        // Finanzen
        if (preg_match('/(finanzen|buchhaltung|rechnung|zahlung|geld|kosten)/i', $messageLower)) {
            return $this->subAgentFactory->createFinanceManagerAgent();
        }

        // HR
        if (preg_match('/(mitarbeiter|personal|vertrag|gehalt|hr|human resources)/i', $messageLower)) {
            return $this->subAgentFactory->createHrManagerAgent();
        }

        // Marketing
        if (preg_match('/(marketing|kampagne|social media|content|werbung|werben)/i', $messageLower)) {
            return $this->subAgentFactory->createMarketingManagerAgent();
        }

        // CEO Assistant (Fallback für komplexe Anfragen)
        return $this->subAgentFactory->createCeoAssistantAgent();
    }

    /**
     * Verknüpft einen Sub-Agenten mit einem Tool.
     */
    private function linkSubAgentToTool(ToolDefinition $toolDefinition, AgentInterface $subAgent): void
    {
        // Aktualisiere die Tool-Definition mit Sub-Agenten-Information
        $schema = $toolDefinition->getSchema();
        $schema['properties']['sub_agent'] = [
            'type' => 'string',
            'description' => 'Der Sub-Agent, der für die Ausführung zuständig ist',
            'default' => $subAgent->getName(),
        ];
        $toolDefinition->setSchema($schema);


        // Speichere die Änderungen
        // Note: Dies wird automatisch durch Doctrine gespeichert, wenn die Entity geupdated wird
    }

    /**
     * Generiert eine User-freundliche Antwort mit Freigabe-Link.
     */
    private function generateUserResponse(ToolDefinition $toolDefinition, AgentInterface $subAgent): string
    {
        return sprintf(
            "Ich habe kein passendes Tool für deine Anfrage gefunden. \n\n" .
            "Ich habe jedoch einen **%s** Sub-Agenten identifiziert, der diese Aufgabe übernehmen kann. \n\n" .
            "Ich habe ein neues Tool mit dem Namen **'%s'** entworfen, das diese Aufgabe erfüllen könnte. \n\n" .
            "👉 **Tool freigeben:** Bitte besuche die [Tools-Verwaltung](%s) und genehmige das Tool mit der ID %d. \n\n" .
            "👎 **Tool ablehnen:** Bitte besuche die [Tools-Verwaltung](%s) und lehne das Tool mit der ID %d ab. \n\n" .
            "**Tool-Beschreibung:** %s",
            $subAgent->getName(),
            $toolDefinition->getName(),
            $this->urlGenerator->generate('app_tool_pending_list', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $toolDefinition->getId(),
            $this->urlGenerator->generate('app_tool_pending_list', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $toolDefinition->getId(),
            $toolDefinition->getDescription()
        );
    }

    /**
     * Extrahiere einen sinnvollen Tool-Namen aus der User-Anfrage.
     * Analysiert die Anfrage und generiert passende Namen wie:
     * - "website_scraping" für Webseiten-Zusammenfassungen
     * - "data_analysis" für Datenanalysen
     * - "document_processing" für Dokumentenverarbeitung
     */
    private function extractToolNameFromRequest(string $userMessage): string
    {
        $messageLower = strtolower($userMessage);
        
        // 1. Prüfe auf bestehende Tools und generiere passende Namen
        // Webseiten-Recherche
        if (preg_match('/(webseite|website|web page|webpage|url|http[s]?:\/\/|www\.)/i', $messageLower)) {
            if (preg_match('/(zusammenfassen|zusammenfassung|summarize|summary|analysieren|analyse)/i', $messageLower)) {
                return 'website_scraping';
            }
            if (preg_match('/(durchsuchen|recherche|recherchieren|suche|suchen)/i', $messageLower)) {
                return 'website_research';
            }
            if (preg_match('/(extrahiere|extrahieren|daten abrufen|content extrahieren)/i', $messageLower)) {
                return 'website_content_extraction';
            }
            return 'website_analysis';
        }
        
        // Datenanalyse
        if (preg_match('/(daten|data|analyse|analysieren|auswertung|statistik|zahlen)/i', $messageLower)) {
            if (preg_match('/(tabelle|table|csv|excel)/i', $messageLower)) {
                return 'data_table_analysis';
            }
            if (preg_match('/(statistik|statistics|diagramm|chart)/i', $messageLower)) {
                return 'data_visualization';
            }
            return 'data_analysis';
        }
        
        // Code-Assistenz
        if (preg_match('/(code|programm|skript|script|funktion|klassen|php|symfony|entwickeln|develop)/i', $messageLower)) {
            if (preg_match('/(debuggen|debug|fehler|error|problem)/i', $messageLower)) {
                return 'code_debugging';
            }
            if (preg_match('/(generieren|generieren|erstellen|create)/i', $messageLower)) {
                return 'code_generation';
            }
            return 'code_assistance';
        }
        
        // Dokumentenverarbeitung
        if (preg_match('/(dokument|pdf|excel|datei|file|verarbeiten|lesen|extrahieren)/i', $messageLower)) {
            if (preg_match('/(pdf)/i', $messageLower)) {
                return 'pdf_processing';
            }
            if (preg_match('/(excel|csv)/i', $messageLower)) {
                return 'spreadsheet_processing';
            }
            return 'document_processing';
        }
        
        // E-Mail
        if (preg_match('/(email|e-mail|nachricht|mail|senden|send)/i', $messageLower)) {
            return 'email_management';
        }
        
        // Wetter
        if (preg_match('/(wetter|weather|vorhersage|forecast)/i', $messageLower)) {
            return 'weather_forecast';
        }
        
        // Kalender/Termine
        if (preg_match('/(termin|appointment|kalender|calendar|planen|scheduling)/i', $messageLower)) {
            return 'appointment_scheduling';
        }
        
        // Fallback: Analysiere die ersten Wörter und bereinige
        $words = preg_split('/\s+/', trim($userMessage));
        $toolName = implode('_', array_slice($words, 0, 3));
        
        // Bereinige den Namen
        $toolName = preg_replace('/[^a-zA-Z0-9_]/', '', $toolName);
        $toolName = strtolower($toolName);
        
        // Falls zu kurz, füge einen generischen Präfix hinzu
        if (strlen($toolName) < 3) {
            $toolName = 'custom_tool_' . $toolName;
        }
        
        return $toolName;
    }

    /**
     * Generiere eine bessere Beschreibung für das neue Tool.
     * Nicht die User-Nachricht direkt, sondern eine generische Beschreibung basierend auf dem Tool-Typ.
     */
    private function generateBetterToolDescription(string $userMessage, string $subAgentName): string
    {
        $messageLower = strtolower($userMessage);
        
        // Webseiten-Tools
        if ($subAgentName === 'website_researcher' || str_contains($messageLower, 'webseite') || str_contains($messageLower, 'website')) {
            if (preg_match('/(zusammenfassen|zusammenfassung|summarize)/i', $messageLower)) {
                return 'Fasst Webseiten-Inhalte zusammen und extrahiert wichtige Informationen wie Impressum, Kontakte, Geschäftszweck.';
            }
            if (preg_match('/(durchsuchen|recherche|suche)/i', $messageLower)) {
                return 'Durchsucht Webseiten nach spezifischen Informationen und liefert strukturierte Ergebnisse.';
            }
            return 'Analysiert und verarbeitet Webseiten-Inhalte.';
        }
        
        // Datenanalyse-Tools
        if ($subAgentName === 'data_analyst' || str_contains($messageLower, 'daten') || str_contains($messageLower, 'analyse')) {
            return 'Analysiert Daten, erkennt Muster und liefert statistische Auswertungen.';
        }
        
        // Code-Tools
        if ($subAgentName === 'code_assistant' || str_contains($messageLower, 'code') || str_contains($messageLower, 'programm')) {
            return 'Unterstützt bei der Code-Analyse, Fehlerbehebung und Generierung von Code-Snippets.';
        }
        
        // Dokumenten-Tools
        if ($subAgentName === 'document_processor' || str_contains($messageLower, 'dokument') || str_contains($messageLower, 'pdf')) {
            return 'Verarbeitet Dokumente, extrahiert Daten und erstellt Zusammenfassungen.';
        }
        
        // Kommunikation
        if ($subAgentName === 'communication_manager' || str_contains($messageLower, 'email') || str_contains($messageLower, 'nachricht')) {
            return 'Verwaltet E-Mails, Nachrichten und andere Kommunikationsaufgaben.';
        }
        
        // API-Integration
        if ($subAgentName === 'api_integration' || str_contains($messageLower, 'api')) {
            return 'Bindet externe APIs an, verwaltet Authentifizierung und führt API-Aufrufe durch.';
        }
        
        // Projektmanagement
        if ($subAgentName === 'project_manager' || str_contains($messageLower, 'projekt') || str_contains($messageLower, 'aufgabe')) {
            return 'Verwaltet Projekte, Aufgaben und Termine.';
        }
        
        // Finanzen
        if ($subAgentName === 'finance_manager' || str_contains($messageLower, 'finanzen') || str_contains($messageLower, 'rechnung')) {
            return 'Verwaltet Buchhaltung, Rechnungen und Zahlungen.';
        }
        
        // HR
        if ($subAgentName === 'hr_manager' || str_contains($messageLower, 'mitarbeiter') || str_contains($messageLower, 'personal')) {
            return 'Verwaltet Mitarbeiterdaten, Verträge und Personalangelegenheiten.';
        }
        
        // Marketing
        if ($subAgentName === 'marketing_manager' || str_contains($messageLower, 'marketing') || str_contains($messageLower, 'kampagne')) {
            return 'Verantwortlich für Marketing-Kampagnen, Social Media und Content-Erstellung.';
        }
        
        // CEO Assistant
        if ($subAgentName === 'ceo_assistant') {
            return 'Unterstützt bei strategischen Entscheidungen und Aufgabenpriorisierung.';
        }
        
        // Fallback: Generische Beschreibung
        return 'Führt spezifische Aufgaben basierend auf der User-Anfrage aus.';
    }

    

    /**
     * Behandelt unstrukturierte Antworten vom LLM
     */
    private function handleUnstructuredResponse(string $responseContent, string $userMessage, string $userIdentifier): string
    {
        $this->logger->warning('Unstrukturierte LLM-Antwort erkannt, starte Fallback-Handling');

        // Versuche, die Antwort zu analysieren und passende Aktion zu bestimmen
        $responseLower = strtolower($responseContent);

        // Prüfe auf Webseiten-Recherche-Anfragen
        if (preg_match('/(webseite|website|recherche|suche|research|durchsuchen|zusammenfassen|impressum|kontakte|geschäftszweck|standort|branche|visiongastro)/i', $responseLower)) {
            return $this->handleSubAgentSuggestion($userMessage, $userIdentifier);
        }

        // Prüfe auf Datenanalyse-Anfragen
        if (preg_match('/(daten|analyse|statistik|auswertung|zahlen|diagramm)/i', $responseLower)) {
            return $this->handleSubAgentSuggestion($userMessage, $userIdentifier);
        }

        // Prüfe auf Code-Anfragen
        if (preg_match('/(code|programm|skript|funktion|klassen|php|symfony|entwickeln)/i', $responseLower)) {
            return $this->handleSubAgentSuggestion($userMessage, $userIdentifier);
        }

        // Prüfe auf Dokumenten-Anfragen
        if (preg_match('/(dokument|pdf|excel|datei|verarbeiten|lesen|extrahieren)/i', $responseLower)) {
            return $this->handleSubAgentSuggestion($userMessage, $userIdentifier);
        }

        // Fallback: Erstelle ein Fallback-JSON-Schema
        return $this->createFallbackJsonResponse($responseContent, $userMessage);
    }

    /**
     * Erstellt eine Fallback-JSON-Antwort für unstrukturierte LLM-Antworten
     */
    private function createFallbackJsonResponse(string $llmResponse, string $userMessage): string
    {
        // Analysiere die User-Nachricht, um den Intent zu bestimmen
        $intent = $this->determineIntentFromMessage($userMessage);

        $fallbackResponse = [
            'type' => 'dialog',
            'content' => $llmResponse,
            'status' => 'unstructured',
            'intent' => $intent,
            'suggested_action' => 'subagent_delegation',
            'confidence' => 0.7,
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM)
        ];

        return json_encode($fallbackResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Bestimmt den Intent aus einer User-Nachricht
     */
    private function determineIntentFromMessage(string $message): string
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
     * Behandelt Tool-Call-Antworten
     * Falls tool_name unknown oder leer ist, wird Tool-Generierung ausgelöst
     */
    private function handleToolCallResponse(string $responseContent, string $userMessage, string $userIdentifier): string
    {
        $responseData = json_decode($responseContent, true);
        $toolName = $responseData['tool_name'] ?? 'unknown';
        $parameters = $responseData['parameters'] ?? [];

        // Falls tool_name "unknown" oder leer ist → Tool-Generierung auslösen
        if ($toolName === 'unknown' || empty($toolName) || !isset($responseData['tool_name'])) {
            $this->logger->info('Tool-Call mit unbekanntem oder fehlendem Tool-Namen erkannt, starte Tool-Generierung...');
            return $this->handleToolNotFound($userMessage, $userIdentifier);
        }

        $this->logger->info('Tool-Call erkannt', [
            'tool_name' => $toolName,
            'parameters' => $parameters
        ]);

        // Hier würde normalerweise das Tool ausgeführt werden
        // Für jetzt geben wir eine Bestätigung zurück
        return sprintf(
            "Tool-Aufruf erkannt: **%s** mit Parametern: %s. Die Ausführung wird vorbereitet.",
            $toolName,
            json_encode($parameters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Behandelt Sub-Agent-Delegation
     */
    private function handleSubAgentDelegation(string $responseContent, string $userMessage, string $userIdentifier): string
    {
        $responseData = json_decode($responseContent, true);
        $subAgentType = $responseData['subagent'] ?? 'website_researcher';
        $task = $responseData['task'] ?? $userMessage;

        $this->logger->info('Sub-Agent-Delegation erkannt', [
            'subagent' => $subAgentType,
            'task' => $task
        ]);

        // 1. Sub-Agent erstellen
        $subAgent = $this->determineAndCreateSubAgent($userMessage);

        // 2. Aufgabe an den Sub-Agenten delegieren
        $messages = new MessageBag(Message::ofUser($task));

        // 3. Sub-Agent ausführen
        $result = $subAgent->call($messages);

        // 4. Ergebnis zurückgeben
        return $result->getContent();
    }

    /**
     * Behandelt direkte Dialog-Antworten
     * Prüft, ob die Antwort auf fehlende Tools hindeutet und löst dann Tool-Generierung aus
     */
    private function handleDialogResponse(string $responseContent, string $userMessage, string $userIdentifier): string
    {
        $responseData = json_decode($responseContent, true);

        // Die LLM-Antwort nutzt 'reason' als Schlüssel für die Antwort, 
        // Fallback auf 'content' für Kompatibilität
        $content = $responseData['content'] ?? '';
        $reason = $responseData['reason'] ?? '';
        $intent = $responseData['intent'] ?? 'general';

        // Prüfe, ob die Antwort darauf hindeutet, dass kein Tool verfügbar ist
        if ($this->isNoToolAvailableResponse($reason, $content)) {
            $this->logger->info('Dialog-Antwort enthält Tool-Fehlermeldung, starte Tool-Generierung...');
            return $this->handleToolNotFound($userMessage, $userIdentifier);
        }

        // Normale Dialog-Antwort
        $finalContent = $content ?: $reason ?: 'Keine Antwort verfügbar';

        $this->logger->info('Dialog-Antwort erkannt', [
            'intent' => $intent,
            'content_length' => strlen($finalContent)
        ]);

        return $finalContent;
    }

    /**
     * Behandelt website_research_result Antworten
     */
    private function handleWebsiteResearchResponse(string $responseContent): string
    {
        $responseData = json_decode($responseContent, true);
        
        // Extrahiere die wichtigsten Informationen
        $url = $responseData['url'] ?? 'Unbekannte URL';
        $summary = $responseData['zusammenfassung'] ?? $responseData['summary'] ?? '';
        $impressum = $responseData['impressum'] ?? [];
        $kontakte = $responseData['kontakte'] ?? [];
        
        // Formatiere die Antwort für den User
        $output = "### Webseiten-Zusammenfassung: {$url}\n\n";
        
        if (!empty($summary)) {
            if (is_array($summary)) {
                $output .= "**Zusammenfassung:**\n";
                foreach ($summary as $key => $value) {
                    if (is_array($value)) {
                        $output .= "- **{$key}:** " . implode(', ', $value) . "\n";
                    } else {
                        $output .= "- **{$key}:** {$value}\n";
                    }
                }
            } else {
                $output .= "**Zusammenfassung:** {$summary}\n\n";
            }
        }
        
        if (!empty($impressum)) {
            $output .= "\n**Impressum:**\n";
            foreach ($impressum as $key => $value) {
                $output .= "- **{$key}:** {$value}\n";
            }
        }
        
        if (!empty($kontakte) && is_array($kontakte)) {
            $output .= "\n**Kontakte:**\n";
            foreach ($kontakte as $kontakt) {
                $name = $kontakt['name'] ?? 'Unbekannt';
                $email = $kontakt['email'] ?? 'Nicht angegeben';
                $telefon = $kontakt['telefon'] ?? 'Nicht angegeben';
                $output .= "- **{$name}:** {$email}, Tel: {$telefon}\n";
            }
        }
        
        $this->logger->info('Website Research Antwort formatiert');
        return $output;
    }
}
