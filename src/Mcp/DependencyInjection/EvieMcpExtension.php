<?php
// src/Mcp/DependencyInjection/EvieMcpExtension.php

namespace App\Mcp\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;

final class EvieMcpExtension extends Extension implements ConfigurationInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration($this, $configs);

        // Set parameters
        $container->setParameter('evie_mcp.cache_ttl', $config['cache_ttl']);
        $container->setParameter('evie_mcp.servers', $config['servers']);

        // Set the server configs for McpServerManager
        $container->getDefinition('App\\Mcp\\Client\\McpServerManager')
            ->setArgument('$serverConfigs', $config['servers']);

        // Load services
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.yaml');
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('evie_mcp');
        $treeBuilder->getRootNode()
            ->children()
                ->integerNode('cache_ttl')->defaultValue(300)->end()
                ->arrayNode('servers')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('transport')->values(['stdio', 'http'])->isRequired()->end()
                            ->scalarNode('command')->end()
                            ->arrayNode('arguments')->scalarPrototype()->end()->end()
                            ->scalarNode('url')->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    public function getAlias(): string
    {
        return 'evie_mcp';
    }
}