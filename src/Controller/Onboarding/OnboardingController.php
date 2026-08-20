<?php

namespace App\Controller\Onboarding;

use App\Entity\AI\Capability;
use App\Entity\AI\LLMConfiguration;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\User;
use App\Repository\AI\CapabilityRepository;
use App\Repository\AI\LLMConfigurationRepository;
use App\Repository\Tenant\TenantRepository;
use App\Service\AI\CapabilityRegistry;
use App\Service\Onboarding\OnboardingService;
use App\Service\Security\SecretManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * OnboardingController provides the onboarding flow for new users.
 * 
 * Features:
 * - Multi-step onboarding process
 * - User goals collection
 * - System discovery
 * - LLM configuration
 * - Integration setup
 * - Permission setup
 * - First agent test
 * - Progress tracking
 */
class OnboardingController extends AbstractController
{
    public function __construct(
        private OnboardingService $onboardingService,
        private CapabilityRegistry $capabilityRegistry,
        private SecretManager $secretManager,
        private LLMConfigurationRepository $llmConfigurationRepository,
        private CapabilityRepository $capabilityRepository,
        private TenantRepository $tenantRepository
    ) {
    }

    /**
     * Onboarding Dashboard - Overview of onboarding progress
     */
    #[Route('/onboarding', name: 'onboarding_dashboard')]
    #[IsGranted('ROLE_USER')]
    public function dashboard(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        // Check if onboarding is complete
        if ($this->onboardingService->isOnboardingComplete($user)) {
            return $this->redirectToRoute('agent_dashboard');
        }

        // Get onboarding progress
        $progress = $this->onboardingService->getProgress($user);
        $currentStep = $this->onboardingService->getCurrentStep($user);

        // Get user goals if available
        $goals = $this->getUserGoals($user);

        // Get discovery results if available
        $discoveryResults = $this->getDiscoveryResults($user);

        // Get LLM configurations
        $llmConfigs = $this->llmConfigurationRepository->findByUser($user->getId());

        // Get installed capabilities
        $capabilities = $this->capabilityRepository->findInstalledByTenant($tenant->getId());

        // Get integration status
        $integrationStatus = $this->getIntegrationStatus($user);

        return $this->render('onboarding/dashboard.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'progress' => $progress,
            'currentStep' => $currentStep,
            'goals' => $goals,
            'discoveryResults' => $discoveryResults,
            'llmConfigs' => $llmConfigs,
            'capabilities' => $capabilities,
            'integrationStatus' => $integrationStatus,
            'steps' => $this->onboardingService->getSteps(),
            'currentRoute' => 'onboarding',
        ]);
    }

    /**
     * Start Onboarding
     */
    #[Route('/onboarding/start', name: 'onboarding_start')]
    #[IsGranted('ROLE_USER')]
    public function startOnboarding(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Check if onboarding is already complete
        if ($this->onboardingService->isOnboardingComplete($user)) {
            return $this->redirectToRoute('agent_dashboard');
        }

        // Initialize onboarding
        $onboardingData = $this->onboardingService->initializeOnboarding($user);

        // Set initial step
        $this->onboardingService->setCurrentStep($user, 'start');

        return $this->redirectToRoute('onboarding_step', ['step' => 'user_goals']);
    }

    /**
     * Onboarding Step - User Goals Collection
     */
    #[Route('/onboarding/user-goals', name: 'onboarding_user_goals')]
    #[IsGranted('ROLE_USER')]
    public function userGoals(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        // Check if onboarding is complete
        if ($this->onboardingService->isOnboardingComplete($user)) {
            return $this->redirectToRoute('agent_dashboard');
        }

        // Get current step
        $currentStep = $this->onboardingService->getCurrentStep($user);

        // If we're not on the right step, redirect
        if ($currentStep !== 'start' && $currentStep !== 'user_goals') {
            return $this->redirectToRoute('onboarding_dashboard');
        }

        // Get user type for recommendations
        $userType = $request->query->get('user_type', 'personal');
        $recommendedGoals = $this->onboardingService->getRecommendedGoals($userType);

        // Get existing goals
        $existingGoals = $this->getUserGoals($user);

        if ($request->isMethod('POST')) {
            $goals = $request->request->all('goals');
            
            // Filter out empty goals
            $goals = array_filter($goals, function($goal) {
                return !empty(trim($goal));
            });

            if (!empty($goals)) {
                $this->storeUserGoals($user, $goals);
                
                // Advance to next step
                $this->onboardingService->nextStep($user);
                
                return $this->redirectToRoute('onboarding_step', ['step' => 'system_discovery']);
            }
        }

        return $this->render('onboarding/user_goals.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'recommendedGoals' => $recommendedGoals,
            'existingGoals' => $existingGoals,
            'userType' => $userType,
            'userTypes' => ['personal', 'business', 'developer', 'education'],
            'currentStep' => 'user_goals',
            'steps' => $this->onboardingService->getSteps(),
            'progress' => $this->onboardingService->getProgress($user),
            'currentRoute' => 'onboarding',
        ]);
    }

    /**
     * Onboarding Step - System Discovery
     */
    #[Route('/onboarding/system-discovery', name: 'onboarding_system_discovery')]
    #[IsGranted('ROLE_USER')]
    public function systemDiscovery(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        // Check if onboarding is complete
        if ($this->onboardingService->isOnboardingComplete($user)) {
            return $this->redirectToRoute('agent_dashboard');
        }

        // Get current step
        $currentStep = $this->onboardingService->getCurrentStep($user);

        // If we're not on the right step, redirect
        if ($currentStep !== 'system_discovery') {
            return $this->redirectToRoute('onboarding_dashboard');
        }

        // Get user goals
        $goals = $this->getUserGoals($user);

        // Perform system discovery
        $discoveryResults = $this->onboardingService->performSystemDiscovery($user, $goals);

        // Store discovery results
        $this->storeDiscoveryResults($user, $discoveryResults);

        // Get recommended capabilities
        $recommendedCapabilities = $discoveryResults['recommendedCapabilities'] ?? [];

        if ($request->isMethod('POST')) {
            $selectedCapabilities = $request->request->all('capabilities');
            
            // Install selected capabilities
            if (!empty($selectedCapabilities)) {
                $this->onboardingService->installRecommendedCapabilities(
                    $user,
                    $selectedCapabilities
                );
            }

            // Advance to next step
            $this->onboardingService->nextStep($user);
            
            return $this->redirectToRoute('onboarding_step', ['step' => 'llm_selection']);
        }

        return $this->render('onboarding/system_discovery.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'goals' => $goals,
            'discoveryResults' => $discoveryResults,
            'recommendedCapabilities' => $recommendedCapabilities,
            'installedCapabilities' => $this->capabilityRepository->findInstalledByTenant($tenant->getId()),
            'currentStep' => 'system_discovery',
            'steps' => $this->onboardingService->getSteps(),
            'progress' => $this->onboardingService->getProgress($user),
            'currentRoute' => 'onboarding',
        ]);
    }

    /**
     * Onboarding Step - LLM Selection
     */
    #[Route('/onboarding/llm-selection', name: 'onboarding_llm_selection')]
    #[IsGranted('ROLE_USER')]
    public function llmSelection(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        // Check if onboarding is complete
        if ($this->onboardingService->isOnboardingComplete($user)) {
            return $this->redirectToRoute('agent_dashboard');
        }

        // Get current step
        $currentStep = $this->onboardingService->getCurrentStep($user);

        // If we're not on the right step, redirect
        if ($currentStep !== 'llm_selection') {
            return $this->redirectToRoute('onboarding_dashboard');
        }

        // Get LLM providers
        $providers = $this->onboardingService->getLLMProviders();

        // Get existing LLM configurations
        $llmConfigs = $this->llmConfigurationRepository->findByUser($user->getId());
        $defaultConfig = $this->llmConfigurationRepository->findDefaultByUser($user->getId());

        if ($request->isMethod('POST')) {
            $provider = $request->request->get('provider');
            $model = $request->request->get('model');
            $configuration = $request->request->all('configuration');

            if (!empty($provider) && !empty($model)) {
                $this->onboardingService->configureLLM($user, $provider, $model, $configuration);
                
                // Advance to next step
                $this->onboardingService->nextStep($user);
                
                return $this->redirectToRoute('onboarding_step', ['step' => 'secret_setup']);
            }
        }

        return $this->render('onboarding/llm_selection.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'providers' => $providers,
            'llmConfigs' => $llmConfigs,
            'defaultConfig' => $defaultConfig,
            'currentStep' => 'llm_selection',
            'steps' => $this->onboardingService->getSteps(),
            'progress' => $this->onboardingService->getProgress($user),
            'currentRoute' => 'onboarding',
        ]);
    }

    /**
     * Onboarding Step - Secret Setup
     */
    #[Route('/onboarding/secret-setup', name: 'onboarding_secret_setup')]
    #[IsGranted('ROLE_USER')]
    public function secretSetup(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        // Check if onboarding is complete
        if ($this->onboardingService->isOnboardingComplete($user)) {
            return $this->redirectToRoute('agent_dashboard');
        }

        // Get current step
        $currentStep = $this->onboardingService->getCurrentStep($user);

        // If we're not on the right step, redirect
        if ($currentStep !== 'secret_setup') {
            return $this->redirectToRoute('onboarding_dashboard');
        }

        // Get secret requirements
        $requirements = $this->onboardingService->getSecretSetupRequirements($user);

        // Get installed capabilities
        $capabilities = $this->capabilityRepository->findInstalledByTenant($tenant->getId());

        if ($request->isMethod('POST')) {
            $secretKey = $request->request->get('secret_key');
            $secretValue = $request->request->get('secret_value');
            $description = $request->request->get('description');

            if (!empty($secretKey) && !empty($secretValue)) {
                $this->onboardingService->setupSecret($user, $secretKey, $secretValue, $description);
            }

            // Check if all required secrets are configured
            if ($this->allRequiredSecretsConfigured($user)) {
                // Advance to next step
                $this->onboardingService->nextStep($user);
                
                return $this->redirectToRoute('onboarding_step', ['step' => 'integration_setup']);
            }
        }

        return $this->render('onboarding/secret_setup.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'requirements' => $requirements,
            'capabilities' => $capabilities,
            'currentStep' => 'secret_setup',
            'steps' => $this->onboardingService->getSteps(),
            'progress' => $this->onboardingService->getProgress($user),
            'currentRoute' => 'onboarding',
        ]);
    }

    /**
     * Onboarding Step - Integration Setup
     */
    #[Route('/onboarding/integration-setup', name: 'onboarding_integration_setup')]
    #[IsGranted('ROLE_USER')]
    public function integrationSetup(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        // Check if onboarding is complete
        if ($this->onboardingService->isOnboardingComplete($user)) {
            return $this->redirectToRoute('agent_dashboard');
        }

        // Get current step
        $currentStep = $this->onboardingService->getCurrentStep($user);

        // If we're not on the right step, redirect
        if ($currentStep !== 'integration_setup') {
            return $this->redirectToRoute('onboarding_dashboard');
        }

        // Get integration requirements
        $requirements = $this->onboardingService->getIntegrationSetupRequirements($user);

        // Get installed integrations
        $integrations = $this->onboardingService->getIntegrationsByTenant($tenant);

        if ($request->isMethod('POST')) {
            // For now, just advance to next step
            // In a real implementation, you would configure integrations here
            
            // Advance to next step
            $this->onboardingService->nextStep($user);
            
            return $this->redirectToRoute('onboarding_step', ['step' => 'permission_setup']);
        }

        return $this->render('onboarding/integration_setup.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'requirements' => $requirements,
            'integrations' => $integrations,
            'currentStep' => 'integration_setup',
            'steps' => $this->onboardingService->getSteps(),
            'progress' => $this->onboardingService->getProgress($user),
            'currentRoute' => 'onboarding',
        ]);
    }

    /**
     * Onboarding Step - Permission Setup
     */
    #[Route('/onboarding/permission-setup', name: 'onboarding_permission_setup')]
    #[IsGranted('ROLE_USER')]
    public function permissionSetup(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        // Check if onboarding is complete
        if ($this->onboardingService->isOnboardingComplete($user)) {
            return $this->redirectToRoute('agent_dashboard');
        }

        // Get current step
        $currentStep = $this->onboardingService->getCurrentStep($user);

        // If we're not on the right step, redirect
        if ($currentStep !== 'permission_setup') {
            return $this->redirectToRoute('onboarding_dashboard');
        }

        // Get permission requirements
        $requirements = $this->onboardingService->getPermissionSetupRequirements($user);

        // Get installed capabilities
        $capabilities = $this->capabilityRepository->findInstalledByTenant($tenant->getId());

        if ($request->isMethod('POST')) {
            // For now, just advance to next step
            // In a real implementation, you would grant permissions here
            
            // Advance to next step
            $this->onboardingService->nextStep($user);
            
            return $this->redirectToRoute('onboarding_step', ['step' => 'first_agent_test']);
        }

        return $this->render('onboarding/permission_setup.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'requirements' => $requirements,
            'capabilities' => $capabilities,
            'currentStep' => 'permission_setup',
            'steps' => $this->onboardingService->getSteps(),
            'progress' => $this->onboardingService->getProgress($user),
            'currentRoute' => 'onboarding',
        ]);
    }

    /**
     * Onboarding Step - First Agent Test
     */
    #[Route('/onboarding/first-agent-test', name: 'onboarding_first_agent_test')]
    #[IsGranted('ROLE_USER')]
    public function firstAgentTest(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        // Check if onboarding is complete
        if ($this->onboardingService->isOnboardingComplete($user)) {
            return $this->redirectToRoute('agent_dashboard');
        }

        // Get current step
        $currentStep = $this->onboardingService->getCurrentStep($user);

        // If we're not on the right step, redirect
        if ($currentStep !== 'first_agent_test') {
            return $this->redirectToRoute('onboarding_dashboard');
        }

        if ($request->isMethod('POST')) {
            // Perform first agent test
            $testResult = $this->onboardingService->performFirstAgentTest($user);
            
            // Advance to next step (ready)
            $this->onboardingService->nextStep($user);
            
            // Mark onboarding as complete
            $this->onboardingService->markAsComplete($user);
            
            $this->addFlash('success', 'Onboarding completed successfully! Welcome to EVIE!');
            
            return $this->redirectToRoute('agent_dashboard');
        }

        return $this->render('onboarding/first_agent_test.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'currentStep' => 'first_agent_test',
            'steps' => $this->onboardingService->getSteps(),
            'progress' => $this->onboardingService->getProgress($user),
            'currentRoute' => 'onboarding',
        ]);
    }

    /**
     * Generic onboarding step handler
     */
    #[Route('/onboarding/step/{step}', name: 'onboarding_step')]
    #[IsGranted('ROLE_USER')]
    public function stepHandler(string $step, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Check if onboarding is complete
        if ($this->onboardingService->isOnboardingComplete($user)) {
            return $this->redirectToRoute('agent_dashboard');
        }

        // Check if step is valid
        $validSteps = $this->onboardingService->getSteps();
        if (!in_array($step, $validSteps, true)) {
            return $this->redirectToRoute('onboarding_dashboard');
        }

        // Redirect to the specific step route
        return $this->redirectToRoute('onboarding_' . $step);
    }

    /**
     * Skip onboarding
     */
    #[Route('/onboarding/skip', name: 'onboarding_skip', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function skipOnboarding(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Mark onboarding as complete
        $this->onboardingService->markAsComplete($user);
        
        $this->addFlash('info', 'Onboarding skipped. You can complete it later from your dashboard.');
        
        return $this->redirectToRoute('agent_dashboard');
    }

    /**
     * Get user goals from metadata
     */
    private function getUserGoals(User $user): array
    {
        $secret = $user->getSecrets()->first();
        
        if ($secret === null) {
            return [];
        }

        $metadata = $secret->getMetadata() ?? [];
        return $metadata['userGoals'] ?? [];
    }

    /**
     * Store user goals in metadata
     */
    private function storeUserGoals(User $user, array $goals): void
    {
        $secret = $user->getSecrets()->first();
        
        if ($secret === null) {
            return;
        }

        $metadata = $secret->getMetadata() ?? [];
        $metadata['userGoals'] = $goals;
        $secret->setMetadata($metadata);
        
        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->persist($secret);
        $entityManager->flush();
    }

    /**
     * Get discovery results from metadata
     */
    private function getDiscoveryResults(User $user): array
    {
        $secret = $user->getSecrets()->first();
        
        if ($secret === null) {
            return [];
        }

        $metadata = $secret->getMetadata() ?? [];
        return $metadata['discoveryResults'] ?? [];
    }

    /**
     * Store discovery results in metadata
     */
    private function storeDiscoveryResults(User $user, array $results): void
    {
        $secret = $user->getSecrets()->first();
        
        if ($secret === null) {
            return;
        }

        $metadata = $secret->getMetadata() ?? [];
        $metadata['discoveryResults'] = $results;
        $secret->setMetadata($metadata);
        
        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->persist($secret);
        $entityManager->flush();
    }

    /**
     * Get integration status for a user
     */
    private function getIntegrationStatus(User $user): array
    {
        $tenant = $user->getTenant();
        $integrations = $this->onboardingService->getIntegrationsByTenant($tenant);
        
        $status = [
            'total' => count($integrations),
            'enabled' => 0,
            'configured' => 0,
            'connected' => 0,
            'ready' => 0,
        ];

        foreach ($integrations as $integration) {
            if ($integration->isEnabled()) {
                $status['enabled']++;
            }
            if ($integration->isConfigured()) {
                $status['configured']++;
            }
            if ($integration->isConnected()) {
                $status['connected']++;
            }
            if ($integration->isReady()) {
                $status['ready']++;
            }
        }

        return $status;
    }

    /**
     * Check if all required secrets are configured
     */
    private function allRequiredSecretsConfigured(User $user): bool
    {
        $requirements = $this->onboardingService->getSecretSetupRequirements($user);
        return empty($requirements['required']);
    }
}
