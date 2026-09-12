<?php

declare(strict_types=1);

namespace App\AI\Onboarding;

use App\Entity\UserProfile;
use App\Repository\UserProfileRepository;
use App\Service\SecretService;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * OnboardingFlowManager - Verwaltet den Onboarding-Prozess fuer neue Benutzer.
 *
 * Der Flow ist PHASENBASIERT und DETERMINISTISCH, damit er zuverlaessig
 * durchlaeuft (auch ohne erreichbaren Onboarding-Agent) und alle fuer den
 * Betrieb noetigen Konfigurationen erfasst:
 *
 *   Phase A - KI-Settings (zwingend zuerst): LLM-Anbieter, -Modell, -API-Key
 *   Phase B - Profil/Bedarfsanalyse: Rolle, Use-Cases, Branche
 *   Phase C - Schnittstellen: aus Use-Cases abgeleitete API-Keys/Email/OAuth
 *   Phase D - Abschluss
 *
 * Die KI-Settings bewusst zuerst, damit nachfolgende Agent-Aufrufe den
 * konfigurierten Provider nutzen koennen. Die Schnittstellen-Phase folgt erst
 * nach der Bedarfsanalyse, da sich die noetigen Keys aus den Use-Cases ergeben
 * (Blueprint-konform, keine Fantasie-Tools).
 *
 * Der optionale onboarding-Agent wird nur zur Benachrichtigung beim Abschluss
 * herangezogen; die Schrittlogik selbst ist datengetrieben (keine LLM-
 * Halluzination). Secrets (API-Keys, E-Mail-Zugangsdaten) werden ueber den
 * SecretService verschluesselt gespeichert.
 *
 * @see ROADMAP_PHASE3.md Massnahme 10: Onboarding-Prompt optimieren
 */
#[Autoconfigure(tags: ['ai.onboarding_manager'])]
class OnboardingFlowManager
{
    private ContextStoreManager $contextStore;
    private UserProfileRepository $userProfileRepo;
    private AgentInterface $onboardingAgent;
    private OnboardingStepProvider $stepProvider;
    private IntegrationRequirementMapper $requirementMapper;
    private SecretService $secretService;
    private int $currentStep = 0;

    /**
     * @param ContextStoreManager           $contextStore       Verwaltet den Benutzerkontext
     * @param UserProfileRepository         $userProfileRepo     Repository fuer Benutzerprofile
     * @param AgentInterface                 $onboardingAgent     Dedizierter Onboarding-Agent (nur fuer Abschluss-Benachrichtigung)
     * @param OnboardingStepProvider        $stepProvider        Deterministische Schrittfolge
     * @param IntegrationRequirementMapper  $requirementMapper  Leitet Use-Cases auf Schnittstellen ab
     * @param SecretService                 $secretService      Verschluesselte Ablage von API-Keys/E-Mail-Zugangsdaten
     */
    public function __construct(
        ContextStoreManager $contextStore,
        UserProfileRepository $userProfileRepo,
        AgentInterface $onboardingAgent,
        OnboardingStepProvider $stepProvider,
        IntegrationRequirementMapper $requirementMapper,
        SecretService $secretService
    ) {
        $this->contextStore = $contextStore;
        $this->userProfileRepo = $userProfileRepo;
        $this->onboardingAgent = $onboardingAgent;
        $this->stepProvider = $stepProvider;
        $this->requirementMapper = $requirementMapper;
        $this->secretService = $secretService;
    }

    /**
     * Startet den Onboarding-Prozess fuer einen neuen Benutzer.
     *
     * @param string $userIdentifier Eindeutige Benutzerkennung
     * @param array  $initialContext Optionaler Anfangskontext
     *
     * @return array Der erste Onboarding-Schritt
     */
    public function startOnboarding(string $userIdentifier, array $initialContext = []): array
    {
        $this->currentStep = 0;

        $context = $this->contextStore->loadContext($userIdentifier);
        if (!isset($context['onboarding_data'])) {
            $context['onboarding_data'] = [
                'started_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                'phase_3_optimized' => true,
                'prompt_version' => '2.0',
            ];
        }
        $context['onboarding_data']['current_step'] = $this->currentStep;
        $this->contextStore->saveContext($userIdentifier, $context);

        return $this->buildStepResponse($userIdentifier, $context);
    }

    /**
     * Verarbeitet die Benutzerantwort und bewegt sich zum naechsten Schritt.
     *
     * @param string         $userIdentifier Eindeutige Benutzerkennung
     * @param string|array   $response       Benutzerantwort (String oder strukturierte Daten)
     *
     * @return array Naechster Onboarding-Schritt oder Abschlussbestaetigung
     */
    public function processResponse(string $userIdentifier, string|array $response): array
    {
        $context = $this->contextStore->loadContext($userIdentifier);

        if (!isset($context['onboarding_data'])) {
            $context['onboarding_data'] = [
                'started_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                'phase_3_optimized' => true,
                'prompt_version' => '2.0',
            ];
        }

        $this->currentStep = $context['onboarding_data']['current_step'] ?? 0;
        $steps = $this->resolveSteps($context);
        $currentStepDef = $steps[$this->currentStep] ?? null;

        if ($currentStepDef === null) {
            return $this->completeOnboarding($userIdentifier);
        }

        $field = $currentStepDef['field'];
        $type = $currentStepDef['type'];

        // Antwort normalisieren und im Kontext speichern.
        $normalized = $this->normalizeResponse($response, $type);
        $context['onboarding_data'][$field] = $normalized;
        $context['onboarding_data']['step_' . $this->currentStep] = [
            'response' => $normalized,
            'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];

        // Nebenwirkungen je nach Schritttyp ausfuehren (Secret-Speicherung,
        // LLM-Praeferenz-Persistierung, Modell-Optionen dynamisieren).
        $this->applyStepSideEffects($userIdentifier, $context, $currentStepDef, $normalized);

        // Wenn der Use-Case-Schritt beantwortet wurde, muessen die
        // Schnittstellen-Schritte neu in die Schrittfolge eingefuegt werden,
        // da ihre Anzahl von den Use-Cases abhaengt.
        if ($field === 'use_cases') {
            // use_cases als Liste sicherstellen
            $context['onboarding_data']['use_cases'] = $this->toArray($normalized);
        }

        $this->currentStep++;
        $context['onboarding_data']['current_step'] = $this->currentStep;
        $this->contextStore->saveContext($userIdentifier, $context);

        $steps = $this->resolveSteps($context);
        if (!isset($steps[$this->currentStep])) {
            return $this->completeOnboarding($userIdentifier);
        }

        return $this->buildStepResponse($userIdentifier, $context);
    }

    /**
     * Gibt den naechsten Onboarding-Schritt zurueck.
     *
     * @param string $userIdentifier   Eindeutige Benutzerkennung
     * @param array  $additionalContext Zusaetzlicher Kontext
     *
     * @return array Schrittinformationen
     */
    public function getNextStep(string $userIdentifier, array $additionalContext = []): array
    {
        $context = $this->contextStore->loadContext($userIdentifier);
        $this->currentStep = $context['onboarding_data']['current_step'] ?? 0;

        return $this->buildStepResponse($userIdentifier, $context);
    }

    /**
     * Beendet den Onboarding-Prozess und speichert die Benutzerdaten.
     *
     * @param string $userIdentifier Eindeutige Benutzerkennung
     *
     * @return array Abschlussbestaetigung
     */
    public function completeOnboarding(string $userIdentifier): array
    {
        $context = $this->contextStore->loadContext($userIdentifier);

        $userProfile = $this->userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);
        if (!$userProfile) {
            $userProfile = new UserProfile();
            $userProfile->setUserIdentifier($userIdentifier);
        }

        $this->updateUserProfileFromContext($userProfile, $context);

        $userProfile->setUpdatedAt(new \DateTimeImmutable());
        $onbData = $userProfile->getOnboardingData() ?? [];
        $onbData['completed'] = true;
        $onbData['completed_at'] = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $userProfile->setOnboardingData($onbData);
        $this->userProfileRepo->save($userProfile, true);

        $context['onboarding_data']['completed_at'] = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $context['onboarding_data']['status'] = 'completed';
        $this->contextStore->saveContext($userIdentifier, $context);

        $this->currentStep = 0;

        // Onboarding-Agent (optional) ueber Abschluss informieren. Fehler
        // hier duerfen den Abschluss nicht blockieren.
        $this->notifyAgentOfCompletion($userIdentifier, $userProfile);

        return [
            'status' => 'completed',
            'message' => 'Onboarding abgeschlossen! Danke fuer deine Angaben.',
            'user_profile' => $this->getUserProfileData($userProfile),
            'onboarding_data' => $context['onboarding_data'] ?? [],
        ];
    }

    /**
     * Gibt den Onboarding-Status fuer einen Benutzer zurueck.
     *
     * @param string $userIdentifier Eindeutige Benutzerkennung
     *
     * @return array Statusinformationen
     */
    public function getOnboardingStatus(string $userIdentifier): array
    {
        $userProfile = $this->userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);

        if (!$userProfile) {
            return ['status' => 'not_started'];
        }

        $onbData = $userProfile->getOnboardingData() ?? [];
        if (($onbData['completed'] ?? false) === true) {
            return [
                'status' => 'completed',
                'completed_at' => $onbData['completed_at'] ?? null,
            ];
        }

        $context = $this->contextStore->loadContext($userIdentifier);
        if (!isset($context['onboarding_data']) || empty($context['onboarding_data'])) {
            return ['status' => 'not_started'];
        }

        return [
            'status' => 'in_progress',
            'current_step' => $context['onboarding_data']['current_step'] ?? 0,
            'onboarding_data' => $context['onboarding_data'],
        ];
    }

    /**
     * Setzt den Onboarding-Prozess zurueck.
     */
    public function resetOnboarding(string $userIdentifier): void
    {
        $this->currentStep = 0;

        $context = $this->contextStore->loadContext($userIdentifier);
        unset($context['onboarding_data']);
        $this->contextStore->saveContext($userIdentifier, $context);

        $userProfile = $this->userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);
        if ($userProfile) {
            $onbData = $userProfile->getOnboardingData() ?? [];
            $onbData['completed'] = false;
            $onbData['completed_at'] = null;
            $userProfile->setOnboardingData($onbData);
            $this->userProfileRepo->save($userProfile, true);
        }
    }

    /**
     * Startet das Onboarding neu.
     */
    public function resumeOnboarding(string $userIdentifier): array
    {
        $this->resetOnboarding($userIdentifier);

        return $this->startOnboarding($userIdentifier);
    }

    // ----------------------------------------------------------------------
    // Deterministische Schritt-Engine
    // ----------------------------------------------------------------------

    /**
     * Loest die aktuelle Schrittfolge aus dem Kontext (Basis + dynamische
     * Schnittstellen aus Use-Cases + Abschluss).
     *
     * @param array<string, mixed> $context
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveSteps(array $context): array
    {
        $useCases = $this->toArray($context['onboarding_data']['use_cases'] ?? []);

        return $this->stepProvider->allSteps($useCases, $this->requirementMapper);
    }

    /**
     * Baut die Antwort-Struktur fuer den aktuellen Schritt.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function buildStepResponse(string $userIdentifier, array $context): array
    {
        $this->currentStep = $context['onboarding_data']['current_step'] ?? 0;
        $steps = $this->resolveSteps($context);

        if (!isset($steps[$this->currentStep])) {
            return $this->completeOnboarding($userIdentifier);
        }

        $step = $steps[$this->currentStep];
        $options = $this->resolveOptions($step, $context);

        return [
            'status' => 'in_progress',
            'step_id' => $step['id'],
            'phase' => $step['phase'] ?? '',
            'current_step' => $this->currentStep,
            'total_steps' => count($steps),
            'question' => $step['question'],
            'type' => $step['type'],
            'options' => $options,
            'help' => $step['help'] ?? '',
            'required' => $step['required'] ?? true,
            'context' => $context['onboarding_data'] ?? [],
        ];
    }

    /**
     * Loest ggf. dynamische Optionen auf (z.B. Modell-Liste abhaengig vom
     * zuvor gewaehlten Anbieter).
     *
     * @param array<string, mixed> $step
     * @param array<string, mixed> $context
     *
     * @return array<int|string, string>
     */
    private function resolveOptions(array $step, array $context): array
    {
        if ($step['field'] === 'llm_model') {
            $provider = $context['onboarding_data']['llm_provider'] ?? OnboardingStepProvider::DEFAULT_PROVIDER;

            return $this->stepProvider->modelsForProvider($provider);
        }

        return $step['options'] ?? [];
    }

    /**
     * Normalisiert die Antwort je nach Schritttyp.
     *
     * @param string|array $response
     *
     * @return string|array<string>
     */
    private function normalizeResponse(string|array $response, string $type): string|array
    {
        if ($type === 'multiple_choice' && is_array($response)) {
            return array_values(array_map('strval', $response));
        }

        return is_array($response) ? $response : (string) $response;
    }

    /**
     * @param mixed $value
     *
     * @return array<string>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        return [];
    }

    /**
     * Fuehrt die Nebenwirkungen eines Schritts aus:
     *  - llm_provider / llm_model: LLM-Praeferenz im UserProfile speichern
     *  - llm_api_key: API-Key als Secret speichern
     *  - secret/email_*: entsprechende Secrets speichern
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $step
     * @param string|array          $value
     */
    private function applyStepSideEffects(string $userIdentifier, array &$context, array $step, string|array $value): void
    {
        $field = $step['field'];
        $type = $step['type'];

        // LLM-Praeferenz (Provider/Modell) im UserProfile persistieren.
        if ($field === 'llm_provider' || $field === 'llm_model') {
            $profile = $this->userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);
            if (!$profile) {
                $profile = new UserProfile();
                $profile->setUserIdentifier($userIdentifier);
            }
            if ($field === 'llm_provider') {
                $profile->setPreferredLlmProvider(is_string($value) ? $value : null);
                // Default-Modell fuer den Provider setzen, falls noch keins gewaehlt.
                if (null === $profile->getPreferredLlmModel()) {
                    $provider = is_string($value) ? $value : OnboardingStepProvider::DEFAULT_PROVIDER;
                    $models = $this->stepProvider->modelsForProvider($provider);
                    $profile->setPreferredLlmModel(array_key_first($models) ?: OnboardingStepProvider::DEFAULT_MODEL);
                }
            } else {
                $profile->setPreferredLlmModel(is_string($value) ? $value : null);
            }
            $this->userProfileRepo->save($profile, true);

            return;
        }

        // API-Keys und E-Mail-Zugangsdaten als Secret speichern.
        if ($type === 'secret') {
            $secretKey = $this->secretKeyForField($field, $context);
            $plain = is_array($value) ? (string) ($value['value'] ?? '') : (string) $value;
            if ($secretKey !== '' && $plain !== '') {
                $this->secretService->set($secretKey, $plain, $userIdentifier, 'onboarding');
            }

            return;
        }

        // E-Mail-Verbindung (SMTP/IMAP) als zusammengesetztes Secret ablegen.
        if ($type === 'email_smtp' || $type === 'email_imap') {
            $this->storeEmailConnection($userIdentifier, $type, $value, $context);

            return;
        }
    }

    /**
     * Mappt das llm_api_key-Feld auf den provider-spezifischen Secret-Namen.
     *
     * @param array<string, mixed> $context
     */
    private function secretKeyForField(string $field, array $context): string
    {
        if ($field === 'llm_api_key') {
            $provider = $context['onboarding_data']['llm_provider'] ?? OnboardingStepProvider::DEFAULT_PROVIDER;

            return $provider === 'gemini' ? 'GEMINI_API_KEY' : 'MISTRAL_API_KEY';
        }

        return $field;
    }

    /**
     * Speichert eine E-Mail-Verbindung (SMTP/IMAP) als verschluesseltes Secret.
     *
     * @param string|array $value Erwartet ein Array mit host/port/user/pass/encryption oder einen DSN-String
     * @param array<string, mixed> $context
     */
    private function storeEmailConnection(string $userIdentifier, string $type, string|array $value, array $context): void
    {
        $dsn = $this->buildMailerDsn($type, $value);
        if ($dsn === '') {
            return;
        }
        $secretKey = $type === 'email_smtp' ? 'MAILER_DSN' : 'IMAP_DSN';
        $this->secretService->set($secretKey, $dsn, $userIdentifier, 'onboarding');

        // Empfaenger-/Absenderadresse als separates Secret hinterlegen, falls
        // im Wert enthalten, damit das EmailTool eine Default-From hat.
        if (is_array($value) && !empty($value['from'])) {
            $this->secretService->set('MAILER_FROM', (string) $value['from'], $userIdentifier, 'onboarding');
        }
    }

    /**
     * Baut aus den uebergebenen Verbindungsdaten einen Mailer/IMAP-DSN.
     *
     * @param string|array $value
     */
    private function buildMailerDsn(string $type, string|array $value): string
    {
        if (is_string($value)) {
            return $this->sanitizeDsn($value);
        }

        $scheme = $type === 'email_smtp' ? 'smtp' : 'imap';
        $host = (string) ($value['host'] ?? '');
        if ($host === '') {
            return '';
        }
        $port = (string) ($value['port'] ?? ($scheme === 'smtp' ? '587' : '993'));
        $user = (string) ($value['user'] ?? '');
        $pass = (string) ($value['pass'] ?? '');
        $encryption = (string) ($value['encryption'] ?? ($scheme === 'smtp' ? 'tls' : 'ssl'));

        $credentials = '';
        if ($user !== '' && $pass !== '') {
            $credentials = rawurlencode($user) . ':' . rawurlencode($pass) . '@';
        }

        $dsn = $scheme . '://' . $credentials . $host . ':' . $port;
        if ($encryption !== '') {
            $dsn .= '?encryption=' . $encryption;
        }

        return $dsn;
    }

    /**
     * Uebernimmt einen manuell uebergebenen DSN-String, sofern er mit dem
     * erwarteten Scheme beginnt (Vermeidung von Injection fremder Schemes).
     */
    private function sanitizeDsn(string $dsn): string
    {
        $dsn = trim($dsn);
        if (preg_match('#^(smtp|imap|smtps|imaps)://#i', $dsn)) {
            return $dsn;
        }

        return '';
    }

    // ----------------------------------------------------------------------
    // Profil-Update & Agent-Benachrichtigung
    // ----------------------------------------------------------------------

    /**
     * Aktualisiert das Benutzerprofil basierend auf dem Onboarding-Kontext.
     */
    private function updateUserProfileFromContext(UserProfile $userProfile, array $context): void
    {
        $onboardingData = $context['onboarding_data'] ?? [];

        if (isset($onboardingData['user_type'])) {
            $userProfile->setUserType(is_array($onboardingData['user_type']) ? (string) ($onboardingData['user_type'][0] ?? '') : (string) $onboardingData['user_type']);
        }

        $preferences = $userProfile->getPreferences() ?? [];
        $map = [
            'user_type_detail' => 'user_type_detail',
            'technical_skills' => 'technical_skills',
            'experience_level' => 'experience_level',
            'use_cases' => 'use_cases',
            'industry' => 'industry',
            'industry_detail' => 'industry_detail',
            'response_style' => 'response_style',
            'technical_level' => 'technical_level',
            'language' => 'language',
            'notifications' => 'notifications',
            'hitl_requirements' => 'hitl_requirements',
            'security_level' => 'security_level',
        ];
        foreach ($map as $src => $dest) {
            if (isset($onboardingData[$src])) {
                $preferences[$dest] = $onboardingData[$src];
            }
        }
        if (isset($onboardingData['llm_provider'])) {
            $userProfile->setPreferredLlmProvider((string) $onboardingData['llm_provider']);
        }
        if (isset($onboardingData['llm_model'])) {
            $userProfile->setPreferredLlmModel((string) $onboardingData['llm_model']);
        }

        $preferences['_onboarding_metadata'] = [
            'onboarding_phase_3' => true,
            'onboarding_version' => '2.0',
            'onboarding_timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];
        $userProfile->setPreferences($preferences);
    }

    /**
     * Informiert den onboarding-Agent ueber den Abschluss (optional).
     */
    private function notifyAgentOfCompletion(string $userIdentifier, UserProfile $userProfile): void
    {
        try {
            $completionNotification = [
                'action' => 'onboarding_completed',
                'user_identifier' => $userIdentifier,
                'user_profile' => $this->getUserProfileData($userProfile),
                'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            ];

            $messages = new MessageBag(
                Message::ofUser(json_encode($completionNotification, \JSON_THROW_ON_ERROR))
            );

            $this->onboardingAgent->call($messages);
        } catch (\Exception $e) {
            // Abschluss-Benachrichtigung ist optional; Fehler nicht fatal.
        }
    }

    /**
     * Gibt die Benutzerprofildaten als Array zurueck.
     */
    private function getUserProfileData(UserProfile $userProfile): array
    {
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
     * Loggt einen Fehler (Bestandteil der oeffentlichen API fuer Fallbacks).
     */
    private function logError(string $message, array $context = []): void
    {
        // Fehler werden nicht weiter gereicht; deterministischer Flow braucht
        // kein Logging-Backend, um den Abschluss nicht zu gefaehrden.
    }
}
