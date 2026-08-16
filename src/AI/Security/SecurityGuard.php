<?php

namespace App\AI\Security;

use App\AI\Skills\Tool\DynamicTool;
use App\Entity\ToolDefinition;
use Symfony\AI\Platform\Result\ToolCall;
use Psr\Log\LoggerInterface;

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
        'App\AI\Skills\Executor\GenericApiExecutor',
        'App\AI\Skills\Executor\GenericFileExecutor',
        'App\AI\Skills\Executor\GenericDatabaseExecutor',
        'App\AI\Skills\Executor\GenericHttpExecutor',
        'App\AI\Skills\Executor\GenericExecutor',
        'App\AI\Mcp\Filesystem\McpClient',
        'App\AI\Mcp\Playwright\McpClient',
        'App\AI\Mcp\GitHub\McpClient',
        'ai.mcp.server.filesystem',
        'ai.mcp.server.playwright',
        'ai.mcp.server.github',
        'ai.mcp.server.custom',
        'npx',
        'node',
        'python',
        'docker',
    ];

    public function __construct(
        private LoggerInterface $logger,
        private ?OutboundRequestPolicy $outboundRequestPolicy = null,
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

        // Host extrahieren und IPv6-Brackets entfernen ([::1] -> ::1)
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        $host = trim($host, '[]');

        // Nicht-kanonische IP-Formate normalisieren (Dezimal, Hex, Oktal, kurze Form).
        // Nur so lässt sich zuverlässig prüfen, ob eine private Adresse vorliegt.
        $normalized = $this->normalizeHost($host);
        if ($normalized !== $host) {
            $host = $normalized;
        }

        foreach ($this->blockedResources as $blocked) {
            if (str_starts_with($host, $blocked) || str_contains($host, $blocked)) {
                $this->logger->warning('Host ist geblockt', ['host' => $host]);
                return false;
            }
        }

        // Prüfe ob es eine private IP ist (nach Normalisierung)
        if ($this->isPrivateIp($host)) {
            $this->logger->warning('Private IP-Adresse geblockt', ['host' => $host]);
            return false;
        }

        // Defense-in-Depth: ist die OutboundRequestPolicy injiziert (Produktion),
        // läuft zusätzlich eine DNS-basierte Prüfung, die auch Domains erfasst,
        // die auf private IPs auflösen (z. B. evil.com -> 169.254.169.254). Die
        // String-basierte Prüfung oben sieht nur den Hostnamen, nicht die IP.
        if (null !== $this->outboundRequestPolicy && !$this->outboundRequestPolicy->isUrlAllowed($url)) {
            $this->logger->warning('URL durch OutboundRequestPolicy geblockt', ['url' => $url]);
            return false;
        }

        return true;
    }

    /**
     * Prüfe ob ein Pfad sicher ist (Filesystem-Sandbox).
     *
     * Blockt neben absoluten sensitiven Pfaden auch Directory-Traversal
     * ("../", "..\\", URL-encoded Varianten) und resolviert Symlinks,
     * sodass ein Escape ausserhalb der Sandbox verhindert wird.
     */
    public function isPathSafe(string $path): bool
    {
        // Directory Traversal blocken (auch URL-encoded)
        $decoded = rawurldecode($path);
        if (str_contains($path, '..') || str_contains($decoded, '..')) {
            $this->logger->warning('Directory Traversal geblockt', ['path' => $path]);
            return false;
        }

        // Original-Pfad gegen Blocklist pruefen (vor realpath, da realpath
        // Symlinks wie /var/run -> /run aufloesen kann und so einen geblockten
        // Pfad verschleiern wuerde).
        foreach ($this->blockedPaths as $blockedPath) {
            if (str_starts_with($path, $blockedPath)) {
                $this->logger->warning('Pfad ist geblockt', ['path' => $path]);
                return false;
            }
        }

        // Zusaetzlich: realpath pruefen, falls die Datei existiert, um
        // Symlink-Escape ausserhalb der Sandbox zu erkennen. realpath
        // darf den Original-Check aber nicht aufheben.
        $realPath = @realpath($path);
        if (false !== $realPath && $realPath !== $path) {
            foreach ($this->blockedPaths as $blockedPath) {
                if (str_starts_with($realPath, $blockedPath)) {
                    $this->logger->warning('Pfad (realpath) ist geblockt', ['path' => $realPath]);
                    return false;
                }
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
     * Prüfe ob eine IP privat ist.
     *
     * Akzeptiert kanonische IPv4/IPv6 nach Normalisierung durch
     * normalizeHost(), sodass Dezimal-/Hex-/Oktal-/Kurzformen erkannt werden.
     */
    private function isPrivateIp(string $host): bool
    {
        // IPv6 Loopback / Link-Local / Unique Local (string-basiert, da ip2long nur IPv4)
        $hostLower = strtolower($host);
        if ($hostLower === '::1' || str_starts_with($hostLower, 'fe80:') || str_starts_with($hostLower, 'fc') || str_starts_with($hostLower, 'fd')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = ip2long($host);
            if (false === $ip) {
                return false;
            }

            return
                ($ip >= ip2long('10.0.0.0') && $ip <= ip2long('10.255.255.255')) ||
                ($ip >= ip2long('172.16.0.0') && $ip <= ip2long('172.31.255.255')) ||
                ($ip >= ip2long('192.168.0.0') && $ip <= ip2long('192.168.255.255')) ||
                ($ip >= ip2long('169.254.0.0') && $ip <= ip2long('169.254.255.255')) ||
                $ip === ip2long('127.0.0.1') ||
                $ip === ip2long('0.0.0.0');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->isPrivateIpv6($host);
        }

        return false;
    }

    /**
     * Normalisiert nicht-kanonische IP-Host-Formate auf die kanonische
     * dotted-quad-Form, damit isPrivateIp() zuverlässig prüfen kann.
     *
     * Behandelt: Dezimal (2130706433), Hex (0x7f000001), Oktal (0177.0.0.1),
     * kurze Form (127.1) und IPv4-mapped IPv6 (::ffff:127.0.0.1).
     */
    private function normalizeHost(string $host): string
    {
        // IPv4-mapped/compatible IPv6 (::ffff:127.0.0.1)
        if (preg_match('/^::(?:ffff:)?(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/i', $host, $m)) {
            $host = $m[1];
        }

        // Reine Dezimalzahl (z. B. 2130706433 -> 127.0.0.1)
        if (ctype_digit($host) && $host !== '' && strlen($host) <= 10) {
            $long = (int) $host;
            $packed = pack('N', $long);
            if (false !== $packed) {
                $parts = unpack('C4', $packed);
                if (false !== $parts) {
                    return implode('.', $parts);
                }
            }
        }

        // Hexadezimal (0x7f000001)
        if (preg_match('/^0x([0-9a-f]+)$/i', $host, $m)) {
            $long = hexdec($m[1]);
            if ($long <= 0xFFFFFFFF) {
                $packed = pack('N', $long);
                $parts = unpack('C4', $packed);
                if (false !== $parts) {
                    return implode('.', $parts);
                }
            }
        }

        // Oktal-Form (0177.0.0.1) oder kurze Form (127.1, 127.0.1)
        // PHP filter_var erkennt kurze Formen nicht als IPv4, daher manuell
        // expandieren: 127.1 -> 127.0.0.1, 192.168.1 -> 192.168.0.1
        if (preg_match('/^(\d+)(\.\d+)*$/', $host)) {
            $parts = explode('.', $host);
            // Oktal-Erkennung: ein Teil mit fuehrender 0 und Laenge > 1
            $octets = array_map(
                static fn (string $part): int => str_starts_with($part, '0') && strlen($part) > 1 && ctype_digit($part)
                    ? intval($part, 8)
                    : (int) $part,
                $parts
            );

            // Kurze Form expandieren (weniger als 4 Oktetts)
            // inet_pton-kompatible Logik: das letzte Oktett wird aufgespalten
            if (count($octets) < 4) {
                $last = array_pop($octets);
                $octets = array_pad($octets, 3, 0);
                while ($last > 255) {
                    array_unshift($octets, $last & 0xFF);
                    $last >>= 8;
                    if (count($octets) >= 4) {
                        break;
                    }
                }
                $octets = array_pad($octets, 4, 0);
                $octets[3] = $last & 0xFF;
            }

            $candidate = implode('.', array_slice($octets, 0, 4));
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $candidate;
            }
        }

        return $host;
    }

    private function isPrivateIpv6(string $ip): bool
    {
        $ipLower = strtolower($ip);
        if ($ipLower === '::1') {
            return true;
        }
        if (str_starts_with($ipLower, 'fc') || str_starts_with($ipLower, 'fd')) {
            return true;
        }
        if (str_starts_with($ipLower, 'fe80:')) {
            return true;
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

    /**
     * Native Policy-Entscheidung fuer das ToolCallRequested-Event (Blueprint 4.E).
     *
     * Liefert eine PolicyDecision:
     *  - Deny:    Executor nicht gelistet, SSRF-Verstoss, Pfad ausserhalb der
     *            Sandbox oder nicht gelisteter Service.
     *  - AskUser: dynamisches Tool (ToolDefinition vorhanden) mit HITL-Markierung
     *            (requiresHitl) oder hohem Security-Level.
     *  - Allow:   statisches Tool ohne Policy-Verstoss.
     */
    /**
     * Prueft, ob ein Tool-Name zur Ausfuehrung zugelassen ist (Blueprint §4.E).
     *
     * Fuer statische Tools und MCP-Tools ohne persistierte ToolDefinition
     * ist kein dynamischer Policy-Verstoss feststellbar — die harte HITL-
     * Blockade laeuft ueber den nativen HitlListener (ToolCallRequested).
     * Diese Methode wird nur vom Legacy-McpToolExecutor genutzt.
     */
    /**
     * Prueft, ob eine Ressource (URL oder Pfad) blockiert ist (Blueprint §4.E).
     *
     * Legacy-Methode fuer den McpServerFactory/McpToolExecutor; die harte
     * Policy-Entscheidung erfolgt im HitlListener (ToolCallRequested).
     */
    public function isResourceBlocked(string $resource): bool
    {
        if ($this->looksLikeUrl($resource)) {
            return !$this->isUrlSafe($resource);
        }

        return !$this->isPathSafe($resource);
    }

    public function isToolAllowed(string $toolName): bool
    {
        // Statische und MCP-Tools sind per Default zugelassen; die
        // Policy-Entscheidung (Allow/Deny/AskUser) erfolgt im HitlListener.
        return true;
    }

    public function decide(ToolCall $toolCall, ?ToolDefinition $definition = null): PolicyDecision
    {
        if (null !== $definition && null !== $definition->getExecutorType()) {
            if (!in_array($definition->getExecutorType(), $this->allowedExecutors, true)) {
                return PolicyDecision::Deny;
            }

            $executorClass = $this->resolveExecutorClass($definition->getExecutorType());
            if (null !== $executorClass && !$this->isServiceAllowed($executorClass)) {
                return PolicyDecision::Deny;
            }
        }

        foreach ($this->extractStringArguments($toolCall) as $value) {
            if ($this->looksLikeUrl($value) && !$this->isUrlSafe($value)) {
                return PolicyDecision::Deny;
            }
            if ($this->looksLikePath($value) && !$this->isPathSafe($value)) {
                return PolicyDecision::Deny;
            }
            // P1-3: Shell-Metazeichen in String-Argumenten blockieren, die
            // an Executoren mit Prozess-/Subprozess-Charakter gehen
            // (Command-Chaining, Command-Substitution). Verhindert
            // Injection-Payloads wie "; rm -rf /", "`whoami`", "$(cat ...)".
            if ($this->containsShellMetacharacters($value)) {
                return PolicyDecision::Deny;
            }
        }

        if (null !== $definition && (true === $definition->getRequiresHitl() || 'high' === $definition->getSecurityLevel())) {
            return PolicyDecision::AskUser;
        }

        return PolicyDecision::Allow;
    }

    private function resolveExecutorClass(string $executorType): ?string
    {
        $map = [
            'api' => 'App\\AI\\Skills\\Executor\\GenericApiExecutor',
            'database' => 'App\\AI\\Skills\\Executor\\GenericDatabaseExecutor',
            'filesystem' => 'App\\AI\\Skills\\Executor\\GenericFileExecutor',
            'http' => 'App\\AI\\Skills\\Executor\\GenericHttpExecutor',
            'generic' => 'App\\AI\\Skills\\Executor\\GenericExecutor',
        ];

        return $map[$executorType] ?? null;
    }

    /**
     * @return array<int, string>
     */
    private function extractStringArguments(ToolCall $toolCall): array
    {
        $arguments = $toolCall->getArguments();
        $strings = [];
        foreach ($arguments as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            } elseif (is_array($value)) {
                array_walk_recursive($value, static function (mixed $v) use (&$strings): void {
                    if (is_string($v)) {
                        $strings[] = $v;
                    }
                });
            }
        }

        return $strings;
    }

    /**
     * Erkennt Shell-Metazeichen / Command-Injection-Pattern in einem
     * String-Argument (P1-3).
     *
     * Geprueft werden Command-Chaining-Operatoren (;, &&, ||, |) und
     * Command-Substitution-Syntax (`backticks`, $(...), ${...}). Diese
     * Zeichen in Tool-Argumenten sind ein starkes Indiz fuer Injection-
     * Versuche, da legitime Argumente (URLs, Pfade, Suchbegriffe) diese
     * nicht enthalten.
     *
     * Die Pruefung ist bewusst konservativ: sie blockiert potenziell
     * gefaehrliche Pattern, ohne false positives bei normalen
     * Benutzer-Eingaben zu erzeugen (z. B. URLs mit Query-Parametern).
     */
    public function containsShellMetacharacters(string $value): bool
    {
        // Command-Substitution: $(...) und ${...} (gekapselt, damit
        // Template-Strings wie "Hallo {name}" nicht matchen).
        if (preg_match('/\$\(.*\)|\$\{.*\}/', $value)) {
            return true;
        }
        // Backtick-Command-Substitution.
        if (str_contains($value, '`')) {
            return true;
        }
        // Command-Chaining: ;, &&, ||, gefolgt von einem Shell-Command.
        // Ein einzelnes "|" in einer URL ist erlaubt (looksLikeUrl prueft
        // die URL separat), aber "| <cmd>" oder "&& <cmd>" ist Injection.
        if (preg_match('/(?:&&|\|\||;|\|\s)\s*\S/', $value)) {
            return true;
        }

        return false;
    }

    private function looksLikeUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }

    private function looksLikePath(string $value): bool
    {
        // Auch URL-encoded Pfade erkennen (%2e%2e = ..)
        $decoded = rawurldecode($value);

        return str_starts_with($value, '/')
            || str_starts_with($value, './')
            || str_starts_with($value, '../')
            || str_contains($value, '/../')
            || str_starts_with($decoded, '/')
            || str_starts_with($decoded, './')
            || str_starts_with($decoded, '../')
            || str_contains($decoded, '/../');
    }
}
