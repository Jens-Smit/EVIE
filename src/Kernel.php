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
            AiBundle::class => ['ai'],
        ];
    }
}