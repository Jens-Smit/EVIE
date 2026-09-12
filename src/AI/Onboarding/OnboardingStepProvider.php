<?php

declare(strict_types=1);

namespace App\AI\Onboarding;

/**
 * OnboardingStepProvider liefert die dynamische, verzweigende
 * Schrittfolge des EVIE-Onboardings.
 *
 * Der Flow ist PHASENBASIERT und beginnt zwingend mit den KI-Settings,
 * damit nachfolgende Agent-Aufrufe den konfigurierten Provider nutzen
 * koennen. Danach oeffnet sich ein verzweigender Bedarfsdialog:
 *
 *   Phase A - KI-Settings (zwingend zuerst):
 *     1. LLM-Anbieter waehlen (mistral/gemini)
 *     2. LLM-Modell waehlen (provider-abhaengig)
 *     3. LLM API-Key erfassen (als Secret, mit Live-Validierung)
 *
 *   Phase B - Ziel & Verzweigung (dynamisch):
 *     4. Hauptziel: Was soll EVIE fuer dich tun?
 *        - "Mein Unternehmen managen"  -> Branche -> Bereiche (multiselect)
 *        - "Mich bei meiner Arbeit unterstützen" -> Use-Cases (multiselect)
 *        - "Etwas anderes" -> Freitext
 *     5. (nur bei "Unternehmen managen") Branche
 *     6. (nur bei "Unternehmen managen") Bereiche (multiselect + freetext)
 *     7. Pro gewaehltem Bereich: E-Mail-Konto konfigurieren (kombiniert SMTP+IMAP,
 *        wiederholbar -> mehrere Konten pro Bereich)
 *     8. Tavily-API-Key (bei Recherche-Use-Case)
 *     9. "Weitere Bereiche/Konfiguration hinzufuegen?" -> Loop oder Abschluss
 *
 *   Phase C - Abschluss/Zusammenfassung.
 *
 * Der Provider ist rein datengetrieben und enthaelt KEINE Konstruktor-
 * Injection (Projektvorgabe fuer Tools/Services dieser Schicht). Das
 * Branching wird ueber das onboarding_data-Context-Array gesteuert, das
 * der FlowManager an resolveSteps() uebergibt.
 */
final class OnboardingStepProvider
{
    /** Liste der LLM-Anbieter (Id => Anzeigename) */
    public const PROVIDERS = [
        'mistral' => 'Mistral AI',
        'gemini' => 'Google Gemini',
    ];

    /** Modelle je Anbieter (Id => Anzeigename) */
    public const MODELS = [
        'mistral' => [
            'mistral-small-latest' => 'Mistral Small (Latest)',
            'mistral-large-latest' => 'Mistral Large (Latest)',
        ],
        'gemini' => [
            'gemini-1.5-flash-latest' => 'Gemini 1.5 Flash',
            'gemini-1.5-pro-latest' => 'Gemini 1.5 Pro',
        ],
    ];

    public const DEFAULT_PROVIDER = 'mistral';
    public const DEFAULT_MODEL = 'mistral-small-latest';

    /** Hauptziele (Phase B Einstieg) */
    public const GOALS = [
        'manage_company' => 'EVIE soll mein Unternehmen managen',
        'assist_work' => 'EVIE soll mich bei meiner Arbeit unterstuetzen',
        'other' => 'Etwas anderes',
    ];

    /** Branchen (nur bei manage_company) */
    public const INDUSTRIES = [
        'software_it' => 'Software / IT',
        'gastronomy' => 'Gastronomie / Gastgewerbe',
        'retail' => 'Einzelhandel / E-Commerce',
        'finance' => 'Finanzen / Rechnungswesen',
        'healthcare' => 'Gesundheitswesen',
        'education' => 'Bildung',
        'manufacturing' => 'Produktion / Logistik',
        'nonprofit' => 'Non-profit / NGO',
        'other' => 'Andere',
    ];

    /** Bereiche, die EVIE abdecken kann (multiselect + freetext) */
    public const BUSINESS_AREAS = [
        'sales' => 'Vertrieb',
        'support' => 'Support / Kundenservice',
        'marketing' => 'Marketing',
        'hr' => 'Personalwesen (HR)',
        'finance' => 'Finanzen / Buchhaltung',
        'operations' => 'Betrieb / Operations',
        'project_management' => 'Projektmanagement',
        'it' => 'IT / Technik',
    ];

    /** Use-Cases (nur bei assist_work) */
    public const USE_CASES = [
        'code_generation' => 'Code-Generierung',
        'code_review' => 'Code-Review',
        'business_automation' => 'Geschaeftsprozess-Automatisierung',
        'data_analysis' => 'Datenanalyse',
        'document_processing' => 'Dokument-Verarbeitung',
        'research' => 'Recherche & Informationssuche',
        'project_management' => 'Projektmanagement',
        'system_administration' => 'Systemadministration',
        'education' => 'Lernen & Weiterbildung',
        'other' => 'Andere',
    ];

    /**
     * Liefert die festen Basis-Schritte (Phase A + Phase B Einstieg), unabhaengig
     * von den Use-Cases. Das dynamische Branching ergibt sich aus den Antworten
     * und wird in allSteps() anhand des Kontexts zusammengestellt.
     *
     * @return array<int, array<string, mixed>>
     */
    public function baseSteps(): array
    {
        return [
            [
                'id' => 'llm_provider',
                'phase' => 'KI-Settings',
                'question' => 'Welchen KI-Anbieter moechtest du fuer EVIE nutzen? Diese Einstellung wird zuerst benoetigt, damit EVIE funktioniert.',
                'type' => 'multiple_choice',
                'field' => 'llm_provider',
                'options' => self::PROVIDERS,
                'help' => 'Der Anbieter bestimmt, welches Sprachmodell EVIE verwendet.',
            ],
            [
                'id' => 'llm_model',
                'phase' => 'KI-Settings',
                'question' => 'Welches Modell soll EVIE verwenden?',
                'type' => 'multiple_choice',
                'field' => 'llm_model',
                'options' => self::MODELS[self::DEFAULT_PROVIDER],
                'help' => 'Du kannst dies jederzeit in den Einstellungen aendern.',
            ],
            [
                'id' => 'llm_api_key',
                'phase' => 'KI-Settings',
                'question' => 'Bitte gib deinen API-Key fuer den gewaehlten Anbieter ein. Der Key wird verschluesselt als Secret gespeichert und vor dem Speichern geprueft.',
                'type' => 'secret',
                'field' => 'llm_api_key',
                'help' => 'Ohne gueltigen API-Key kann EVIE keine KI-Anfragen ausfuehren. Der Key wird live gegen die Anbieter-API validiert.',
            ],
            [
                'id' => 'goal',
                'phase' => 'Ziel',
                'question' => 'Was moechtest du mit EVIE erreichen?',
                'type' => 'multiple_choice',
                'field' => 'goal',
                'options' => self::GOALS,
                'help' => 'Deine Antwort bestimmt, welche weiteren Fragen EVIE stellt.',
            ],
        ];
    }

    /**
     * Erzeugt die Schnittstellen-Schritte (Phase C) aus den gewaehlten
     * Use-Cases / Bereichen mittels IntegrationRequirementMapper.
     *
     * @param array<string> $useCases
     *
     * @return array<int, array<string, mixed>>
     */
    public function integrationSteps(array $useCases, IntegrationRequirementMapper $mapper): array
    {
        $requirements = $mapper->mapUseCases($useCases);
        $steps = [];
        foreach ($requirements as $req) {
            $steps[] = [
                'id' => 'integration_' . $req['key'],
                'phase' => 'Schnittstellen',
                'question' => $req['label'] . ' konfigurieren',
                'type' => $req['type'],
                'field' => $req['key'],
                'help' => $req['help'],
                'required' => $req['required'],
            ];
        }

        return $steps;
    }

    /**
     * Vollstaendige, dynamische Schrittliste bestehend aus Basis-Schritten,
     * verzweigten Bedarfsfragen (Ziel/Branche/Bereiche/E-Mail-Konten) und
     * Abschluss. Die Verzweigung ergibt sich aus dem onboarding_data-Kontext.
     *
     * @param array<string, mixed> $context  Vollstaendiges onboarding_data-Array
     *
     * @return array<int, array<string, mixed>>
     */
    public function allSteps(array $context, IntegrationRequirementMapper $mapper): array
    {
        $base = $this->baseSteps();
        $branch = $this->branchSteps($context);
        $useCases = $this->useCasesFromContext($context);
        $integrations = $this->integrationSteps($useCases, $mapper);

        $completion = [
            [
                'id' => 'summary',
                'phase' => 'Abschluss',
                'question' => 'Vielen Dank! Deine Angaben wurden gespeichert. Du kannst EVIE jetzt nutzen.',
                'type' => 'summary',
                'field' => 'summary',
            ],
        ];

        return array_merge($base, $branch, $integrations, $completion);
    }

    /**
     * Baut die verzweigten Bedarfsfragen anhand des Kontexts.
     *
     * @param array<string, mixed> $context
     *
     * @return array<int, array<string, mixed>>
     */
    private function branchSteps(array $context): array
    {
        $steps = [];
        $goal = $context['goal'] ?? null;
        $goal = is_array($goal) ? ($goal[0] ?? null) : $goal;

        // Freitext bei "etwas anderes"
        if ($goal === 'other' && !isset($context['goal_detail'])) {
            $steps[] = [
                'id' => 'goal_detail',
                'phase' => 'Ziel',
                'question' => 'Bitte beschreibe kurz, was du mit EVIE erreichen moechtest.',
                'type' => 'text',
                'field' => 'goal_detail',
                'help' => 'Freitext-Antwort.',
            ];
        }

        // Verzweigung bei "Unternehmen managen": Branche -> Bereiche -> E-Mail-Konten
        if ($goal === 'manage_company') {
            if (!isset($context['industry'])) {
                $steps[] = [
                    'id' => 'industry',
                    'phase' => 'Profil',
                    'question' => 'In welcher Branche arbeitest du primaer?',
                    'type' => 'multiple_choice',
                    'field' => 'industry',
                    'options' => self::INDUSTRIES,
                    'help' => 'Zur Personalisierung der Antworten.',
                ];
            }

            if (!isset($context['business_areas'])) {
                $steps[] = [
                    'id' => 'business_areas',
                    'phase' => 'Bereiche',
                    'question' => 'Welche Bereiche soll EVIE abdecken? Waehle alle zutreffenden.',
                    'type' => 'multiselect',
                    'field' => 'business_areas',
                    'options' => self::BUSINESS_AREAS,
                    'help' => 'Mehrfachauswahl. Du kannst weitere Bereiche als Freitext hinzufuegen.',
                    'allow_freetext' => true,
                ];
            }

            // E-Mail-Konten pro gewaehltem Bereich (wiederholbar)
            $areas = $this->toArray($context['business_areas'] ?? []);
            $configuredAreas = $this->toArray($context['email_configured_areas'] ?? []);
            foreach ($areas as $area) {
                if (in_array($area, $configuredAreas, true)) {
                    continue;
                }
                $label = self::BUSINESS_AREAS[$area] ?? ucfirst((string) $area);
                $steps[] = [
                    'id' => 'email_account_' . $area,
                    'phase' => 'E-Mail-Konten',
                    'question' => sprintf(
                        'E-Mail-Konto fuer "%s" konfigurieren (SMTP + IMAP). Du kannst es auch ueberspringen.',
                        $label
                    ),
                    'type' => 'email_combined',
                    'field' => 'email_account',
                    'area' => $area,
                    'help' => 'Kombinierte SMTP- und IMAP-Eingabe fuer diesen Bereich. Verschiedene Bereiche koennen verschiedene E-Mail-Adressen nutzen.',
                    'required' => false,
                ];
            }
        }

        // Verzweigung bei "bei Arbeit unterstuetzen": Use-Cases (multiselect)
        if ($goal === 'assist_work' && !isset($context['use_cases'])) {
            $steps[] = [
                'id' => 'use_cases',
                'phase' => 'Profil',
                'question' => 'Welche Use-Cases hast du? Waehle alle zutreffenden.',
                'type' => 'multiselect',
                'field' => 'use_cases',
                'options' => self::USE_CASES,
                'help' => 'Mehrfachauswahl. EVIE leitet daraus die noetigen Schnittstellen ab.',
                'allow_freetext' => true,
            ];
        }

        // Schleife: "Weitere Bereiche hinzufuegen?" nachdem E-Mail-Konten
        // konfiguriert wurden (nur bei manage_company, wenn Bereiche gewaehlt).
        if ($goal === 'manage_company') {
            $areas = $this->toArray($context['business_areas'] ?? []);
            $configuredAreas = $this->toArray($context['email_configured_areas'] ?? []);
            $allConfigured = !empty($areas) && count($areas) === count($configuredAreas);
            if ($allConfigured && !isset($context['add_more'])) {
                $steps[] = [
                    'id' => 'add_more',
                    'phase' => 'Abschluss',
                    'question' => 'Moechtest du weitere Bereiche konfigurieren oder war es das erstmal?',
                    'type' => 'multiple_choice',
                    'field' => 'add_more',
                    'options' => [
                        'add_area' => 'Weiteren Bereich hinzufuegen',
                        'done' => 'Das war es erstmal',
                    ],
                    'help' => 'Du kannst spaeter weitere Bereiche in den Einstellungen hinzufuegen.',
                ];
            }
        }

        return $steps;
    }

    /**
     * Liefert die Modelle fuer einen Anbieter.
     *
     * @return array<string,string>
     */
    public function modelsForProvider(string $provider): array
    {
        return self::MODELS[$provider] ?? self::MODELS[self::DEFAULT_PROVIDER];
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
     * Vereinigt die Use-Cases aus dem assist_work-Zweig und die aus
     * manage_company gewaehlten Bereichen, sodass der RequirementMapper
     * beide Quellen beruecksichtigt.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string>
     */
    private function useCasesFromContext(array $context): array
    {
        $useCases = $this->toArray($context['use_cases'] ?? []);
        $areas = $this->toArray($context['business_areas'] ?? []);

        // Bereiche, die E-Mail-Schnittstellen benoetigen, werden als
        // Pseudo-Use-Case 'business_automation' weitergereicht, damit der
        // RequirementMapper Tavily/SMTP/IMAP ableitet.
        $emailAreas = array_intersect($areas, ['sales', 'support', 'marketing', 'hr', 'finance', 'operations']);
        if (!empty($emailAreas) && !in_array('business_automation', $useCases, true)) {
            $useCases[] = 'business_automation';
        }
        if (in_array('research', $this->toArray($context['use_cases'] ?? []), true) && !in_array('research', $useCases, true)) {
            $useCases[] = 'research';
        }

        return array_values(array_unique($useCases));
    }
}
