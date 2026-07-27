<?php
// src/Mcp/DependencyInjection/Configuration.php

namespace App\Mcp\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration for the EVIE MCP bundle.
 */
final class Configuration implements ConfigurationInterface
{
    /** @var array<string> */
    public const DEFAULT_SERVER_ALIASES = ['filesystem', 'playwright'];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('evie_mcp');

        $treeBuilder->getRootNode()
            ->children()
                ->integerNode('cache_ttl')
                    ->defaultValue(300)
                    ->info('Cache TTL for MCP tools (in seconds).')
                ->end()
                ->integerNode('timeout')
                    ->defaultValue(60)
                    ->info('Timeout for MCP transport (in seconds).')
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
                            ->arrayNode('arguments')
                                ->scalarPrototype()
                                ->defaultValue([])
                                ->info('Arguments for STDIO transport command.')
                            ->end()
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
}