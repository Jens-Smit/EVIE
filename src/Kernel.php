<?php

namespace App;

use SymfonyBundleFrameworkBundleKernelMicroKernelTrait;
use SymfonyComponentHttpKernelKernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }
}
