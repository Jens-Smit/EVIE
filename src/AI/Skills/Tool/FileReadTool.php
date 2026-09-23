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
    description: 'Liest den Inhalt einer lokalen Datei aus dem Sandbox-Verzeichnis. NICHT fuer URLs/Websites geeignet: nutze dafuer den website_researcher-Sub-Agenten.'
)]
final class FileReadTool
{
    public function __construct(
        private ?SecurityGuard $securityGuard = null,
    ) {
    }

    public function __invoke(array $parameters = []): array
    {
        $filePath = $parameters['path'] ?? ($parameters['url'] ?? '');

        // P1: file_read ist ausschliesslich fuer lokale Dateipfade. URLs
        // (http/https) werden explizit abgelehnt, damit das Tool nicht als
        // Web-Crawler zweckentfremdet wird — Web-Recherche gehoert zum
        // website_researcher-Sub-Agenten (Tavily/MCP/HTTP).
        if (preg_match('#^https?://#i', $filePath) === 1) {
            throw new \RuntimeException(
                'file_read unterstuetzt keine URLs. '
                . 'Nutze fuer Web-Recherche den Sub-Agenten website_researcher.'
            );
        }

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
