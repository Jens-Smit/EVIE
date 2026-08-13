<?php

namespace AppAISkillsExecutor;

use AppAISkillsToolDynamicTool;

interface ExecutorInterface
{
    public function execute(DynamicTool $tool, array $parameters): mixed;
    public function getType(): string;
}
