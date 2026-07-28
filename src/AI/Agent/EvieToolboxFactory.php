<?php
// src/AI/Agent/EvieToolboxFactory.php

namespace App\AI\Agent;

use App\Mcp\Toolbox\McpToolFactoryWrapper;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolFactory\ChainFactory;
use Symfony\AI\Agent\Toolbox\ToolFactory\ReflectionToolFactory;

final class EvieToolboxFactory
{
    public function __construct(
        private readonly McpToolFactoryWrapper $mcpToolFactory,
        private readonly iterable $nativeTools,
    ) {
    }

    public function create(): Toolbox
    {
        $reflectionFactory = new ReflectionToolFactory();
        // ChainFactory akzeptiert Factories mit `getTool()`-Methode (kein Interface nötig)
        $chainFactory = new ChainFactory([
            $this->mcpToolFactory,
            $reflectionFactory,
        ]);

        return new Toolbox($chainFactory, iterator_to_array($this->nativeTools));
    }
}
