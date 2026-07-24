<?php

namespace App;

use SymfonyBundleFrameworkBundleKernelMicroKernelTrait;
use SymfonyComponentHttpKernelKernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}