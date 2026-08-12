<?php

namespace App\AI\Security;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * SecurityGuard - Implementiert harte Sandbox-Grenzen für EVIE
 * 
 * Verhindert, dass dynamisch generierte Tools unsichere Services oder Ressourcen nutzen.
 * Integriert mit Symfony AI Bundle Best Practices.
 * 
 * @see https://symfony.com/doc/current/ai/bundles/ai-bundle.html
 */
final readonly class SecurityGuard
{
    private ParameterBagInterface $params;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
    }

    /**
     * Prüft, ob ein Service in der Whitelist enthalten ist.
     * Unterstützt:
     * - Direkte Übereinstimmung
     * - Vererbung (is_a() Prüfung)
     * - Wildcard-Patterns (z. B. 'App\AI\Skills\Tool\*')
     */
    public function isServiceAllowed(string $serviceClass): bool
    {
        $allowedServices = $this->getAllowedServices();

        // 1. Direkte Übereinstimmung
        if (in_array($serviceClass, $allowedServices, true)) {
            return true;
        }

        // 2. Prüfe auf Wildcard-Patterns
        foreach ($allowedServices as $allowedService) {
            if (str_contains($allowedService, '*')) {
                $pattern = str_replace('\*', '.*', $allowedService);
                if (preg_match('/^' . $pattern . '$/', $serviceClass)) {
                    return true;
                }
            }
        }

        // 3. Prüfe auf Vererbung
        foreach ($allowedServices as $allowedService) {
            if (class_exists($allowedService) && is_a($serviceClass, $allowedService, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prüft, ob eine Ressource (z. B. URL, Dateipfad) blockiert ist.
     */
    public function isResourceBlocked(string $resource): bool
    {
        $blockedPatterns = $this->getBlockedPatterns();

        foreach ($blockedPatterns as $pattern) {
            if (str_contains($resource, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validiert eine Tool-Konfiguration auf Sicherheitskonformität.
     * 
     * @param array $config Tool-Konfiguration (Schema)
     * @return bool True, wenn das Tool sicher ist
     */
    public function validateToolConfiguration(array $config): bool
    {
        // 1. Prüfe, ob das Tool einen erlaubten Service referenziert
        if (isset($config['service']) && !$this->isServiceAllowed($config['service'])) {
            return false;
        }

        // 2. Prüfe, ob das Tool auf blockierte Ressourcen zugreift
        if (isset($config['resource']) && $this->isResourceBlocked($config['resource'])) {
            return false;
        }

        // 3. Prüfe blockierte URLs
        if (isset($config['url']) && $this->isResourceBlocked($config['url'])) {
            return false;
        }

        // 4. Prüfe blockierte Dateipfade
        if (isset($config['path']) && $this->isResourceBlocked($config['path'])) {
            return false;
        }

        return true;
    }

    /**
     * Wirft eine Exception, wenn ein Tool nicht erlaubt ist.
     * 
     * @param array $toolSchema Tool-Schema
     * @param string $toolName Name des Tools
     * @throws \RuntimeException Wenn das Tool nicht erlaubt ist
     */
    public function assertToolAllowed(array $toolSchema, string $toolName): void
    {
        if (!$this->validateToolConfiguration($toolSchema)) {
            throw new \RuntimeException(sprintf(
                'Tool "%s" ist nicht in der SecurityGuard-Whitelist enthalten. ' .
                'Erlaubte Services: %s. Blockierte Patterns: %s. ' .
                'Tool-Schema: %s',
                $toolName,
                implode(', ', $this->getAllowedServices()),
                implode(', ', $this->getBlockedPatterns()),
                json_encode($toolSchema, JSON_PRETTY_PRINT)
            ));
        }
    }

    /**
     * Gibt die Liste der erlaubten Services zurück.
     */
    public function getAllowedServices(): array
    {
        return $this->params->get('evie.security.allowed_services', [
            // Standardmäßig erlaubte Services
            'App\AI\Skills\Tool\GenericApiExecutor',
            'App\AI\Skills\Tool\FileSystemReadExecutor',
            'App\AI\Skills\Tool\DatabaseQueryExecutor',
            'App\AI\Skills\Tool\HttpClientExecutor',
            // Symfony AI Bundle Tools
            'Symfony\AI\Agent\Bridge\Wikipedia\Wikipedia',
            'Symfony\AI\Agent\Bridge\Firecrawl\Firecrawl',
            'Symfony\AI\Agent\Bridge\Tavily\Tavily',
            // Wildcard für alle EVIE Tools
            'App\AI\Skills\Tool\*',
        ]);
    }

    /**
     * Gibt die Liste der blockierten Patterns zurück.
     */
    public function getBlockedPatterns(): array
    {
        return $this->params->get('evie.security.blocked_patterns', [
            // Lokale Server
            'localhost',
            '127.0.0.1',
            // Sensible Verzeichnisse
            '/etc/',
            '/root/',
            '/var/',
            '/bin/',
            '/sbin/',
            // Sensible Dateien
            '*.env',
            '*.env.',
            '*.pem',
            '*.key',
            '*.crt',
            'composer.json',
            'composer.lock',
            // Datenbank-Verbindungen
            'mysql:',
            'postgresql:',
            'sqlite:',
            // Interne Netzwerke
            '192.168.',
            '10.',
            '172.16.',
        ]);
    }

    /**
     * Fügt einen Service zur Whitelist hinzu.
     */
    public function allowService(string $serviceName): void
    {
        $allowedServices = $this->getAllowedServices();
        if (!in_array($serviceName, $allowedServices, true)) {
            $allowedServices[] = $serviceName;
            $this->params->set('evie.security.allowed_services', $allowedServices);
        }
    }

    /**
     * Entfernt einen Service aus der Whitelist.
     */
    public function blockService(string $serviceName): void
    {
        $allowedServices = $this->getAllowedServices();
        $key = array_search($serviceName, $allowedServices, true);
        if ($key !== false) {
            unset($allowedServices[$key]);
            $this->params->set('evie.security.allowed_services', array_values($allowedServices));
        }
    }

    /**
     * Fügt ein Pattern zur Blocklist hinzu.
     */
    public function blockPattern(string $pattern): void
    {
        $blockedPatterns = $this->getBlockedPatterns();
        if (!in_array($pattern, $blockedPatterns, true)) {
            $blockedPatterns[] = $pattern;
            $this->params->set('evie.security.blocked_patterns', $blockedPatterns);
        }
    }

    /**
     * Entfernt ein Pattern aus der Blocklist.
     */
    public function allowPattern(string $pattern): void
    {
        $blockedPatterns = $this->getBlockedPatterns();
        $key = array_search($pattern, $blockedPatterns, true);
        if ($key !== false) {
            unset($blockedPatterns[$key]);
            $this->params->set('evie.security.blocked_patterns', array_values($blockedPatterns));
        }
    }
}
