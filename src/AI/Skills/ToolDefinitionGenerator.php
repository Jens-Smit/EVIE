<?php
// src/AI/Skills/ToolDefinitionGenerator.php

namespace App\AI\Skills;

use App\Entity\ToolDefinition;
use App\Entity\ToolCategory;
use App\Repository\ToolDefinitionRepository;
use App\Repository\ToolCategoryRepository;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Psr\Log\LoggerInterface;

/**
 * Generiert Tool-Definitionen basierend auf User-Anforderungen.
 * NUTZT LLM mit optimiertem Prompt aus Phase 3, um intelligente, wiederverwendbare Tool-Schemata zu erstellen.
 * 
 * @see ROADMAP_PHASE3.md Maßnahme 8: LLM-Prompt-Optimierung
 */
class ToolDefinitionGenerator
{
    public function __construct(
        private ToolDefinitionRepository $toolDefinitionRepo,
        private ToolCategoryRepository $toolCategoryRepo,
        private PlatformInterface $platform,
        private AgentInterface $toolGeneratorAgent,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Generiert eine neue Tool-Definition basierend auf der User-Anfrage.
     * NUTZT den optimierten tool_generator-Agent mit File-Based Prompt aus Phase 3.
     * 
     * @param string $toolName Der Name des neuen Tools
     * @param string $description Beschreibung des Tools
     * @param array $context Zusätzlicher Kontext (z.B. ursprüngliche User-Anfrage)
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

        // 1. Prüfe, ob ein ähnliches Tool bereits existiert
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

        // 4. Komplexität bestimmen
        $complexity = $this->determineComplexity($schema);

        // 5. Abhängigkeiten bestimmen
        $dependencies = $this->determineDependencies($description);

        // 6. Sicherheitslevel aus Schema extrahieren oder standardmäßig setzen
        $securityLevel = $this->extractSecurityLevel($schema);
        $hitlRequired = $this->extractHitlRequirement($schema);

        // 7. Erstelle die Tool-Definition
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName($this->sanitizeToolName($toolName));
        $toolDefinition->setDescription($description);
        $toolDefinition->setSchema($schema);
        $toolDefinition->setParameters($this->extractParametersFromSchema($schema));
        $toolDefinition->setCategory($category);
        $toolDefinition->setComplexity($complexity);
        $toolDefinition->setDependencies($dependencies);
        $toolDefinition->setSecurityLevel($securityLevel);
        $toolDefinition->setHitlRequired($hitlRequired);
        $toolDefinition->setStatus('pending');

        // 8. Metadaten für Wiederverwendung und Phase 3-Optimierung
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
     * @param array $context Zusätzlicher Kontext
     * @return array Das generierte JSON-Schema
     */
    private function generateSchemaWithToolGeneratorAgent(
        string $toolName,
        string $description,
        array $context = []
    ): array {
        $userMessage = $context['original_request'] ?? $description;
        
        // Erstelle eine strukturierte Anfrage für den tool_generator-Agent
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

            // Füge Metadaten hinzu, falls nicht vorhanden
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
     * Stell sicher, dass das Schema alle benötigten Metadaten enthält
     */
    private function ensureSchemaMetadata(array $schema): array
    {
        // Standard-Sicherheitslevel
        if (!isset($schema['security_level'])) {
            $schema['security_level'] = 'medium';
        }

        // Standard HITL-Anforderung
        if (!isset($schema['hitl_required'])) {
            $schema['hitl_required'] = true; // Standardmäßig HITL für neue Tools
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
                $toolName,
                'Tool-Schema ist ungueltig: type muss "object" sein'
            );
        }

        if (!array_key_exists('properties', $schema)) {
            throw new ToolRegistrationException(
                sprintf('Tool-Schema fuer "%s" ist ungueltig: properties-Feld fehlt', $toolName)
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
     * Sucht nach ähnlichen Tools für Wiederverwendung
     */
    private function findSimilarTool(string $description): ?ToolDefinition
    {
        $keywords = $this->extractKeywords($description);
        $allTools = $this->toolDefinitionRepo->findAll();

        foreach ($allTools as $tool) {
            $toolKeywords = $this->extractKeywords($tool->getDescription());
            $matchScore = $this->calculateSimilarityScore($keywords, $toolKeywords);

            if ($matchScore > 0.7) { // 70% Ähnlichkeit
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
     * Berechnet die Ähnlichkeit zwischen zwei Keyword-Listen (Jaccard-Index)
     */
    private function calculateSimilarityScore(array $keywords1, array $keywords2): float
    {
        $intersection = count(array_intersect($keywords1, $keywords2));
        $union = count(array_unique(array_merge($keywords1, $keywords2)));

        return $union > 0 ? $intersection / $union : 0;
    }

    /**
     * LLM generiert ein JSON-Schema für das Tool (Fallback-Methode)
     * 
     * @deprecated Wird durch generateSchemaWithToolGeneratorAgent ersetzt
     */
    private function generateSchemaWithLLM(
        string $toolName,
        string $description,
        array $context = []
    ): array {
        $userMessage = $context['original_request'] ?? $description;

        $prompt = <<<PROMPT
Du bist ein Experte für die Erstellung von Tool-Definitionen für AI-Agenten.
Erstelle ein **JSON-Schema** für ein Tool mit folgendem Namen und Beschreibung:

**Tool-Name:** {$toolName}
**Beschreibung:** {$description}
**User-Anfrage:** {$userMessage}

**Anforderungen an das Schema:**
1. Das Schema muss **wiederverwendbar** sein
2. Definiere **klare Parameter** mit:
   - type (string, integer, boolean, array, object)
   - description (klare Beschreibung auf Deutsch)
   - required (boolean, falls Pflichtfeld)
   - default (Standardwert, falls sinnvoll)
   - enum (mögliche Werte, falls begrenzt)
   - pattern (Regex für Strings, falls Validation nötig)
   - minLength/maxLength (für Strings)
   - minimum/maximum (für Zahlen)
3. Nutze **sinnvolle Standardwerte** wo möglich
4. Das Tool sollte **modular** sein
5. Berücksichtige **Sicherheitsaspekte** (keine gefährlichen Operationen)
6. Füge **Sicherheitsmetadaten** hinzu:
   - security_level: "low"|"medium"|"high"
   - hitl_required: true|false
7. Antworte **NUR mit dem JSON-Schema** in gültigem JSON-Format, ohne zusätzliche Erklärungen!

**Beispiel für ein gutes Schema:**
{
    "type": "object",
    "security_level": "medium",
    "hitl_required": true,
    "properties": {
        "url": {
            "type": "string",
            "description": "Die URL der Webseite, die analysiert werden soll",
            "format": "uri",
            "pattern": "^https?:\\/\\/[^\\s]+$"
        },
        "depth": {
            "type": "integer",
            "description": "Wie tief die Analyse gehen soll (1-5)",
            "minimum": 1,
            "maximum": 5,
            "default": 2
        }
    },
    "required": ["url"]
}
PROMPT;

        try {
            $messages = new MessageBag(Message::ofUser($prompt));
            $response = $this->platform->invoke('mistral-large-latest', $messages)->asText();

            // Versuche, die Antwort als JSON zu parsen
            $schema = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            // P0-2: strikte Schema-Validierung im LLM-Fallback-Pfad.
            $this->validateSchema($schema, $toolName);

            // Füge fehlende Metadaten hinzu
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

    /**
     * Erstellt ein Fallback-Schema, falls LLM fehlschlägt
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
                    'description' => 'Eingabedaten für das Tool: ' . substr($description, 0, 100),
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
        $descriptionLower = strtolower($description);

        $categoryMappings = [
            'web_scraping' => ['web', 'seite', 'url', 'html', 'scrapi', 'recherche', 'durchsuchen', 'zusammenfassen'],
            'data_analysis' => ['daten', 'analyse', 'statistik', 'auswertung', 'zahl', 'diagramm', 'muster'],
            'communication' => ['mail', 'email', 'nachricht', 'kommunikation', 'linkedin', 'slack', 'twilio'],
            'api_integration' => ['api', 'oauth', 'auth', 'zugang', 'rest', 'graphql', 'endpoint'],
            'document_processing' => ['datei', 'pdf', 'excel', 'dokument', 'verarbeiten', 'lesen', 'extrahieren'],
            'code_generation' => ['code', 'programm', 'skript', 'funktion', 'klasse', 'php', 'symfony', 'entwickeln'],
            'project_management' => ['projekt', 'aufgabe', 'termin', 'ressource', 'planung'],
        ];

        foreach ($categoryMappings as $categoryName => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($descriptionLower, $keyword)) {
                    return $this->toolCategoryRepo->findOneByName($categoryName);
                }
            }
        }

        return $this->toolCategoryRepo->findOneByName('general');
    }

    /**
     * Bestimmt die Komplexität des Tools
     */
    private function determineComplexity(array $schema): string
    {
        $propertyCount = count($schema['properties'] ?? []);
        $requiredCount = count($schema['required'] ?? []);

        if ($propertyCount >= 5 || $requiredCount >= 3) {
            return 'high';
        }
        if ($propertyCount >= 3 || $requiredCount >= 2) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Bestimmt Abhängigkeiten des Tools
     */
    private function determineDependencies(string $description): array
    {
        $dependencies = [];
        $descriptionLower = strtolower($description);

        $dependencyMappings = [
            'http_client' => ['web', 'seite', 'url', 'api', 'rest', 'graphql'],
            'firecrawl' => ['web', 'seite', 'scraping', 'crawl'],
            'mailer' => ['mail', 'email', 'nachricht'],
            'imap' => ['mail', 'email', 'empfangen', 'lesen'],
            'linkedin_api' => ['linkedin'],
            'slack_api' => ['slack'],
            'oauth' => ['oauth', 'auth', 'authentifizierung'],
            'filesystem' => ['datei', 'pdf', 'excel', 'dokument'],
            'php_spreadsheet' => ['excel', 'spreadsheet'],
        ];

        foreach ($dependencyMappings as $dependency => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($descriptionLower, $keyword)) {
                    $dependencies[] = $dependency;
                    break;
                }
            }
        }

        return array_unique($dependencies);
    }

    /**
     * Extrahiere Parameter aus dem Schema für die ToolDefinition
     */
    private function extractParametersFromSchema(array $schema): array
    {
        $parameters = [];
        $properties = $schema['properties'] ?? [];

        foreach ($properties as $name => $property) {
            $parameter = [
                'name' => $name,
                'type' => $property['type'] ?? 'string',
                'required' => in_array($name, $schema['required'] ?? []),
                'description' => $property['description'] ?? '',
            ];

            if (isset($property['default'])) {
                $parameter['default'] = $property['default'];
            }

            if (isset($property['enum'])) {
                $parameter['enum'] = $property['enum'];
            }

            if (isset($property['pattern'])) {
                $parameter['pattern'] = $property['pattern'];
            }

            if (isset($property['minLength'])) {
                $parameter['minLength'] = $property['minLength'];
            }

            if (isset($property['maxLength'])) {
                $parameter['maxLength'] = $property['maxLength'];
            }

            if (isset($property['minimum'])) {
                $parameter['minimum'] = $property['minimum'];
            }

            if (isset($property['maximum'])) {
                $parameter['maximum'] = $property['maximum'];
            }

            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * Bereinigt den Tool-Namen
     */
    private function sanitizeToolName(string $name): string
    {
        // Ersetze Sonderzeichen
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
        // Bereinige doppelte Unterstriche
        $name = preg_replace('/_+/', '_', $name);
        // Entferne führende/trailing Unterstriche
        $name = trim($name, '_');
        // Kleinschreibung
        $name = strtolower($name);

        if (empty($name)) {
            $name = 'custom_tool';
        }

        return $name;
    }

    /**
     * Genehmigt ein Tool
     */
    public function approveTool(ToolDefinition $toolDefinition): void
    {
        $toolDefinition->setStatus('approved');
        $toolDefinition->setApprovedAt(new \DateTimeImmutable());
        $this->toolDefinitionRepo->save($toolDefinition, true);

        $this->logger->info('Tool genehmigt', [
            'tool_id' => $toolDefinition->getId(),
            'tool_name' => $toolDefinition->getName(),
        ]);
    }

    /**
     * Lehnt ein Tool ab
     */
    public function rejectTool(ToolDefinition $toolDefinition, string $reason = null): void
    {
        $toolDefinition->setStatus('rejected');
        $toolDefinition->setRejectedAt(new \DateTimeImmutable());
        
        $metadata = $toolDefinition->getMetadata() ?? [];
        $metadata['rejection_reason'] = $reason;
        $metadata['rejected_at'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $toolDefinition->setMetadata($metadata);

        $this->toolDefinitionRepo->save($toolDefinition, true);

        $this->logger->info('Tool abgelehnt', [
            'tool_id' => $toolDefinition->getId(),
            'tool_name' => $toolDefinition->getName(),
            'reason' => $reason,
        ]);
    }

    /**
     * Gibt alle ausstehenden Tools zurück
     */
    public function getPendingTools(): array
    {
        return $this->toolDefinitionRepo->findBy(['status' => 'pending']);
    }

    /**
     * Gibt alle genehmigten Tools zurück
     */
    public function getApprovedTools(): array
    {
        return $this->toolDefinitionRepo->findBy(['status' => 'approved']);
    }

    /**
     * Gibt alle Tools einer Kategorie zurück
     */
    public function getToolsByCategory(ToolCategory $category): array
    {
        return $this->toolDefinitionRepo->findBy(['category' => $category]);
    }

    /**
     * Generiert eine Tool-Definition direkt aus einer User-Anfrage (für den Orchestrator)
     * 
     * @param string $userRequest Die ursprüngliche User-Anfrage
     * @return ToolDefinition Die generierte Tool-Definition
     */
    public function generateFromUserRequest(string $userRequest): ToolDefinition
    {
        // Extrahiere Tool-Name und Beschreibung aus der Anfrage
        $toolName = $this->extractToolNameFromRequest($userRequest);
        $description = $this->extractDescriptionFromRequest($userRequest);

        return $this->generateToolDefinition($toolName, $description, [
            'original_request' => $userRequest,
            'source' => 'user_request',
        ]);
    }

    /**
     * Extrahiere Tool-Name aus einer User-Anfrage
     */
    private function extractToolNameFromRequest(string $request): string
    {
        // Versuche, einen Tool-Namen zu extrahieren
        $requestLower = strtolower($request);
        
        // Muster für "Erstelle ein Tool für..."
        if (preg_match('/(erstelle|erzeuge|mache|baue|entwickle)\s+(ein|einen|eine)?\s*(tool|funktion|werkzeug|feature)\s+(für|zum|zur)?\s+(.+)/i', $request, $matches)) {
            $baseName = $matches[5] ?? $request;
            return $this->sanitizeToolName($baseName);
        }

        // Muster für "Ich brauche ein Tool, das..."
        if (preg_match('/(brauche|benötige|möchte|will)\s+(ein|einen|eine)?\s*(tool|funktion|werkzeug)\s+(.+)/i', $request, $matches)) {
            $baseName = $matches[4] ?? $request;
            return $this->sanitizeToolName($baseName);
        }

        // Standard: Nutze die ersten Wörter der Anfrage
        $words = preg_split('/\s+/', $request);
        $firstWords = array_slice($words, 0, 3);
        return $this->sanitizeToolName(implode('_', $firstWords));
    }

    /**
     * Extrahiere Beschreibung aus einer User-Anfrage
     */
    private function extractDescriptionFromRequest(string $request): string
    {
        // Die gesamte Anfrage als Beschreibung nutzen
        return $request;
    }
}
