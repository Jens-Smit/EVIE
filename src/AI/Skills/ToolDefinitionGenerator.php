<?php
// src/AI/Skills/ToolDefinitionGenerator.php

namespace App\AI\Skills;

use App\Entity\ToolDefinition;
use App\Entity\ToolCategory;
use App\Repository\ToolDefinitionRepository;
use App\Repository\ToolCategoryRepository;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Psr\Log\LoggerInterface;

/**
 * Generiert Tool-Definitionen basierend auf User-Anforderungen.
 * NUTZT LLM, um intelligente, wiederverwendbare Tool-Schemata zu erstellen.
 */
class ToolDefinitionGenerator
{
    public function __construct(
        private ToolDefinitionRepository $toolDefinitionRepo,
        private ToolCategoryRepository $toolCategoryRepo,
        private PlatformInterface $platform,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Generiert eine neue Tool-Definition basierend auf der User-Anfrage.
     * NUTZT LLM, um ein intelligentes Schema zu erstellen.
     */
    public function generateToolDefinition(
        string $toolName,
        string $description,
        array $context = []
    ): ToolDefinition {
        $this->logger->info('Generiere Tool-Definition', [
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

        // 2. LLM generiert ein intelligentes Schema
        $schema = $this->generateSchemaWithLLM($toolName, $description, $context);

        // 3. Kategorie bestimmen
        $category = $this->determineCategory($description);

        // 4. Komplexität bestimmen
        $complexity = $this->determineComplexity($schema);

        // 5. Abhängigkeiten bestimmen
        $dependencies = $this->determineDependencies($description);

        // 6. Erstelle die Tool-Definition
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName($this->sanitizeToolName($toolName));
        $toolDefinition->setDescription($description);
        $toolDefinition->setSchema($schema);
        $toolDefinition->setParameters($this->extractParametersFromSchema($schema));
        $toolDefinition->setCategory($category);
        $toolDefinition->setComplexity($complexity);
        $toolDefinition->setDependencies($dependencies);
        $toolDefinition->setStatus('pending');

        // 7. Metadaten für Wiederverwendung
        $toolDefinition->setMetadata([
            'generated_by' => 'llm',
            'reusable' => true,
            'generation_context' => $context,
            'generation_timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        // 8. Speichere in der Datenbank
        $this->toolDefinitionRepo->save($toolDefinition, true);

        $this->logger->info('Neues Tool erstellt', [
            'tool_id' => $toolDefinition->getId(),
            'tool_name' => $toolDefinition->getName(),
            'category' => $category?->getName(),
            'complexity' => $complexity,
        ]);

        return $toolDefinition;
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
     * LLM generiert ein JSON-Schema für das Tool
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
6. Antworte **NUR mit dem JSON-Schema** in gültigem JSON-Format, ohne zusätzliche Erklärungen!

**Beispiel für ein gutes Schema:**
{
    "type": "object",
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
        },
        "include_images": {
            "type": "boolean",
            "description": "Ob Bilder in die Analyse einbezogen werden sollen",
            "default": false
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

            // Validierung
            if (!isset($schema['type']) || $schema['type'] !== 'object') {
                throw new \RuntimeException('LLM hat kein gültiges JSON-Schema generiert');
            }

            return $schema;

        } catch (\Exception $e) {
            $this->logger->error('Fehler bei der LLM-Schema-Generierung', [
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
}
