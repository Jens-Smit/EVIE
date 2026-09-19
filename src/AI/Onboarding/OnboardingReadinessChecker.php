<?php

declare(strict_types=1);

namespace App\AI\Onboarding;

/**
 * OnboardingReadinessChecker - Konsistenzpruefung vor dem Abschluss.
 *
 * Der Button "Onboarding abschliessen" ist ab sofort aus jeder Phase
 * heraus verfuegbar. Vor dem Abschluss prueft diese Klasse, welche
 * Pflichtangaben vorliegen:
 *  - Phase A: LLM-Provider, -Modell und API-Key gesetzt
 *  - Phase B: Aufgabenbeschreibung (mission_statement oder goal) gesetzt
 *  - Phase C: mindestens eine Strategie bestaetigt
 *
 * Nicht blockierende Punkte (Sub-Agenten, Tools, weitere Secrets) werden
 * als Warnung aufgefuehrt, aber verhindern den Abschluss nicht.
 */
final class OnboardingReadinessChecker
{
    /**
     * @param array<string, mixed> $onboardingData
     *
     * @return array{
     *     ready: bool,
     *     checks: array<int, array{id: string, label: string, passed: bool, blocking: bool, step_id: string}>,
     *     missing_blocking: array<int, string>
     * }
     */
    public function check(array $onboardingData): array
    {
        $checks = [
            $this->checkField($onboardingData, 'llm_provider', 'LLM-Anbieter gewaehlt', 'llm_provider', true),
            $this->checkField($onboardingData, 'llm_model', 'LLM-Modell gewaehlt', 'llm_model', true),
            $this->checkField($onboardingData, 'llm_api_key', 'LLM-API-Key hinterlegt', 'llm_api_key', true),
            $this->checkField($onboardingData, 'mission_statement', 'Aufgabe beschrieben', 'mission_statement', true),
            $this->checkField($onboardingData, 'strategy_confirmed', 'Strategie bestaetigt', 'strategy_review', true),
        ];

        $hasSubAgents = !empty($onboardingData['sub_agents_created']);
        $checks[] = [
            'id' => 'sub_agents',
            'label' => 'Sub-Agenten eingerichtet',
            'passed' => $hasSubAgents,
            'blocking' => false,
            'step_id' => 'sub_agent_review',
        ];

        $hasTools = isset($onboardingData['tools']) || isset($onboardingData['tools_created']);
        $checks[] = [
            'id' => 'tools',
            'label' => 'Tools angelegt (Freigabe nach Abschluss)',
            'passed' => $hasTools,
            'blocking' => false,
            'step_id' => 'tool_review',
        ];

        $missingBlocking = [];
        $ready = true;
        foreach ($checks as $check) {
            if ($check['blocking'] && !$check['passed']) {
                $ready = false;
                $missingBlocking[] = $check['id'];
            }
        }

        return [
            'ready' => $ready,
            'checks' => $checks,
            'missing_blocking' => $missingBlocking,
        ];
    }

    /**
     * @param array<string, mixed> $onboardingData
     *
     * @return array{id: string, label: string, passed: bool, blocking: bool, step_id: string}
     */
    private function checkField(array $onboardingData, string $field, string $label, string $stepId, bool $blocking): array
    {
        $value = $onboardingData[$field] ?? null;
        $passed = false;
        if (is_array($value)) {
            $passed = $value !== [];
        } elseif (is_string($value)) {
            $passed = trim($value) !== '';
        } elseif (is_bool($value)) {
            $passed = $value;
        }

        return [
            'id' => $field,
            'label' => $label,
            'passed' => $passed,
            'blocking' => $blocking,
            'step_id' => $stepId,
        ];
    }
}
