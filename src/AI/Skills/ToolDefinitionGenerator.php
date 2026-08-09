<?php
namespace App\AI\Skills;

use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Generiert Tool-Definitionen basierend auf User-Anfragen.
 * Nutzt Mistral, um JSON-Schemata für neue Tools zu erstellen.
 */
class ToolDefinitionGenerator
{
    private ToolDefinitionRepository $toolDefinitionRepo;
    private PlatformInterface $platform;

    public function __construct(
        ToolDefinitionRepository $toolDefinitionRepo,
        PlatformInterface $platform
    ) {
        $this->toolDefinitionRepo = $toolDefinitionRepo;
        $this->platform = $platform;
    }

    /**
     * Generates a new tool definition based on the user's need.
     * Uses the LLM to generate a proper JSON schema for the tool.
     */
    public function generateToolDefinition(string $toolName, string $description, array $context = []): ToolDefinition
    {
        // Use the LLM to generate a schema for the tool
        $schema = $this->generateSchemaWithLLM($toolName, $description, $context);

        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName($toolName);
        $toolDefinition->setDescription($description);
        $toolDefinition->setSchema($schema);
        $toolDefinition->setParameters($this->extractParametersFromSchema($schema));
        $toolDefinition->setStatus('pending'); // Default status is pending

        // Save to database
        $this->toolDefinitionRepo->save($toolDefinition, true);

        return $toolDefinition;
    }

    /**
     * Uses the LLM to generate a JSON schema for a tool.
     * Falls back to a default schema if LLM generation fails.
     */
    private function generateSchemaWithLLM(string $toolName, string $description, array $context = []): array
    {
        // First try: Use the LLM to generate a schema
        try {
            // Create a prompt for the LLM to generate a JSON schema
            $prompt = sprintf(
                "Generate a JSON schema for a tool named '%s' with the description: '%s'. " .
                "The schema should be a valid JSON Schema object with properties, types, descriptions, and required fields. " .
                "Return only the JSON schema, without any additional text. " .
                "Example format: {\"type\": \"object\", \"properties\": {\"task\": {\"type\": \"string\", \"description\": \"The task to perform\"}}, \"required\": [\"task\"]}",
                $toolName,
                $description
            );

            // Use the platform to get a structured response
            $messages = new MessageBag(Message::ofUser($prompt));
            $response = $this->platform->invoke('mistral-small-latest', $messages)->asText();

            // Parse the response to get the schema
            $schema = json_decode($response, true);

            // Validate the schema
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON from LLM: ' . json_last_error_msg());
            }

            // Ensure the schema is valid
            if (!isset($schema['type']) || $schema['type'] !== 'object') {
                throw new \RuntimeException('Invalid schema structure from LLM');
            }

            return $schema;
        } catch (\Exception $e) {
            // Fallback: Generate a default schema based on tool name and description
            return $this->generateFallbackSchema($toolName, $description, $context);
        }
    }

    /**
     * Generates a fallback schema when LLM generation fails.
     */
    private function generateFallbackSchema(string $toolName, string $description, array $context = []): array
    {
        // Determine the type of tool based on name and description
        $toolType = $this->determineToolType($toolName, $description);

        // Generate appropriate schema based on tool type
        switch ($toolType) {
            case 'website_research':
                return [
                    'type' => 'object',
                    'properties' => [
                        'task' => [
                            'type' => 'string',
                            'description' => 'Die Aufgabe, die der Sub-Agent ausführen soll',
                        ],
                        'parameters' => [
                            'type' => 'object',
                            'description' => 'Zusätzliche Parameter für die Aufgabe',
                            'additionalProperties' => true,
                        ],
                    ],
                    'required' => ['task'],
                ];

            case 'data_analysis':
                return [
                    'type' => 'object',
                    'properties' => [
                        'data_source' => [
                            'type' => 'string',
                            'description' => 'Die Datenquelle oder der Datensatz',
                        ],
                        'analysis_type' => [
                            'type' => 'string',
                            'description' => 'Der Typ der Analyse (z.B. statistisch, Trendanalyse)',
                        ],
                        'parameters' => [
                            'type' => 'object',
                            'description' => 'Zusätzliche Parameter für die Analyse',
                            'additionalProperties' => true,
                        ],
                    ],
                    'required' => ['data_source'],
                ];

            case 'code_assistance':
                return [
                    'type' => 'object',
                    'properties' => [
                        'code' => [
                            'type' => 'string',
                            'description' => 'Der Code, der analysiert oder generiert werden soll',
                        ],
                        'language' => [
                            'type' => 'string',
                            'description' => 'Die Programmiersprache',
                        ],
                        'task' => [
                            'type' => 'string',
                            'description' => 'Die spezifische Aufgabe (z.B. Analyse, Generierung, Review)',
                        ],
                    ],
                    'required' => ['task'],
                ];

            case 'document_processing':
                return [
                    'type' => 'object',
                    'properties' => [
                        'document' => [
                            'type' => 'string',
                            'description' => 'Das Dokument oder der Dateipfad',
                        ],
                        'processing_type' => [
                            'type' => 'string',
                            'description' => 'Der Typ der Verarbeitung (z.B. Extraktion, Analyse, Konvertierung)',
                        ],
                        'parameters' => [
                            'type' => 'object',
                            'description' => 'Zusätzliche Parameter für die Verarbeitung',
                            'additionalProperties' => true,
                        ],
                    ],
                    'required' => ['document'],
                ];

            default:
                // Generic fallback schema
                return [
                    'type' => 'object',
                    'properties' => [
                        'task' => [
                            'type' => 'string',
                            'description' => 'Die Aufgabe, die das Tool ausführen soll',
                        ],
                        'parameters' => [
                            'type' => 'object',
                            'description' => 'Zusätzliche Parameter für die Aufgabe',
                            'additionalProperties' => true,
                        ],
                    ],
                    'required' => ['task'],
                ];
        }
    }

    /**
     * Determines the type of tool based on name and description.
     */
    private function determineToolType(string $toolName, string $description): string
    {
        $nameLower = strtolower($toolName);
        $descLower = strtolower($description);

        if (preg_match('/(web|site|research|recherche|impressum|kontakt|geschäft|standort|branche)/i', $nameLower) ||
            preg_match('/(web|site|research|recherche|impressum|kontakt|geschäft|standort|branche)/i', $descLower)) {
            return 'website_research';
        }

        if (preg_match('/(data|analyse|statistik|auswertung|zahlen)/i', $nameLower) ||
            preg_match('/(data|analyse|statistik|auswertung|zahlen)/i', $descLower)) {
            return 'data_analysis';
        }

        if (preg_match('/(code|programm|skript|funktion|entwickeln)/i', $nameLower) ||
            preg_match('/(code|programm|skript|funktion|entwickeln)/i', $descLower)) {
            return 'code_assistance';
        }

        if (preg_match('/(dokument|pdf|excel|datei|verarbeiten)/i', $nameLower) ||
            preg_match('/(dokument|pdf|excel|datei|verarbeiten)/i', $descLower)) {
            return 'document_processing';
        }

        return 'generic';
    }

    /**
     * Extracts parameters from a schema for storage in the ToolDefinition.
     */
    private function extractParametersFromSchema(array $schema): array
    {
        $parameters = [];
        
        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $name => $property) {
                $parameters[] = [
                    'name' => $name,
                    'type' => $property['type'] ?? 'string',
                    'description' => $property['description'] ?? '',
                    'required' => in_array($name, $schema['required'] ?? []),
                ];
            }
        }

        return $parameters;
    }

    /**
     * Requests approval for a pending tool definition.
     */
    public function requestApproval(ToolDefinition $toolDefinition): void
    {
        $toolDefinition->setStatus('pending_approval');
        $this->toolDefinitionRepo->save($toolDefinition, true);
    }

    /**
     * Approves a tool definition.
     */
    public function approveTool(ToolDefinition $toolDefinition): void
    {
        $toolDefinition->setStatus('approved');
        $toolDefinition->setUpdatedAt(new \DateTimeImmutable());
        $this->toolDefinitionRepo->save($toolDefinition, true);
    }
}
