<?php

namespace AppAISkills;

use AppEntityToolDefinition;

/**
 * Löst Prompts für Sub-Agenten auf
 */
class SubAgentPromptResolver
{
    private array $promptTemplates = [];

    public function __construct(string $promptDirectory)
    {
        $this->loadPromptTemplates($promptDirectory);
    }

    /**
     * Lädt Prompt-Templates aus dem Verzeichnis
     */
    private function loadPromptTemplates(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = scandir($directory);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'json') {
                $content = file_get_contents($directory . '/' . $file);
                $name = pathinfo($file, PATHINFO_FILENAME);
                $this->promptTemplates[$name] = json_decode($content, true);
            }
        }
    }

    /**
     * Gibt den Prompt für einen Agenten zurück
     */
    public function getPromptForAgent(string $agentType): ?string
    {
        return $this->promptTemplates[$agentType]['prompt'] ?? null;
    }

    /**
     * Erstellt einen Prompt für eine Tool-Definition
     */
    public function createToolPrompt(ToolDefinition $definition): string
    {
        $basePrompt = $this->promptTemplates['tool']['base'] ?? 'Du bist ein AI-Assistent. Führe das folgende Tool aus: {name}. Beschreibung: {description}. Schema: {schema}. Executor-Typ: {executorType}';
        
        return str_replace(
            ['{name}', '{description}', '{schema}', '{executorType}'],
            [
                $definition->getName(),
                $definition->getDescription(),
                json_encode($definition->getSchema(), JSON_PRETTY_PRINT),
                $definition->getExecutorType() ?? 'generic'
            ],
            $basePrompt
        );
    }
}
