<?php

namespace App\AI\Skills\Executor;

use App\AI\Skills\Tool\DynamicTool;

interface ExecutorInterface
{
    public function execute(DynamicTool $tool, array $parameters): mixed;
    public function getType(): string;
}
