<?php

namespace App;

use Symfony\AI\AiBundle\AiBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Returns the container extension classes to load for this kernel.
     *
     * @return array<string, string>
     */
    protected function getContainerExtensionClasses(): array
    {
        return [
            \App\Mcp\DependencyInjection\EvieMcpExtension::class => ['evie_mcp'],
            AiBundle::class => ['ai'],
        ];
    }

    /**
     * Returns the bundles to load for this kernel.
     *
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        // 1. Lade die regulären Bundles aus der config/bundles.php
        $contents = require $this->getProjectDir().'/config/bundles.php';
        foreach ($contents as $class => $envs) {
            if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                yield new $class();
            }
        }
        
        // 2. Registriere dein eigenes Bundle manuell per yield
        yield new \App\Mcp\EvieMcpBundle();
    }
}