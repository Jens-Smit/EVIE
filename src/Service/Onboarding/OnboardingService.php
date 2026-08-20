<?php

namespace App\Service\Onboarding;

use App\Entity\AI\Capability;
use App\Entity\AI\LLMConfiguration;
use App\Entity\AI\Conversation;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\User;
use App\Entity\Tenant\Organization;
use App\Repository\AI\CapabilityRepository;
use App\Repository\AI\LLMConfigurationRepository;
use App\Repository\AI\ConversationRepository;
use App\Repository\Tenant\TenantRepository;
use App\Repository\Tenant\UserRepository;
use App\Repository\Tenant\OrganizationRepository;
use App\Service\AI\CapabilityRegistry;
use App\Service\AI\ConversationService;
use App\Service\Security\SecretManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * OnboardingService manages the onboarding process for new users and tenants.
 * 
 * Features:
 * - User goals collection
 * - System discovery and capability recommendations
 * - LLM selection and configuration
 * - Secret setup assistance
 * - Integration setup
 * - Permission setup
 * - First agent test
 * - Ready state verification
 */
class OnboardingService
{
    private const string SESSION_KEY = 'onboarding';
    private const array ONBOARDING_STEPS = [
        'start',
        'user_goals',
        'system_discovery',
        'llm_selection',
        'secret_setup',
        'integration_setup',
        'permission_setup',
        'first_agent_test',
        'ready',
    ];

    public function __construct(
        private CapabilityRegistry $capabilityRegistry,
        private SecretManager $secretManager,
        private ConversationService $conversationService,
        private CapabilityRepository $capabilityRepository,
        private LLMConfigurationRepository $llmConfigurationRepository,
        private ConversationRepository $conversationRepository,
        private TenantRepository $tenantRepository,
        private UserRepository $userRepository,
        private OrganizationRepository $organizationRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Get the onboarding steps.
     * 
     * @return array
     */
    public function getSteps(): array
    {
        return self::ONBOARDING_STEPS;
    }

    /**
     * Get the current onboarding step for a user.
     * 
     * @param User $user The user
     * @return string Current step
     */
    public function getCurrentStep(User $user): string
    {
        $metadata = $user->getSecrets()?->first()?->getMetadata() ?? [];
        
        if (isset($metadata[self::SESSION_KEY]['currentStep'])) {
            return $metadata[self::SESSION_KEY]['currentStep'];
        }

        // If no step is stored, check if onboarding is complete
        if ($this->isOnboardingComplete($user)) {
            return 'ready';
        }

        // Start from the beginning
        return 'start';
    }

    /**
     * Set the current onboarding step for a user.
     * 
     * @param User $user The user
     * @param string $step The step
     * @return User The updated user
     */
    public function setCurrentStep(User $user, string $step): User
    {
        // Validate step
        if (!in_array($step, self::ONBOARDING_STEPS, true)) {
            throw new \InvalidArgumentException("Invalid onboarding step: {$step}");
        }

        // Store in user metadata (via first secret as workaround)
        // In a real implementation, you would store this in a dedicated onboarding table
        $secret = $user->getSecrets()->first();
        
        if ($secret === null) {
            // Create a dummy secret for storing onboarding state
            // This is a workaround - in production, use a dedicated entity
            $secret = $this->secretManager->createSecret(
                $user,
                'onboarding_state',
                json_encode(['currentStep' => $step])
            );
        } else {
            $metadata = $secret->getMetadata() ?? [];
            $metadata[self::SESSION_KEY] = [
                'currentStep' => $step,
                'updatedAt' => (new \DateTimeImmutable())->format('c'),
            ];
            $secret->setMetadata($metadata);
            $this->entityManager->persist($secret);
        }

        $this->entityManager->flush();

        return $user;
    }

    /**
     * Advance to the next onboarding step.
     * 
     * @param User $user The user
     * @return string New current step
     */
    public function nextStep(User $user): string
    {
        $currentStep = $this->getCurrentStep($user);
        $currentIndex = array_search($currentStep, self::ONBOARDING_STEPS, true);
        
        if ($currentIndex === false || $currentIndex >= count(self::ONBOARDING_STEPS) - 1) {
            return 'ready';
        }

        $nextStep = self::ONBOARDING_STEPS[$currentIndex + 1];
        $this->setCurrentStep($user, $nextStep);
        
        return $nextStep;
    }

    /**
     * Go back to the previous onboarding step.
     * 
     * @param User $user The user
     * @return string Previous step
     */
    public function previousStep(User $user): string
    {
        $currentStep = $this->getCurrentStep($user);
        $currentIndex = array_search($currentStep, self::ONBOARDING_STEPS, true);
        
        if ($currentIndex === false || $currentIndex <= 0) {
            return 'start';
        }

        $previousStep = self::ONBOARDING_STEPS[$currentIndex - 1];
        $this->setCurrentStep($user, $previousStep);
        
        return $previousStep;
    }

    /**
     * Check if onboarding is complete for a user.
     * 
     * @param User $user The user
     * @return bool
     */
    public function isOnboardingComplete(User $user): bool
    {
        $currentStep = $this->getCurrentStep($user);
        return $currentStep === 'ready' || $this->isReady($user);
    }

    /**
     * Check if a user is ready to use the system.
     * 
     * @param User $user The user
     * @return bool
     */
    public function isReady(User $user): bool
    {
        $tenant = $user->getTenant();
        
        // Check if user has at least one LLM configuration
        $llmConfigs = $this->llmConfigurationRepository->findByUser($user->getId());
        if (empty($llmConfigs)) {
            return false;
        }

        // Check if at least one LLM configuration is configured
        $configured = false;
        foreach ($llmConfigs as $config) {
            if ($config->isConfigured()) {
                $configured = true;
                break;
            }
        }

        if (!$configured) {
            return false;
        }

        // Check if user has at least one capability installed and configured
        $capabilities = $this->capabilityRepository->findInstalledByTenant($tenant->getId());
        if (empty($capabilities)) {
            return false;
        }

        $readyCapabilities = false;
        foreach ($capabilities as $capability) {
            if ($capability->isReady()) {
                $readyCapabilities = true;
                break;
            }
        }

        return $readyCapabilities;
    }

    /**
     * Get the onboarding progress for a user.
     * 
     * @param User $user The user
     * @return array Progress information
     */
    public function getProgress(User $user): array
    {
        $currentStep = $this->getCurrentStep($user);
        $currentIndex = array_search($currentStep, self::ONBOARDING_STEPS, true);
        
        $completedSteps = [];
        $remainingSteps = [];

        foreach (self::ONBOARDING_STEPS as $index => $step) {
            if ($index <= $currentIndex) {
                $completedSteps[] = $step;
            } else {
                $remainingSteps[] = $step;
            }
        }

        return [
            'currentStep' => $currentStep,
            'currentIndex' => $currentIndex,
            'totalSteps' => count(self::ONBOARDING_STEPS),
            'completedSteps' => $completedSteps,
            'remainingSteps' => $remainingSteps,
            'progressPercentage' => $this->calculateProgressPercentage($currentIndex),
            'isComplete' => $this->isOnboardingComplete($user),
            'isReady' => $this->isReady($user),
        ];
    }

    /**
     * Calculate the progress percentage.
     * 
     * @param int $currentIndex The current step index
     * @return int Percentage (0-100)
     */
    private function calculateProgressPercentage(int $currentIndex): int
    {
        $totalSteps = count(self::ONBOARDING_STEPS);
        return (int)((($currentIndex + 1) / $totalSteps) * 100);
    }

    /**
     * Initialize onboarding for a new user.
     * 
     * @param User $user The user
     * @return array Initial onboarding data
     */
    public function initializeOnboarding(User $user): array
    {
        // Set initial step
        $this->setCurrentStep($user, 'start');

        // Create initial data
        $tenant = $user->getTenant();
        $organization = $user->getOrganization();

        return [
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'fullName' => $user->getFullName(),
            ],
            'tenant' => [
                'id' => $tenant->getId(),
                'name' => $tenant->getName(),
            ],
            'organization' => $organization ? [
                'id' => $organization->getId(),
                'name' => $organization->getName(),
            ] : null,
            'steps' => self::ONBOARDING_STEPS,
            'currentStep' => 'start',
            'progress' => $this->getProgress($user),
        ];
    }

    /**
     * Collect user goals.
     * 
     * @param User $user The user
     * @param array $goals Array of user goals
     * @return array Updated onboarding data
     */
    public function collectUserGoals(User $user, array $goals): array
    {
        // Store goals in user metadata
        // In a real implementation, you would store this in a dedicated table
        $secret = $user->getSecrets()->first();
        
        if ($secret === null) {
            $secret = $this->secretManager->createSecret(
                $user,
                'user_goals',
                json_encode($goals)
            );
        } else {
            $metadata = $secret->getMetadata() ?? [];
            $metadata['userGoals'] = $goals;
            $secret->setMetadata($metadata);
            $this->entityManager->persist($secret);
            $this->entityManager->flush();
        }

        // Advance to next step
        $this->nextStep($user);

        return [
            'goals' => $goals,
            'nextStep' => $this->getCurrentStep($user),
            'progress' => $this->getProgress($user),
        ];
    }

    /**
     * Get recommended goals based on user type.
     * 
     * @param string $userType The user type (developer, business, personal, etc.)
     * @return array Recommended goals
     */
    public function getRecommendedGoals(string $userType): array
    {
        $recommendations = [
            'developer' => [
                'Integrate with GitHub repositories',
                'Set up automated code reviews',
                'Create documentation from code',
                'Deploy applications automatically',
            ],
            'business' => [
                'Manage customer communications',
                'Automate document processing',
                'Analyze business data',
                'Schedule meetings and appointments',
            ],
            'personal' => [
                'Organize personal notes',
                'Manage to-do lists',
                'Get answers to questions',
                'Summarize articles and documents',
            ],
            'education' => [
                'Create educational content',
                'Grade assignments automatically',
                'Tutor students',
                'Generate quizzes and tests',
            ],
        ];

        return $recommendations[$userType] ?? $recommendations['personal'];
    }

    /**
     * Perform system discovery and recommend capabilities.
     * 
     * @param User $user The user
     * @param array $userGoals User goals
     * @return array Discovery results
     */
    public function performSystemDiscovery(User $user, array $userGoals = []): array
    {
        $tenant = $user->getTenant();
        $discoveredCapabilities = [];
        $recommendedCapabilities = [];

        // Get all available capabilities
        $availableCapabilities = $this->capabilityRegistry->getCapabilitiesByTenant($tenant);

        // Get built-in capabilities
        $builtinCapabilities = $this->capabilityRegistry->getBuiltinCapabilities();

        // Check which capabilities are already registered
        $registeredIdentifiers = [];
        foreach ($availableCapabilities as $cap) {
            $registeredIdentifiers[] = $cap->getIdentifier();
        }

        // Discover unregistered built-in capabilities
        foreach ($builtinCapabilities as $builtin) {
            if (!in_array($builtin['identifier'], $registeredIdentifiers, true)) {
                $discoveredCapabilities[] = [
                    'identifier' => $builtin['identifier'],
                    'name' => $builtin['name'],
                    'description' => $builtin['description'],
                    'category' => $builtin['category'],
                    'provider' => $builtin['provider'],
                    'version' => $builtin['version'],
                    'isRegistered' => false,
                ];
            }
        }

        // Recommend capabilities based on user goals
        $recommended = $this->recommendCapabilitiesByGoals($userGoals);
        
        foreach ($recommended as $identifier) {
            $builtin = $this->capabilityRegistry->getBuiltinCapability($identifier);
            if ($builtin !== null) {
                $recommendedCapabilities[] = [
                    'identifier' => $identifier,
                    'name' => $builtin['name'],
                    'description' => $builtin['description'],
                    'category' => $builtin['category'],
                    'reason' => 'Recommended based on your goals',
                ];
            }
        }

        // Advance to next step
        $this->nextStep($user);

        return [
            'discoveredCapabilities' => $discoveredCapabilities,
            'recommendedCapabilities' => $recommendedCapabilities,
            'totalDiscovered' => count($discoveredCapabilities),
            'totalRecommended' => count($recommendedCapabilities),
            'nextStep' => $this->getCurrentStep($user),
            'progress' => $this->getProgress($user),
        ];
    }

    /**
     * Recommend capabilities based on user goals.
     * 
     * @param array $goals User goals
     * @return array Recommended capability identifiers
     */
    private function recommendCapabilitiesByGoals(array $goals): array
    {
        $goalCapabilities = [
            'github' => ['github_integration'],
            'code review' => ['github_integration'],
            'documentation' => ['llm_integration'],
            'deploy' => ['github_integration'],
            'email' => ['email_management', 'microsoft_graph', 'google_workspace'],
            'customer communications' => ['email_management'],
            'document processing' => ['file_management'],
            'business data' => ['database_query'],
            'schedule' => ['microsoft_graph', 'google_workspace'],
            'organize' => ['file_management'],
            'to-do' => ['file_management'],
            'questions' => ['llm_integration', 'web_browsing'],
            'summarize' => ['llm_integration'],
            'educational content' => ['llm_integration'],
            'grade' => ['llm_integration'],
            'tutor' => ['llm_integration'],
            'quizzes' => ['llm_integration'],
        ];

        $recommended = [];

        foreach ($goals as $goal) {
            $goal = strtolower($goal);
            foreach ($goalCapabilities as $keyword => $capabilities) {
                if (str_contains($goal, $keyword)) {
                    foreach ($capabilities as $capability) {
                        if (!in_array($capability, $recommended, true)) {
                            $recommended[] = $capability;
                        }
                    }
                }
            }
        }

        // Always recommend LLM integration as it's fundamental
        if (!in_array('llm_integration', $recommended, true)) {
            $recommended[] = 'llm_integration';
        }

        return $recommended;
    }

    /**
     * Install recommended capabilities.
     * 
     * @param User $user The user
     * @param array $capabilityIdentifiers Capability identifiers to install
     * @return array Installation results
     */
    public function installRecommendedCapabilities(User $user, array $capabilityIdentifiers): array
    {
        $tenant = $user->getTenant();
        $results = [];

        foreach ($capabilityIdentifiers as $identifier) {
            try {
                $capability = $this->capabilityRegistry->installCapability($tenant, $identifier);
                $results[$identifier] = [
                    'status' => 'success',
                    'capabilityId' => $capability->getId(),
                    'message' => 'Capability installed successfully',
                ];
            } catch (\Exception $e) {
                $results[$identifier] = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Get available LLM providers.
     * 
     * @return array
     */
    public function getLLMProviders(): array
    {
        return [
            [
                'identifier' => 'mistral',
                'name' => 'Mistral AI',
                'description' => 'High-performance language models',
                'models' => [
                    'mistral-tiny',
                    'mistral-small',
                    'mistral-medium',
                    'mistral-large',
                ],
                'requiresApiKey' => true,
                'apiKeyName' => 'MISTRAL_API_KEY',
            ],
            [
                'identifier' => 'openai',
                'name' => 'OpenAI',
                'description' => 'OpenAI language models',
                'models' => [
                    'gpt-4o-mini',
                    'gpt-4o',
                    'gpt-4',
                    'gpt-3.5-turbo',
                ],
                'requiresApiKey' => true,
                'apiKeyName' => 'OPENAI_API_KEY',
            ],
            [
                'identifier' => 'anthropic',
                'name' => 'Anthropic',
                'description' => 'Anthropic language models',
                'models' => [
                    'claude-3-haiku',
                    'claude-3-sonnet',
                    'claude-3-opus',
                ],
                'requiresApiKey' => true,
                'apiKeyName' => 'ANTHROPIC_API_KEY',
            ],
            [
                'identifier' => 'google',
                'name' => 'Google',
                'description' => 'Google language models',
                'models' => [
                    'gemini-1.5-flash',
                    'gemini-1.5-pro',
                    'gemini-2.0-flash',
                ],
                'requiresApiKey' => true,
                'apiKeyName' => 'GOOGLE_API_KEY',
            ],
            [
                'identifier' => 'custom',
                'name' => 'Custom',
                'description' => 'Custom LLM provider',
                'models' => [],
                'requiresApiKey' => false,
                'apiKeyName' => null,
            ],
        ];
    }

    /**
     * Configure LLM for a user.
     * 
     * @param User $user The user
     * @param string $provider The LLM provider
     * @param string $model The model
     * @param array $configuration Configuration options
     * @return LLMConfiguration The created configuration
     */
    public function configureLLM(
        User $user,
        string $provider,
        string $model,
        array $configuration = []
    ): LLMConfiguration {
        // Create or update LLM configuration
        $config = $this->llmConfigurationRepository->findDefaultByUser($user->getId());
        
        if ($config === null) {
            $config = new LLMConfiguration();
            $config->setUser($user);
            $config->setTenant($user->getTenant());
        }

        $config->setProvider($provider);
        $config->setModel($model);
        $config->setConfiguration($configuration);
        $config->setIsDefault(true);
        $config->setIsConfigured(true);

        $this->entityManager->persist($config);
        $this->entityManager->flush();

        // Advance to next step
        $this->nextStep($user);

        return $config;
    }

    /**
     * Get secret setup requirements.
     * 
     * @param User $user The user
     * @return array Secret requirements
     */
    public function getSecretSetupRequirements(User $user): array
    {
        $tenant = $user->getTenant();
        $requirements = [
            'required' => [],
            'optional' => [],
            'configured' => [],
        ];

        // Get installed capabilities
        $capabilities = $this->capabilityRepository->findInstalledByTenant($tenant->getId());

        foreach ($capabilities as $capability) {
            $requiredSecrets = $capability->getRequiredSecrets();
            
            foreach ($requiredSecrets as $secretKey => $config) {
                if ($config['required']) {
                    if ($this->secretManager->hasSecret($tenant->getId(), $secretKey)) {
                        $requirements['configured'][] = [
                            'key' => $secretKey,
                            'description' => $config['description'],
                            'capability' => $capability->getIdentifier(),
                        ];
                    } else {
                        $requirements['required'][] = [
                            'key' => $secretKey,
                            'description' => $config['description'],
                            'capability' => $capability->getIdentifier(),
                        ];
                    }
                } else {
                    if ($this->secretManager->hasSecret($tenant->getId(), $secretKey)) {
                        $requirements['configured'][] = [
                            'key' => $secretKey,
                            'description' => $config['description'],
                            'capability' => $capability->getIdentifier(),
                        ];
                    } else {
                        $requirements['optional'][] = [
                            'key' => $secretKey,
                            'description' => $config['description'],
                            'capability' => $capability->getIdentifier(),
                        ];
                    }
                }
            }
        }

        return $requirements;
    }

    /**
     * Setup a secret for a user.
     * 
     * @param User $user The user
     * @param string $key The secret key
     * @param string $value The secret value
     * @param string|null $description Description
     * @return array Result
     */
    public function setupSecret(
        User $user,
        string $key,
        string $value,
        ?string $description = null
    ): array {
        $tenant = $user->getTenant();
        
        // Check if secret already exists
        if ($this->secretManager->hasSecret($tenant->getId(), $key)) {
            // Update existing secret
            $secret = $this->secretManager->getSecret($tenant->getId(), $key);
            $this->secretManager->updateSecret($secret, $value);
            
            return [
                'status' => 'updated',
                'key' => $key,
                'message' => 'Secret updated successfully',
            ];
        }

        // Create new secret
        $this->secretManager->createSecret($user, $key, $value, $description);

        return [
            'status' => 'created',
            'key' => $key,
            'message' => 'Secret created successfully',
        ];
    }

    /**
     * Get integration setup requirements.
     * 
     * @param User $user The user
     * @return array Integration requirements
     */
    public function getIntegrationSetupRequirements(User $user): array
    {
        $tenant = $user->getTenant();
        $requirements = [
            'required' => [],
            'optional' => [],
            'configured' => [],
        ];

        // Get installed capabilities
        $capabilities = $this->capabilityRepository->findInstalledByTenant($tenant->getId());

        foreach ($capabilities as $capability) {
            $requiredIntegrations = $capability->getRequiredIntegrations();
            
            foreach ($requiredIntegrations as $integration => $config) {
                if ($config['required']) {
                    // In a real implementation, check if integration is configured
                    // For now, we'll assume it's not configured
                    $requirements['required'][] = [
                        'integration' => $integration,
                        'description' => $config['description'],
                        'capability' => $capability->getIdentifier(),
                    ];
                } else {
                    $requirements['optional'][] = [
                        'integration' => $integration,
                        'description' => $config['description'],
                        'capability' => $capability->getIdentifier(),
                    ];
                }
            }
        }

        return $requirements;
    }

    /**
     * Get permission setup requirements.
     * 
     * @param User $user The user
     * @return array Permission requirements
     */
    public function getPermissionSetupRequirements(User $user): array
    {
        $tenant = $user->getTenant();
        $requirements = [
            'required' => [],
            'optional' => [],
            'granted' => [],
        ];

        // Get installed capabilities
        $capabilities = $this->capabilityRepository->findInstalledByTenant($tenant->getId());

        foreach ($capabilities as $capability) {
            $requiredPermissions = $capability->getRequiredPermissions();
            
            foreach ($requiredPermissions as $permission => $config) {
                if ($config['required']) {
                    // In a real implementation, check if permission is granted
                    // For now, we'll assume it's not granted
                    $requirements['required'][] = [
                        'permission' => $permission,
                        'description' => $config['description'],
                        'capability' => $capability->getIdentifier(),
                    ];
                } else {
                    $requirements['optional'][] = [
                        'permission' => $permission,
                        'description' => $config['description'],
                        'capability' => $capability->getIdentifier(),
                    ];
                }
            }
        }

        return $requirements;
    }

    /**
     * Grant a permission to a user.
     * 
     * @param User $user The user
     * @param string $permission The permission
     * @return array Result
     */
    public function grantPermission(User $user, string $permission): array
    {
        // In a real implementation, you would add the permission to the user's roles
        // For now, we'll just log it
        $this->logger->info('Permission granted', [
            'userId' => $user->getId(),
            'permission' => $permission,
        ]);

        return [
            'status' => 'success',
            'permission' => $permission,
            'message' => 'Permission granted successfully',
        ];
    }

    /**
     * Perform the first agent test.
     * 
     * @param User $user The user
     * @return array Test results
     */
    public function performFirstAgentTest(User $user): array
    {
        // Create a test conversation
        $conversation = $this->conversationService->createConversation($user);
        
        // Add a test message
        $this->conversationService->addUserMessage(
            $conversation,
            'Hello, this is my first message. Can you help me get started?'
        );

        // Add a test response
        $this->conversationService->addAssistantMessage(
            $conversation,
            'Welcome to EVIE! I can help you with various tasks. What would you like to do first?'
        );

        // Generate a summary
        $summary = $this->conversationService->generateSummary($conversation);

        // Auto-title the conversation
        $this->conversationService->autoTitle($conversation);

        // Advance to next step
        $this->nextStep($user);

        return [
            'conversationId' => $conversation->getId(),
            'conversationTitle' => $conversation->getTitle(),
            'summary' => $summary,
            'messageCount' => $conversation->getMessageCount(),
            'nextStep' => $this->getCurrentStep($user),
            'progress' => $this->getProgress($user),
        ];
    }

    /**
     * Mark onboarding as complete.
     * 
     * @param User $user The user
     * @return array Result
     */
    public function markAsComplete(User $user): array
    {
        $this->setCurrentStep($user, 'ready');

        return [
            'status' => 'success',
            'message' => 'Onboarding completed successfully',
            'isReady' => $this->isReady($user),
            'progress' => $this->getProgress($user),
        ];
    }

    /**
     * Get onboarding status for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return array Status for all users in the tenant
     */
    public function getTenantOnboardingStatus(Tenant $tenant): array
    {
        $users = $this->userRepository->findByTenant($tenant->getId());
        $status = [
            'totalUsers' => count($users),
            'onboarding' => [],
            'ready' => [],
            'notStarted' => [],
        ];

        foreach ($users as $user) {
            $progress = $this->getProgress($user);
            
            if ($progress['isComplete']) {
                $status['ready'][] = [
                    'userId' => $user->getId(),
                    'email' => $user->getEmail(),
                    'fullName' => $user->getFullName(),
                ];
            } elseif ($progress['currentStep'] === 'start') {
                $status['notStarted'][] = [
                    'userId' => $user->getId(),
                    'email' => $user->getEmail(),
                    'fullName' => $user->getFullName(),
                ];
            } else {
                $status['onboarding'][] = [
                    'userId' => $user->getId(),
                    'email' => $user->getEmail(),
                    'fullName' => $user->getFullName(),
                    'currentStep' => $progress['currentStep'],
                    'progressPercentage' => $progress['progressPercentage'],
                ];
            }
        }

        $status['onboardingCount'] = count($status['onboarding']);
        $status['readyCount'] = count($status['ready']);
        $status['notStartedCount'] = count($status['notStarted']);

        return $status;
    }
}
