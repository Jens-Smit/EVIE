<?php

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\SecurityGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

/**
 * Unit-Tests für SecurityGuard
 * 
 * Testet:
 * - Whitelist-Prüfung für Services
 * - Blocklist-Prüfung für Ressourcen
 * - Tool-Konfiguration-Validierung
 * - Exception-Handling
 * - Parameter-basierte Konfiguration
 */
final class SecurityGuardTest extends TestCase
{
    private SecurityGuard $guard;
    private ParameterBag $params;

    protected function setUp(): void
    {
        $this->params = new ParameterBag([
            'evie.security.allowed_services' => [
                'GenericApiExecutor',
                'FileSystemReadExecutor',
                'DatabaseQueryExecutor',
                'App\AI\Skills\Tool\*',
            ],
            'evie.security.blocked_patterns' => [
                'localhost',
                '127.0.0.1',
                '/etc/',
                '*.env',
                'mysql:',
            ],
        ]);

        $this->guard = new SecurityGuard($this->params);
    }

    // ========================================================================
    // Tests für isServiceAllowed()
    // ========================================================================

    public function testIsServiceAllowedWithDirectMatch(): void
    {
        $this->assertTrue(
            $this->guard->isServiceAllowed('GenericApiExecutor')
        );
    }

    public function testIsServiceAllowedWithWildcardMatch(): void
    {
        $this->assertTrue(
            $this->guard->isServiceAllowed('App\AI\Skills\Tool\CustomTool')
        );
    }

    public function testIsServiceAllowedWithNonAllowedService(): void
    {
        $this->assertFalse(
            $this->guard->isServiceAllowed('UnknownService')
        );
    }

    public function testIsServiceAllowedWithPartialMatch(): void
    {
        // Teilweise Übereinstimmung sollte nicht ausreichen
        $this->assertFalse(
            $this->guard->isServiceAllowed('App\AI\Skills\DangerousExecutor')
        );
    }

    // ========================================================================
    // Tests für isResourceBlocked()
    // ========================================================================

    public function testIsResourceBlockedWithLocalhost(): void
    {
        $this->assertTrue(
            $this->guard->isResourceBlocked('http://localhost/api')
        );
    }

    public function testIsResourceBlockedWithIpAddress(): void
    {
        $this->assertTrue(
            $this->guard->isResourceBlocked('http://127.0.0.1/api')
        );
    }

    public function testIsResourceBlockedWithEtcPath(): void
    {
        $this->assertTrue(
            $this->guard->isResourceBlocked('/etc/passwd')
        );
    }

    public function testIsResourceBlockedWithEnvFile(): void
    {
        $this->assertTrue(
            $this->guard->isResourceBlocked('/path/to/.env')
        );
    }

    public function testIsResourceBlockedWithMysqlUrl(): void
    {
        $this->assertTrue(
            $this->guard->isResourceBlocked('mysql://user:pass@localhost/db')
        );
    }

    public function testIsResourceBlockedWithAllowedResource(): void
    {
        $this->assertFalse(
            $this->guard->isResourceBlocked('https://api.example.com/data')
        );
    }

    public function testIsResourceBlockedWithAllowedPath(): void
    {
        $this->assertFalse(
            $this->guard->isResourceBlocked('/var/www/html/index.html')
        );
    }

    // ========================================================================
    // Tests für validateToolConfiguration()
    // ========================================================================

    public function testValidateToolConfigurationWithValidConfig(): void
    {
        $validConfig = [
            'service' => 'GenericApiExecutor',
            'resource' => 'https://api.example.com/data',
        ];
        $this->assertTrue(
            $this->guard->validateToolConfiguration($validConfig)
        );
    }

    public function testValidateToolConfigurationWithBlockedService(): void
    {
        $blockedServiceConfig = [
            'service' => 'UnknownService',
            'resource' => 'https://api.example.com/data',
        ];
        $this->assertFalse(
            $this->guard->validateToolConfiguration($blockedServiceConfig)
        );
    }

    public function testValidateToolConfigurationWithBlockedResource(): void
    {
        $blockedResourceConfig = [
            'service' => 'GenericApiExecutor',
            'resource' => 'http://localhost/api',
        ];
        $this->assertFalse(
            $this->guard->validateToolConfiguration($blockedResourceConfig)
        );
    }

    public function testValidateToolConfigurationWithBlockedUrl(): void
    {
        $config = [
            'service' => 'GenericApiExecutor',
            'url' => 'http://localhost/api',
        ];
        $this->assertFalse(
            $this->guard->validateToolConfiguration($config)
        );
    }

    public function testValidateToolConfigurationWithBlockedPath(): void
    {
        $config = [
            'service' => 'GenericApiExecutor',
            'path' => '.env',
        ];
        $this->assertFalse(
            $this->guard->validateToolConfiguration($config)
        );
    }

    public function testValidateToolConfigurationWithWildcardService(): void
    {
        $config = [
            'service' => 'App\AI\Skills\Tool\CustomTool',
            'resource' => 'https://api.example.com/data',
        ];
        $this->assertTrue(
            $this->guard->validateToolConfiguration($config)
        );
    }

    // ========================================================================
    // Tests für assertToolAllowed()
    // ========================================================================

    public function testAssertToolAllowedWithValidTool(): void
    {
        $config = [
            'service' => 'GenericApiExecutor',
        ];

        // Sollte keine Exception werfen
        $this->guard->assertToolAllowed($config, 'test_tool');
        $this->assertTrue(true);
    }

    public function testAssertToolAllowedWithBlockedService(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ist nicht in der SecurityGuard-Whitelist enthalten');

        $config = [
            'service' => 'UnknownService',
        ];

        $this->guard->assertToolAllowed($config, 'dangerous_tool');
    }

    public function testAssertToolAllowedWithBlockedResource(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ist nicht in der SecurityGuard-Whitelist enthalten');

        $config = [
            'service' => 'GenericApiExecutor',
            'resource' => '/etc/passwd',
        ];

        $this->guard->assertToolAllowed($config, 'test_tool');
    }

    // ========================================================================
    // Tests für getAllowedServices()
    // ========================================================================

    public function testGetAllowedServices(): void
    {
        $allowedServices = $this->guard->getAllowedServices();

        $this->assertIsArray($allowedServices);
        $this->assertContains('GenericApiExecutor', $allowedServices);
        $this->assertContains('App\AI\Skills\Tool\*', $allowedServices);
    }

    // ========================================================================
    // Tests für getBlockedPatterns()
    // ========================================================================

    public function testGetBlockedPatterns(): void
    {
        $blockedPatterns = $this->guard->getBlockedPatterns();

        $this->assertIsArray($blockedPatterns);
        $this->assertContains('localhost', $blockedPatterns);
        $this->assertContains('*.env', $blockedPatterns);
    }

    // ========================================================================
    // Tests für allowService() und blockService()
    // ========================================================================

    public function testAllowService(): void
    {
        $newService = 'NewService';
        
        // Vorher sollte der Service nicht erlaubt sein
        $this->assertFalse(
            $this->guard->isServiceAllowed($newService)
        );

        // Service zur Whitelist hinzufügen
        $this->guard->allowService($newService);

        // Jetzt sollte der Service erlaubt sein
        $this->assertTrue(
            $this->guard->isServiceAllowed($newService)
        );
    }

    public function testBlockService(): void
    {
        // Service blockieren
        $this->guard->blockService('GenericApiExecutor');

        // Jetzt sollte der Service nicht mehr erlaubt sein
        $this->assertFalse(
            $this->guard->isServiceAllowed('GenericApiExecutor')
        );
    }

    // ========================================================================
    // Tests für allowPattern() und blockPattern()
    // ========================================================================

    public function testBlockPattern(): void
    {
        $newPattern = '/tmp/';
        $resource = '/tmp/test.txt';

        // Vorher sollte die Ressource nicht blockiert sein
        $this->assertFalse(
            $this->guard->isResourceBlocked($resource)
        );

        // Pattern zur Blocklist hinzufügen
        $this->guard->blockPattern($newPattern);

        // Jetzt sollte die Ressource blockiert sein
        $this->assertTrue(
            $this->guard->isResourceBlocked($resource)
        );
    }

    public function testAllowPattern(): void
    {
        $pattern = '/etc/';
        $resource = '/etc/passwd';

        // Vorher sollte die Ressource blockiert sein
        $this->assertTrue(
            $this->guard->isResourceBlocked($resource)
        );

        // Pattern aus der Blocklist entfernen
        $this->guard->allowPattern($pattern);

        // Jetzt sollte die Ressource nicht mehr blockiert sein
        $this->assertFalse(
            $this->guard->isResourceBlocked($resource)
        );
    }

    // ========================================================================
    // Tests für ParameterBag-Integration
    // ========================================================================

    public function testSecurityGuardUsesParameterBag(): void
    {
        // Erstelle einen neuen ParameterBag mit anderen Einstellungen
        $customParams = new ParameterBag([
            'evie.security.allowed_services' => [
                'CustomService',
            ],
            'evie.security.blocked_patterns' => [
                'blocked',
            ],
        ]);

        $customGuard = new SecurityGuard($customParams);

        // Prüfe, ob die custom Einstellungen verwendet werden
        $this->assertTrue(
            $customGuard->isServiceAllowed('CustomService')
        );
        $this->assertFalse(
            $customGuard->isServiceAllowed('GenericApiExecutor')
        );
        $this->assertTrue(
            $customGuard->isResourceBlocked('blocked')
        );
        $this->assertFalse(
            $customGuard->isResourceBlocked('localhost')
        );
    }

    // ========================================================================
    // Edge Cases
    // ========================================================================

    public function testIsServiceAllowedWithEmptyString(): void
    {
        $this->assertFalse(
            $this->guard->isServiceAllowed('')
        );
    }

    public function testIsResourceBlockedWithEmptyString(): void
    {
        $this->assertFalse(
            $this->guard->isResourceBlocked('')
        );
    }

    public function testValidateToolConfigurationWithEmptyConfig(): void
    {
        $this->assertTrue(
            $this->guard->validateToolConfiguration([])
        );
    }
}
