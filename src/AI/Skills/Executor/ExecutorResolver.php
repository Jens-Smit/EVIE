<?php

namespace App\AI\Skills\Executor;

use App\AI\Security\SecurityGuard;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ExecutorResolver implements ExecutorResolverInterface
{
    private array $executors = [];

    public function __construct(
        private LoggerInterface $logger,
        Connection $connection,
        HttpClientInterface $httpClient,
        SecurityGuard $securityGuard,
    ) {
        $this->executors = [
            'api' => new GenericApiExecutor(),
            'database' => new GenericDatabaseExecutor($connection),
            'filesystem' => new GenericFileExecutor(),
            'http' => new GenericHttpExecutor($httpClient, $securityGuard),
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
