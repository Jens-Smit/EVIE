<?php
// src/AI/Skills/ToolDefinitionGenerator.php

namespace App\AI\Skills;

use App\Entity\ToolDefinition;
use App\Entity\ToolCategory;
use App\Repository\ToolDefinitionRepository;
use App\Repository\ToolCategoryRepository;
use App\Security\UserContext;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Psr\Log\LoggerInterface;

/**
 * Generiert Tool-Definitionen basierend auf User-Anforderungen.
 * NUTZT LLM mit optimiertem Prompt aus Phase 3, um intelligente, wiederverwendbare Tool-Schemata zu erstellen.
 * 
 * @see ROADMAP_PHASE3.md Massnahme 8: LLM-Prompt-Optimierung
 */
class ToolDefinitionGenerator
{
    public function __construct(
        private ToolDefinitionRepository $toolDefinitionRepo,
        private ToolCategoryRepository $toolCategoryRepo,
        private PlatformInterface $platform,
        private AgentInterface $toolGeneratorAgent,
        private LoggerInterface $logger,
        private UserContext $userContext,
    ) {
    }

    /**
     * Generiert eine neue Tool-Definition basierend auf der User-Anfrage.
     * NUTZT den optimierten tool_generator-Agent mit File-Based Prompt aus Phase 3.
     * 
     * @param string $toolName Der Name des neuen Tools
     * @param string $description Beschreibung des Tools
     * @param array $context Zusaetzlicher Kontext (z.B. urspruengliche User-Anfrage)
     * @return ToolDefinition Die generierte Tool-Definition
     */
    public function generateToolDefinition(
        string $toolName,
        string $description,
        array $context = []
    ): ToolDefinition {
        $this->logger->info('Generiere Tool-Definition mit optimiertem Prompt (Phase 3)', [
            'tool_name' => $toolName,
            'description' => substr($description, 0, 100),
        ]);

        // 1. Pruefe, ob ein aehnliches Tool bereits existiert
        $similarTool = $this->findSimilarTool($description);
        if ($similarTool) {
            $this->logger->info('Wiederverwendung eines existierenden Tools', [
                'existing_tool' => $similarTool->getName(),
                'requested_tool' => $toolName,
            ]);
            return $similarTool;
        }

        // 2. Nutze den tool_generator-Agent mit optimiertem Prompt aus Phase 3
        $schema = $this->generateSchemaWithToolGeneratorAgent($toolName, $description, $context);

        // 3. Kategorie bestimmen
        $category = $this->determineCategory($description);

        // 4. Komplexitaet bestimmen
        $complexity = $this->determineComplexity($schema);

        // 5. Abhaengigkeiten bestimmen
        $dependencies = $this->determineDependencies($description);

        // 6. Sicherheitslevel aus Schema extrahieren oder standardmaessig setzen
        $securityLevel = $this->extractSecurityLevel($schema);
        $hitlRequired = $this->extractHitlRequirement($schema);

        // 7. Erstelle die Tool-Definition
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName($this->sanitizeToolName($toolName));
        $toolDefinition->setDescription($description);
        $toolDefinition->setSchema($schema);
        $toolDefinition->setCategory($category);
        $toolDefinition->setComplexity($complexity);
        $toolDefinition->setDependencies($dependencies);
        $toolDefinition->setSecurityLevel($securityLevel);
        $toolDefinition->setRequiresHitl($hitlRequired);
        $toolDefinition->setStatus('pending');
        
        // P0-5 Tenant-Isolation: setze den User-Identifier
        $userIdentifier = $this->userContext->getUserIdentifier();
        if (null !== $userIdentifier) {
            $toolDefinition->setUserIdentifier($userIdentifier);
        }

        // 8. Metadaten fuer Wiederverwendung und Phase 3-Optimierung
        $toolDefinition->setMetadata([
            'generated_by' => 'llm',
            'generation_method' => 'tool_generator_agent',
            'phase_3_optimized' => true,
            'prompt_version' => '1.0',
            'reusable' => true,
            'generation_context' => $context,
            'generation_timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        // 9. Speichere in der Datenbank
        $this->toolDefinitionRepo->save($toolDefinition, true);

        $this->logger->info('Neues Tool mit optimiertem Prompt erstellt (Phase 3)', [
            'tool_id' => $toolDefinition->getId(),
            'tool_name' => $toolDefinition->getName(),
            'category' => $category?->getName(),
            'complexity' => $complexity,
            'security_level' => $securityLevel,
            'hitl_required' => $hitlRequired,
        ]);

        return $toolDefinition;
    }

    /**
     * Generiert Schema mit dem tool_generator-Agent (Phase 3 Optimierung)
     * 
     * @param string $toolName Name des Tools
     * @param string $description Beschreibung des Tools
     * @param array $context Zusaetzlicher Kontext
     * @return array Das generierte JSON-Schema
     */
    private function generateSchemaWithToolGeneratorAgent(
        string $toolName,
        string $description,
        array $context = []
    ): array {
        $userMessage = $context['original_request'] ?? $description;
        
        // Erstelle eine strukturierte Anfrage fuer den tool_generator-Agent
        $requestData = [
            'tool_name' => $toolName,
            'description' => $description,
            'user_request' => $userMessage,
            'context' => $context,
            'requirements' => [
                'Follow EVIE ToolInterface Structure',
                'Include security metadata (security_level, hitl_required)',
                'Use JSON Schema Draft 2020-12',
                'Provide clear parameter descriptions in German',
                'Include validation patterns where applicable',
                'Consider Symfony DI compatibility',
            ]
        ];

        try {
            // Nutze den tool_generator-Agent mit seinem optimierten Prompt
            $messages = new MessageBag(
                Message::ofUser(json_encode($requestData, JSON_THROW_ON_ERROR))
            );
            
            $response = $this->toolGeneratorAgent->call($messages);
            $responseContent = $response->getContent();

            // Versuche, die Antwort als JSON zu parsen
            $schema = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

            // P0-2: strikte Schema-Validierung. Ein invalides Schema wird
            // abgelehnt, statt stillschweigend geladen zu werden.
            $this->validateSchema($schema, $toolName);

            // Fuege Metadaten hinzu, falls nicht vorhanden
            $schema = $this->ensureSchemaMetadata($schema);

            $this->logger->debug('Tool-Schema erfolgreich generiert', [
                'tool_name' => $toolName,
                'schema_size' => strlen($responseContent),
            ]);

            return $schema;

        } catch (\Exception $e) {
            $this->logger->error('Fehler bei der Schema-Generierung mit tool_generator-Agent', [
                'error' => $e->getMessage(),
                'tool_name' => $toolName,
            ]);

            // Fallback: Nutze den alten LLM-Ansatz
            return $this->generateSchemaWithLLM($toolName, $description, $context);
        }
    }

    /**
     * Stell sicher, dass das Schema alle benoetigten Metadaten enthaelt
     */
    private function ensureSchemaMetadata(array $schema): array
    {
        // Standard-Sicherheitslevel
        if (!isset($schema['security_level'])) {
            $schema['security_level'] = 'medium';
        }

        // Standard HITL-Anforderung
        if (!isset($schema['hitl_required'])) {
            $schema['hitl_required'] = true; // Standardmaessig HITL fuer neue Tools
        }

        // Standard Sub-Agent
        if (!isset($schema['sub_agent'])) {
            $schema['sub_agent'] = 'data_analyst'; // Standard-Sub-Agent
        }

        return $schema;
    }

    /**
     * Validiert ein generiertes Tool-Schema (P0-2).
     *
     * Ein gueltiges Schema muss type=object und ein properties-Feld
     * enthalten. Invalides Schema fuehrt zu einer ToolRegistrationException,
     * sodass es nicht stillschweigend in die DynamicToolbox gelangt.
     *
     * @param array<string, mixed> $schema
     */
    private function validateSchema(array $schema, string $toolName): void
    {
        if (!isset($schema['type']) || $schema['type'] !== 'object') {
            throw new ToolRegistrationException(
                sprintf('Tool-Schema fuer "%s" ist ungueltig: type muss "object" sein', $toolName),
                null,
                ['tool_name' => $toolName, 'reason' => 'invalid_type']
            );
        }

        if (!array_key_exists('properties', $schema)) {
            throw new ToolRegistrationException(
                sprintf('Tool-Schema fuer "%s" ist ungueltig: properties-Feld fehlt', $toolName),
                null,
                ['tool_name' => $toolName, 'reason' => 'missing_properties']
            );
        }
    }

    /**
     * Extrahiere Sicherheitslevel aus dem Schema
     */
    private function extractSecurityLevel(array $schema): string
    {
        return $schema['security_level'] ?? 'medium';
    }

    /**
     * Extrahiere HITL-Anforderung aus dem Schema
     */
    private function extractHitlRequirement(array $schema): bool
    {
        return $schema['hitl_required'] ?? true;
    }

    /**
     * Sucht nach aehnlichen Tools fuer Wiederverwendung
     */
    private function findSimilarTool(string $description): ?ToolDefinition
    {
        $keywords = $this->extractKeywords($description);
        $allTools = $this->toolDefinitionRepo->findAll();

        foreach ($allTools as $tool) {
            $toolKeywords = $this->extractKeywords($tool->getDescription());
            $matchScore = $this->calculateSimilarityScore($keywords, $toolKeywords);

            if ($matchScore > 0.7) { // 70% Aehnlichkeit
                return $tool;
            }
        }

        return null;
    }

    /**
     * Extrahiere Keywords aus einer Beschreibung
     */
    private function extractKeywords(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        $words = preg_split('/\s+/', $text);

        return array_filter($words, function($word) {
            return strlen($word) > 3;
        });
    }

    /**
     * Berechnet die Aehnlichkeit zwischen zwei Keyword-Listen (Jaccard-Index)
     */
    private function calculateSimilarityScore(array $keywords1, array $keywords2): float
    {
        $intersection = count(array_intersect($keywords1, $keywords2));
        $union = count(array_unique(array_merge($keywords1, $keywords2)));

        return $union > 0 ? $intersection / $union : 0;
    }

    /**
     * LLM generiert ein JSON-Schema fuer das Tool (Fallback-Methode)
     * 
     * @deprecated Wird durch generateSchemaWithToolGeneratorAgent ersetzt
     */
    private function generateSchemaWithLLM(
        string $toolName,
        string $description,
        array $context = []
    ): array {
        $userMessage = $context['original_request'] ?? $description;
        $prompt = $this->buildToolSchemaPrompt($toolName, $description, $userMessage);

        try {
            $messages = new MessageBag(Message::ofUser($prompt));
            $response = $this->platform->invoke('mistral-large-latest', $messages)->asText();

            // Versuche, die Antwort als JSON zu parsen
            $schema = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            // P0-2: strikte Schema-Validierung im LLM-Fallback-Pfad.
            $this->validateSchema($schema, $toolName);

            // Fuege fehlende Metadaten hinzu
            return $this->ensureSchemaMetadata($schema);

        } catch (\Exception $e) {
            $this->logger->error('Fehler bei der LLM-Schema-Generierung (Fallback)', [
                'error' => $e->getMessage(),
                'response' => $response ?? 'null',
            ]);

            // Fallback: Einfaches Schema erstellen
            return $this->createFallbackSchema($toolName, $description);
        }
    }

    private function buildToolSchemaPrompt(string $toolName, string $description, string $userMessage): string
    {
        return 'Du bist ein Experte fuer die Erstellung von Tool-Definitionen fuer AI-Agenten.' . PHP_EOL .
            'Erstelle ein JSON-Schema fuer ein Tool mit folgendem Namen und Beschreibung:' . PHP_EOL . PHP_EOL .
            'Tool-Name: ' . $toolName . PHP_EOL .
            'Beschreibung: ' . $description . PHP_EOL .
            'User-Anfrage: ' . $userMessage . PHP_EOL . PHP_EOL .
            'Anforderungen an das Schema:' . PHP_EOL .
            '1. Das Schema muss wiederverwendbar sein' . PHP_EOL .
            '2. Definiere klare Parameter mit type, description, required, default, enum, pattern, minLength/maxLength, minimum/maximum' . PHP_EOL .
            '3. Nutze sinnvolle Standardwerte wo moeglich' . PHP_EOL .
            '4. Das Tool sollte modular sein' . PHP_EOL .
            '5. Beruecksichtige Sicherheitsaspekte (keine gefaehrlichen Operationen)' . PHP_EOL .
            '6. Fuege Sicherheitsmetadaten hinzu: security_level (low|medium|high), hitl_required (true|false)' . PHP_EOL .
            '7. Antworte NUR mit dem JSON-Schema in gueltigem JSON-Format, ohne zusaetzliche Erklaerungen!' . PHP_EOL . PHP_EOL .
            'Beispiel fuer ein gutes Schema:' . PHP_EOL .
            '{"type": "object", "security_level": "medium", "hitl_required": true, "properties": {"url": {"type": "string", "description": "Die URL der Webseite, die analysiert werden soll", "format": "uri", "pattern": "^https?:\\\\/\\\\/[^\\\\s]+$"}}, "required": ["url"]}';
    }

    /**
     * Erstellt ein Fallback-Schema, falls LLM fehlschlaegt
     */
    private function createFallbackSchema(string $toolName, string $description): array
    {
        return [
            'type' => 'object',
            'security_level' => 'medium',
            'hitl_required' => true,
            'properties' => [
                'input' => [
                    'type' => 'string',
                    'description' => 'Eingabedaten fuer das Tool: ' . substr($description, 0, 100),
                ],
            ],
            'required' => ['input'],
        ];
    }

    /**
     * Bestimmt die Kategorie des Tools
     */
    private function determineCategory(string $description): ?ToolCategory
    {
        $categoryNames = [
            'Web Research' => ['web', 'website', 'online', 'url', 'scrape', 'crawl', 'html', 'http'],
            'Data Analysis' => ['data', 'analyse', 'statistik', 'daten', 'chart', 'diagramm', 'excel', 'csv'],
            'Code Development' => ['code', 'programm', 'php', 'javascript', 'python', 'git', 'debug', 'refactor'],
            'Document Processing' => ['dokument', 'pdf', 'text', 'verarbeitung', 'extraktion', 'parse'],
            'Communication' => ['email', 'mail', 'linkedin', 'slack', 'nachricht', 'kommunikation'],
            'API Integration' => ['api', 'rest', 'graphql', 'oauth', 'anbindung', 'integration'],
            'Project Management' => ['projekt', 'aufgabe', 'task', 'management', 'planung', 'zeiterfassung'],
            'Finance' => ['finanz', 'buchhaltung', 'rechnung', 'geld', 'kosten', 'budget'],
            'HR Management' => ['personal', 'mitarbeiter', 'bewerbung', 'gehaltsabrechnung', 'urlaub'],
            'Marketing' => ['marketing', 'kampagne', 'werbung', 'social media', 'analyse', 'kunden'],
            'CEO Assistant' => ['strategie', 'planung', 'entscheidung', 'management', 'fuehrung', 'analyse'],
        ];

        $descriptionLower = strtolower($description);

        foreach ($categoryNames as $categoryName => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($descriptionLower, $keyword)) {
                    $category = $this->toolCategoryRepo->findOneBy(['name' => $categoryName]);
                    if ($category) {
                        return $category;
                    }
                    // Falls Kategorie nicht existiert, erstelle eine Standard-Kategorie
                    break;
                }
            }
        }

        // Standard-Kategorie
        $defaultCategory = $this->toolCategoryRepo->findOneBy(['name' => 'General']);
        if ($defaultCategory) {
            return $defaultCategory;
        }

        // Erstelle eine neue Standard-Kategorie
        $newCategory = new ToolCategory();
        $newCategory->setName('General');
        $newCategory->setDescription('Allgemeine Tools ohne spezifische Kategorie');
        $this->toolCategoryRepo->save($newCategory, true);

        return $newCategory;
    }

    /**
     * Bestimmt die Komplexitaet des Tools
     */
    private function determineComplexity(array $schema): string
    {
        $propertyCount = count($schema['properties'] ?? []);
        $requiredCount = count($schema['required'] ?? []);

        if ($propertyCount >= 5 || $requiredCount >= 4) {
            return 'high';
        } elseif ($propertyCount >= 3 || $requiredCount >= 2) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Bestimmt die Abhaengigkeiten des Tools
     */
    private function determineDependencies(string $description): array
    {
        $dependencies = [];
        $descriptionLower = strtolower($description);

        if (str_contains($descriptionLower, 'api') || str_contains($descriptionLower, 'anbindung')) {
            $dependencies[] = 'API Access';
        }
        if (str_contains($descriptionLower, 'datenbank') || str_contains($descriptionLower, 'database')) {
            $dependencies[] = 'Database';
        }
        if (str_contains($descriptionLower, 'datei') || str_contains($descriptionLower, 'file')) {
            $dependencies[] = 'File System';
        }
        if (str_contains($descriptionLower, 'netzwerk') || str_contains($descriptionLower, 'network') || str_contains($descriptionLower, 'http')) {
            $dependencies[] = 'Network';
        }

        return $dependencies;
    }

    /**
     * Bereinigt den Tool-Namen
     */
    private function sanitizeToolName(string $name): string
    {
        // Ersetze Sonderzeichen und Leerzeichen
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        
        // Entferne führende/trailing Unterstriche
        $name = trim($name, '_');
        
        // Begrenze die Länge
        if (strlen($name) > 50) {
            $name = substr($name, 0, 50);
        }
        
        // Stelle sicher, dass der Name nicht leer ist
        if (empty($name)) {
            $name = 'unnamed_tool';
        }
        
        return $name;
    }
}

class ToolRegistrationException extends \RuntimeException
{
    private array $context;

    public function __construct(string $message, ?\Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
