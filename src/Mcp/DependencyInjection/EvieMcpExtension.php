<?php
// src/Mcp/DependencyInjection/EvieMcpExtension.php

namespace App\Mcp\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;

/**
 * Dependency Injection extension for the EVIE MCP bundle.
 * Loads configuration and registers services for MCP server integration.
 */
final class EvieMcpExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Set parameters
        $container->setParameter('evie_mcp.cache_ttl', $config['cache_ttl']);
        $container->setParameter('evie_mcp.timeout', $config['timeout']);
        $container->setParameter('evie_mcp.servers', $config['servers']);

        // Load services
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'evie_mcp';
    }
}