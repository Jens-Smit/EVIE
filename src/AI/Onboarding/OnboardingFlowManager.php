<?php

declare(strict_types=1);

namespace App\AI\Onboarding;

use App\AI\Platform\TenantPlatformContext;
use App\AI\Skills\ToolDefinitionGenerator;
use App\Entity\UserProfile;
use App\Event\PendingToolApprovalEvent;
use App\Repository\UserProfileRepository;
use App\Service\ApiKeyValidator;
use App\Service\SecretService;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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
    private ?ApiKeyValidator $apiKeyValidator;
    private OnboardingStrategyService $strategyService;
    private OnboardingReadinessChecker $readinessChecker;
    private EventDispatcherInterface $eventDispatcher;
    private ?ToolDefinitionGenerator $toolDefinitionGenerator = null;
    private ?TenantPlatformContext $tenantPlatformContext = null;

    /**
     * @param ContextStoreManager           $contextStore       Verwaltet den Benutzerkontext
     * @param UserProfileRepository         $userProfileRepo     Repository fuer Benutzerprofile
     * @param AgentInterface                 $onboardingAgent     Onboarding-Agent (Chat-Dialogpartner, Strategie-Entwuerfe)
     * @param OnboardingStepProvider        $stepProvider        Deterministische Schrittfolge
     * @param IntegrationRequirementMapper  $requirementMapper  Leitet Use-Cases auf Schnittstellen ab (Fallback)
     * @param SecretService                 $secretService      Verschluesselte Ablage von API-Keys/E-Mail-Zugangsdaten
     * @param OnboardingStrategyService     $strategyService    Strategie-Entwurf/Persistenz + Sub-Agent-Anlage
     * @param OnboardingReadinessChecker    $readinessChecker  Konsistenzpruefung vor Abschluss
     * @param TenantPlatformContext|null    $tenantPlatformContext Tenant-Kontext fuer pro-Tenant LLM-Keys (optional, Tests)
     */
    public function __construct(
        ContextStoreManager $contextStore,
        UserProfileRepository $userProfileRepo,
        AgentInterface $onboardingAgent,
        OnboardingStepProvider $stepProvider,
        IntegrationRequirementMapper $requirementMapper,
        SecretService $secretService,
        OnboardingStrategyService $strategyService,
        OnboardingReadinessChecker $readinessChecker,
        EventDispatcherInterface $eventDispatcher,
        ?ToolDefinitionGenerator $toolDefinitionGenerator = null,
        ?ApiKeyValidator $apiKeyValidator = null,
        ?TenantPlatformContext $tenantPlatformContext = null
    ) {
        $this->contextStore = $contextStore;
        $this->userProfileRepo = $userProfileRepo;
        $this->onboardingAgent = $onboardingAgent;
        $this->stepProvider = $stepProvider;
        $this->requirementMapper = $requirementMapper;
        $this->secretService = $secretService;
        $this->strategyService = $strategyService;
        $this->readinessChecker = $readinessChecker;
        $this->eventDispatcher = $eventDispatcher;
        $this->toolDefinitionGenerator = $toolDefinitionGenerator;
        $this->apiKeyValidator = $apiKeyValidator;
        $this->tenantPlatformContext = $tenantPlatformContext;
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
        $context = $this->contextStore->loadContext($userIdentifier);
        if (!isset($context['onboarding_data'])) {
            $context['onboarding_data'] = [
                'started_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                'phase_3_optimized' => true,
                'prompt_version' => '2.0',
            ];
        }
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

        $steps = $this->resolveSteps($context);
        $currentStepDef = $this->firstUnansweredStep($steps, $context['onboarding_data']);

        if ($currentStepDef === null) {
            return $this->completeOnboarding($userIdentifier);
        }

        $field = $currentStepDef['field'];
        $type = $currentStepDef['type'];

        // Antwort normalisieren. Secret-Werte (z.B. API-Keys, E-Mail-
        // Passwoerter) werden NIE im Klartext im onboarding_data abgelegt:
        // Der Kontext wird u.a. in LLM-System-Prompts und Chat-Antworten
        // serialisiert (Audit: keine Secrets in Logs/Kontext). Die Werte
        // landen ausschliesslich verschluesselt im SecretService (siehe
        // applyStepSideEffects unten); im Kontext wird nur ein boolescher
        // Hinterlegt-Marker bzw. eine passwortbereinigte Kopie gespeichert,
        // damit der Readiness-Checker das Pflichtfeld weiter pruefen kann.
        $normalized = $this->normalizeResponse($response, $type);
        $this->applyStepSideEffects($userIdentifier, $context, $currentStepDef, $normalized);

        if ($type === 'secret') {
            $plain = is_array($normalized) ? (string) ($normalized['value'] ?? '') : (string) $normalized;
            $context['onboarding_data'][$field] = $plain !== '';
            $context['onboarding_data']['step_' . $field] = [
                'response' => $plain !== '' ? '(secret stored)' : '',
                'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            ];
        } elseif ($type === 'email_combined' && is_array($normalized)) {
            $sanitized = $normalized;
            $sanitized['smtp_pass'] = '';
            $sanitized['imap_pass'] = '';
            $context['onboarding_data'][$field] = $sanitized;
            $context['onboarding_data']['step_' . $field] = [
                'response' => $sanitized,
                'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            ];
        } else {
            $context['onboarding_data'][$field] = $normalized;
            $context['onboarding_data']['step_' . $field] = [
                'response' => $normalized,
                'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            ];
        }

        // use_cases als Liste sicherstellen, damit der RequirementMapper
        // die Schnittstellen-Schritte ableiten kann.
        if ($field === 'use_cases') {
            $context['onboarding_data']['use_cases'] = $this->toArray($normalized);
        }

        $this->contextStore->saveContext($userIdentifier, $context);

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

        return $this->buildStepResponse($userIdentifier, $context);
    }

    /**
     * LLM-gestuetzter Chat im Onboarding-Modus: Die Antwort des Onboarding-
     * Agents wird zurueckgegeben und parallel werden daraus strukturiert
     * gewonnene Erkenntnisse (Ziel, Branche, Bereiche, Use-Cases, Mission)
     * in den Onboarding-Kontext uebernommen - ohne einen Wizard-Schritt zu
     * konsumieren (G1/G6).
     *
     * @param string $userIdentifier Eindeutige Benutzerkennung (nur aus Auth)
     * @param string $message       Nutzer-Nachricht
     *
     * @return array{response: string, extracted: array<string, mixed>, step: array<string, mixed>}
     */
    public function chat(string $userIdentifier, string $message): array
    {
        $context = $this->contextStore->loadContext($userIdentifier);
        $onboardingData = $context['onboarding_data'] ?? [];

        $systemPrompt = $this->buildOnboardingChatSystemPrompt($onboardingData);
        $extractPayload = json_encode([
            'action' => 'extract_onboarding_context',
            'user_message' => $message,
            'known_context' => $onboardingData,
            'response_format' => [
                'message' => 'string: freundliche Rueckfrage/Antwort an den Nutzer',
                'extracted' => 'object mit nur bekannten Feldern: goal (manage_company|assist_work|other), industry, business_areas (string[]), use_cases (string[]), mission_statement (string)',
            ],
        ], \JSON_THROW_ON_ERROR);

        $responseText = '';
        $extracted = [];
        try {
            $messages = new MessageBag(
                Message::forSystem($systemPrompt),
                Message::ofUser($extractPayload)
            );
            $result = $this->withTenantContext($userIdentifier, fn () => $this->onboardingAgent->call($messages));
            $decoded = $this->decodeLlmJson($result->getContent());
            if (is_array($decoded)) {
                $responseText = (string) ($decoded['message'] ?? '');
                if (isset($decoded['extracted']) && is_array($decoded['extracted'])) {
                    $extracted = $decoded['extracted'];
                }
            } else {
                $responseText = $result->getContent();
            }
        } catch (\Throwable $e) {
            $responseText = 'Der Onboarding-Assistent ist gerade nicht erreichbar. Deine Angaben im Wizard werden weiterhin gespeichert.';
        }

        if (trim($responseText) === '') {
            $responseText = 'Danke! Ich habe deine Angaben aufgenommen. Weiter im Wizard oder schreib mir einfach mehr.';
        }

        // Deterministischer Fallback (keine Halluzination): Wenn noch keine
        // Mission im Kontext liegt und das LLM kein mission_statement extra-
        // hiert, uebernimmt die erste substanzielle Nutzernachricht selbst
        // als mission_statement - exakt in den Worten des Nutzers. Kurze
        // Begruesungen/Rueckfragen (unter 20 Zeichen) werden nicht als
        // Aufgabe fehlinterpretiert.
        if (trim((string) ($extracted['mission_statement'] ?? '')) === ''
            && trim((string) ($onboardingData['mission_statement'] ?? '')) === ''
            && mb_strlen(trim($message)) >= 20) {
            $extracted['mission_statement'] = trim($message);
        }

        $ingested = $this->ingestExtracted($userIdentifier, $extracted);

        return [
            'response' => $responseText,
            'extracted' => $ingested,
            'step' => $this->getNextStep($userIdentifier),
        ];
    }

    /**
     * Uebernimmt strukturiert extrahierte Erkenntnisse aus dem Chat in den
     * Onboarding-Kontext, ohne einen Wizard-Schritt zu konsumieren. Nur
     * bekannte Felder werden uebernommen (keine Halluzination in der
     * Schrittlogik). Mission/Profil fuellen dieselben Kontextfelder wie
     * processResponse(), sodass der Wizard sofort weiterspringen kann.
     *
     * @param string                $userIdentifier Eindeutige Benutzerkennung
     * @param array<string, mixed>  $extracted      z.B. aus der LLM-Extraktion
     *
     * @return array<string, mixed> Die tatsaechlich uebernommenen Felder
     */
    public function ingestExtracted(string $userIdentifier, array $extracted): array
    {
        $context = $this->contextStore->loadContext($userIdentifier);
        if (!isset($context['onboarding_data'])) {
            $context['onboarding_data'] = [
                'started_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                'phase_3_optimized' => true,
                'prompt_version' => '2.0',
            ];
        }

        $ingested = [];
        $data = &$context['onboarding_data'];

        $goal = $extracted['goal'] ?? null;
        if (is_string($goal) && in_array($goal, ['manage_company', 'assist_work', 'other'], true)) {
            $data['goal'] = $goal;
            $ingested['goal'] = $goal;
        }

        $industry = $extracted['industry'] ?? null;
        if (is_string($industry) && $industry !== '') {
            $data['industry'] = $industry;
            $ingested['industry'] = $industry;
        }

        $areas = $this->stringListValue($extracted['business_areas'] ?? null);
        if ($areas !== []) {
            $data['business_areas'] = $areas;
            $ingested['business_areas'] = $areas;
        }

        $useCases = $this->stringListValue($extracted['use_cases'] ?? null);
        if ($useCases !== []) {
            $data['use_cases'] = $useCases;
            $ingested['use_cases'] = $useCases;
        }

        $mission = $extracted['mission_statement'] ?? null;
        if (is_string($mission) && trim($mission) !== '') {
            $data['mission_statement'] = trim($mission);
            $ingested['mission_statement'] = trim($mission);
        }

        unset($data);

        if ($ingested !== []) {
            $data2 = $context['onboarding_data'];
            $data2['chat_extracted_at'] = (new \DateTimeImmutable())->format(\DATE_ATOM);
            $context['onboarding_data'] = $data2;
            $this->contextStore->saveContext($userIdentifier, $context);
        }

        return $ingested;
    }

    /**
     * System-Prompt fuer den Onboarding-Chat: Basis-Instruktion plus
     * aktueller onboarding_data-Kontext, damit der Agent den Verlauf kennt.
     *
     * @param array<string, mixed> $onboardingData
     */
    private function buildOnboardingChatSystemPrompt(array $onboardingData): string
    {
        $prompt = "Du bist der Onboarding-Assistent von EVIE. Du begleitest den Nutzer beim Einsetzen von EVIE.\n"
            . "Stelle Rueckfragen zum Einsatz (Branche, Bereiche, Use-Cases, konkrete Aufgaben), sei kurz und freundlich.\n"
            . "Erfinde keine Fakten ueber den Nutzer.\n\n";

        $known = json_encode($onboardingData, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return $prompt . "## Aktueller Onboarding-Kontext:\n" . $known . "\n";
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
            'onboarding_data' => $context['onboarding_data'],
            'readiness' => $this->readinessChecker->check($context['onboarding_data']),
        ];
    }

    /**
     * Liefert die Readiness-Pruefung (Konsistenz-Checkliste) fuer den
     * aktuellen Kontext, ohne den Status-Endpunkt zu veraendern.
     *
     * @return array{ready: bool, checks: array<int, array<string, mixed>>, missing_blocking: array<int, string>}
     */
    public function getReadiness(string $userIdentifier): array
    {
        $context = $this->contextStore->loadContext($userIdentifier);

        return $this->readinessChecker->check($context['onboarding_data'] ?? []);
    }

    /**
     * Setzt den Onboarding-Prozess zurueck.
     */
    public function resetOnboarding(string $userIdentifier): void
    {
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
        return $this->stepProvider->allSteps($context['onboarding_data'] ?? [], $this->requirementMapper);
    }

    /**
     * Bestimmt den naechsten unbeantworteten Schritt anhand des onboarding-
     * Kontexts. Da die Schrittfolge dynamisch ist (verzweigend, schrumpfend),
     * kann nicht mit einem festen numerischen Index gearbeitet werden: ein
     * beantworteter Schritt faellt aus der Liste, und der Index wuerde sich
     * verschieben. Stattdessen wird der erste Schritt geliefert, dessen
     * Feld im Kontext noch nicht gesetzt ist.
     *
     * @param array<int, array<string, mixed>> $steps
     * @param array<string, mixed>             $onboardingData
     *
     * @return array<string, mixed>|null
     */
    private function firstUnansweredStep(array $steps, array $onboardingData): ?array
    {
        foreach ($steps as $step) {
            $field = $step['field'] ?? null;
            $id = $step['id'] ?? null;
            if ($field === null || $id === null) {
                continue;
            }
            // Der summary-Schritt ist der Abschluss; er gilt als unbeantwortet,
            // bis er bestaetigt wird (Feld 'summary' wird auf 'confirm' gesetzt).
            // email_account-Schritte sind pro Bereich wiederholbar; sie gelten
            // als beantwortet, sobald der Bereich konfiguriert (email_configured_areas)
            // oder explizit uebersprungen (email_skipped_areas) wurde.
            if ($field === 'email_account' && isset($step['area'])) {
                $configured = $this->toArray($onboardingData['email_configured_areas'] ?? []);
                $skipped = $this->toArray($onboardingData['email_skipped_areas'] ?? []);
                if (in_array($step['area'], $configured, true) || in_array($step['area'], $skipped, true)) {
                    continue;
                }
                return $step;
            }
            if (!array_key_exists($field, $onboardingData)) {
                return $step;
            }
        }

        return null;
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
        // Phase C: Strategie-Vorschlag on-demand erzeugen, sobald die
        // Aufgabenbeschreibung vorliegt (LLM mit Heuristik-Fallback).
        $this->ensureStrategyDraft($userIdentifier, $context);

        $steps = $this->resolveSteps($context);
        $onboardingData = $context['onboarding_data'] ?? [];

        $step = $this->firstUnansweredStep($steps, $onboardingData);
        if ($step === null) {
            return $this->completeOnboarding($userIdentifier);
        }

        // Position des aktuellen Schritts fuer die Fortschrittsanzeige (1-basiert).
        $currentPosition = 0;
        foreach ($steps as $idx => $s) {
            if (($s['id'] ?? null) === ($step['id'] ?? null)) {
                $currentPosition = $idx;
                break;
            }
        }

        $options = $this->resolveOptions($step, $context);

        return [
            'status' => 'in_progress',
            'step_id' => $step['id'],
            'phase' => $step['phase'] ?? '',
            'current_step' => $currentPosition,
            'total_steps' => count($steps),
            'question' => $step['question'],
            'type' => $step['type'],
            'options' => $options,
            'help' => $step['help'] ?? '',
            'required' => $step['required'] ?? true,
            'area' => $step['area'] ?? null,
            'tool_id' => $step['tool_id'] ?? null,
            'allow_freetext' => $step['allow_freetext'] ?? false,
            'strategy_draft' => $context['onboarding_data']['strategy_draft'] ?? null,
            'sub_agent_catalog' => ($step['type'] === 'sub_agent_review') ? OnboardingStrategyService::SUB_AGENT_CATALOG : null,
            'tools' => $context['onboarding_data']['tools'] ?? null,
            'readiness' => $this->readinessChecker->check($context['onboarding_data'] ?? []),
            'context' => $context['onboarding_data'] ?? [],
        ];
    }

    /**
     * Erzeugt - falls noetig - den Strategie-Vorschlag fuer den naechsten
     * strategy_review-Schritt und legt ihn im Kontext ab, damit das Frontend
     * ihn anzeigen kann (Phase C). Nur bei mission_statement und ohne
     * bestaetigte Strategie.
     *
     * @param array<string, mixed> $context
     */
    private function ensureStrategyDraft(string $userIdentifier, array &$context): void
    {
        $onboardingData = $context['onboarding_data'] ?? [];
        $mission = trim((string) ($onboardingData['mission_statement'] ?? ''));
        if ($mission === '' || ($onboardingData['strategy_confirmed'] ?? false) === true || isset($onboardingData['strategy_draft'])) {
            return;
        }

        $draft = $this->strategyService->draftStrategy($userIdentifier, $onboardingData);
        $context['onboarding_data']['strategy_draft'] = $draft;
        $this->contextStore->saveContext($userIdentifier, $context);
    }

    /**
     * Validiert und normalisiert Sub-Agent-Namen aus einer Nutzerantwort
     * (confirm oder Freitext-Korrekturen). Nur Katalog-Rollen sind erlaubt.
     *
     * @param array<string, mixed> $strategy
     *
     * @return array<int, string>
     */
    private function resolveSubAgentSelection(array $strategy, string|array $value): array
    {
        $recommended = [];
        foreach (($strategy['sub_agents'] ?? []) as $name) {
            if (is_string($name) && isset(OnboardingStrategyService::SUB_AGENT_CATALOG[$name])) {
                $recommended[] = $name;
            }
        }

        if (is_string($value) && strtolower(trim($value)) === 'confirm') {
            return array_values(array_unique($recommended));
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $keep = $recommended;
        $lowered = strtolower($value);
        foreach ($recommended as $name) {
            if (str_contains($lowered, $name) || str_contains($lowered, str_replace('_', ' ', $name))) {
                continue;
            }
        }

        return array_values(array_unique($keep));
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

        if ($type === 'multiselect') {
            // multiselect kann ein Array sein oder ein String mit Komma-Separation.
            // Freitext-Zusaetze aus dem Template werden als 'freetext' mitgegeben.
            if (is_array($response)) {
                $items = array_values(array_map('strval', $response));
            } else {
                $items = array_values(array_filter(array_map('trim', explode(',', (string) $response)), fn ($v) => $v !== ''));
            }

            return $items;
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
                $this->validateApiKeyBeforeStore($field, $secretKey, $plain, $context);
                $scope = 'onboarding';
                if (isset($step['tool_id'])) {
                    $scope = 'tool:' . (int) $step['tool_id'];
                    $this->markToolSecretDone($context, (int) $step['tool_id'], $secretKey);
                }
                $this->secretService->set($secretKey, $plain, $userIdentifier, $scope);
            }

            return;
        }

        // E-Mail-Verbindung (SMTP/IMAP) als zusammengesetztes Secret ablegen.
        if ($type === 'email_smtp' || $type === 'email_imap') {
            $this->storeEmailConnection($userIdentifier, $type, $value, $context);

            return;
        }

        // Kombinierte E-Mail-Maske (SMTP + IMAP in einem Schritt). Leitet beide
        // DSNs ab und speichert sie als Secret: Haupt-Adresse mit Scope
        // email:main (Standard-Key-Namen), Bereichsadressen mit Scope
        // email:{area} und Key-Suffix _AREA (ueberschreiben sich nicht).
        // Jede Maske ist optional: leere Antworten werden nicht als Secret
        // gespeichert, sondern nur als beantwortet bzw. uebersprungen markiert.
        if ($type === 'email_combined') {
            $area = $step['area'] ?? null;
            if ($value === '' || $value === []) {
                if ($area !== null) {
                    $skipped = $this->toArray($context['onboarding_data']['email_skipped_areas'] ?? []);
                    if (!in_array($area, $skipped, true)) {
                        $skipped[] = $area;
                        $context['onboarding_data']['email_skipped_areas'] = $skipped;
                    }
                }

                return;
            }

            $this->storeEmailCombined($userIdentifier, $value, $area, $context);

            return;
        }

        // Strategie-Review (Phase C): Vorschlag bestaetigen oder per Freitext
        // korrigieren; danach als initiale aktive AgentGoal persistieren.
        if ($type === 'strategy_review') {
            $this->applyStrategyConfirmation($userIdentifier, $context, $value);

            return;
        }

        // Sub-Agent-Review (Phase D.1): bestätigte Rollen aus dem Katalog als
        // Tenant-Instanzen anlegen (SubAgentDefinition, isActive=true).
        if ($type === 'sub_agent_review') {
            $this->applySubAgentConfirmation($userIdentifier, $context, $value);

            return;
        }

        // Tool-Review (Phase D.2): erzeugte Tool-Definitionen bestätigen; die
        // Tools bleiben status=pending mit requiresHitl=true (Freigabe nur im
        // Frontend) und melden ihre requiredSecrets fuer Phase E.
        if ($type === 'tool_review') {
            $this->applyToolConfirmation($userIdentifier, $context, $value);

            return;
        }
    }

    /**
     * Phase C: Verarbeitet die Bestaetigung/Korrektur des Strategie-
     * Vorschlags. 'confirm' uebernimmt den Draft; Freitext wird als
     * Korrektur in goal/description uebernommen. Persistiert danach die
     * initiale aktive AgentGoal (source: onboarding).
     *
     * @param array<string, mixed> $context
     * @param string|array         $value
     */
    private function applyStrategyConfirmation(string $userIdentifier, array &$context, string|array $value): void
    {
        $draft = $context['onboarding_data']['strategy_draft'] ?? null;
        if (!is_array($draft)) {
            return;
        }

        if (is_string($value) && strtolower(trim($value)) !== 'confirm' && trim($value) !== '') {
            $draft['goal'] = trim($value);
        }

        $context['onboarding_data'] = $this->strategyService->persistStrategy($userIdentifier, $context['onboarding_data'], $draft);
        unset($context['onboarding_data']['strategy_draft']);

        // Phase D.2 vorbereiten: Capability-Lücken ohne deckenden Katalog-
        // Sub-Agenten als pending ToolDefinition erzeugen (exakt der Pfad,
        // den Pipeline-Phase 4 geht: ToolDefinitionGenerator + HITL-Event).
        $this->generateToolsForStrategy($userIdentifier, $context);
    }

    /**
     * Erzeugt fuer Capability-Deskriptoren der Strategie, die kein statischer
     * Katalog-Sub-Agent deckt, ToolDefinition-Eintraege ueber den bestehenden
     * ToolDefinitionGenerator (status pending, requiresHitl true). Die
     * erzeugten Tools werden mit ihren requiredSecrets im Kontext registriert
     * und im tool_review-Schritt angezeigt. Der HITL-Freigabe-Pfad bleibt
     * unveraendert (Frontend-Freigabe nach Abschluss).
     *
     * @param array<string, mixed> $context
     */
    private function generateToolsForStrategy(string $userIdentifier, array &$context): void
    {
        if ($this->toolDefinitionGenerator === null) {
            return;
        }

        $strategy = $context['onboarding_data']['strategy'] ?? [];
        $capabilities = $this->stringListValue($strategy['capabilities'] ?? []);
        $coveredSubAgents = $this->stringListValue($strategy['sub_agents'] ?? []);

        // Deterministische Faehigkeits->Tool-Zuordnung (keine Halluzination):
        // nur bekannte Faehigkeiten erzeugen ein Tool mit definiertem Secret.
        $toolMatrix = [
            'research' => ['tool' => 'web_research', 'secrets' => ['TAVILY_API_KEY']],
            'business_automation' => ['tool' => 'email_automation', 'secrets' => []],
            'data_analysis' => ['tool' => 'data_analysis', 'secrets' => []],
            'document_processing' => ['tool' => 'document_processing', 'secrets' => []],
        ];

        $tools = [];
        foreach ($capabilities as $capability) {
            $normalized = strtolower(trim((string) $capability));
            if (str_starts_with($normalized, 'business_area:')) {
                continue;
            }
            $spec = $toolMatrix[$normalized] ?? null;
            if ($spec === null) {
                continue;
            }

            try {
                $definition = $this->toolDefinitionGenerator->generateToolDefinition(
                    $spec['tool'],
                    sprintf('Onboarding: Faehigkeit "%s" fuer die Strategie "%s".', $capability, (string) ($strategy['title'] ?? '')),
                    [
                        'user_identifier' => $userIdentifier,
                        'original_request' => (string) ($strategy['goal'] ?? ''),
                        'source' => 'onboarding',
                    ]
                );
            } catch (\Throwable $e) {
                continue;
            }

            if ($definition->getUserIdentifier() === null || $definition->getUserIdentifier() === '') {
                $definition->setUserIdentifier($userIdentifier);
            }
            $definition->setRequiresHitl(true);
            $definition->setStatus('pending');

            $executorConfig = $definition->getExecutorConfig() ?? [];
            $executorConfig['requiredSecrets'] = $spec['secrets'];
            $definition->setExecutorConfig($executorConfig);

            $this->eventDispatcher->dispatch(new PendingToolApprovalEvent($definition, $userIdentifier));

            $tools[] = [
                'id' => $definition->getId(),
                'name' => $definition->getName(),
                'description' => $definition->getDescription(),
                'security_level' => $definition->getSecurityLevel(),
                'status' => $definition->getStatus(),
                'requires_hitl' => $definition->getRequiresHitl(),
                'required_secrets' => $spec['secrets'],
            ];
        }

        if ($tools !== []) {
            $context['onboarding_data']['tools'] = $tools;
            $context['onboarding_data']['tools_created'] = true;
        }
    }

    /**
     * Phase D.1: Bestaetigt die empfohlenen Sub-Agenten und legt sie an.
     *
     * @param array<string, mixed> $context
     * @param string|array         $value
     */
    private function applySubAgentConfirmation(string $userIdentifier, array &$context, string|array $value): void
    {
        $strategy = $context['onboarding_data']['strategy'] ?? [];
        $selected = $this->resolveSubAgentSelection($strategy, $value);

        $created = $this->strategyService->ensureSubAgents($selected);
        $context['onboarding_data']['sub_agents_created'] = $created;
        $context['onboarding_data']['sub_agents_confirmed'] = true;
    }

    /**
     * Markiert einen tool-spezifischen Secret-Schritt als erledigt, damit der
     * naechste Credential-Schritt (Phase E) erscheint.
     *
     * @param array<string, mixed> $context
     */
    private function markToolSecretDone(array &$context, int $toolId, string $secretKey): void
    {
        $toolSecrets = $context['onboarding_data']['tool_secrets'] ?? [];
        if (!is_array($toolSecrets)) {
            return;
        }
        foreach ($toolSecrets as $idx => $entry) {
            if ((int) ($entry['tool_id'] ?? 0) === $toolId && ($entry['key'] ?? '') === $secretKey) {
                $toolSecrets[$idx]['done'] = true;
            }
        }
        $context['onboarding_data']['tool_secrets'] = $toolSecrets;
    }

    /**
     * Phase D.2: Bestaetigt die erzeugten Tool-Definitionen. Die Tools bleiben
     * pending (HITL-Freigabe im Frontend nach Abschluss); die benoetigten
     * Secrets werden fuer Phase E als tool_secrets im Kontext registriert.
     *
     * @param array<string, mixed> $context
     * @param string|array         $value
     */
    private function applyToolConfirmation(string $userIdentifier, array &$context, string|array $value): void
    {
        $tools = $context['onboarding_data']['tools'] ?? [];
        if (!is_array($tools) || $tools === []) {
            $context['onboarding_data']['tools_confirmed'] = true;

            return;
        }

        $toolSecrets = [];
        foreach ($tools as $tool) {
            $toolId = (int) ($tool['id'] ?? 0);
            $requiredSecrets = $this->stringListValue($tool['required_secrets'] ?? []);
            foreach ($requiredSecrets as $secretKey) {
                $toolSecrets[] = [
                    'tool_id' => $toolId,
                    'key' => $secretKey,
                    'label' => (string) ($tool['name'] ?? $secretKey),
                    'done' => false,
                ];
            }
        }

        if ($toolSecrets !== []) {
            $context['onboarding_data']['tool_secrets'] = $toolSecrets;
        }
        $context['onboarding_data']['tools_confirmed'] = true;
    }

    /**
     * @param mixed $value
     *
     * @return array<int, string>
     */
    private function stringListValue(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), static fn (string $v) => $v !== ''));
        }
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        return [];
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
     * Validiert einen API-Key gegen die echte Anbieter-API, bevor er als
     * Secret gespeichert wird. Bei einem eindeutig ungueltigen Key (HTTP
     * 401/403) wird eine InvalidApiKeyException geworfen, sodass der
     * Onboarding-Schritt nicht fortgesetzt wird. Netzwerk-/Verbindungs-
     * fehler blockieren den Flow nicht (siehe ApiKeyValidator).
     *
     * @param array<string, mixed> $context
     *
     * @throws \App\AI\Onboarding\Exception\InvalidApiKeyException
     */
    private function validateApiKeyBeforeStore(string $field, string $secretKey, string $plain, array $context): void
    {
        if ($this->apiKeyValidator === null) {
            return;
        }

        $provider = match ($secretKey) {
            'MISTRAL_API_KEY' => 'mistral',
            'GEMINI_API_KEY' => 'gemini',
            'TAVILY_API_KEY' => 'tavily',
            default => null,
        };

        if ($provider === null) {
            return;
        }

        $result = $this->apiKeyValidator->validate($provider, $plain);
        if (!($result['valid'] ?? false)) {
            throw new Exception\InvalidApiKeyException($result['message'] ?? 'API-Key ist ungueltig.');
        }
    }

    /**
     * Speichert eine kombinierte E-Mail-Verbindung (SMTP + IMAP) aus einer
     * Maske als verschluesselte Secrets. Die Haupt-Adresse wird mit den
     * Standard-Key-Namen (MAILER_DSN/IMAP_DSN/MAILER_FROM) und Scope
     * email:main gespeichert; Bereichsadressen mit Key-Suffix _AREA und
     * Scope email:{area}, damit sich mehrere Adressen nicht ueberschreiben
     * (SecretService keyed nach keyName je Tenant).
     *
     * @param string|array $value Array mit smtp.* und imap.* Schluesseln
     * @param array<string, mixed> $context
     */
    private function storeEmailCombined(string $userIdentifier, string|array $value, ?string $area, array &$context): void
    {
        if (!is_array($value)) {
            return;
        }

        $suffix = $area !== null ? '_' . strtoupper($area) : '';
        $scope = $area !== null ? 'email:' . $area : 'email:main';

        $smtpHost = (string) ($value['smtp_host'] ?? $value['host'] ?? '');
        if ($smtpHost !== '') {
            $smtpDsn = $this->buildMailerDsn('email_smtp', [
                'host' => $smtpHost,
                'port' => $value['smtp_port'] ?? $value['port'] ?? '587',
                'user' => $value['smtp_user'] ?? $value['user'] ?? '',
                'pass' => $value['smtp_pass'] ?? $value['pass'] ?? '',
                'encryption' => $value['smtp_encryption'] ?? $value['encryption'] ?? 'tls',
            ]);
            if ($smtpDsn !== '') {
                $this->secretService->set('MAILER_DSN' . $suffix, $smtpDsn, $userIdentifier, $scope);
            }
        }

        $imapHost = (string) ($value['imap_host'] ?? '');
        if ($imapHost !== '') {
            $imapDsn = $this->buildMailerDsn('email_imap', [
                'host' => $imapHost,
                'port' => $value['imap_port'] ?? '993',
                'user' => $value['imap_user'] ?? $value['smtp_user'] ?? $value['user'] ?? '',
                'pass' => $value['imap_pass'] ?? $value['smtp_pass'] ?? $value['pass'] ?? '',
                'encryption' => $value['imap_encryption'] ?? 'ssl',
            ]);
            if ($imapDsn !== '') {
                $this->secretService->set('IMAP_DSN' . $suffix, $imapDsn, $userIdentifier, $scope);
            }
        }

        $from = (string) ($value['from'] ?? $value['smtp_user'] ?? '');
        if ($from !== '') {
            $this->secretService->set('MAILER_FROM' . $suffix, $from, $userIdentifier, $scope);
        }

        // Bereich als konfiguriert markieren, damit der naechste E-Mail-Schritt
        // fuer den folgenden Bereich erscheint.
        if ($area !== null) {
            $configured = $this->toArray($context['onboarding_data']['email_configured_areas'] ?? []);
            if (!in_array($area, $configured, true)) {
                $configured[] = $area;
                $context['onboarding_data']['email_configured_areas'] = $configured;
            }
        }
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
     * Fuehrt einen Onboarding-Agent-Aufruf im Tenant-Kontext aus, damit
     * TenantAwarePlatform/TenantAwareEmbeddingService den pro-Tenant-
     * Mistral-Key aus dem SecretService nutzen (analog
     * OrchestratorDialogService::ask()). Ohne Kontext-Service (Unit-Tests)
     * wird der Aufruf unverändert ausgefuehrt.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    private function withTenantContext(string $userIdentifier, callable $callback): mixed
    {
        if ($this->tenantPlatformContext === null) {
            return $callback();
        }

        $this->tenantPlatformContext->setUserIdentifier($userIdentifier);
        try {
            return $callback();
        } finally {
            $this->tenantPlatformContext->clear();
        }
    }

    /**
     * Dekodiert die LLM-Antwort als JSON. Echte Modelle umschliessen JSON
     * haeufig mit Markdown-Code-Fences (```json ... ```); diese werden
     * vor dem Parsen entfernt, damit die Chat-Extraktion nicht still-
     * schweigend leer bleibt. Ungueltiges JSON liefert null (Aufrufer
     * faellt dann auf den Rohtext zurueck).
     */
    private function decodeLlmJson(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }
        $firstBrace = strpos($content, '{');
        $lastBrace = strrpos($content, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $content = substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
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

            $this->withTenantContext($userIdentifier, fn () => $this->onboardingAgent->call($messages));
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
}
