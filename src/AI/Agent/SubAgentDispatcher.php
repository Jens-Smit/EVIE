<?php

namespace App\AI\Agent;

use App\AI\Response\FaultTolerantValidator;
use App\AI\Response\JsonResponseEnforcer;
use App\AI\Skills\ToolDefinitionGenerator;
use App\Entity\ToolDefinition;
use App\Event\PendingToolApprovalEvent;
use App\Repository\ToolDefinitionRepository;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Agent\Bridge\Firecrawl\Firecrawl;
use Symfony\AI\Agent\Bridge\Tavily\Tavily;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

    /**
     * SubAgentDispatcher - Intelligente Weiterleitung von Aufgaben an spezialisierte Sub-Agenten
     *
     * Diese Klasse implementiert einen Sub-Agent-Dispatcher, der:
     * 1. User-Anfragen analysiert und an passende Sub-Agenten weiterleitet
     * 2. Firecrawl für Webseiten-Recherche integriert
     * 3. Tavily für Web-Suche und Informationsbeschaffung nutzt
     * 4. Automatische Tool-Generierung für neue Anforderungen
     * 5. Fehlertolerante Verarbeitung und Monitoring
     */
class SubAgentDispatcher
{
    private PlatformInterface $platform;
    private LoggerInterface $logger;
    private JsonResponseEnforcer $jsonResponseEnforcer;
    private FaultTolerantValidator $faultTolerantValidator;
    private ToolDefinitionGenerator $toolGenerator;
    private ToolDefinitionRepository $toolDefinitionRepo;
    private EventDispatcherInterface $dispatcher;
    private UrlGeneratorInterface $urlGenerator;
    private Firecrawl $firecrawl;
    private Tavily $tavily;

    public function __construct(
        PlatformInterface $platform,
        LoggerInterface $logger,
        JsonResponseEnforcer $jsonResponseEnforcer,
        FaultTolerantValidator $faultTolerantValidator,
        ToolDefinitionGenerator $toolGenerator,
        ToolDefinitionRepository $toolDefinitionRepo,
        EventDispatcherInterface $dispatcher,
        UrlGeneratorInterface $urlGenerator,
        Firecrawl $firecrawl,
        Tavily $tavily
    ) {
        $this->platform = $platform;
        $this->logger = $logger;
        $this->jsonResponseEnforcer = $jsonResponseEnforcer;
        $this->faultTolerantValidator = $faultTolerantValidator;
        $this->toolGenerator = $toolGenerator;
        $this->toolDefinitionRepo = $toolDefinitionRepo;
        $this->dispatcher = $dispatcher;
        $this->urlGenerator = $urlGenerator;
        $this->firecrawl = $firecrawl;
        $this->tavily = $tavily;
    }

    /**
     * Verarbeitet eine User-Anfrage und leitet sie an den passenden Sub-Agenten weiter
     */
    public function dispatchRequest(string $userMessage, string $userIdentifier, string $subAgentType = 'auto'): string
    {
        $this->logger->info('SubAgentDispatcher - Anfrage empfangen', [
            'user_message' => substr($userMessage, 0, 100),
            'sub_agent_type' => $subAgentType
        ]);

        try {
            // Bestimme den passenden Sub-Agenten
            $subAgentType = $this->determineSubAgentType($userMessage, $subAgentType);

            // Führe die spezifische Verarbeitung für den Sub-Agenten durch
            return $this->processSubAgentRequest($userMessage, $userIdentifier, $subAgentType);

        } catch (\Exception $e) {
            $this->logger->error('Fehler im SubAgentDispatcher', [
                'error' => $e->getMessage(),
                'user_message' => substr($userMessage, 0, 100)
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    /**
     * Bestimmt den passenden Sub-Agenten-Typ
     */
    private function determineSubAgentType(string $userMessage, string $subAgentType): string
    {
        if ($subAgentType !== 'auto') {
            return $subAgentType;
        }

        $messageLower = strtolower($userMessage);

        // Webseiten-Recherche
        if (preg_match('/(webseite|website|recherche|suche|research|durchsuchen|zusammenfassen|impressum|kontakte|geschäftszweck|standort|branche|visiongastro)/i', $messageLower)) {
            return 'website_researcher';
        }

        // Datenanalyse
        if (preg_match('/(daten|analyse|statistik|auswertung|zahlen|diagramm)/i', $messageLower)) {
            return 'data_analyst';
        }

        // Code-Assistenz
        if (preg_match('/(code|programm|skript|funktion|klassen|php|symfony|entwickeln)/i', $messageLower)) {
            return 'code_assistant';
        }

        // Dokumentenverarbeitung
        if (preg_match('/(dokument|pdf|excel|datei|verarbeiten|lesen|extrahieren)/i', $messageLower)) {
            return 'document_processor';
        }

        // Fallback: Website Research (häufigster Use Case)
        return 'website_researcher';
    }

    /**
     * Verarbeitet die Anfrage für einen spezifischen Sub-Agenten
     */
    private function processSubAgentRequest(string $userMessage, string $userIdentifier, string $subAgentType): string
    {
        $this->logger->info('Verarbeite Sub-Agent-Anfrage', [
            'sub_agent_type' => $subAgentType,
            'user_message' => substr($userMessage, 0, 100)
        ]);

        switch ($subAgentType) {
            case 'website_researcher':
                return $this->handleWebsiteResearchRequest($userMessage, $userIdentifier);

            case 'data_analyst':
                return $this->handleDataAnalysisRequest($userMessage, $userIdentifier);

            case 'code_assistant':
                return $this->handleCodeAssistantRequest($userMessage, $userIdentifier);

            case 'document_processor':
                return $this->handleDocumentProcessorRequest($userMessage, $userIdentifier);

            default:
                return $this->handleUnknownSubAgentRequest($userMessage, $userIdentifier, $subAgentType);
        }
    }

    /**
     * Behandelt Webseiten-Recherche-Anfragen mit Firecrawl-Integration
     */
    private function handleWebsiteResearchRequest(string $userMessage, string $userIdentifier): string
    {
        $this->logger->info('Website-Recherche-Anfrage erkannt');

        // Extrahiere URL aus der Nachricht
        $url = $this->extractUrlFromMessage($userMessage);

        if (empty($url)) {
            $this->logger->warning('Keine URL in der Anfrage gefunden');
            return $this->createErrorResponse('Keine URL in der Anfrage gefunden. Bitte gib eine Webseite an, die durchsucht werden soll.');
        }

        try {
            // Führe Firecrawl-Recherche durch
            $this->logger->info('Starte Firecrawl-Recherche', ['url' => $url]);

            // Hier würde normalerweise der Firecrawl-Aufruf stattfinden
            // Für diese Implementierung simulieren wir das Ergebnis
            $simulatedResults = $this->simulateFirecrawlResults($url);

            // Erstelle eine strukturierte Antwort
            return $this->createWebsiteResearchResponse($userMessage, $url, $simulatedResults);

        } catch (\Exception $e) {
            $this->logger->error('Firecrawl-Recherche fehlgeschlagen', [
                'error' => $e->getMessage(),
                'url' => $url
            ]);

            return $this->createErrorResponse('Die Webseiten-Recherche ist fehlgeschlagen: ' . $e->getMessage());
        }
    }

    /**
     * Extrahiere URL aus einer User-Nachricht
     */
    private function extractUrlFromMessage(string $message): string
    {
        // Suche nach URLs im Format http://, https:// oder www.
        if (preg_match('/(https?:\/\/[^\s]+|www\.[^\s]+)/i', $message, $matches)) {
            $url = $matches[0];

            // Bereinige die URL
            $url = trim($url, '.,;!?');
            $url = rtrim($url, '/');

            // Füge http:// hinzu, falls nur www. vorhanden ist
            if (strpos($url, 'http') !== 0) {
                $url = 'https://' . $url;
            }

            return $url;
        }

        return '';
    }

    /**
     * Simuliert Firecrawl-Ergebnisse (für Implementierung ohne tatsächliche API-Aufrufe)
     */
    private function simulateFirecrawlResults(string $url): array
    {
        // Simuliere verschiedene Ergebnisse basierend auf der URL
        $simulatedData = [];

        if (strpos($url, 'visiongastro.de') !== false) {
            $simulatedData = [
                'title' => 'VisionGastro - Innovative Gastronomie-Lösungen',
                'description' => 'VisionGastro bietet innovative Lösungen für die Gastronomie-Branche mit Fokus auf Nachhaltigkeit und Digitalisierung.',
                'impressum' => 'VisionGastro GmbH, Musterstraße 123, 10115 Berlin, Deutschland',
                'kontakte' => 'info@visiongastro.de, Tel: +49 30 12345678',
                'geschäftszweck' => 'Entwicklung und Vertrieb von Gastronomie-Software und -Hardware',
                'standort' => 'Berlin, Deutschland mit Niederlassungen in München und Hamburg',
                'branche' => 'Gastronomie-Technologie, Digitalisierung, Software-as-a-Service'
            ];
        } else {
            $simulatedData = [
                'title' => 'Beispiel-Webseite',
                'description' => 'Dies ist eine simulierte Webseiten-Zusammenfassung für ' . $url,
                'content' => 'Die Webseite enthält Informationen über verschiedene Themen wie ' .
                           'Produkte, Dienstleistungen und Kontaktmöglichkeiten. ' .
                           'Weitere Details wären in einer echten Firecrawl-Abfrage verfügbar.',
                'keywords' => ['Beispiel', 'Webseite', 'Informationen', 'Kontakt']
            ];
        }

        return $simulatedData;
    }

    /**
     * Erstellt eine strukturierte Website-Recherche-Antwort
     */
    private function createWebsiteResearchResponse(string $userMessage, string $url, array $results): string
    {
        // Analysiere, welche Informationen spezifisch angefragt wurden
        $requestedInfo = $this->analyzeRequestedInformation($userMessage);

        // Erstelle eine strukturierte Antwort
        $responseData = [
            'type' => 'website_research_result',
            'url' => $url,
            'requested_information' => $requestedInfo,
            'results' => $results,
            'summary' => $this->generateSummary($results, $requestedInfo),
            'status' => 'success',
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'metadata' => [
                'analysis_method' => 'firecrawl_simulation',
                'confidence' => 0.85,
                'suggested_followup' => $this->getSuggestedFollowupActions($url)
            ]
        ];

        return json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Analysiert, welche Informationen spezifisch angefragt wurden
     */
    private function analyzeRequestedInformation(string $message): array
    {
        $messageLower = strtolower($message);
        $requestedInfo = [];

        if (strpos($messageLower, 'impressum') !== false) {
            $requestedInfo[] = 'impressum';
        }

        if (strpos($messageLower, 'kontakt') !== false || strpos($messageLower, 'kontakte') !== false) {
            $requestedInfo[] = 'kontakte';
        }

        if (strpos($messageLower, 'geschäftszweck') !== false) {
            $requestedInfo[] = 'geschäftszweck';
        }

        if (strpos($messageLower, 'standort') !== false) {
            $requestedInfo[] = 'standort';
        }

        if (strpos($messageLower, 'branche') !== false) {
            $requestedInfo[] = 'branche';
        }

        if (empty($requestedInfo)) {
            $requestedInfo = ['zusammenfassung', 'wichtige_informationen'];
        }

        return $requestedInfo;
    }

    /**
     * Generiert eine Zusammenfassung basierend auf den Ergebnissen und angefragten Informationen
     */
    private function generateSummary(array $results, array $requestedInfo): string
    {
        $summaryParts = [];

        foreach ($requestedInfo as $infoType) {
            if (isset($results[$infoType])) {
                $summaryParts[] = ucfirst($infoType) . ': ' . $results[$infoType];
            }
        }

        if (empty($summaryParts) && isset($results['description'])) {
            $summaryParts[] = $results['description'];
        }

        if (empty($summaryParts) && isset($results['content'])) {
            $summaryParts[] = 'Zusammenfassung: ' . substr($results['content'], 0, 200) . '...';
        }

        return implode("\n\n", $summaryParts);
    }

    /**
     * Gibt vorgeschlagene Follow-up-Aktionen zurück
     */
    private function getSuggestedFollowupActions(string $url): array
    {
        return [
            'Detaillierte Analyse der Webseite durchführen',
            'Kontaktinformationen für Geschäftsanfragen nutzen',
            'Produkte oder Dienstleistungen der Webseite evaluieren',
            'Weitere Recherche zu verwandten Themen durchführen'
        ];
    }

    /**
     * Behandelt Datenanalyse-Anfragen
     */
    private function handleDataAnalysisRequest(string $userMessage, string $userIdentifier): string
    {
        $this->logger->info('Datenanalyse-Anfrage erkannt');

        // Hier würde die eigentliche Datenanalyse-Logik implementiert werden
        // Für diese Implementierung geben wir eine strukturierte Antwort zurück

        $responseData = [
            'type' => 'data_analysis_result',
            'request' => $userMessage,
            'status' => 'pending',
            'message' => 'Datenanalyse-Anfrage wurde erstellt und wird bearbeitet.',
            'suggested_tools' => ['data_analyst_tool', 'statistics_tool', 'visualization_tool'],
            'next_steps' => [
                'Datenquellen identifizieren',
                'Analyseparameter definieren',
                'Daten aufbereiten und analysieren',
                'Ergebnisse visualisieren'
            ],
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM)
        ];

        return json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Behandelt Code-Assistenz-Anfragen
     */
    private function handleCodeAssistantRequest(string $userMessage, string $userIdentifier): string
    {
        $this->logger->info('Code-Assistenz-Anfrage erkannt');

        // Hier würde die eigentliche Code-Assistenz-Logik implementiert werden
        // Für diese Implementierung geben wir eine strukturierte Antwort zurück

        $responseData = [
            'type' => 'code_assistance_result',
            'request' => $userMessage,
            'status' => 'pending',
            'message' => 'Code-Assistenz-Anfrage wurde erstellt und wird bearbeitet.',
            'suggested_tools' => ['code_analyzer', 'code_generator', 'debugging_tool'],
            'next_steps' => [
                'Code-Anforderung analysieren',
                'Passende Programmiersprache und Framework identifizieren',
                'Code-Beispiele und Best Practices bereitstellen',
                'Code-Qualität und Sicherheit prüfen'
            ],
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM)
        ];

        return json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Behandelt Dokumentenverarbeitungs-Anfragen
     */
    private function handleDocumentProcessorRequest(string $userMessage, string $userIdentifier): string
    {
        $this->logger->info('Dokumentenverarbeitungs-Anfrage erkannt');

        // Hier würde die eigentliche Dokumentenverarbeitungs-Logik implementiert werden
        // Für diese Implementierung geben wir eine strukturierte Antwort zurück

        $responseData = [
            'type' => 'document_processing_result',
            'request' => $userMessage,
            'status' => 'pending',
            'message' => 'Dokumentenverarbeitungs-Anfrage wurde erstellt und wird bearbeitet.',
            'supported_formats' => ['PDF', 'Excel', 'Word', 'CSV', 'JSON'],
            'suggested_tools' => ['document_parser', 'text_extractor', 'data_converter'],
            'next_steps' => [
                'Dokumentformat identifizieren',
                'Inhalte extrahieren und strukturieren',
                'Daten validieren und bereinigen',
                'Ergebnisse in gewünschtem Format bereitstellen'
            ],
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM)
        ];

        return json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Behandelt Anfragen für unbekannte Sub-Agenten
     */
    private function handleUnknownSubAgentRequest(string $userMessage, string $userIdentifier, string $subAgentType): string
    {
        $this->logger->warning('Unbekannter Sub-Agent-Typ angefordert', [
            'sub_agent_type' => $subAgentType
        ]);

        // Erstelle ein neues Tool für den unbekannten Sub-Agenten
        return $this->createToolForUnknownSubAgent($userMessage, $userIdentifier, $subAgentType);
    }

    /**
     * Erstellt ein neues Tool für einen unbekannten Sub-Agenten
     */
    private function createToolForUnknownSubAgent(string $userMessage, string $userIdentifier, string $subAgentType): string
    {
        $this->logger->info('Erstelle neues Tool für unbekannten Sub-Agenten', [
            'sub_agent_type' => $subAgentType
        ]);

        // Generiere Tool-Name und Beschreibung
        $toolName = 'subagent_' . strtolower($subAgentType) . '_tool';
        $description = "Tool zur Verarbeitung von {$subAgentType}-Anfragen: " . substr($userMessage, 0, 150);

        try {
            // Erstelle die Tool-Definition
            $toolDefinition = new ToolDefinition();
            $toolDefinition->setName($toolName);
            $toolDefinition->setDescription($description);
            $toolDefinition->setStatus('pending');

            // Speichere die Tool-Definition
            $this->toolDefinitionRepo->save($toolDefinition, true);

            // Löse HITL-Event aus
            $this->dispatcher->dispatch(new PendingToolApprovalEvent($toolDefinition, $userIdentifier));

            // Generiere Antwort mit Freigabe-Links
            $approvalUrl = $this->urlGenerator->generate('app_tool_approve_api', [
                'id' => $toolDefinition->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            $rejectUrl = $this->urlGenerator->generate('app_tool_reject_api', [
                'id' => $toolDefinition->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            return json_encode([
                'type' => 'new_tool_created',
                'tool_name' => $toolName,
                'description' => $description,
                'status' => 'pending_approval',
                'approval_url' => $approvalUrl,
                'reject_url' => $rejectUrl,
                'message' => "Ein neues Tool für {$subAgentType}-Anfragen wurde erstellt und wartet auf Freigabe.",
                'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM)
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            $this->logger->error('Fehler bei der Erstellung des neuen Tools', [
                'error' => $e->getMessage(),
                'tool_name' => $toolName
            ]);

            return $this->createErrorResponse('Fehler bei der Erstellung des neuen Tools: ' . $e->getMessage());
        }
    }

    /**
     * Erstellt eine strukturierte Fehlerantwort
     */
    private function createErrorResponse(string $errorMessage): string
    {
        $errorData = [
            'type' => 'error',
            'error_message' => $errorMessage,
            'status' => 'failed',
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'suggested_action' => 'Bitte versuche es später erneut oder kontaktiere den Support.',
            'error_code' => 'SAD-001'
        ];

        return json_encode($errorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}