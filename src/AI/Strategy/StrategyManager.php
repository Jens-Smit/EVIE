<?php
// src/AI/Strategy/StrategyManager.php

namespace App\AI\Strategy;

use App\AI\Agent\SubAgentFactory;
use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Entwickelt Strategien für komplexe Aufgaben.
 * Analysiert User-Anfragen, identifiziert benötigte Tools und Sub-Agenten,
 * und erstellt Ausführungspläne.
 */
class StrategyManager
{
    public function __construct(
        private ToolDefinitionRepository $toolDefinitionRepo,
        private SubAgentFactory $subAgentFactory,
        private PlatformInterface $platform,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Entwickelt eine Strategie für eine komplexe Aufgabe
     */
    public function developStrategy(string $taskDescription, string $userIdentifier): array
    {
        $this->logger->info('Entwickle Strategie für Aufgabe', [
            'task' => substr($taskDescription, 0, 100),
            'user' => $userIdentifier,
        ]);

        // 1. Aufgabe analysieren
        $analysis = $this->analyzeTask($taskDescription);

        // 2. Benötigte Tools identifizieren
        $requiredTools = $this->identifyRequiredTools($analysis, $taskDescription);

        // 3. Sub-Agenten auswählen
        $requiredAgents = $this->selectSubAgents($analysis);

        // 4. Aufgaben in Schritte zerlegen
        $steps = $this->breakDownTask($analysis, $requiredTools, $requiredAgents, $taskDescription);

        // 5. Ausführungsplan erstellen
        $executionPlan = $this->createExecutionPlan($steps);

        $strategy = [
            'task' => $taskDescription,
            'analysis' => $analysis,
            'required_tools' => $requiredTools,
            'required_agents' => array_map(fn($agent) => $agent->getName(), $requiredAgents),
            'execution_plan' => $executionPlan,
            'estimated_duration' => $this->estimateDuration($executionPlan),
            'risk_assessment' => $this->assessRisk($executionPlan),
            'recommendations' => $this->generateRecommendations($analysis, $executionPlan),
        ];

        $this->logger->info('Strategie entwickelt', [
            'steps_count' => count($executionPlan['steps']),
            'estimated_duration' => $strategy['estimated_duration'],
            'risk_level' => $strategy['risk_assessment']['level'],
        ]);

        return $strategy;
    }

    /**
     * Analysiert eine Aufgabe
     */
    private function analyzeTask(string $description): array
    {
        return [
            'intent' => $this->determineIntent($description),
            'complexity' => $this->determineComplexity($description),
            'domain' => $this->determineDomain($description),
            'keywords' => $this->extractKeywords($description),
            'entities' => $this->extractEntities($description),
        ];
    }

    /**
     * Bestimmt die Absicht (Intent) der Aufgabe
     */
    private function determineIntent(string $description): string
    {
        $descriptionLower = strtolower($description);

        if (preg_match('/(analysier|zusammenfass|recherchier|such|find)/i', $descriptionLower)) {
            return 'research';
        }
        if (preg_match('/(erstell|generier|entwickel|schreib)/i', $descriptionLower)) {
            return 'creation';
        }
        if (preg_match('/(analysier|auswert|berechn|statistik)/i', $descriptionLower)) {
            return 'analysis';
        }
        if (preg_match('/(entscheid|wähl|genehmig|ablehn)/i', $descriptionLower)) {
            return 'decision';
        }
        if (preg_match('/(plan|organisier|koordinier)/i', $descriptionLower)) {
            return 'planning';
        }
        if (preg_match('/(automatisier|integrier|verbind)/i', $descriptionLower)) {
            return 'integration';
        }

        return 'general';
    }

    /**
     * Bestimmt die Komplexität der Aufgabe
     */
    private function determineComplexity(string $description): string
    {
        $wordCount = str_word_count($description);
        $sentenceCount = preg_match_all('/[.!?]+/', $description);

        if ($wordCount > 20 || $sentenceCount > 3) {
            return 'high';
        }
        if ($wordCount > 10 || $sentenceCount > 1) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Bestimmt den Bereich (Domain) der Aufgabe
     */
    private function determineDomain(string $description): string
    {
        $descriptionLower = strtolower($description);

        if (preg_match('/(web|seite|url|html|online)/i', $descriptionLower)) {
            return 'web';
        }
        if (preg_match('/(daten|analyse|statistik|zahl)/i', $descriptionLower)) {
            return 'data';
        }
        if (preg_match('/(mail|email|nachricht|kommunikation|linkedin|slack)/i', $descriptionLower)) {
            return 'communication';
        }
        if (preg_match('/(api|oauth|auth|zugang|rest|graphql)/i', $descriptionLower)) {
            return 'api_integration';
        }
        if (preg_match('/(code|programm|skript|entwickeln|php|symfony)/i', $descriptionLower)) {
            return 'code';
        }
        if (preg_match('/(dokument|pdf|excel|datei)/i', $descriptionLower)) {
            return 'document';
        }
        if (preg_match('/(projekt|aufgabe|termin|planung)/i', $descriptionLower)) {
            return 'project_management';
        }
        if (preg_match('/(finanz|buchhaltung|rechnung|zahlung)/i', $descriptionLower)) {
            return 'finance';
        }
        if (preg_match('/(mitarbeiter|personal|gehalt|vertrag)/i', $descriptionLower)) {
            return 'hr';
        }
        if (preg_match('/(marketing|kampagne|werbung|social media)/i', $descriptionLower)) {
            return 'marketing';
        }

        return 'general';
    }

    /**
     * Extrahiere Keywords aus einer Beschreibung
     */
    private function extractKeywords(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        $words = preg_split('/\s+/', $text);

        return array_filter(array_unique($words), function($word) {
            return strlen($word) > 3;
        });
    }

    /**
     * Extrahiere Entitäten (Namen, Orte, Unternehmen etc.)
     */
    private function extractEntities(string $description): array
    {
        $entities = [];

        // URL Erkennung
        if (preg_match_all('/https?:\/\/[^\s]+/', $description, $matches)) {
            $entities['urls'] = $matches[0];
        }

        // E-Mail Erkennung
        if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $description, $matches)) {
            $entities['emails'] = $matches[0];
        }

        return $entities;
    }

    /**
     * Identifiziert benötigte Tools basierend auf der Analyse
     */
    private function identifyRequiredTools(array $analysis, string $taskDescription): array
    {
        $requiredTools = [];
        $domain = $analysis['domain'];
        $intent = $analysis['intent'];

        // Basierend auf Domain und Intent
        switch ($domain) {
            case 'web':
                if ($intent === 'research') {
                    $requiredTools[] = $this->createToolRequirement(
                        'web_scraper',
                        'Webseiten durchsuchen und Inhalte extrahieren',
                        'web_scraping'
                    );
                    $requiredTools[] = $this->createToolRequirement(
                        'content_analyzer',
                        'Inhalte analysieren und zusammenfassen',
                        'web_scraping'
                    );
                }
                break;

            case 'communication':
                if ($intent === 'creation' || $intent === 'research') {
                    $requiredTools[] = $this->createToolRequirement(
                        'email_sender',
                        'E-Mails senden',
                        'communication'
                    );
                    $requiredTools[] = $this->createToolRequirement(
                        'linkedin_connector',
                        'LinkedIn API Verbindung',
                        'communication'
                    );
                }
                break;

            case 'data':
                if ($intent === 'analysis') {
                    $requiredTools[] = $this->createToolRequirement(
                        'data_analyzer',
                        'Daten analysieren und Muster erkennen',
                        'data_analysis',
                        'existing'
                    );
                }
                break;

            case 'api_integration':
                $requiredTools[] = $this->createToolRequirement(
                    'oauth_handler',
                    'OAuth Authentifizierung verwalten',
                    'api_integration'
                );
                $requiredTools[] = $this->createToolRequirement(
                    'api_caller',
                    'API-Anfragen durchführen',
                    'api_integration'
                );
                break;
        }

        // Prüfe, ob ähnliche Tools bereits existieren
        foreach ($requiredTools as &$tool) {
            if ($tool['type'] === 'new') {
                $existingTool = $this->toolDefinitionRepo->findOneBy([
                    'name' => $tool['name'],
                    'status' => 'approved',
                ]);

                if ($existingTool) {
                    $tool['type'] = 'existing';
                    $tool['id'] = $existingTool->getId();
                }
            }
        }

        return $requiredTools;
    }

    /**
     * Erstellt eine Tool-Anforderung
     */
    private function createToolRequirement(
        string $name,
        string $description,
        string $category,
        string $type = 'new'
    ): array {
        return [
            'name' => $name,
            'type' => $type,
            'description' => $description,
            'category' => $category,
        ];
    }

    /**
     * Wählt passende Sub-Agenten basierend auf der Analyse
     */
    private function selectSubAgents(array $analysis): array
    {
        $agents = [];
        $domain = $analysis['domain'];
        $intent = $analysis['intent'];

        // Primärer Agent basierend auf Domain
        switch ($domain) {
            case 'web':
                $agents[] = $this->subAgentFactory->createWebsiteResearchAgent();
                break;
            case 'data':
                $agents[] = $this->subAgentFactory->createDataAnalysisAgent();
                break;
            case 'communication':
                $agents[] = $this->subAgentFactory->createCommunicationManagerAgent();
                break;
            case 'api_integration':
                $agents[] = $this->subAgentFactory->createApiIntegrationAgent();
                break;
            case 'code':
                $agents[] = $this->subAgentFactory->createCodeAssistantAgent();
                break;
            case 'document':
                $agents[] = $this->subAgentFactory->createDocumentProcessorAgent();
                break;
            case 'project_management':
                $agents[] = $this->subAgentFactory->createProjectManagerAgent();
                break;
            case 'finance':
                $agents[] = $this->subAgentFactory->createFinanceManagerAgent();
                break;
            case 'hr':
                $agents[] = $this->subAgentFactory->createHrManagerAgent();
                break;
            case 'marketing':
                $agents[] = $this->subAgentFactory->createMarketingManagerAgent();
                break;
            default:
                // Für komplexe Aufgaben: CEO Assistant als Koordinator
                if ($analysis['complexity'] === 'high') {
                    $agents[] = $this->subAgentFactory->createCeoAssistantAgent();
                }
        }

        // Sekundäre Agenten basierend auf Intent
        if ($intent === 'decision' && !in_array('ceo_assistant', array_map(fn($a) => $a->getName(), $agents))) {
            $agents[] = $this->subAgentFactory->createCeoAssistantAgent();
        }

        return $agents;
    }

    /**
     * Zerlegt eine Aufgabe in einzelne Schritte
     */
    private function breakDownTask(
        array $analysis,
        array $requiredTools,
        array $requiredAgents,
        string $taskDescription
    ): array {
        $steps = [];
        $domain = $analysis['domain'];
        $intent = $analysis['intent'];
        $complexity = $analysis['complexity'];

        // Standard-Schritte für alle Aufgaben
        $steps[] = $this->createStep(
            1,
            'Aufgabe analysieren und Anforderungen verstehen',
            'orchestrator',
            null,
            '10s',
            []
        );

        // Domänenspezifische Schritte
        switch ($domain) {
            case 'web':
                if ($intent === 'research') {
                    $steps[] = $this->createStep(
                        2,
                        'Webseite identifizieren und URL extrahieren',
                        'orchestrator',
                        null,
                        '5s',
                        [1]
                    );
                    $steps[] = $this->createStep(
                        3,
                        'Webseite mit Firecrawl durchsuchen',
                        'website_researcher',
                        'firecrawl_search',
                        '30s',
                        [2]
                    );
                    $steps[] = $this->createStep(
                        4,
                        'Inhalte extrahieren und strukturieren',
                        'website_researcher',
                        'content_extractor',
                        '20s',
                        [3]
                    );
                    $steps[] = $this->createStep(
                        5,
                        'Ergebnisse zusammenfassen und formatieren',
                        'website_researcher',
                        'summary_generator',
                        '15s',
                        [4]
                    );
                }
                break;

            case 'communication':
                if ($intent === 'creation') {
                    $steps[] = $this->createStep(
                        2,
                        'Empfänger und Inhalte vorbereiten',
                        'communication_manager',
                        null,
                        '15s',
                        [1]
                    );
                    $steps[] = $this->createStep(
                        3,
                        'E-Mail oder Nachricht senden',
                        'communication_manager',
                        'email_sender',
                        '20s',
                        [2]
                    );
                    $steps[] = $this->createStep(
                        4,
                        'Bestätigung und Protokollierung',
                        'communication_manager',
                        null,
                        '5s',
                        [3]
                    );
                }
                break;

            case 'data':
                if ($intent === 'analysis') {
                    $steps[] = $this->createStep(
                        2,
                        'Datenquelle identifizieren und laden',
                        'data_analyst',
                        'data_loader',
                        '20s',
                        [1]
                    );
                    $steps[] = $this->createStep(
                        3,
                        'Daten analysieren und Muster erkennen',
                        'data_analyst',
                        'data_analyzer',
                        '40s',
                        [2]
                    );
                    $steps[] = $this->createStep(
                        4,
                        'Ergebnisse visualisieren',
                        'data_analyst',
                        'visualization_generator',
                        '30s',
                        [3]
                    );
                }
                break;
        }

        // Für komplexe Aufgaben: CEO Assistant als Koordinator
        if ($complexity === 'high' && count($steps) > 1) {
            array_unshift($steps, $this->createStep(
                1,
                'Strategie entwickeln und Aufgaben planen',
                'ceo_assistant',
                null,
                '20s',
                []
            ));
            // Renumber all steps
            foreach ($steps as $index => &$step) {
                $step['step'] = $index + 1;
            }
        }

        return $steps;
    }

    /**
     * Erstellt einen Schritt für den Ausführungsplan
     */
    private function createStep(
        int $stepNumber,
        string $description,
        string $agent,
        ?string $tool,
        string $estimatedTime,
        array $dependencies
    ): array {
        return [
            'step' => $stepNumber,
            'description' => $description,
            'agent' => $agent,
            'tool' => $tool,
            'estimated_time' => $estimatedTime,
            'dependencies' => $dependencies,
        ];
    }

    /**
     * Erstellt einen Ausführungsplan
     */
    private function createExecutionPlan(array $steps): array
    {
        $parallelExecution = $this->canExecuteInParallel($steps);
        $fallbackStrategy = $this->determineFallbackStrategy($steps);

        return [
            'steps' => $steps,
            'parallel_execution' => $parallelExecution,
            'fallback_strategy' => $fallbackStrategy,
            'total_steps' => count($steps),
        ];
    }

    /**
     * Prüft, ob Schritte parallel ausgeführt werden können
     */
    private function canExecuteInParallel(array $steps): bool
    {
        // Einfache Logik: Wenn keine Abhängigkeiten zwischen Schritten bestehen
        foreach ($steps as $step) {
            if (!empty($step['dependencies'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Bestimmt die Fallback-Strategie
     */
    private function determineFallbackStrategy(array $steps): array
    {
        return [
            'type' => 'continue_with_reduced_functionality',
            'on_failure' => [
                'log_error' => true,
                'notify_user' => true,
                'skip_step' => true,
                'continue_execution' => true,
            ],
        ];
    }

    /**
     * Schätzt die Gesamtdauer
     */
    private function estimateDuration(array $executionPlan): string
    {
        $totalSeconds = 0;
        foreach ($executionPlan['steps'] as $step) {
            $time = $this->parseTimeString($step['estimated_time']);
            $totalSeconds += $time;
        }

        return $this->formatDuration($totalSeconds);
    }

    /**
     * Parsed Zeit-Strings (z.B. "30s", "2m", "1h")
     */
    private function parseTimeString(string $timeString): int
    {
        if (preg_match('/(\d+)s/', $timeString, $matches)) {
            return (int)$matches[1];
        }
        if (preg_match('/(\d+)m/', $timeString, $matches)) {
            return (int)$matches[1] * 60;
        }
        if (preg_match('/(\d+)h/', $timeString, $matches)) {
            return (int)$matches[1] * 3600;
        }
        return 10; // Standard: 10 Sekunden
    }

    /**
     * Formatiert Sekunden in lesbare Dauer
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $remainingSeconds = $seconds % 60;
            return $minutes . 'm ' . $remainingSeconds . 's';
        }
        $hours = floor($seconds / 3600);
        $remainingMinutes = floor(($seconds % 3600) / 60);
        return $hours . 'h ' . $remainingMinutes . 'm';
    }

    /**
     * Bewertet das Risiko der Ausführung
     */
    private function assessRisk(array $executionPlan): array
    {
        $riskLevel = 'low';
        $riskFactors = [];

        // Prüfe auf kritische Agenten
        $criticalAgents = ['ceo_assistant', 'finance_manager', 'hr_manager'];
        foreach ($executionPlan['steps'] as $step) {
            if (in_array($step['agent'], $criticalAgents)) {
                $riskLevel = 'high';
                $riskFactors[] = 'Kritischer Agent: ' . $step['agent'];
                break;
            }
        }

        // Prüfe auf externe Tools
        $externalTools = ['email_sender', 'linkedin_connector', 'oauth_handler'];
        foreach ($executionPlan['steps'] as $step) {
            if ($step['tool'] && in_array($step['tool'], $externalTools)) {
                if ($riskLevel !== 'high') {
                    $riskLevel = 'medium';
                }
                $riskFactors[] = 'Externes Tool: ' . $step['tool'];
            }
        }

        // Prüfe Komplexität
        if ($executionPlan['total_steps'] > 5) {
            if ($riskLevel !== 'high') {
                $riskLevel = 'medium';
            }
            $riskFactors[] = 'Hohe Schrittanzahl: ' . $executionPlan['total_steps'];
        }

        return [
            'level' => $riskLevel,
            'factors' => $riskFactors,
            'recommendation' => $this->getRiskRecommendation($riskLevel),
        ];
    }

    /**
     * Gibt Empfehlung basierend auf Risikostufe
     */
    private function getRiskRecommendation(string $riskLevel): string
    {
        switch ($riskLevel) {
            case 'high':
                return 'Manuelle Freigabe erforderlich';
            case 'medium':
                return 'Benachrichtigung empfohlen';
            default:
                return 'Automatische Ausführung möglich';
        }
    }

    /**
     * Generiert Empfehlungen
     */
    private function generateRecommendations(array $analysis, array $executionPlan): array
    {
        $recommendations = [];

        // Empfehlung basierend auf Komplexität
        if ($analysis['complexity'] === 'high') {
            $recommendations[] = [
                'type' => 'strategy_review',
                'priority' => 'high',
                'description' => 'Komplexe Aufgabe - Strategie vor Ausführung prüfen',
            ];
        }

        // Empfehlung basierend auf Risiko
        $riskAssessment = $this->assessRisk($executionPlan);
        if ($riskAssessment['level'] === 'high') {
            $recommendations[] = [
                'type' => 'manual_approval',
                'priority' => 'critical',
                'description' => 'Hohe Risikostufe - Manuelle Freigabe erforderlich',
                'details' => $riskAssessment['factors'],
            ];
        }

        // Empfehlung für fehlende Tools
        foreach ($executionPlan['steps'] as $step) {
            if ($step['tool'] && $step['tool'] !== 'null') {
                $existingTool = $this->toolDefinitionRepo->findOneBy([
                    'name' => $step['tool'],
                    'status' => 'approved',
                ]);

                if (!$existingTool) {
                    $recommendations[] = [
                        'type' => 'tool_creation',
                        'priority' => 'medium',
                        'description' => 'Benötigtes Tool fehlt: ' . $step['tool'],
                        'tool_name' => $step['tool'],
                    ];
                }
            }
        }

        return $recommendations;
    }
}
