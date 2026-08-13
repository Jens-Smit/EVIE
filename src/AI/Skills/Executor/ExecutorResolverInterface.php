<?php

namespace AppAISkillsExecutor;

interface ExecutorResolverInterface
{
    public function resolve(string $executorType): ExecutorInterface;
    public function supports(string $executorType): bool;
}
