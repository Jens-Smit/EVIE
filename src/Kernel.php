<?php

namespace App;

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
        ];
    }
}