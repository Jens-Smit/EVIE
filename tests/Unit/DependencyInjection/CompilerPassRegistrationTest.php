<?php

declare(strict_types=1);

namespace App\Tests\Unit\DependencyInjection;

use App\DependencyInjection\Compiler\AiMcpServersCompilerPass;
use App\DependencyInjection\Compiler\AiSubAgentsCompilerPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Unit-Tests fuer die Compiler-Passes zur dynamischen Registrierung von
 * MCP-Servern und Sub-Agenten aus dem Cache.
 *
 * Beide Passes waren laut Coverage-Report ungetestet (0%).
 */
final class CompilerPassRegistrationTest extends TestCase
{
    public function testMcpPassSkipsWithoutRequiredServices(): void
    {
        $container = new ContainerBuilder();
        $pass = new AiMcpServersCompilerPass();

        $pass->process($container);

        self::assertFalse($container->has('ai.mcp.server.dynamic.anything'));
    }

    public function testMcpPassRegistersCachedDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->register('doctrine.orm.entity_manager', \stdClass::class);
        $container->register('cache.app', ArrayAdapter::class);
        $container->register('ai.mcp.server.filesystem', \stdClass::class);
        $container->setDefinition(
            \App\Repository\McpServerDefinitionRepository::class,
            new Definition(\App\Repository\McpServerDefinitionRepository::class)
        );

        $definition = $this->createMcpDefinition('fs-test', 'filesystem');
        $cache = new ArrayAdapter();
        $cache->get('ai.mcp_server.definitions', static fn () => [$definition]);
        $container->set('cache.app', $cache);

        $pass = new AiMcpServersCompilerPass();
        $pass->process($container);

        self::assertTrue($container->has('ai.mcp.server.dynamic.fs-test'));
        $serviceDef = $container->getDefinition('ai.mcp.server.dynamic.fs-test');
        self::assertSame('ai.mcp.server.filesystem', (string) $serviceDef->getClass());
        self::assertTrue($serviceDef->hasTag('ai.mcp.server'));
    }

    public function testMcpPassSkipsUnknownType(): void
    {
        $container = new ContainerBuilder();
        $container->register('doctrine.orm.entity_manager', \stdClass::class);
        $container->register('cache.app', ArrayAdapter::class);
        $container->setDefinition(
            \App\Repository\McpServerDefinitionRepository::class,
            new Definition(\App\Repository\McpServerDefinitionRepository::class)
        );

        $definition = $this->createMcpDefinition('unknown-type', 'does_not_exist');
        $cache = new ArrayAdapter();
        $cache->get('ai.mcp_server.definitions', static fn () => [$definition]);
        $container->set('cache.app', $cache);

        $pass = new AiMcpServersCompilerPass();
        $pass->process($container);

        self::assertFalse($container->has('ai.mcp.server.dynamic.unknown-type'));
    }

    public function testSubAgentPassSkipsWithoutRequiredServices(): void
    {
        $container = new ContainerBuilder();
        $pass = new AiSubAgentsCompilerPass();

        $pass->process($container);

        self::assertFalse($container->has('ai.agent.dynamic.anything'));
    }

    public function testSubAgentPassRegistersCachedDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->register('doctrine.orm.entity_manager', \stdClass::class);
        $container->register('cache.app', ArrayAdapter::class);
        $container->setDefinition(
            \App\Repository\SubAgentDefinitionRepository::class,
            new Definition(\App\Repository\SubAgentDefinitionRepository::class)
        );

        $definition = new \App\Entity\SubAgentDefinition();
        $definition->setName('stub_agent');
        $definition->setClassName(\stdClass::class);
        $cache = new ArrayAdapter();
        $cache->get('ai.sub_agent.definitions', static fn () => [$definition]);
        $container->set('cache.app', $cache);

        $pass = new AiSubAgentsCompilerPass();
        $pass->process($container);

        self::assertTrue($container->has('ai.agent.dynamic.stub_agent'));
        $serviceDef = $container->getDefinition('ai.agent.dynamic.stub_agent');
        self::assertSame(\stdClass::class, (string) $serviceDef->getClass());
        self::assertTrue($serviceDef->hasTag('ai.agent'));
    }

    public function testSubAgentPassSkipsNonExistentClass(): void
    {
        $container = new ContainerBuilder();
        $container->register('doctrine.orm.entity_manager', \stdClass::class);
        $container->register('cache.app', ArrayAdapter::class);
        $container->setDefinition(
            \App\Repository\SubAgentDefinitionRepository::class,
            new Definition(\App\Repository\SubAgentDefinitionRepository::class)
        );

        $definition = new \App\Entity\SubAgentDefinition();
        $definition->setName('ghost_agent');
        $definition->setClassName('App\\Does\\Not\\Exist');
        $cache = new ArrayAdapter();
        $cache->get('ai.sub_agent.definitions', static fn () => [$definition]);
        $container->set('cache.app', $cache);

        $pass = new AiSubAgentsCompilerPass();
        $pass->process($container);

        self::assertFalse($container->has('ai.agent.dynamic.ghost_agent'));
    }

    private function createMcpDefinition(string $name, string $type): \App\Entity\McpServerDefinition
    {
        $definition = new \App\Entity\McpServerDefinition();
        $definition->setName($name);
        $definition->setType($type);
        $definition->setConfiguration([]);
        $definition->setAllowedTools([]);
        $definition->setBlockedResources([]);

        return $definition;
    }
}
