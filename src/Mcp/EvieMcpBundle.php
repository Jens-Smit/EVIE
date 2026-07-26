<?php

namespace App\Mcp;

use App\Mcp\DependencyInjection\EvieMcpExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Bundle for EVIE MCP Client integration.
 * Provides MCP server connections and tool integration with Symfony AI Agent.
 */
final class EvieMcpBundle extends Bundle
{
    public function getContainerExtension(): EvieMcpExtension
    {
        return new EvieMcpExtension();
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
