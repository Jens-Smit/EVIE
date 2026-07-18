<?php

namespace App\AI\Security;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(tags: ['ai.security.guard'])]
class SecurityGuard
{
    private array $allowedServices;
    private array $blockedPatterns;

    public function __construct()
    {
        // Define allowed base services
        $this->allowedServices = [
            'GenericApiExecutor',
            'FileSystemReadExecutor',
            'DatabaseQueryExecutor',
        ];

        // Define blocked patterns (e.g., URLs, file paths)
        $this->blockedPatterns = [
            'localhost',
            '127.0.0.1',
            '/etc/',
            '/root/',
            '*.env',
        ];
    }

    /**
     * Checks if a tool is allowed to use a specific service.
     */
    public function isServiceAllowed(string $serviceName): bool
    {
        return in_array($serviceName, $this->allowedServices);
    }

    /**
     * Checks if a resource (e.g., URL, file path) is blocked.
     */
    public function isResourceBlocked(string $resource): bool
    {
        foreach ($this->blockedPatterns as $pattern) {
            if (str_contains($resource, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validates a tool's configuration for security compliance.
     */
    public function validateToolConfiguration(array $config): bool
    {
        // Check if the tool tries to use a blocked service
        if (isset($config['service']) && !$this->isServiceAllowed($config['service'])) {
            return false;
        }

        // Check if the tool tries to access a blocked resource
        if (isset($config['resource']) && $this->isResourceBlocked($config['resource'])) {
            return false;
        }

        return true;
    }

    /**
     * Adds a service to the allowed list.
     */
    public function allowService(string $serviceName): void
    {
        if (!in_array($serviceName, $this->allowedServices)) {
            $this->allowedServices[] = $serviceName;
        }
    }

    /**
     * Blocks a service.
     */
    public function blockService(string $serviceName): void
    {
        $key = array_search($serviceName, $this->allowedServices);
        if ($key !== false) {
            unset($this->allowedServices[$key]);
        }
    }

    /**
     * Adds a pattern to the blocked list.
     */
    public function blockPattern(string $pattern): void
    {
        if (!in_array($pattern, $this->blockedPatterns)) {
            $this->blockedPatterns[] = $pattern;
        }
    }

    /**
     * Removes a pattern from the blocked list.
     */
    public function allowPattern(string $pattern): void
    {
        $key = array_search($pattern, $this->blockedPatterns);
        if ($key !== false) {
            unset($this->blockedPatterns[$key]);
        }
    }
}
