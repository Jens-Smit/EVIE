<?php

declare(strict_types=1);

namespace App\AI\Onboarding;

use App\Entity\AgentGoal;
use App\Entity\SubAgentDefinition;
use App\Repository\AgentGoalRepository;
use App\Repository\SubAgentDefinitionRepository;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * OnboardingStrategyService - erzeugt aus dem Onboarding-Brainstorming
 * (mission_statement + Profil) die initiale Strategie des Tenants.
 *
 * Der deterministische Kern bleibt: Der Service erzeugt einen Struktur-
 * Vorschlag (Ziel, Teilschritte, Erfolgsmetrik, Capability-Deskriptoren),
 * den der Nutzer im strategy_review-Schritt bestaetigt oder korrigiert.
 * Das LLM liefert nur Vorschlaege; ohne erreichbares LLM greift eine
 * heuristische Variante (keine Halluzination, keine Fantasie-Objekte).
 *
 * Persistiert wird nach Nutzerbestaetigung:
 *  - eine initiale AgentGoal (status active, source onboarding)
 *  - der Strategie-Entwurf im onboarding_data['strategy']-Kontext
 *
 * Sub-Agenten und Tools werden NICHT erfunden: Der Service empfiehlt nur
 * Rollen aus dem existierenden Sub-Agent-Katalog (ai.yaml) und delegiert
 * die Tool-Erzeugung an den bestehenden ToolDefinitionGenerator-Pfad.
 */
final class OnboardingStrategyService
{
    /**
     * Statischer Sub-Agent-Katalog aus config/packages/ai.yaml.
     * Nur diese Rollen duerfen empfohlen und instanziiert werden
     * (Blueprint: keine Fantasie-Objekte zur Laufzeit erfinden).
     */
    public const SUB_AGENT_CATALOG = [
        'website_researcher' => 'Webseiten-Recherche und Inhaltszusammenfassung',
        'data_analyst' => 'Datenanalyse und statistische Auswertungen',
        'code_assistant' => 'Code-Analyse, Generierung und Review',
        'document_processor' => 'Dokumentenverarbeitung (PDF, Excel, etc.)',
        'communication_manager' => 'E-Mails, LinkedIn, Slack und andere Kommunikation',
        'api_integration' => 'API-Anbindungen, OAuth, REST, GraphQL',
        'project_manager' => 'Projektmanagement und Aufgabenverwaltung',
        'finance_manager' => 'Buchhaltung, Rechnungen, Finanzen',
        'hr_manager' => 'Personalwesen und Mitarbeiterverwaltung',
        'marketing_manager' => 'Marketing, Kampagnen, Social Media',
        'ceo_assistant' => 'Strategie, Planung und Entscheidungen',
        'tool_generator' => 'Erstellung neuer Tool-Schemata',
        'onboarding' => 'Benutzer-Onboarding',
    ];

    /**
     * Deterministches Mapping: Use-Case-/Bereichs-IDs aus dem Onboarding
     * auf Sub-Agent-Rollen des existierenden Katalogs.
     */
    private const USE_CASE_SUB_AGENTS = [
        'research' => ['website_researcher'],
        'code_generation' => ['code_assistant'],
        'code_review' => ['code_assistant'],
        'data_analysis' => ['data_analyst'],
        'document_processing' => ['document_processor'],
        'business_automation' => ['communication_manager', 'api_integration'],
        'project_management' => ['project_manager'],
        'system_administration' => ['api_integration'],
        'education' => ['document_processor'],
        'sales' => ['communication_manager', 'marketing_manager'],
        'support' => ['communication_manager'],
        'marketing' => ['marketing_manager'],
        'hr' => ['hr_manager'],
        'finance' => ['finance_manager'],
        'operations' => ['project_manager'],
        'it' => ['api_integration'],
    ];

    public function __construct(
        private AgentInterface $onboardingAgent,
        private AgentGoalRepository $goalRepo,
        private SubAgentDefinitionRepository $subAgentDefinitionRepo,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Erzeugt den Strategie-Vorschlag aus mission_statement und Profil.
     *
     * LLM-Antworten werden strikt validiert: Nur bekannte Struktur-Felder
     * und Katalog-Sub-Agenten werden uebernommen; alles andere faellt auf
     * die heuristische Basis zurueck (keine LLM-Halluzination in der
     * Schrittlogik).
     *
     * @param array<string, mixed> $onboardingData
     *
     * @return array{
     *     title: string,
     *     goal: string,
     *     steps: array<int, string>,
     *     success_metric: string,
     *     capabilities: array<int, string>,
     *     sub_agents: array<int, string>,
     *     source: 'llm'|'heuristic'
     * }
     */
    public function draftStrategy(string $userIdentifier, array $onboardingData): array
    {
        $heuristic = $this->heuristicDraft($onboardingData);

        $mission = trim((string) ($onboardingData['mission_statement'] ?? ''));
        if ($mission === '') {
            return $heuristic;
        }

        try {
            $llmDraft = $this->requestLlmDraft($userIdentifier, $mission, $onboardingData, $heuristic);
        } catch (\Throwable $e) {
            $this->logger->warning('Onboarding-Strategie-LLM-Abruf fehlgeschlagen, nutze Heuristik', [
                'error' => $e->getMessage(),
            ]);

            return $heuristic;
        }

        return $this->mergeDrafts($heuristic, $llmDraft);
    }

    /**
     * Persistiert die (ggf. vom Nutzer korrigierte) Strategie als initiale
     * aktive AgentGoal und im onboarding_data-Kontext.
     *
     * @param array<string, mixed> $strategy
     *
     * @return array<string, mixed> Die angereicherte onboarding_data-Struktur
     */
    public function persistStrategy(string $userIdentifier, array $onboardingData, array $strategy): array
    {
        $goal = new AgentGoal();
        $goal->setUserIdentifier($userIdentifier);
        $goal->setTitle((string) ($strategy['title'] ?? 'EVIE-Strategie'));
        $goal->setDescription((string) ($strategy['goal'] ?? ''));
        $goal->setStatus('active');
        $goal->setSuccessMetric((string) ($strategy['success_metric'] ?? ''));
        $goal->setCapabilityConstraints([
            'source' => 'onboarding',
            'steps' => array_values($strategy['steps'] ?? []),
            'capabilities' => array_values($strategy['capabilities'] ?? []),
            'sub_agents' => array_values($strategy['sub_agents'] ?? []),
        ]);
        $this->goalRepo->save($goal, true);

        $onboardingData['strategy'] = $strategy;
        $onboardingData['strategy']['goal_id'] = $goal->getId();
        $onboardingData['strategy_confirmed'] = true;
        $onboardingData['strategy_confirmed_at'] = (new \DateTimeImmutable())->format(\DATE_ATOM);

        return $onboardingData;
    }

    /**
     * Legt die vom Nutzer bestaetigten Sub-Agenten als Tenant-Instanzen an.
     *
     * Es werden NUR Rollen aus dem existierenden Katalog instanziiert; die
     * Konfiguration referenziert die statische ai.yaml-Registrierung
     * (SubAgentFactory laedt aktive SubAgentDefinition-Eintraege und nimmt
     * sie als Subagent-Tool in die Orchestrator-Toolbox). Bereits
     * existierende Definitionen (gleicher Name) werden nicht dupliziert.
     *
     * @param array<string> $subAgentNames Rollen-IDs aus dem Katalog
     *
     * @return array<int, array{name: string, description: string, created: bool}> Angelegte/vorhandene Definitionen
     */
    public function ensureSubAgents(array $subAgentNames): array
    {
        $created = [];
        foreach ($subAgentNames as $name) {
            $label = self::SUB_AGENT_CATALOG[$name] ?? null;
            if ($label === null) {
                continue;
            }

            $existing = $this->subAgentDefinitionRepo->findOneByName($name);
            if ($existing !== null) {
                $existing->setIsActive(true);
                $existing->setUpdatedAt(new \DateTimeImmutable());
                $this->subAgentDefinitionRepo->save($existing, true);
                $created[] = ['name' => $name, 'description' => $label, 'created' => false];

                continue;
            }

            $definition = new SubAgentDefinition();
            $definition->setName($name);
            $definition->setDescription($label);
            $definition->setClassName(\Symfony\AI\Agent\Agent::class);
            $definition->setConfiguration([
                'model' => 'mistral-small-latest',
                'role' => $name,
                'source' => 'onboarding',
                'catalog' => true,
            ]);
            $definition->setIsActive(true);
            $definition->setUpdatedAt(new \DateTimeImmutable());
            $this->subAgentDefinitionRepo->save($definition, true);
            $created[] = ['name' => $name, 'description' => $label, 'created' => true];
        }

        return $created;
    }

    /**
     * Heuristischer Basis-Entwurf aus den profildaten des Onboardings.
     *
     * @param array<string, mixed> $onboardingData
     *
     * @return array{title: string, goal: string, steps: array<int, string>, success_metric: string, capabilities: array<int, string>, sub_agents: array<int, string>, source: string}
     */
    private function heuristicDraft(array $onboardingData): array
    {
        $mission = trim((string) ($onboardingData['mission_statement'] ?? ''));
        $goalDetail = trim((string) ($onboardingData['goal_detail'] ?? ''));
        $useCases = $this->stringList($onboardingData['use_cases'] ?? []);
        $areas = $this->stringList($onboardingData['business_areas'] ?? []);
        $industry = trim((string) (is_array($onboardingData['industry'] ?? null) ? ($onboardingData['industry'][0] ?? '') : ($onboardingData['industry'] ?? '')));

        $focus = $mission !== '' ? $mission : ($goalDetail !== '' ? $goalDetail : 'EVIE produktiv einsetzen');
        $title = $industry !== ''
            ? sprintf('Initial-Strategie (%s)', $industry)
            : 'Initial-Strategie fuer EVIE';

        $steps = [
            'Strategie bestaetigen und EVIE im Alltag nutzen',
            'Benötigte Sub-Agenten und Tools einrichten',
            'Erste Aufgaben aus dem Alltag an EVIE delegieren',
        ];

        $capabilities = array_values(array_unique(array_merge(
            $useCases,
            array_map(static fn (string $area) => 'business_area: ' . $area, $areas)
        )));
        if ($capabilities === []) {
            $capabilities = ['assist_work'];
        }

        return [
            'title' => $title,
            'goal' => $focus,
            'steps' => $steps,
            'success_metric' => 'Erste delegierte Aufgabe erfolgreich durch EVIE ausgefuehrt',
            'capabilities' => $capabilities,
            'sub_agents' => $this->recommendSubAgents($useCases, $areas),
            'source' => 'heuristic',
        ];
    }

    /**
     * Fordert einen LLM-Strukturvorschlag beim Onboarding-Agenten an.
     *
     * @param array<string, mixed>  $onboardingData
     * @param array<string, mixed>  $heuristic
     *
     * @return array<string, mixed>
     */
    private function requestLlmDraft(string $userIdentifier, string $mission, array $onboardingData, array $heuristic): array
    {
        $payload = [
            'action' => 'draft_onboarding_strategy',
            'mission_statement' => $mission,
            'profile' => [
                'goal' => $onboardingData['goal'] ?? null,
                'industry' => $onboardingData['industry'] ?? null,
                'business_areas' => $this->stringList($onboardingData['business_areas'] ?? []),
                'use_cases' => $this->stringList($onboardingData['use_cases'] ?? []),
            ],
            'allowed_sub_agents' => array_keys(self::SUB_AGENT_CATALOG),
            'response_format' => [
                'title' => 'string',
                'goal' => 'string',
                'steps' => 'string[]',
                'success_metric' => 'string',
                'capabilities' => 'string[]',
                'sub_agents' => 'string[] (nur IDs aus allowed_sub_agents)',
            ],
        ];

        $messages = new MessageBag(
            Message::ofUser(json_encode($payload, \JSON_THROW_ON_ERROR))
        );

        $result = $this->onboardingAgent->call($messages);
        $content = $result->getContent();

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * Fuehrt Heuristik und validierten LLM-Vorschlag zusammen.
     *
     * @param array<string, mixed> $heuristic
     * @param array<string, mixed> $llmDraft
     *
     * @return array<string, mixed>
     */
    private function mergeDrafts(array $heuristic, array $llmDraft): array
    {
        $title = trim((string) ($llmDraft['title'] ?? ''));
        $goalText = trim((string) ($llmDraft['goal'] ?? ''));

        $steps = [];
        if (isset($llmDraft['steps']) && is_array($llmDraft['steps'])) {
            foreach ($llmDraft['steps'] as $step) {
                $step = trim((string) $step);
                if ($step !== '') {
                    $steps[] = $step;
                }
            }
        }

        $capabilities = [];
        if (isset($llmDraft['capabilities']) && is_array($llmDraft['capabilities'])) {
            foreach ($llmDraft['capabilities'] as $cap) {
                $cap = trim((string) $cap);
                if ($cap !== '') {
                    $capabilities[] = $cap;
                }
            }
        }

        $subAgents = [];
        if (isset($llmDraft['sub_agents']) && is_array($llmDraft['sub_agents'])) {
            foreach ($llmDraft['sub_agents'] as $name) {
                $name = trim((string) $name);
                if (isset(self::SUB_AGENT_CATALOG[$name])) {
                    $subAgents[] = $name;
                }
            }
        }

        $merged = $heuristic;
        $llmContributed = false;
        if ($title !== '') {
            $merged['title'] = $title;
            $llmContributed = true;
        }
        if ($goalText !== '') {
            $merged['goal'] = $goalText;
            $llmContributed = true;
        }
        if ($steps !== []) {
            $merged['steps'] = $steps;
            $llmContributed = true;
        }
        if ($capabilities !== []) {
            $merged['capabilities'] = array_values(array_unique(array_merge($capabilities, $this->stringList($heuristic['capabilities'] ?? []))));
            $llmContributed = true;
        }
        if ($subAgents !== []) {
            $merged['sub_agents'] = array_values(array_unique(array_merge($subAgents, $this->stringList($heuristic['sub_agents'] ?? []))));
            $llmContributed = true;
        }
        if ($llmContributed) {
            $merged['source'] = 'llm';
        }

        return $merged;
    }

    /**
     * Deterministische Sub-Agent-Empfehlung aus Use-Cases und Bereichen.
     *
     * @param array<string> $useCases
     * @param array<string> $areas
     *
     * @return array<int, string>
     */
    private function recommendSubAgents(array $useCases, array $areas): array
    {
        $recommended = [];
        foreach (array_merge($useCases, $areas) as $key) {
            foreach (self::USE_CASE_SUB_AGENTS[$key] ?? [] as $subAgent) {
                $recommended[] = $subAgent;
            }
        }

        $recommended = array_values(array_unique($recommended));
        if ($recommended === []) {
            $recommended = ['ceo_assistant'];
        }

        return $recommended;
    }

    /**
     * @param mixed $value
     *
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), static fn (string $v) => $v !== ''));
        }
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        return [];
    }
}
