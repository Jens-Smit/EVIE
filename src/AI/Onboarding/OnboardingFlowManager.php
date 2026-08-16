<?php

namespace App\AI\Onboarding;

use App\Entity\UserProfile;
use App\Repository\UserProfileRepository;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * OnboardingFlowManager - Verwaltet den Onboarding-Prozess für neue Benutzer
 * 
 * PHASE 3 OPTIMIZATION: Nutzt den dedizierten onboarding-Agent mit optimiertem Prompt
 * aus config/prompts/onboarding_prompt.json für strukturiertes, kontextbewusstes Onboarding.
 * 
 * @see ROADMAP_PHASE3.md Maßnahme 10: Onboarding-Prompt optimieren
 */
#[Autoconfigure(tags: ['ai.onboarding_manager'])]
class OnboardingFlowManager
{
    private ContextStoreManager $contextStore;
    private UserProfileRepository $userProfileRepo;
    private AgentInterface $onboardingAgent;
    private int $currentStep = 0;
    
    /**
     * @param ContextStoreManager $contextStore Verwaltet den Benutzerkontext
     * @param UserProfileRepository $userProfileRepo Repository für Benutzerprofile
     * @param AgentInterface $onboardingAgent Der dedizierte Onboarding-Agent mit optimiertem Prompt
     */
    public function __construct(
        ContextStoreManager $contextStore,
        UserProfileRepository $userProfileRepo,
        AgentInterface $onboardingAgent
    ) {
        $this->contextStore = $contextStore;
        $this->userProfileRepo = $userProfileRepo;
        $this->onboardingAgent = $onboardingAgent;
    }

    /**
     * Startet den Onboarding-Prozess für einen neuen Benutzer.
     * Nutzt den onboarding-Agent mit optimiertem Prompt aus Phase 3.
     * 
     * @param string $userIdentifier Eindeutige Benutzerkennung
     * @param array $initialContext Optionaler Anfangskontext
     * @return array Der erste Onboarding-Schritt
     */
    public function startOnboarding(string $userIdentifier, array $initialContext = []): array
    {
        $this->currentStep = 0;
        
        // Lade oder initialisiere den Benutzerkontext
        $context = $this->contextStore->loadContext($userIdentifier);
        
        // Setze den Anfangskontext für den onboarding-Agent
        if (!isset($context['onboarding_data'])) {
            $context['onboarding_data'] = [
                'started_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'phase_3_optimized' => true,
                'prompt_version' => '1.0',
            ];
        }
        
        // Speichere den Kontext
        $this->contextStore->saveContext($userIdentifier, $context);
        
        // Starte den ersten Schritt mit dem onboarding-Agent
        return $this->getNextStep($userIdentifier, $initialContext);
    }

    /**
     * Verarbeitet die Benutzerantwort und bewegt sich zum nächsten Schritt.
     * Delegiert an den onboarding-Agent für intelligente Verarbeitung.
     * 
     * @param string $userIdentifier Eindeutige Benutzerkennung
     * @param string|array $response Benutzerantwort (kann String oder strukturierte Daten sein)
     * @return array Nächster Onboarding-Schritt oder Abschlussbestätigung
     */
    public function processResponse(string $userIdentifier, string|array $response): array
    {
        $context = $this->contextStore->loadContext($userIdentifier);
        
        // Initialisiere Onboarding-Daten, falls nicht vorhanden
        if (!isset($context['onboarding_data'])) {
            $context['onboarding_data'] = [
                'started_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'phase_3_optimized' => true,
            ];
        }
        
        // Speichere die aktuelle Antwort
        $context['onboarding_data']['step_' . $this->currentStep] = [
            'response' => is_array($response) ? $response : ['value' => $response],
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        
        // Erstelle eine strukturierte Anfrage für den onboarding-Agent
        $agentRequest = [
            'action' => 'process_response',
            'user_identifier' => $userIdentifier,
            'current_step' => $this->currentStep,
            'response' => is_array($response) ? $response : ['value' => $response],
            'context' => $context['onboarding_data'] ?? [],
            'previous_steps' => $this->getPreviousSteps($context),
        ];
        
        try {
            // Nutze den onboarding-Agent mit optimiertem Prompt
            $messages = new MessageBag(
                Message::ofUser(json_encode($agentRequest, JSON_THROW_ON_ERROR))
            );
            
            $agentResponse = $this->onboardingAgent->call($messages);
            $responseContent = $agentResponse->getContent();
            
            // Parse die Antwort des Agents
            $stepData = $this->parseAgentResponse($responseContent);
            
            // Aktualisiere den Kontext basierend auf der Agenten-Antwort
            if (isset($stepData['context_updates'])) {
                foreach ($stepData['context_updates'] as $key => $value) {
                    $context['onboarding_data'][$key] = $value;
                }
            }
            
            // Speichere den aktualisierten Kontext
            $this->contextStore->saveContext($userIdentifier, $context);
            
            // Erhöhe den Schrittzähler
            $this->currentStep++;
            
            // Prüfe, ob das Onboarding abgeschlossen ist
            if (($stepData['status'] ?? '') === 'completed' || ($stepData['next_step'] ?? null) === null) {
                return $this->completeOnboarding($userIdentifier);
            }
            
            // Gebe den nächsten Schritt zurück
            return [
                'status' => 'in_progress',
                'step_id' => $stepData['next_step'] ?? 'unknown',
                'current_step' => $this->currentStep,
                'question' => $stepData['question'] ?? 'Wie können wir Ihnen helfen?',
                'type' => $stepData['type'] ?? 'text',
                'options' => $stepData['options'] ?? [],
                'validation' => $stepData['validation'] ?? [],
                'context' => $context['onboarding_data'],
            ];
            
        } catch (\Exception $e) {
            $this->logError('Fehler bei der Verarbeitung der Onboarding-Antwort', [
                'error' => $e->getMessage(),
                'user_identifier' => $userIdentifier,
                'step' => $this->currentStep,
            ]);
            
            // Fallback: Nutze die alte Methode
            return $this->processResponseFallback($userIdentifier, $response);
        }
    }

    /**
     * Parsed die Antwort des onboarding-Agents
     */
    private function parseAgentResponse(string $responseContent): array
    {
        try {
            // Versuche, die Antwort als JSON zu parsen
            $data = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);
            
            // Validierung der Agenten-Antwort
            if (!isset($data['status']) && !isset($data['question'])) {
                throw new \RuntimeException('Ungültige Agenten-Antwort: fehlende Status- oder Frage-Informationen');
            }
            
            return $data;
            
        } catch (\JsonException $e) {
            // Falls das Parsen fehlschlägt, erstelle eine Standard-Antwort
            return [
                'status' => 'error',
                'question' => 'Wie können wir Ihnen helfen?',
                'type' => 'text',
                'error' => 'Ungültige Agenten-Antwort',
            ];
        }
    }

    /**
     * Gibt die vorherigen Schritte zurück (für den Agenten-Kontext)
     */
    private function getPreviousSteps(array $context): array
    {
        $previousSteps = [];
        
        if (isset($context['onboarding_data'])) {
            foreach ($context['onboarding_data'] as $key => $value) {
                if (str_starts_with($key, 'step_')) {
                    $previousSteps[] = [
                        'step' => $key,
                        'data' => $value,
                    ];
                }
            }
        }
        
        return $previousSteps;
    }

    /**
     * Gibt den nächsten Onboarding-Schritt zurück
     * 
     * @param string $userIdentifier Eindeutige Benutzerkennung
     * @param array $additionalContext Zusätzlicher Kontext
     * @return array Schrittinformationen
     */
    public function getNextStep(string $userIdentifier, array $additionalContext = []): array
    {
        $context = $this->contextStore->loadContext($userIdentifier);
        
        // Erstelle eine Anfrage für den onboarding-Agent
        $agentRequest = [
            'action' => 'get_next_step',
            'user_identifier' => $userIdentifier,
            'current_step' => $this->currentStep,
            'context' => $context['onboarding_data'] ?? [],
            'additional_context' => $additionalContext,
        ];
        
        try {
            // Nutze den onboarding-Agent
            $messages = new MessageBag(
                Message::ofUser(json_encode($agentRequest, JSON_THROW_ON_ERROR))
            );
            
            $agentResponse = $this->onboardingAgent->call($messages);
            $responseContent = $agentResponse->getContent();
            
            // Parse die Antwort
            $stepData = $this->parseAgentResponse($responseContent);
            
            // Speichere Kontext-Updates
            if (isset($stepData['context_updates'])) {
                foreach ($stepData['context_updates'] as $key => $value) {
                    $context['onboarding_data'][$key] = $value;
                }
                $this->contextStore->saveContext($userIdentifier, $context);
            }
            
            return [
                'status' => 'in_progress',
                'step_id' => $stepData['step_id'] ?? 'step_' . $this->currentStep,
                'current_step' => $this->currentStep,
                'question' => $stepData['question'] ?? 'Wie können wir Ihnen helfen?',
                'type' => $stepData['type'] ?? 'text',
                'options' => $stepData['options'] ?? [],
                'validation' => $stepData['validation'] ?? [],
                'context' => $context['onboarding_data'] ?? [],
            ];
            
        } catch (\Exception $e) {
            $this->logError('Fehler beim Abrufen des nächsten Onboarding-Schritts', [
                'error' => $e->getMessage(),
                'user_identifier' => $userIdentifier,
            ]);
            
            // Fallback: Nutze die alte Methode
            return $this->getCurrentStepFallback($userIdentifier);
        }
    }

    /**
     * Beendet den Onboarding-Prozess und speichert die Benutzerdaten.
     * 
     * @param string $userIdentifier Eindeutige Benutzerkennung
     * @return array Abschlussbestätigung
     */
    public function completeOnboarding(string $userIdentifier): array
    {
        $context = $this->contextStore->loadContext($userIdentifier);
        
        // Erstelle oder aktualisiere das Benutzerprofil
        $userProfile = $this->userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);
        
        if (!$userProfile) {
            $userProfile = new UserProfile();
            $userProfile->setUserIdentifier($userIdentifier);
        }
        
        // Setze die Benutzerdaten aus dem Onboarding-Kontext
        $this->updateUserProfileFromContext($userProfile, $context);
        
        // Speichere das Profil
        $userProfile->setUpdatedAt(new \DateTimeImmutable());
        $userProfile->setOnboardingCompleted(true);
        $userProfile->setOnboardingCompletedAt(new \DateTimeImmutable());
        $this->userProfileRepo->save($userProfile, true);
        
        // Setze den Abschluss im Kontext
        $context['onboarding_data']['completed_at'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $context['onboarding_data']['status'] = 'completed';
        $this->contextStore->saveContext($userIdentifier, $context);
        
        // Zurücksetzen des Schrittzählers
        $this->currentStep = 0;
        
        // Informiere den onboarding-Agent über den Abschluss
        $this->notifyAgentOfCompletion($userIdentifier, $userProfile);
        
        return [
            'status' => 'completed',
            'message' => 'Onboarding abgeschlossen! Danke für deine Angaben.',
            'user_profile' => $this->getUserProfileData($userProfile),
            'onboarding_data' => $context['onboarding_data'] ?? [],
            'next_steps' => [
                'Explore Available Tools: /tools list',
                'Start a Conversation: Just type your question!',
                'View Your Profile: /profile show',
            ],
        ];
    }

    /**
     * Aktualisiert das Benutzerprofil basierend auf dem Onboarding-Kontext
     */
    private function updateUserProfileFromContext(UserProfile $userProfile, array $context): void
    {
        $onboardingData = $context['onboarding_data'] ?? [];
        
        // Setze Benutzertyp
        if (isset($onboardingData['user_type'])) {
            $userProfile->setUserType($onboardingData['user_type']);
        }
        
        // User Type Detail (UserProfile hat keinen dedizierten Setter;
        // in preferences speichern - Blueprint-konform, keine Schema-Aenderung).
        if (isset($onboardingData['user_type_detail'])) {
            $preferences = $userProfile->getPreferences() ?? [];
            $preferences['user_type_detail'] = $onboardingData['user_type_detail'];
            $userProfile->setPreferences($preferences);
        }
        
        if (isset($onboardingData['technical_skills'])) {
            $preferences = $userProfile->getPreferences() ?? [];
            $preferences['technical_skills'] = $onboardingData['technical_skills'];
            $userProfile->setPreferences($preferences);
        }
        
        if (isset($onboardingData['experience_level'])) {
            $preferences = $userProfile->getPreferences() ?? [];
            $preferences['experience_level'] = $onboardingData['experience_level'];
            $userProfile->setPreferences($preferences);
        }
        
        if (isset($onboardingData['use_cases'])) {
            $preferences = $userProfile->getPreferences() ?? [];
            $preferences['use_cases'] = $onboardingData['use_cases'];
            $userProfile->setPreferences($preferences);
        }
        
        if (isset($onboardingData['industry'])) {
            $preferences = $userProfile->getPreferences() ?? [];
            $preferences['industry'] = $onboardingData['industry'];
            $userProfile->setPreferences($preferences);
        }
        
        if (isset($onboardingData['industry_detail'])) {
            $preferences = $userProfile->getPreferences() ?? [];
            $preferences['industry_detail'] = $onboardingData['industry_detail'];
            $userProfile->setPreferences($preferences);
        }
        
        // Setze Präferenzen
        if (isset($onboardingData['response_style'])) {
            $preferences = $userProfile->getPreferences() ?? [];
            $preferences['response_style'] = $onboardingData['response_style'];
            $userProfile->setPreferences($preferences);
        }
        
        if (isset($onboardingData['technical_level'])) {
            $preferences = $userProfile->getPreferences() ?? [];
            $preferences['technical_level'] = $onboardingData['technical_level'];
            $userProfile->setPreferences($preferences);
        }
        
        if (isset($onboardingData['language'])) {
            $preferences = $userProfile->getPreferences() ?? [];
            $preferences['language'] = $onboardingData['language'];
            $userProfile->setPreferences($preferences);
        }
        
        if (isset($onboardingData['notifications'])) {
            $preferences = $userProfile->getPreferences() ?? [];
            $preferences['notifications'] = $onboardingData['notifications'];
            $userProfile->setPreferences($preferences);
        }
        
        if (isset($onboardingData['hitl_requirements'])) {
            $preferences = $userProfile->getPreferences() ?? [];
            $preferences['hitl_requirements'] = $onboardingData['hitl_requirements'];
            $userProfile->setPreferences($preferences);
        }
        
        if (isset($onboardingData['security_level'])) {
            $preferences = $userProfile->getPreferences() ?? [];
            $preferences['security_level'] = $onboardingData['security_level'];
            $userProfile->setPreferences($preferences);
        }
        
        // Metadaten (UserProfile hat keinen setMetadata-Setter; in preferences
        // speichern - Blueprint-konform, keine Schema-Aenderung).
        $preferences = $userProfile->getPreferences() ?? [];
        $preferences['_onboarding_metadata'] = [
            'onboarding_phase_3' => true,
            'onboarding_version' => '1.0',
            'onboarding_timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        $userProfile->setPreferences($preferences);
    }

    /**
     * Informiert den onboarding-Agent über den Abschluss
     */
    private function notifyAgentOfCompletion(string $userIdentifier, UserProfile $userProfile): void
    {
        try {
            $completionNotification = [
                'action' => 'onboarding_completed',
                'user_identifier' => $userIdentifier,
                'user_profile' => $this->getUserProfileData($userProfile),
                'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ];
            
            $messages = new MessageBag(
                Message::ofUser(json_encode($completionNotification, JSON_THROW_ON_ERROR))
            );
            
            $this->onboardingAgent->call($messages);
            
        } catch (\Exception $e) {
            $this->logError('Fehler bei der Benachrichtigung des Agents über Onboarding-Abschluss', [
                'error' => $e->getMessage(),
                'user_identifier' => $userIdentifier,
            ]);
        }
    }

    /**
     * Gibt die Benutzerprofildaten als Array zurück
     */
    private function getUserProfileData(UserProfile $userProfile): array
    {
        // UserProfile hat nur getUserIdentifier/getUserType/getPreferences;
        // die restlichen Werte werden in preferences gespeichert (siehe
        // updateUserProfileFromContext) und hier daraus extrahiert.
        $preferences = $userProfile->getPreferences() ?? [];
        return [
            'user_identifier' => $userProfile->getUserIdentifier(),
            'user_type' => $userProfile->getUserType(),
            'user_type_detail' => $preferences['user_type_detail'] ?? null,
            'technical_skills' => $preferences['technical_skills'] ?? null,
            'experience_level' => $preferences['experience_level'] ?? null,
            'use_cases' => $preferences['use_cases'] ?? null,
            'industry' => $preferences['industry'] ?? null,
            'industry_detail' => $preferences['industry_detail'] ?? null,
            'preferences' => $preferences,
            'metadata' => $preferences['_onboarding_metadata'] ?? [],
        ];
    }

    /**
     * Gibt den aktuellen Onboarding-Schritt zurück (Fallback-Methode)
     * 
     * @deprecated Wird durch getNextStep ersetzt
     */
    private function getCurrentStepFallback(string $userIdentifier): array
    {
        $context = $this->contextStore->loadContext($userIdentifier);

        // Skip steps that don't match the condition
        while (isset($this->getLegacySteps()[$this->currentStep])) {
            $step = $this->getLegacySteps()[$this->currentStep];
            
            if (!isset($step['condition']) || $step['condition']($context)) {
                break;
            }
            
            $this->currentStep++;
        }

        if (!isset($this->getLegacySteps()[$this->currentStep])) {
            return ['status' => 'completed'];
        }

        $step = $this->getLegacySteps()[$this->currentStep];
        return [
            'step_id' => $step['id'],
            'question' => $step['question'],
            'type' => $step['type'] ?? 'multiple_choice',
            'options' => $step['options'] ?? [],
            'current_step' => $this->currentStep,
            'total_steps' => count($this->getLegacySteps()),
        ];
    }

    /**
     * Verarbeitet die Benutzerantwort (Fallback-Methode)
     * 
     * @deprecated Wird durch processResponse ersetzt
     */
    private function processResponseFallback(string $userIdentifier, string|array $response): array
    {
        $currentStep = $this->getLegacySteps()[$this->currentStep];

        // Save the response to the user's context
        $context = $this->contextStore->loadContext($userIdentifier);
        
        if (!isset($context['onboarding_data'])) {
            $context['onboarding_data'] = [];
        }

        // Handle different response types
        if (($currentStep['type'] ?? '') === 'text') {
            $context['onboarding_data'][$currentStep['field']] = $response;
        } else {
            // For multiple-choice questions
            if (is_array($response) || in_array($response, $currentStep['options'] ?? [])) {
                $context['onboarding_data'][$currentStep['field']] = $response;
            }
        }

        // Update user type in the main context
        if (($currentStep['field'] ?? '') === 'user_type') {
            $context['user_type'] = is_array($response) ? $response[0] ?? '' : $response;
        }

        $this->contextStore->saveContext($userIdentifier, $context);

        // Move to the next step
        $this->currentStep++;

        // Check if there are more steps
        if ($this->currentStep >= count($this->getLegacySteps())) {
            return $this->completeOnboarding($userIdentifier);
        }

        return $this->getCurrentStepFallback($userIdentifier);
    }

    /**
     * Gibt die alten Onboarding-Schritte zurück (für Fallback)
     * 
     * @deprecated Wird durch den onboarding-Agent ersetzt
     */
    private function getLegacySteps(): array
    {
        return [
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
                'condition' => fn(array $context) => ($context['user_type'] ?? '') === 'Business (CRM, Termine)',
            ],
            [
                'id' => 'private_type',
                'question' => 'Welche Art von privaten Anwendungen interessieren dich?',
                'options' => ['Recherche', 'Notizen', 'Erinnerungen', 'Andere'],
                'field' => 'private_interests',
                'condition' => fn(array $context) => ($context['user_type'] ?? '') === 'Privat (Recherche, Notizen)',
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
     * Gibt den Onboarding-Status für einen Benutzer zurück
     * 
     * @param string $userIdentifier Eindeutige Benutzerkennung
     * @return array Statusinformationen
     */
    public function getOnboardingStatus(string $userIdentifier): array
    {
        $userProfile = $this->userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);

        if (!$userProfile) {
            return ['status' => 'not_started'];
        }

        // Prüfe, ob das Onboarding abgeschlossen ist
        if ($userProfile->isOnboardingCompleted()) {
            return [
                'status' => 'completed',
                'completed_at' => $userProfile->getOnboardingCompletedAt()?->format(DATE_ATOM),
            ];
        }

        $context = $this->contextStore->loadContext($userIdentifier);

        if (!isset($context['onboarding_data']) || empty($context['onboarding_data'])) {
            return ['status' => 'not_started'];
        }

        // Prüfe, ob alle erforderlichen Felder vorhanden sind
        $requiredFields = ['user_type'];
        foreach ($requiredFields as $field) {
            if (!isset($context['onboarding_data'][$field]) && !isset($context[$field])) {
                return [
                    'status' => 'in_progress',
                    'current_step' => $this->currentStep,
                    'onboarding_data' => $context['onboarding_data'],
                ];
            }
        }

        return ['status' => 'completed'];
    }

    /**
     * Setzt den Onboarding-Prozess zurück
     * 
     * @param string $userIdentifier Eindeutige Benutzerkennung
     */
    public function resetOnboarding(string $userIdentifier): void
    {
        $this->currentStep = 0;
        
        $context = $this->contextStore->loadContext($userIdentifier);
        unset($context['onboarding_data']);
        $this->contextStore->saveContext($userIdentifier, $context);

        $userProfile = $this->userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);
        if ($userProfile) {
            $userProfile->setOnboardingCompleted(false);
            $userProfile->setOnboardingCompletedAt(null);
            $this->userProfileRepo->save($userProfile, true);
        }
    }

    /**
     * Startet das Onboarding neu
     * 
     * @param string $userIdentifier Eindeutige Benutzerkennung
     */
    public function resumeOnboarding(string $userIdentifier): array
    {
        $this->resetOnboarding($userIdentifier);
        return $this->startOnboarding($userIdentifier);
    }

    /**
     * Loggt einen Fehler
     */
    private function logError(string $message, array $context = []): void
    {
        // Hier könnte ein Logger injiziert werden
        // Für jetzt: Fehler in den Kontext schreiben
        $context['error'] = $message;
        $context['error_timestamp'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        
        // In einer echten Implementierung würde man hier einen Logger verwenden
        // $this->logger->error($message, $context);
    }
}
