<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Agent;

use App\AI\Agent\SubAgentFactory;
use App\Entity\SubAgentDefinition;
use App\Repository\SubAgentDefinitionRepository;
use App\Repository\ToolDefinitionRepository;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Unit-Tests fuer SubAgentFactory.
 */
final class SubAgentFactoryTest extends TestCase
{
    private SubAgentDefinitionRepository $subAgentRepo;
    private ToolDefinitionRepository $toolRepo;
    private ContainerInterface $container;
    private LoggerInterface $logger;
    private PlatformInterface $platform;
    private ParameterBagInterface $params;
    private SubAgentFactory $factory;

    protected function setUp(): void
    {
        $this->platform = $this->createMock(PlatformInterface::class);
        $this->toolRepo = $this->createMock(ToolDefinitionRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->subAgentRepo = $this->createMock(SubAgentDefinitionRepository::class);
        $this->params = $this->createMock(ParameterBagInterface::class);

        $this->factory = new SubAgentFactory(
            $this->platform,
            $this->toolRepo,
            $this->logger,
            $this->container,
            $this->subAgentRepo,
            $this->params,
        );
    }

    private function makeDefinition(string $name, string $className = 'App\\Nonexistent\\Agent', array $config = []): SubAgentDefinition
    {
        $def = new SubAgentDefinition();
        $def->setName($name);
        $def->setDescription('Description ' . $name);
        $def->setClassName($className);
        $def->setConfiguration($config);

        return $def;
    }

    public function testCreateFromDefinitionWithGenericAgent(): void
    {
        $definition = $this->makeDefinition('researcher', config: ['model' => 'mistral-small', 'role' => 'data_analyst']);

        $this->toolRepo->expects(self::once())->method('save');

        $agent = $this->factory->createFromDefinition($definition);

        self::assertInstanceOf(AgentInterface::class, $agent);
    }

    public function testCreateFromDefinitionUsesDefaultsWhenConfigMissing(): void
    {
        $definition = $this->makeDefinition('writer');

        $this->toolRepo->expects(self::once())->method('save');

        $agent = $this->factory->createFromDefinition($definition);

        self::assertInstanceOf(AgentInterface::class, $agent);
    }

    public function testCreateByNameFallsBackToStaticConfigWhenNotFound(): void
    {
        $this->subAgentRepo->method('findOneByName')->willReturn(null);

        $this->toolRepo->expects(self::once())->method('save');

        $agent = $this->factory->createByName('custom_agent');

        self::assertInstanceOf(AgentInterface::class, $agent);
    }

    public function testCreateByNameUsesDefinitionWhenFound(): void
    {
        $definition = $this->makeDefinition('researcher');
        $this->subAgentRepo->method('findOneByName')->willReturn($definition);

        $this->toolRepo->expects(self::once())->method('save');

        $agent = $this->factory->createByName('researcher');

        self::assertInstanceOf(AgentInterface::class, $agent);
    }

    public function testCreateAllFromDatabaseReturnsAgents(): void
    {
        $definition = $this->makeDefinition('researcher');
        $this->subAgentRepo->method('findAllActive')->willReturn([$definition]);

        $this->toolRepo->expects(self::once())->method('save');

        $agents = $this->factory->createAllFromDatabase();

        self::assertArrayHasKey('researcher', $agents);
        self::assertInstanceOf(AgentInterface::class, $agents['researcher']);
    }

    public function testCreateAllFromDatabaseContinuesOnError(): void
    {
        $badDefinition = $this->makeDefinition('broken', className: 'Some\\Bad\\Class');
        $goodDefinition = $this->makeDefinition('ok');

        $this->subAgentRepo->method('findAllActive')->willReturn([$badDefinition, $goodDefinition]);

        // First call may throw somewhere internally; container get returns non-agent, falls to generic path
        $this->container->method('get')->willReturn($this->createMock(AgentInterface::class));
        // The broken definition generic path still creates an Agent; only a real exception skips
        $this->toolRepo->method('save');

        $agents = $this->factory->createAllFromDatabase();

        self::assertArrayHasKey('broken', $agents);
        self::assertArrayHasKey('ok', $agents);
    }

    public function testCreateSubAgentReturnsAgentInterface(): void
    {
        $this->toolRepo->expects(self::once())->method('save');

        $agent = $this->factory->createSubAgent('writer', 'code_assistant');

        self::assertInstanceOf(AgentInterface::class, $agent);
    }

    public function testCreateSubAgentToolReturnsSubagentTool(): void
    {
        $this->toolRepo->expects(self::once())->method('save');

        $tool = $this->factory->createSubAgentTool('writer', 'code_assistant');

        self::assertInstanceOf(\Symfony\AI\Agent\Toolbox\Tool\Subagent::class, $tool);
    }

    public function testRegisterAllFromDatabaseRegistersAll(): void
    {
        $definition = $this->makeDefinition('researcher');
        $this->subAgentRepo->method('findAllActive')->willReturn([$definition]);

        $this->toolRepo->expects(self::once())->method('save');

        $this->factory->registerAllFromDatabase();
    }

    public function testRegisterSubAgentPersistsViaEntityManager(): void
    {
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        // Override container mock to return EM
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('doctrine.orm.entity_manager')->willReturn($entityManager);

        $factory = new SubAgentFactory(
            $this->platform,
            $this->toolRepo,
            $this->logger,
            $container,
            $this->subAgentRepo,
            $this->params,
        );

        $definition = $this->makeDefinition('new_agent');
        $factory->registerSubAgent($definition);
    }

    public function testCreateWebsiteResearchAgent(): void
    {
        $this->toolRepo->expects(self::once())->method('save');
        $agent = $this->factory->createWebsiteResearchAgent();
        self::assertInstanceOf(AgentInterface::class, $agent);
    }

    public function testCreateDataAnalysisAgent(): void
    {
        $this->toolRepo->expects(self::once())->method('save');
        $agent = $this->factory->createDataAnalysisAgent();
        self::assertInstanceOf(AgentInterface::class, $agent);
    }

    public function testCreateCodeAssistantAgent(): void
    {
        $this->toolRepo->expects(self::once())->method('save');
        $agent = $this->factory->createCodeAssistantAgent();
        self::assertInstanceOf(AgentInterface::class, $agent);
    }

    public function testCreateDocumentProcessorAgent(): void
    {
        $this->toolRepo->expects(self::once())->method('save');
        $agent = $this->factory->createDocumentProcessorAgent();
        self::assertInstanceOf(AgentInterface::class, $agent);
    }

    public function testCreateCommunicationManagerAgent(): void
    {
        $this->toolRepo->expects(self::once())->method('save');
        $agent = $this->factory->createCommunicationManagerAgent();
        self::assertInstanceOf(AgentInterface::class, $agent);
    }

    public function testCreateApiIntegrationAgent(): void
    {
        $this->toolRepo->expects(self::once())->method('save');
        $agent = $this->factory->createApiIntegrationAgent();
        self::assertInstanceOf(AgentInterface::class, $agent);
    }

    public function testCreateProjectManagerAgent(): void
    {
        $this->toolRepo->expects(self::once())->method('save');
        $agent = $this->factory->createProjectManagerAgent();
        self::assertInstanceOf(AgentInterface::class, $agent);
    }

    public function testCreateFinanceManagerAgent(): void
    {
        $this->toolRepo->expects(self::once())->method('save');
        $agent = $this->factory->createFinanceManagerAgent();
        self::assertInstanceOf(AgentInterface::class, $agent);
    }

    public function testCreateHrManagerAgent(): void
    {
        $this->toolRepo->expects(self::once())->method('save');
        $agent = $this->factory->createHrManagerAgent();
        self::assertInstanceOf(AgentInterface::class, $agent);
    }

    public function testCreateMarketingManagerAgent(): void
    {
        $this->toolRepo->expects(self::once())->method('save');
        $agent = $this->factory->createMarketingManagerAgent();
        self::assertInstanceOf(AgentInterface::class, $agent);
    }

    public function testCreateCeoAssistantAgent(): void
    {
        $this->toolRepo->expects(self::once())->method('save');
        $agent = $this->factory->createCeoAssistantAgent();
        self::assertInstanceOf(AgentInterface::class, $agent);
    }

    public function testGetAvailableSubAgents(): void
    {
        $this->subAgentRepo->method('findAllActive')->willReturn([]);
        $this->toolRepo->method('save');

        $agents = $this->factory->getAvailableSubAgents();

        self::assertArrayHasKey('website_researcher', $agents);
        self::assertArrayHasKey('ceo_assistant', $agents);
        self::assertCount(11, $agents);
    }

    public function testCreateAllSubAgentTools(): void
    {
        $this->subAgentRepo->method('findAllActive')->willReturn([]);
        $this->toolRepo->method('save');

        $tools = $this->factory->createAllSubAgentTools();

        self::assertCount(11, $tools);
        self::assertInstanceOf(\Symfony\AI\Agent\Toolbox\Tool\Subagent::class, $tools['website_researcher']);
    }
}
