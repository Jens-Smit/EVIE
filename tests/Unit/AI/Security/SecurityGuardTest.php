<?php

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\SecurityGuard;
use PHPUnit\Framework\TestCase;

class SecurityGuardTest extends TestCase
{
    private SecurityGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SecurityGuard();
    }

    public function testIsServiceAllowed(): void
    {
        $this->assertTrue($this->guard->isServiceAllowed('GenericApiExecutor'));
        $this->assertTrue($this->guard->isServiceAllowed('FileSystemReadExecutor'));
        $this->assertFalse($this->guard->isServiceAllowed('UnknownService'));
    }

    public function testIsResourceBlocked(): void
    {
        $this->assertTrue($this->guard->isResourceBlocked('http://localhost/api'));
        $this->assertTrue($this->guard->isResourceBlocked('/etc/passwd'));
        $this->assertTrue($this->guard->isResourceBlocked('127.0.0.1'));
        $this->assertFalse($this->guard->isResourceBlocked('https://api.example.com/data'));
    }

    public function testValidateToolConfiguration(): void
    {
        // Valid configuration
        $validConfig = [
            'service' => 'GenericApiExecutor',
            'resource' => 'https://api.example.com/data',
        ];
        $this->assertTrue($this->guard->validateToolConfiguration($validConfig));

        // Blocked service
        $blockedServiceConfig = [
            'service' => 'UnknownService',
            'resource' => 'https://api.example.com/data',
        ];
        $this->assertFalse($this->guard->validateToolConfiguration($blockedServiceConfig));

        // Blocked resource
        $blockedResourceConfig = [
            'service' => 'GenericApiExecutor',
            'resource' => 'http://localhost/api',
        ];
        $this->assertFalse($this->guard->validateToolConfiguration($blockedResourceConfig));
    }

    public function testAllowService(): void
    {
        $this->guard->allowService('NewService');
        $this->assertTrue($this->guard->isServiceAllowed('NewService'));
    }

    public function testBlockService(): void
    {
        $this->guard->blockService('GenericApiExecutor');
        $this->assertFalse($this->guard->isServiceAllowed('GenericApiExecutor'));
    }

    public function testBlockPattern(): void
    {
        $this->guard->blockPattern('blocked-pattern');
        $this->assertTrue($this->guard->isResourceBlocked('https://example.com/blocked-pattern'));
    }

    public function testAllowPattern(): void
    {
        $this->guard->blockPattern('blocked-pattern');
        $this->guard->allowPattern('blocked-pattern');
        $this->assertFalse($this->guard->isResourceBlocked('https://example.com/blocked-pattern'));
    }
}
