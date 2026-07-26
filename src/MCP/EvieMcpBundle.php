<?php
// src/MCP/EvieMcpBundle.php
namespace App\MCP;

use App\MCP\DependencyInjection\EvieMcpExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class EvieMcpBundle extends Bundle
{
    public function getContainerExtension(): EvieMcpExtension
    {
        return new EvieMcpExtension();
    }
}
