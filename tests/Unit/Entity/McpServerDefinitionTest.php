<?php
// tests/Unit/Entity/McpServerDefinitionTest.php

namespace App\Tests\Unit\Entity;

use App\Entity\McpServerDefinition;
use App\Entity\UserProfile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class McpServerDefinitionTest extends TestCase
{
    public function testConstructor(): void
    {
        $definition = new McpServerDefinition();

        $this->assertNotNull($definition->getId());
        $this->assertInstanceOf(Uuid::class, $definition->getId());
        $this->assertNotNull($definition->getCreatedAt());
        $this->assertNull($definition->getUpdatedAt());
    }

    public function testGettersAndSetters(): void
    {
        $definition = new McpServerDefinition();

        // Test name
        $definition->setName('test_server');
        $this->assertEquals('test_server', $definition->getName());

        // Test type
        $definition->setType('filesystem');
        $this->assertEquals('filesystem', $definition->getType());

        // Test description
        $definition->setDescription('Test description');
        $this->assertEquals('Test description', $definition->getDescription());

        // Test configuration
        $config = ['transport' => 'stdio', 'command' => 'npx'];
        $definition->setConfiguration($config);
        $this->assertEquals($config, $definition->getConfiguration());

        // Test isActive
        $definition->setIsActive(true);
        $this->assertTrue($definition->isActive());
        
        $definition->setIsActive(false);
        $this->assertFalse($definition->isActive());

        // Test allowedTools
        $tools = ['read_file', 'list_files'];
        $definition->setAllowedTools($tools);
        $this->assertEquals($tools, $definition->getAllowedTools());

        // Test blockedResources
        $resources = ['/etc/*', '*.env'];
        $definition->setBlockedResources($resources);
        $this->assertEquals($resources, $definition->getBlockedResources());

        // Test createdAt
        $createdAt = new \DateTimeImmutable();
        $definition->setCreatedAt($createdAt);
        $this->assertEquals($createdAt, $definition->getCreatedAt());

        // Test updatedAt
        $updatedAt = new \DateTimeImmutable();
        $definition->setUpdatedAt($updatedAt);
        $this->assertEquals($updatedAt, $definition->getUpdatedAt());

        // Test createdBy
        $user = new UserProfile();
        $definition->setCreatedBy($user);
        $this->assertSame($user, $definition->getCreatedBy());
    }

    public function testGetMcpConfiguration(): void
    {
        $definition = new McpServerDefinition();
        $definition->setName('test_server');
        $definition->setType('filesystem');
        $definition->setConfiguration([
            'transport' => 'stdio',
            'command' => 'npx',
        ]);

        $expected = [
            'name' => 'test_server',
            'type' => 'filesystem',
            'transport' => 'stdio',
            'command' => 'npx',
        ];

        $this->assertEquals($expected, $definition->getMcpConfiguration());
    }

    public function testIsToolAllowed(): void
    {
        $definition = new McpServerDefinition();

        // Empty allowedTools = all tools allowed
        $this->assertTrue($definition->isToolAllowed('any_tool'));

        // With allowedTools
        $definition->setAllowedTools(['read_file', 'list_files']);
        $this->assertTrue($definition->isToolAllowed('read_file'));
        $this->assertTrue($definition->isToolAllowed('list_files'));
        $this->assertFalse($definition->isToolAllowed('delete_file'));
    }

    public function testIsResourceBlocked(): void
    {
        $definition = new McpServerDefinition();

        // Empty blockedResources = no resources blocked
        $this->assertFalse($definition->isResourceBlocked('/etc/passwd'));

        // With blockedResources
        $definition->setBlockedResources(['/etc/*', '*.env']);
        $this->assertTrue($definition->isResourceBlocked('/etc/passwd'));
        $this->assertTrue($definition->isResourceBlocked('/etc/shadow'));
        $this->assertTrue($definition->isResourceBlocked('.env'));
        $this->assertTrue($definition->isResourceBlocked('config/.env.local'));
        $this->assertFalse($definition->isResourceBlocked('/var/www/index.html'));
    }

    public function testIsResourceBlockedWithWildcards(): void
    {
        $definition = new McpServerDefinition();
        $definition->setBlockedResources(['*.log', '/tmp/*', 'config/*.yml']);

        // Test *.log
        $this->assertTrue($definition->isResourceBlocked('app.log'));
        $this->assertTrue($definition->isResourceBlocked('error.log'));
        $this->assertFalse($definition->isResourceBlocked('app.txt'));

        // Test /tmp/*
        $this->assertTrue($definition->isResourceBlocked('/tmp/file.txt'));
        $this->assertTrue($definition->isResourceBlocked('/tmp/subdir/file.txt'));
        $this->assertFalse($definition->isResourceBlocked('/var/tmp/file.txt'));

        // Test config/*.yml
        $this->assertTrue($definition->isResourceBlocked('config/app.yml'));
        $this->assertTrue($definition->isResourceBlocked('config/database.yml'));
        $this->assertFalse($definition->isResourceBlocked('config/app.yaml'));
    }
}
