<?php
// tests/Integration/AI/Mcp/McpServerFactoryIntegrationTest.php

namespace App\Tests\Integration\AI\Mcp;

use App\AI\Mcp\McpServerFactory;
use App\Entity\McpServerDefinition;
use App\Entity\User;
use App\Repository\McpServerDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class McpServerFactoryIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private McpServerDefinitionRepository $repo;
    private McpServerFactory $factory;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->repo = $this->entityManager->getRepository(McpServerDefinition::class);
        $this->factory = self::getContainer()->get(McpServerFactory::class);

        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->entityManager);
        try {
            $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
        } catch (\Throwable) {
        }
    }

    public function testCreateFromDefinitionWithFilesystem(): void
    {
        // 1. Erstelle einen Test-User
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('test-password-hash');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $this->entityManager->persist($user);

        // 2. Erstelle eine MCP-Server-Definition in der DB
        $definition = new McpServerDefinition();
        $definition->setName('test_filesystem');
        $definition->setType('filesystem');
        $definition->setDescription('Test Filesystem Server');
        $definition->setConfiguration([
            'transport' => 'stdio',
            'command' => 'npx',
            'arguments' => ['-y', '@modelcontextprotocol/server-filesystem', '%kernel.project_dir%/var/mcp-sandbox']
        ]);
        $definition->setAllowedTools(['read_file', 'list_files']);
        $definition->setBlockedResources(['/etc/*', '*.env']);
        $definition->setIsActive(true);
        $definition->setCreatedBy($user);

        $this->entityManager->persist($definition);
        $this->entityManager->flush();

        // 3. Erstelle den Server aus der Definition
        $server = $this->factory->createFromDefinition($definition);

        // 4. Überprüfe, dass der Server erstellt wurde
        $this->assertNotNull($server);
        $this->assertSame($server, $this->factory->createFromDefinition($definition));
    }

    public function testCreateByNameFromDatabase(): void
    {
        // 1. Erstelle Test-User
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('test-password-hash');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $this->entityManager->persist($user);

        // 2. Erstelle MCP-Server-Definition in der DB
        $definition = new McpServerDefinition();
        $definition->setName('test_playwright');
        $definition->setType('playwright');
        $definition->setDescription('Test Playwright Server');
        $definition->setConfiguration([
            'transport' => 'stdio',
            'command' => 'npx',
            'arguments' => ['-y', '@modelcontextprotocol/server-playwright']
        ]);
        $definition->setIsActive(true);
        $definition->setCreatedBy($user);

        $this->entityManager->persist($definition);
        $this->entityManager->flush();

        // 3. Erstelle Server nach Name
        $server = $this->factory->createByName('test_playwright');

        // 4. Überprüfe, dass der Server erstellt wurde
        $this->assertNotNull($server);
    }

    public function testCreateByNameFromStaticConfig(): void
    {
        // Versuche, einen statischen Server zu laden (falls nicht in DB)
        $server = $this->factory->createByName('filesystem');

        $this->assertNotNull($server);
    }

    public function testCreateAllFromDatabase(): void
    {
        // 1. Erstelle Test-User
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('test-password-hash');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $this->entityManager->persist($user);

        // 2. Erstelle mehrere MCP-Server-Definitionen
        $definition1 = new McpServerDefinition();
        $definition1->setName('test_server_1');
        $definition1->setType('filesystem');
        $definition1->setIsActive(true);
        $definition1->setCreatedBy($user);

        $definition2 = new McpServerDefinition();
        $definition2->setName('test_server_2');
        $definition2->setType('playwright');
        $definition2->setIsActive(true);
        $definition2->setCreatedBy($user);

        $this->entityManager->persist($definition1);
        $this->entityManager->persist($definition2);
        $this->entityManager->flush();

        // 3. Lade alle Server aus der DB
        $servers = $this->factory->createAllFromDatabase();

        // 4. Überprüfe, dass die Server geladen wurden
        $this->assertIsArray($servers);
        $this->assertArrayHasKey('test_server_1', $servers);
        $this->assertArrayHasKey('test_server_2', $servers);
    }

    public function testRegisterMcpServer(): void
    {
        // 1. Erstelle Test-User
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('test-password-hash');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $this->entityManager->persist($user);

        // 2. Erstelle eine neue Definition
        $definition = new McpServerDefinition();
        $definition->setName('new_test_server');
        $definition->setType('filesystem');
        $definition->setDescription('New Test Server');
        $definition->setConfiguration(['command' => 'npx']);
        $definition->setIsActive(true);
        $definition->setCreatedBy($user);

        // 3. Registriere den Server
        $this->factory->registerMcpServer($definition);

        // 4. Überprüfe, dass die Definition in der DB ist
        $savedDefinition = $this->repo->findOneByName('new_test_server');
        $this->assertNotNull($savedDefinition);
        $this->assertEquals('new_test_server', $savedDefinition->getName());
    }

    public function testGetAvailableServers(): void
    {
        // 1. Erstelle Test-User
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('test-password-hash');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $this->entityManager->persist($user);

        // 2. Erstelle eine Definition in der DB
        $definition = new McpServerDefinition();
        $definition->setName('available_test_server');
        $definition->setType('filesystem');
        $definition->setIsActive(true);
        $definition->setCreatedBy($user);

        $this->entityManager->persist($definition);
        $this->entityManager->flush();

        // 3. Hole alle verfügbaren Server
        $servers = $this->factory->getAvailableServers();

        // 4. Überprüfe, dass der Server enthalten ist
        $this->assertIsArray($servers);
        $this->assertArrayHasKey('available_test_server', $servers);
        // Statische Server sollten auch enthalten sein
        $this->assertArrayHasKey('filesystem', $servers);
    }

    public function testGetActiveServerDefinitions(): void
    {
        // 1. Erstelle Test-User
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('test-password-hash');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $this->entityManager->persist($user);

        // 2. Erstelle aktive und inaktive Definitionen
        $activeDefinition = new McpServerDefinition();
        $activeDefinition->setName('active_server');
        $activeDefinition->setType('filesystem');
        $activeDefinition->setIsActive(true);
        $activeDefinition->setCreatedBy($user);

        $inactiveDefinition = new McpServerDefinition();
        $inactiveDefinition->setName('inactive_server');
        $inactiveDefinition->setType('playwright');
        $inactiveDefinition->setIsActive(false);
        $inactiveDefinition->setCreatedBy($user);

        $this->entityManager->persist($activeDefinition);
        $this->entityManager->persist($inactiveDefinition);
        $this->entityManager->flush();

        // 3. Hole alle aktiven Definitionen
        $definitions = $this->factory->getActiveServerDefinitions();

        // 4. Überprüfe, dass nur aktive Definitionen zurückgegeben werden
        $this->assertIsArray($definitions);
        $this->assertCount(1, $definitions);
        $this->assertEquals('active_server', $definitions[0]->getName());
    }

    public function testGetServerDefinitionsByType(): void
    {
        // 1. Erstelle Test-User
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('test-password-hash');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $this->entityManager->persist($user);

        // 2. Erstelle Definitionen verschiedener Typen
        $filesystemDefinition = new McpServerDefinition();
        $filesystemDefinition->setName('fs_server');
        $filesystemDefinition->setType('filesystem');
        $filesystemDefinition->setIsActive(true);
        $filesystemDefinition->setCreatedBy($user);

        $playwrightDefinition = new McpServerDefinition();
        $playwrightDefinition->setName('pw_server');
        $playwrightDefinition->setType('playwright');
        $playwrightDefinition->setIsActive(true);
        $playwrightDefinition->setCreatedBy($user);

        $this->entityManager->persist($filesystemDefinition);
        $this->entityManager->persist($playwrightDefinition);
        $this->entityManager->flush();

        // 3. Hole Definitionen nach Typ
        $filesystemServers = $this->factory->getServerDefinitionsByType('filesystem');

        // 4. Überprüfe, dass nur filesystem-Server zurückgegeben werden
        $this->assertIsArray($filesystemServers);
        $this->assertCount(1, $filesystemServers);
        $this->assertEquals('fs_server', $filesystemServers[0]->getName());
    }

protected function tearDown(): void
    {
        $conn = $this->entityManager->getConnection();
        try {
            $conn->executeStatement('DELETE FROM mcp_server_definitions');
            $conn->executeStatement('DELETE FROM users');
        } catch (\Throwable) {
        }
        $this->entityManager->clear();
        parent::tearDown();
    }
}
