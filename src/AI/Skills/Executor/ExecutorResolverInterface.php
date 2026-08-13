<?php

namespace App\AI\Skills\Executor;

interface ExecutorResolverInterface
{
    public function resolve(string $executorType): ExecutorInterface;
    public function supports(string $executorType): bool;
}
