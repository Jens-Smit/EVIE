<?php

namespace App\AI\Skills\Tool;

use App\AI\Security\SecurityGuard;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Tool zum Lesen von Dateien.
 *
 * Der Pfad wird vor jedem Zugriff durch SecurityGuard::isPathSafe()
 * validiert (Directory-Traversal, Symlink-Escape, Sandbox-Root-Pruefung).
 * Ohne konfigurierte Sandbox (FILE_SANDBOX_ROOT) greift die Blockliste.
 */
#[AsTool(
    name: 'file_read',
    description: 'Liest den Inhalt einer Datei aus dem Sandbox-Verzeichnis.'
)]
final class FileReadTool
{
    public function __construct(
        private ?SecurityGuard $securityGuard = null,
    ) {
    }

    public function __invoke(array $parameters = []): array
    {
        $filePath = $parameters['path'] ?? '';

        if (null !== $this->securityGuard && !$this->securityGuard->isPathSafe($filePath)) {
            throw new \RuntimeException("Datei liegt ausserhalb des Sandbox-Verzeichnisses: $filePath");
        }

        if (!file_exists($filePath)) {
            throw new \RuntimeException("Datei nicht gefunden: $filePath");
        }

        $content = file_get_contents($filePath);

        return [
            'path' => $filePath,
            'content' => $content,
        ];
    }
}
