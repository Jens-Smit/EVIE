<?php

namespace App\AI\Skills;

use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(tags: ['ai.tool_generator'])]
class ToolDefinitionGenerator
{
    private ToolDefinitionRepository $toolDefinitionRepo;
    private string $mistralApiKey;

    public function __construct(
        ToolDefinitionRepository $toolDefinitionRepo,
        string $mistralApiKey
    ) {
        $this->toolDefinitionRepo = $toolDefinitionRepo;
        $this->mistralApiKey = $mistralApiKey;
    }

    /**
     * Generates a new tool definition based on the user's need.
     */
    public function generateToolDefinition(string $toolName, string $description, array $context = []): ToolDefinition
    {
        // This is a placeholder for the actual Mistral LLM call
        // In production, this would call the Mistral API to generate a tool schema
        
        $schema = $this->generateSchemaForTool($toolName, $description);

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
     * Generates a JSON schema for a tool based on its name and description.
     * This is a placeholder for the actual LLM call.
     */
    private function generateSchemaForTool(string $toolName, string $description): array
    {
        // Example schema generation based on tool name
        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];

        // Add common properties based on tool type
        if (str_contains(strtolower($toolName), 'excel')) {
            $schema['properties'] = [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'Path to the Excel file',
                ],
                'sheet_name' => [
                    'type' => 'string',
                    'description' => 'Name of the sheet to read',
                ],
            ];
            $schema['required'] = ['file_path'];
        } elseif (str_contains(strtolower($toolName), 'analysieren') || str_contains(strtolower($toolName), 'analyse')) {
            $schema['properties'] = [
                'data' => [
                    'type' => 'array',
                    'description' => 'Data to analyze',
                ],
                'method' => [
                    'type' => 'string',
                    'description' => 'Analysis method',
                    'enum' => ['statistical', 'trend', 'comparative'],
                ],
            ];
            $schema['required'] = ['data', 'method'];
        }

        return $schema;
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
        // In a real implementation, this would trigger a notification
        // to the user (e.g., via email, webhook, or frontend notification)
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
