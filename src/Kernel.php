<?php

namespace App;

use App\DependencyInjection\Compiler\E2EStubPass;
use App\DependencyInjection\Compiler\RegisterDynamicToolboxDecoratorPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait {
        MicroKernelTrait::registerContainerConfiguration as private registerBaseContainerConfiguration;
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }

    /**
     * The E2E test suite can run against the dev/prod container configuration
     * (to exercise the real security, form and controller stack) by setting
     * E2E_TESTING=1. When set, a dedicated config overlay
     * (config/packages/e2e/*.yaml) is loaded that enables the test client and
     * stubs external services, without altering the prod/dev defaults.
     */
    public function isE2ETesting(): bool
    {
        $value = $_ENV['E2E_TESTING'] ?? (getenv('E2E_TESTING') ?: '');

        return \in_array((string) $value, ['1', 'true', 'yes'], true);
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $this->registerBaseContainerConfiguration($loader);

        // Load the E2E overlay last so it can override dev/prod settings when
        // running the E2E test suite against those environments.
        if ($this->isE2ETesting()) {
            $e2eDir = $this->getProjectDir().'/config/packages/e2e';
            if (is_dir($e2eDir)) {
                $loader->load($e2eDir.'/*.{php,yaml,yml}', 'glob');
            }
        }
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // When E2E_TESTING is active, replace external-service implementations
        // (Mercure hub) with test stubs so dev/prod containers compile headless.
        $container->addCompilerPass(new RegisterDynamicToolboxDecoratorPass(), PassConfig::TYPE_BEFORE_REMOVING);

        if ($this->isE2ETesting()) {
            $container->addCompilerPass(new E2EStubPass(), PassConfig::TYPE_BEFORE_REMOVING);
        }
    }
}
