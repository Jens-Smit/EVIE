<?php

namespace AppAISecurity;

use AppAISkillsToolDynamicTool;
use PsrLogLoggerInterface;

/**
 * SecurityGuard - Überprüft ob Tools sicher ausgeführt werden dürfen
 * Keine Wildcards mehr, nur explizite Executor-IDs
 */
class SecurityGuard
{
    private array $allowedExecutors = [
        'api',
        'database',
        'filesystem',
        'http',
        'generic'
    ];

    private array $blockedResources = [
        'localhost',
        '127.0.0.1',
        '192.168.',
        '10.',
        '172.16.',
        '169.254.',
        '0.0.0.0',
        '::1',
        'fe80::',
        'fc00::',
    ];

    private array $blockedPaths = [
        '/etc',
        '/root',
        '/home',
        '/var',
        '/usr',
        '/bin',
        '/sbin',
        '/proc',
        '/sys',
        '/dev',
        '/boot',
    ];

    private array $blockedUrls = [
        'http://localhost',
        'https://localhost',
        'http://127.0.0.1',
        'https://127.0.0.1',
    ];

    private array $allowedServices = [
        'AppAISkillsExecutorGenericApiExecutor',
        'AppAISkillsExecutorGenericFileExecutor',
        'AppAISkillsExecutorGenericDatabaseExecutor',
        'AppAISkillsExecutorGenericHttpExecutor',
        'AppAISkillsExecutorGenericExecutor',
        'AppAIMcpFilesystemMcpClient',
        'AppAIMcpPlaywrightMcpClient',
        'AppAIMcpGitHubMcpClient',
    ];

    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    /**
     * Prüfe ob ein Tool sicher ist
     */
    public function isToolSafe(DynamicTool $tool): bool
    {
        $executorType = $tool->getExecutorType();
        
        // Prüfe ob Executor erlaubt ist
        if (!in_array($executorType, $this->allowedExecutors, true)) {
            $this->logger->warning('Tool hat nicht erlaubten Executor-Type', [
                'tool_name' => $tool->getName(),
                'executor_type' => $executorType
            ]);
            return false;
        }

        // Prüfe Security Policy des Tools
        $securityPolicy = $tool->getSecurityPolicy();
        if (isset($securityPolicy['allowed']) && $securityPolicy['allowed'] === false) {
            $this->logger->warning('Tool ist explizit blockiert', [
                'tool_name' => $tool->getName()
            ]);
            return false;
        }

        return true;
    }

    /**
     * Prüfe ob eine URL sicher ist (SSRF-Schutz)
     */
    public function isUrlSafe(string $url): bool
    {
        // Prüfe gegen geblockte URLs
        foreach ($this->blockedUrls as $blockedUrl) {
            if (str_starts_with($url, $blockedUrl)) {
                $this->logger->warning('URL ist geblockt', ['url' => $url]);
                return false;
            }
        }

        // Prüfe Host
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        foreach ($this->blockedResources as $blocked) {
            if (str_starts_with($host, $blocked) || str_contains($host, $blocked)) {
                $this->logger->warning('Host ist geblockt', ['host' => $host]);
                return false;
            }
        }

        // Prüfe ob es eine private IP ist
        if ($this->isPrivateIp($host)) {
            $this->logger->warning('Private IP-Adresse geblockt', ['host' => $host]);
            return false;
        }

        return true;
    }

    /**
     * Prüfe ob ein Pfad sicher ist
     */
    public function isPathSafe(string $path): bool
    {
        foreach ($this->blockedPaths as $blockedPath) {
            if (str_starts_with($path, $blockedPath)) {
                $this->logger->warning('Pfad ist geblockt', ['path' => $path]);
                return false;
            }
        }

        return true;
    }

    /**
     * Prüfe ob ein Service erlaubt ist (keine Wildcards mehr!)
     */
    public function isServiceAllowed(string $serviceClass): bool
    {
        // Explizite Prüfung - keine Wildcards!
        return in_array($serviceClass, $this->allowedServices, true);
    }

    /**
     * Prüfe ob eine IP privat ist
     */
    private function isPrivateIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = ip2long($host);
            return 
                ($ip >= ip2long('10.0.0.0') && $ip <= ip2long('10.255.255.255')) ||
                ($ip >= ip2long('172.16.0.0') && $ip <= ip2long('172.31.255.255')) ||
                ($ip >= ip2long('192.168.0.0') && $ip <= ip2long('192.168.255.255')) ||
                ($ip >= ip2long('169.254.0.0') && $ip <= ip2long('169.254.255.255')) ||
                $ip === ip2long('127.0.0.1') ||
                $ip === ip2long('0.0.0.0');
        }

        return false;
    }

    /**
     * Füge erlaubten Executor hinzu
     */
    public function addAllowedExecutor(string $executorType): void
    {
        if (!in_array($executorType, $this->allowedExecutors, true)) {
            $this->allowedExecutors[] = $executorType;
        }
    }

    /**
     * Füge geblockte Ressource hinzu
     */
    public function addBlockedResource(string $resource): void
    {
        if (!in_array($resource, $this->blockedResources, true)) {
            $this->blockedResources[] = $resource;
        }
    }

    /**
     * Füge erlaubten Service hinzu
     */
    public function addAllowedService(string $serviceClass): void
    {
        if (!in_array($serviceClass, $this->allowedServices, true)) {
            $this->allowedServices[] = $serviceClass;
        }
    }

    /**
     * Entferne erlaubten Service
     */
    public function removeAllowedService(string $serviceClass): void
    {
        $key = array_search($serviceClass, $this->allowedServices, true);
        if ($key !== false) {
            unset($this->allowedServices[$key]);
        }
    }

    /**
     * Getter für Testzwecke
     */
    public function getAllowedExecutors(): array
    {
        return $this->allowedExecutors;
    }

    public function getAllowedServices(): array
    {
        return $this->allowedServices;
    }

    public function getBlockedResources(): array
    {
        return $this->blockedResources;
    }
}
