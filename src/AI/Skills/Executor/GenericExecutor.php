<?php

namespace App\AI\Skills\Executor;

use App\AI\Skills\Tool\DynamicTool;

class GenericExecutor implements ExecutorInterface
{
    public function execute(DynamicTool $tool, array $parameters): mixed
    {
        // Fallback-Executor für unbekannte Typen
        return [
            'status' => 'warning',
            'message' => 'Tool wurde mit Generic-Executor ausgeführt',
            'tool' => $tool->getName(),
            'parameters' => $parameters,
        ];
    }

    public function getType(): string
    {
        return 'generic';
    }
}
