<?php

namespace App\AI\Skills\Executor;

use Psr\Log\LoggerInterface;

class ExecutorResolver implements ExecutorResolverInterface
{
    private array $executors = [];

    public function __construct(
        private LoggerInterface $logger
    ) {
        $this->executors = [
            'api' => new GenericApiExecutor(),
            'database' => new GenericDatabaseExecutor(),
            'filesystem' => new GenericFileExecutor(),
            'http' => new GenericHttpExecutor(),
        ];
    }

    public function resolve(string $executorType): ExecutorInterface
    {
        if (!isset($this->executors[$executorType])) {
            $this->logger->warning('Executor nicht gefunden, verwende GenericExecutor', [
                'executor_type' => $executorType
            ]);
            return new GenericExecutor();
        }
        
        return $this->executors[$executorType];
    }

    public function supports(string $executorType): bool
    {
        return isset($this->executors[$executorType]);
    }

    public function addExecutor(string $type, ExecutorInterface $executor): void
    {
        $this->executors[$type] = $executor;
    }
}
