<?php

namespace App\AI\Skills\Tool;

use Symfony\AI\Agent\Tool\AsTool;

/**
 * Tool zum Lesen von Dateien.
 * Demonstriert die Implementierung eines EVIE-Tools.
 */
#[AsTool(
    name: 'file_read',
    description: 'Liest den Inhalt einer Datei.'
)]
final class FileReadTool implements ToolInterface
{
    public function __invoke(array $parameters = []): array
    {
        $filePath = $parameters['path'] ?? '';
        
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Datei nicht gefunden: $filePath");
        }
        
        $content = file_get_contents($filePath);
        
        return [
            'path' => $filePath,
            'content' => $content,
        ];
    }

    public function getName(): string
    {
        return 'file_read';
    }

    public function getDescription(): string
    {
        return 'Liest den Inhalt einer Datei.';
    }
}