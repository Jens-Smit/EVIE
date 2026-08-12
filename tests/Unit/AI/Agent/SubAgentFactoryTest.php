<?php
// tests/Unit/AI/Agent/SubAgentFactoryTest.php

namespace App\Tests\Unit\AI\Agent;

use App\AI\Agent\SubAgentFactory;
use App\Entity\SubAgentDefinition;
use App\Entity\ToolDefinition;
use App\Repository\SubAgentDefinitionRepository;
use App\Repository\ToolDefinitionRepository;
use App\AI\Skills\DynamicSkillRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

class SubAgentFactoryTest extends TestCase
{
    private SubAgentFactory $factory;
    private ContainerInterface $containerMock;
    private SubAgentDefinitionRepository $repoMock;
    private ToolDefinitionRepository $toolDefinitionRepoMock;
    private DynamicSkillRegistry $dynamicSkillRegistryMock;
    private LoggerInterface $loggerMock;
    private PlatformInterface $platformMock;
    private ParameterBag $paramsMock;

    protected function setUp(): void
    {
        $this->containerMock = $this->createMock(ContainerInterface::class);
        $this->repoMock = $this->createMock(SubAgentDefinitionRepository::class);
        $this->toolDefinitionRepoMock = $this->createMock(ToolDefinitionRepository::class);
        $this->dynamicSkillRegistryMock = $this->createMock(DynamicSkillRegistry::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->platformMock = $this->createMock(PlatformInterface::class);
        $this->paramsMock = $this->createMock(ParameterBag::class);

        $this->factory = new SubAgentFactory(
            $this->platformMock,
            $this->toolDefinitionRepoMock,
            $this->dynamicSkillRegistryMock,
            $this->loggerMock,
            $this->containerMock,
            $this->repoMock,
            $this->paramsMock
        );
    }

    public function testCreateFromDefinitionWithValidClass(): void
    {
        $definition = new SubAgentDefinition();
        $definition->setName('test_agent');
        $definition->setClassName('App\AI\Agent\TestAgent');
        $definition->setDescription('Test Agent');
        $definition->setConfiguration(['model' => 'mistral-large']);

        $subAgentMock = $this->createMock(AgentInterface::class);

        $this->containerMock
            ->method('has')
            ->with('App\AI\Agent\TestAgent')
            ->willReturn(true);

        $this->containerMock
            ->method('get')
            ->with('App\AI\Agent\TestAgent')
            ->willReturn($subAgentMock);

        $this->toolDefinitionRepoMock
            ->method('save')
            ->willReturn(null);

        $this->dynamicSkillRegistryMock
            ->method('addTool')
            ->willReturn(null);

        $result = $this->factory->createFromDefinition($definition);

        $this->assertSame($subAgentMock, $result);
    }

    public function testCreateFromDefinitionWithInvalidClass(): void
    {
        $definition = new SubAgentDefinition();
        $definition->setName('test_agent');
        $definition->setClassName('App\AI\Agent\InvalidAgent');
        $definition->setDescription('Test Agent');
        $definition->setConfiguration(['model' => 'mistral-large', 'role' => 'test']);

        $this->containerMock
            ->method('has')
            ->with('App\AI\Agent\InvalidAgent')
            ->willReturn(false);

        // Mock Agent creation
        $agentMock = $this->createMock(Agent::class);
        $this->platformMock
            ->method('__call')
            ->willReturn($agentMock);

        $this->containerMock
            ->method('get')
            ->with('App\AI\Agent\InvalidAgent')
            ->willThrowException(new \RuntimeException('Class not found'));

        $this->toolDefinitionRepoMock
            ->method('save')
            ->willReturn(null);

        $this->dynamicSkillRegistryMock
            ->method('addTool')
            ->willReturn(null);

        $result = $this->factory->createFromDefinition($definition);

        $this->assertInstanceOf(AgentInterface::class, $result);
    }

    public function testCreateByNameFromDatabase(): void
    {
        $definition = new SubAgentDefinition();
        $definition->setName('test_agent');
        $definition->setClassName('App\AI\Agent\TestAgent');

        $subAgentMock = $this->createMock(AgentInterface::class);

        $this->repoMock
            ->method('findOneByName')
            ->with('test_agent')
            ->willReturn($definition);

        $this->containerMock
            ->method('has')
            ->with('App\AI\Agent\TestAgent')
            ->willReturn(true);

        $this->containerMock
            ->method('get')
            ->with('App\AI\Agent\TestAgent')
            ->willReturn($subAgentMock);

        $this->toolDefinitionRepoMock
            ->method('save')
            ->willReturn(null);

        $this->dynamicSkillRegistryMock
            ->method('addTool')
            ->willReturn(null);

        $result = $this->factory->createByName('test_agent');

        $this->assertSame($subAgentMock, $result);
    }

    public function testCreateByNameFromStaticConfig(): void
    {
        $subAgentMock = $this->createMock(AgentInterface::class);

        $this->repoMock
            ->method('findOneByName')
            ->with('test_agent')
            ->willReturn(null);

        // Mock Agent creation for static config
        $agentMock = $this->createMock(Agent::class);
        $this->platformMock
            ->method('__call')
            ->willReturn($agentMock);

        $this->toolDefinitionRepoMock
            ->method('save')
            ->willReturn(null);

        $this->dynamicSkillRegistryMock
            ->method('addTool')
            ->willReturn(null);

        $result = $this->factory->createByName('test_agent');

        $this->assertInstanceOf(AgentInterface::class, $result);
    }

    public function testCreateAllFromDatabase(): void
    {
        $definition1 = new SubAgentDefinition();
        $definition1->setName('agent_1');
        $definition1->setClassName('App\AI\Agent\Agent1');

        $definition2 = new SubAgentDefinition();
        $definition2->setName('agent_2');
        $definition2->setClassName('App\AI\Agent\Agent2');

        $subAgent1Mock = $this->createMock(AgentInterface::class);
        $subAgent2Mock = $this->createMock(AgentInterface::class);

        $this->repoMock
            ->method('findAllActive')
            ->willReturn([$definition1, $definition2]);

        $this->containerMock
            ->method('has')
            ->willReturn(true);

        $this->containerMock
            ->method('get')
            ->willReturnOnConsecutiveCalls($subAgent1Mock, $subAgent2Mock);

        $this->toolDefinitionRepoMock
            ->method('save')
            ->willReturn(null);

        $this->dynamicSkillRegistryMock
            ->method('addTool')
            ->willReturn(null);

        $result = $this->factory->createAllFromDatabase();

        $this->assertCount(2, $result);
        $this->assertSame($subAgent1Mock, $result['agent_1']);
        $this->assertSame($subAgent2Mock, $result['agent_2']);
    }

    public function testRegisterSubAgent(): void
    {
        $definition = new SubAgentDefinition();
        $definition->setName('new_agent');
        $definition->setClassName('App\AI\Agent\NewAgent');

        $entityManagerMock = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $this->containerMock
            ->method('get')
            ->with('doctrine.orm.entity_manager')
            ->willReturn($entityManagerMock);

        $entityManagerMock
            ->method('persist')
            ->with($definition)
            ->willReturn(null);

        $entityManagerMock
            ->method('flush')
            ->willReturn(null);

        $this->loggerMock
            ->method('info')
            ->willReturn(null);

        $this->factory->registerSubAgent($definition);

        // Verify persist and flush were called
        $entityManagerMock->method('persist')->willReturn(null);
        $entityManagerMock->method('flush')->willReturn(null);
    }

    public function testCreateSubAgent(): void
    {
        $agentMock = $this->createMock(Agent::class);
        $this->platformMock
            ->method('__call')
            ->willReturn($agentMock);

        $this->toolDefinitionRepoMock
            ->method('save')
            ->willReturn(null);

        $this->dynamicSkillRegistryMock
            ->method('addTool')
            ->willReturn(null);

        $result = $this->factory->createSubAgent('test', 'test_role');

        $this->assertInstanceOf(AgentInterface::class, $result);
    }

    public function testCreateSubAgentTool(): void
    {
        $agentMock = $this->createMock(Agent::class);
        $this->platformMock
            ->method('__call')
            ->willReturn($agentMock);

        $this->toolDefinitionRepoMock
            ->method('save')
            ->willReturn(null);

        $this->dynamicSkillRegistryMock
            ->method('addTool')
            ->willReturn(null);

        $result = $this->factory->createSubAgentTool('test', 'test_role');

        $this->assertInstanceOf(\Symfony\AI\Agent\Toolbox\Tool\Subagent::class, $result);
    }

    public function testGetAvailableSubAgents(): void
    {
        $result = $this->factory->getAvailableSubAgents();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('website_researcher', $result);
        $this->assertArrayHasKey('data_analyst', $result);
        $this->assertArrayHasKey('code_assistant', $result);
    }

    public function testCreateAllSubAgentTools(): void
    {
        $result = $this->factory->createAllSubAgentTools();

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }
}
