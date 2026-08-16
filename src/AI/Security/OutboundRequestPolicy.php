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
        $packed = @inet_pton($ip);
        if (false === $packed) {
            // Fallback: String-Praefix fuer nicht-kanonische Eingaben.
            $ipLower = strtolower($ip);
            return $ipLower === '::1'
                || str_starts_with($ipLower, 'fc')
                || str_starts_with($ipLower, 'fd')
                || str_starts_with($ipLower, 'fe80:')
                || $ipLower === '::';
        }

        // 16-Byte kanonische Form (inet_pton-Ausgabe) auswerten.
        // :: (unspecified) = 16 Null-Bytes.
        if ($packed === "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0") {
            return true;
        }
        // ::1 (loopback) = 15 Null-Bytes + 0x01.
        if ($packed === "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\1") {
            return true;
        }

        $b0 = ord($packed[0]);
        $b1 = ord($packed[1]);

        // fc00::/7: erstes Bit gesetzt in erstem Byte (0xfc..0xfd).
        if (($b0 & 0xFE) === 0xFC) {
            return true;
        }
        // fe80::/10: 0xfe und oberste 2 Bits des zweiten Bytes gesetzt.
        if ($b0 === 0xFE && ($b1 & 0xC0) === 0x80) {
            return true;
        }

        // IPv4-mapped (::ffff:a.b.c.d) oder IPv4-compatible (::a.b.c.d):
        // Bytes 0..9 null, Byte 10/11 = 0xff/0xff (mapped) oder beide 0 (compat).
        if ($b0 === 0
            && substr($packed, 0, 10) === "\0\0\0\0\0\0\0\0\0\0"
            && ((ord($packed[10]) === 0xFF && ord($packed[11]) === 0xFF)
                || (ord($packed[10]) === 0 && ord($packed[11]) === 0))) {
            // Eingebettete IPv4 extrahieren und gegen private IPv4-Ranges pruefen.
            $v4 = @inet_ntop(substr($packed, 12, 4));
            if (false !== $v4 && $this->isPrivateIpv4($v4)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Loest den Host auf und gibt die erste zulaessige IP zurueck, die fuer
     * das Connection-Pinning verwendet werden kann.
     *
     * TOCTOU-Schutz gegen DNS-Rebinding: wenn der HTTP-Client die URL mit dem
     * urspruenglichen Hostnamen abruft, kann das DNS zwischen der Policy-
     * Pruefung und dem Verbindungsaufbau erneut aufgeloest werden (z. B.
     * evil.com -> 93.184.216.34 bei der Pruefung, dann evil.com -> 127.0.0.1
     * beim Abruf). Indem die gepruefte IP zurueckgegeben und vom Client als
     * Connection-Target verwendet wird, entfaellt das Zeitfenster.
     *
     * @return string|null Gepruefte IP-Adresse oder null, wenn der Host
     *                     keine zulaessige IP hat oder ungueltig ist.
     */
    public function resolveAllowedIp(string $url): ?string
    {
        $parsed = @parse_url($url);
        if (!is_array($parsed)) {
            return null;
        }
        $host = trim((string) ($parsed['host'] ?? ''), '[]');
        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isIpAllowed($host) ? $host : null;
        }

        $ips = @gethostbynamel($host);
        if (is_array($ips)) {
            foreach ($ips as $ip) {
                if ($this->isIpAllowed($ip)) {
                    return $ip;
                }
            }
        }

        $recordsV6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($recordsV6)) {
            foreach ($recordsV6 as $record) {
                $ipv6 = $record['ipv6'] ?? null;
                if (null !== $ipv6 && $this->isIpAllowed($ipv6)) {
                    return $ipv6;
                }
            }
        }

        return null;
    }

    /**
     * Prüfe ob ein Netzwerk privat ist
     */
    private function isPrivateNetwork(string $host): bool
    {
        // IPv4-Auflösung (A-Records).
        $ips = @gethostbynamel($host);
        if (false !== $ips) {
            foreach ($ips as $ip) {
                if ($this->isPrivateIpv4($ip)) {
                    return true;
                }
            }
        }

        // IPv6-Auflösung (AAAA-Records). gethostbynamel() liefert nur
        // IPv4-Adressen, sodass eine Domain, die ausschliesslich auf eine
        // private/link-lokale IPv6-Adresse auflöst, sonst durchrutschen wuerde.
        $recordsV6 = @dns_get_record($host, DNS_AAAA);
        if (false !== $recordsV6) {
            foreach ($recordsV6 as $record) {
                $ipv6 = $record['ipv6'] ?? null;
                if (null !== $ipv6 && $this->isPrivateIpv6($ipv6)) {
                    return true;
                }
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
