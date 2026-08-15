<?php

namespace App\AI\Security;

use Psr\Log\LoggerInterface;

/**
 * OutboundRequestPolicy - Echte SSRF-Abwehr
 * Ersetzt String-Blocklists durch echte Prüfungen
 */
class OutboundRequestPolicy
{
    private array $allowedSchemes = ['http', 'https'];
    private array $allowedPorts = [80, 443, 8080, 8443];
    private array $allowedHostPatterns = [];
    private array $blockedHostPatterns = [
        'localhost',
        '127.0.0.1',
        '192.168.',
        '10.',
        '172.(1[6-9]|2[0-9]|3[0-1]).',
        '169.254.',
        '0.0.0.0',
        '::1',
        'fe80::',
        'fc00::',
    ];

    private bool $allowPrivateNetworks = false;
    private bool $allowRedirects = false;
    private int $maxRedirects = 0;

    public function __construct(
        private LoggerInterface $logger,
        array $options = []
    ) {
        $this->allowedSchemes = $options['allowed_schemes'] ?? $this->allowedSchemes;
        $this->allowedPorts = $options['allowed_ports'] ?? $this->allowedPorts;
        $this->allowedHostPatterns = $options['allowed_host_patterns'] ?? $this->allowedHostPatterns;
        $this->allowPrivateNetworks = $options['allow_private_networks'] ?? $this->allowPrivateNetworks;
        $this->allowRedirects = $options['allow_redirects'] ?? $this->allowRedirects;
        $this->maxRedirects = $options['max_redirects'] ?? $this->maxRedirects;
    }

    /**
     * Prüfe ob eine URL sicher ist
     */
    public function isUrlAllowed(string $url): bool
    {
        try {
            $parsed = parse_url($url);
            
            if (!$parsed) {
                $this->logger->warning('Ungültige URL', ['url' => $url]);
                return false;
            }

            // Prüfe Scheme
            if (!in_array(strtolower($parsed['scheme'] ?? ''), $this->allowedSchemes, true)) {
                $this->logger->warning('Nicht erlaubtes Scheme', [
                    'url' => $url,
                    'scheme' => $parsed['scheme'] ?? 'none'
                ]);
                return false;
            }

            // Prüfe Host
            $host = $parsed['host'] ?? '';
            if (!$this->isHostAllowed($host)) {
                $this->logger->warning('Host nicht erlaubt', ['host' => $host]);
                return false;
            }

            // Prüfe Port
            $port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);
            if (!in_array((int)$port, $this->allowedPorts, true)) {
                $this->logger->warning('Port nicht erlaubt', ['port' => $port]);
                return false;
            }

            // Prüfe Pfad (keine Directory Traversal)
            $path = $parsed['path'] ?? '';
            if ($this->containsDirectoryTraversal($path)) {
                $this->logger->warning('Directory Traversal versucht', ['path' => $path]);
                return false;
            }

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Fehler bei URL-Prüfung', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Prüfe ob ein Host erlaubt ist
     */
    private function isHostAllowed(string $host): bool
    {
        // IPv6-Brackets entfernen ([::1] -> ::1), damit die nachfolgenden
        // Pattern- und IP-Prüfungen zuverlässig greifen.
        $host = trim($host, '[]');

        // Prüfe gegen geblockte Patterns
        foreach ($this->blockedHostPatterns as $pattern) {
            if (fnmatch($pattern, $host)) {
                return false;
            }
        }

        // Prüfe gegen erlaubte Patterns (falls definiert)
        if (!empty($this->allowedHostPatterns)) {
            foreach ($this->allowedHostPatterns as $pattern) {
                if (fnmatch($pattern, $host)) {
                    return true;
                }
            }
            return false;
        }

        // Prüfe ob es eine private IP ist
        if (!$this->allowPrivateNetworks && $this->isPrivateNetwork($host)) {
            return false;
        }

        // Prüfe DNS (falls Host eine IP ist)
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isIpAllowed($host);
        }

        // Host ist erlaubt, wenn nicht explizit geblockt
        return true;
    }

    /**
     * Prüfe ob eine IP erlaubt ist
     */
    private function isIpAllowed(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return !$this->isPrivateIpv4($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return !$this->isPrivateIpv6($ip);
        }

        return false;
    }

    /**
     * Prüfe ob eine IPv4-Adresse privat ist
     */
    private function isPrivateIpv4(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }

        return 
            ($long >= ip2long('10.0.0.0') && $long <= ip2long('10.255.255.255')) ||
            ($long >= ip2long('172.16.0.0') && $long <= ip2long('172.31.255.255')) ||
            ($long >= ip2long('192.168.0.0') && $long <= ip2long('192.168.255.255')) ||
            ($long >= ip2long('169.254.0.0') && $long <= ip2long('169.254.255.255')) ||
            $long === ip2long('127.0.0.1') ||
            $long === ip2long('0.0.0.0');
    }

    /**
     * Prüfe ob eine IPv6-Adresse privat ist
     */
    private function isPrivateIpv6(string $ip): bool
    {
        // Vereinfachte Prüfung für IPv6
        // Loopback: ::1
        // Unique Local Addresses: fc00::/7
        // Link-Local: fe80::/10
        
        if ($ip === '::1') {
            return true;
        }

        if (str_starts_with($ip, 'fc00:')) {
            return true;
        }

        if (str_starts_with($ip, 'fe80:')) {
            return true;
        }

        return false;
    }

    /**
     * Prüfe ob ein Netzwerk privat ist
     */
    private function isPrivateNetwork(string $host): bool
    {
        // Versuche, den Host aufzulösen
        $ips = gethostbynamel($host);
        if ($ips === false) {
            // Konnte nicht auflösen, also nicht privat
            return false;
        }

        foreach ($ips as $ip) {
            if ($this->isPrivateIpv4($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prüfe auf Directory Traversal
     */
    private function containsDirectoryTraversal(string $path): bool
    {
        return str_contains($path, '..') || str_contains($path, '%2e%2e');
    }

    /**
     * Füge erlaubtes Host-Pattern hinzu
     */
    public function addAllowedHostPattern(string $pattern): void
    {
        $this->allowedHostPatterns[] = $pattern;
    }

    /**
     * Füge geblocktes Host-Pattern hinzu
     */
    public function addBlockedHostPattern(string $pattern): void
    {
        $this->blockedHostPatterns[] = $pattern;
    }

    /**
     * Erlaube private Netzwerke
     */
    public function allowPrivateNetworks(bool $allow = true): void
    {
        $this->allowPrivateNetworks = $allow;
    }

    /**
     * Erlaube Redirects
     */
    public function allowRedirects(bool $allow = true, int $max = 5): void
    {
        $this->allowRedirects = $allow;
        $this->maxRedirects = $max;
    }
}
