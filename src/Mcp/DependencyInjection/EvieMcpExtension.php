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
        $container->setParameter('evie_mcp.server_aliases', array_keys($config['servers']));

        // Load services from the bundle's Resources/config directory
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('evie_mcp');
        
        $treeBuilder->getRootNode()
            ->children()
                ->integerNode('cache_ttl')
                    ->defaultValue(300)
                    ->info('Cache TTL for MCP tools (in seconds).')
                ->end()
                ->arrayNode('servers')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('transport')
                                ->values(['stdio', 'http'])
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('command')
                                ->defaultNull()
                                ->info('Command for STDIO transport.')
                            ->end()
                            
                            // --- HIER WURDE ES KORRIGIERT ---
                            ->arrayNode('arguments')
                                ->info('Arguments for STDIO transport command.')
                                ->defaultValue([])
                                ->scalarPrototype()->end() // Schließt den Prototype
                            ->end() // Schließt das Array "arguments"
                            // --------------------------------
                            
                            ->scalarNode('url')
                                ->defaultNull()
                                ->info('URL for HTTP transport.')
                            ->end()
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