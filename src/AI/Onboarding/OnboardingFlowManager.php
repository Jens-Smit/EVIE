<?php

namespace App\AI\Onboarding;

use App\Entity\UserProfile;
use App\Repository\UserProfileRepository;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(tags: ['ai.onboarding_manager'])]
class OnboardingFlowManager
{
    private ContextStoreManager $contextStore;
    private UserProfileRepository $userProfileRepo;
    private array $onboardingSteps = [];
    private int $currentStep = 0;

    public function __construct(
        ContextStoreManager $contextStore,
        UserProfileRepository $userProfileRepo
    ) {
        $this->contextStore = $contextStore;
        $this->userProfileRepo = $userProfileRepo;

        // Define onboarding steps
        $this->onboardingSteps = [
            [
                'id' => 'welcome',
                'question' => 'Willkommen beim EVIE AI-Agent! Wie möchtest du den Agenten nutzen?',
                'options' => ['Business (CRM, Termine)', 'Privat (Recherche, Notizen)'],
                'field' => 'user_type',
            ],
            [
                'id' => 'business_type',
                'question' => 'Welche Art von Business-Anwendungen interessieren dich?',
                'options' => ['Kundenmanagement', 'Terminplanung', 'Datenanalyse', 'Andere'],
                'field' => 'business_interests',
                'condition' => fn(array $context) => $context['user_type'] === 'Business (CRM, Termine)',
            ],
            [
                'id' => 'private_type',
                'question' => 'Welche Art von privaten Anwendungen interessieren dich?',
                'options' => ['Recherche', 'Notizen', 'Erinnerungen', 'Andere'],
                'field' => 'private_interests',
                'condition' => fn(array $context) => $context['user_type'] === 'Privat (Recherche, Notizen)',
            ],
            [
                'id' => 'preferences',
                'question' => 'Gibt es spezielle Präferenzen oder Anforderungen, die wir berücksichtigen sollen?',
                'type' => 'text',
                'field' => 'custom_preferences',
            ],
        ];
    }

    /**
     * Starts the onboarding flow for a new user.
     */
    public function startOnboarding(string $userIdentifier): array
    {
        $this->currentStep = 0;
        return $this->getCurrentStep($userIdentifier);
    }

    /**
     * Processes the user's response and moves to the next step.
     */
    public function processResponse(string $userIdentifier, string $response): array
    {
        $currentStep = $this->onboardingSteps[$this->currentStep];

        // Save the response to the user's context
        $context = $this->contextStore->loadContext($userIdentifier);
        
        if (!isset($context['onboarding_data'])) {
            $context['onboarding_data'] = [];
        }

        // Handle different response types
        if ($currentStep['type'] === 'text') {
            $context['onboarding_data'][$currentStep['field']] = $response;
        } else {
            // For multiple-choice questions
            if (in_array($response, $currentStep['options'])) {
                $context['onboarding_data'][$currentStep['field']] = $response;
            }
        }

        // Update user type in the main context
        if ($currentStep['field'] === 'user_type') {
            $context['user_type'] = $response;
        }

        $this->contextStore->saveContext($userIdentifier, $context);

        // Move to the next step
        $this->currentStep++;

        // Check if there are more steps
        if ($this->currentStep >= count($this->onboardingSteps)) {
            return $this->completeOnboarding($userIdentifier);
        }

        return $this->getCurrentStep($userIdentifier);
    }

    /**
     * Returns the current onboarding step.
     */
    public function getCurrentStep(string $userIdentifier): array
    {
        $context = $this->contextStore->loadContext($userIdentifier);

        // Skip steps that don't match the condition
        while (isset($this->onboardingSteps[$this->currentStep])) {
            $step = $this->onboardingSteps[$this->currentStep];
            
            if (!isset($step['condition']) || $step['condition']($context)) {
                break;
            }
            
            $this->currentStep++;
        }

        if (!isset($this->onboardingSteps[$this->currentStep])) {
            return ['status' => 'completed'];
        }

        $step = $this->onboardingSteps[$this->currentStep];
        return [
            'step_id' => $step['id'],
            'question' => $step['question'],
            'type' => $step['type'] ?? 'multiple_choice',
            'options' => $step['options'] ?? [],
            'current_step' => $this->currentStep,
            'total_steps' => count($this->onboardingSteps),
        ];
    }

    /**
     * Completes the onboarding process.
     */
    public function completeOnboarding(string $userIdentifier): array
    {
        $context = $this->contextStore->loadContext($userIdentifier);

        // Create or update user profile
        $userProfile = $this->userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);
        
        if (!$userProfile) {
            $userProfile = new UserProfile();
            $userProfile->setUserIdentifier($userIdentifier);
        }

        // Set user type
        if (isset($context['user_type'])) {
            $userProfile->setUserType($context['user_type']);
        }

        // Set preferences
        if (isset($context['onboarding_data'])) {
            $userProfile->setPreferences($context['onboarding_data']);
        }

        $userProfile->setUpdatedAt(new \DateTimeImmutable());
        $this->userProfileRepo->save($userProfile, true);

        // Reset current step
        $this->currentStep = 0;

        return [
            'status' => 'completed',
            'message' => 'Onboarding abgeschlossen! Danke für deine Angaben.',
            'user_profile' => [
                'user_type' => $userProfile->getUserType(),
                'preferences' => $userProfile->getPreferences(),
            ],
        ];
    }

    /**
     * Returns the onboarding status for a user.
     */
    public function getOnboardingStatus(string $userIdentifier): array
    {
        $userProfile = $this->userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);

        if (!$userProfile) {
            return ['status' => 'not_started'];
        }

        $context = $this->contextStore->loadContext($userIdentifier);

        if (!isset($context['onboarding_data']) || empty($context['onboarding_data'])) {
            return ['status' => 'not_started'];
        }

        // Check if all required steps are completed
        $requiredFields = ['user_type'];
        foreach ($requiredFields as $field) {
            if (!isset($context['onboarding_data'][$field]) && !isset($context[$field])) {
                return ['status' => 'in_progress'];
            }
        }

        return ['status' => 'completed'];
    }
}
