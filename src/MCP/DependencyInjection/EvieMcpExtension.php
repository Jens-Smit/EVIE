<?php
// src/MCP/DependencyInjection/EvieMcpExtension.php
namespace App\MCP\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;

final class EvieMcpExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('evie_mcp.cache_ttl', $config['cache_ttl']);
        $container->setParameter('evie_mcp.timeout', $config['timeout']);
        $container->setParameter('evie_mcp.servers', $config['servers']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.yaml');
    }
}
