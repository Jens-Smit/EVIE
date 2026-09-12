<?php

declare(strict_types=1);

namespace App\AI\Onboarding;

/**
 * OnboardingStepProvider liefert die deterministische, phasenbasierte
 * Schrittfolge des EVIE-Onboardings.
 *
 * Damit das Onboarding zuverlaessig durchlaeuft (auch ohne erreichbaren
 * Onboarding-Agent) und alle fuer den Betrieb noetigen Konfigurationen
 * erfasst, wird der Flow in feste Phasen unterteilt:
 *
 *   Phase A - KI-Settings (zwingend zuerst):
 *     1. LLM-Anbieter waehlen (mistral/gemini)
 *     2. LLM-Modell waehlen (provider-abhaengig)
 *     3. LLM API-Key erfassen (als Secret)
 *
 *   Phase B - Profil/Bedarfsanalyse:
 *     4. Nutzerrolle (Developer/Business/Administrator/...)
 *     5. Use-Cases (Code, Recherche, Business-Automation, ...)
 *     6. Branche
 *
 *   Phase C - Schnittstellen (dynamisch aus Use-Cases abgeleitet):
 *     - Tavily-API-Key (Recherche)
 *     - SMTP (E-Mail Versand)
 *     - IMAP (E-Mail Empfang)
 *     - LinkedIn-Token
 *
 *   Phase D - Abschluss/Zusammenfassung.
 *
 * Die KI-Settings bewusst zuerst, damit nachfolgende Agent-Aufrufe den
 * konfigurierten Provider nutzen koennen. Die Schnittstellen-Phase folgt
 * erst nach der Bedarfsanalyse, da sich die noetigen Keys aus den Use-Cases
 * ergeben (Blueprint-konform, keine Fantasie-Tools).
 *
 * Der Provider ist rein datengetrieben und enthaelt KEINE Konstruktor-
 * Injection (Projektvorgabe fuer Tools/Services dieser Schicht).
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

    /**
     * Liefert die festen Basis-Schritte (Phase A + B), die unabhaengig von den
     * Use-Cases immer durchlaufen werden.
     *
     * @return array<int, array{
     *   id: string,
     *   phase: string,
     *   question: string,
     *   type: string,
     *   field: string,
     *   options?: array<int|string,string>,
     *   help?: string
     * }>
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
                'question' => 'Bitte gib deinen API-Key fuer den gewaehlten Anbieter ein. Der Key wird verschluesselt als Secret gespeichert.',
                'type' => 'secret',
                'field' => 'llm_api_key',
                'help' => 'Ohne gueltigen API-Key kann EVIE keine KI-Anfragen ausfuehren. Du findest den Key im jeweiligen Anbieter-Dashboard.',
            ],
            [
                'id' => 'user_role',
                'phase' => 'Profil',
                'question' => 'Was ist deine primaere Rolle?',
                'type' => 'multiple_choice',
                'field' => 'user_type',
                'options' => [
                    'Developer' => 'Developer',
                    'Business User' => 'Business User',
                    'Administrator' => 'Administrator',
                    'Data Scientist' => 'Data Scientist',
                    'Other' => 'Andere',
                ],
                'help' => 'Hieraus leitet EVIE ab, welche Schnittstellen und Tools du benoetigst.',
            ],
            [
                'id' => 'use_cases',
                'phase' => 'Profil',
                'question' => 'Welche Use-Cases hast du? Waehle alle zutreffenden.',
                'type' => 'multiple_choice',
                'field' => 'use_cases',
                'options' => [
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
                ],
                'help' => 'EVIE leitet daraus die noetigen Schnittstellen und API-Keys ab.',
            ],
            [
                'id' => 'industry',
                'phase' => 'Profil',
                'question' => 'In welcher Branche arbeitest du primaer?',
                'type' => 'multiple_choice',
                'field' => 'industry',
                'options' => [
                    'technology' => 'Technologie/Software',
                    'gastronomy' => 'Gastronomie/Gastgewerbe',
                    'retail' => 'Einzelhandel/E-Commerce',
                    'finance' => 'Finanzen/Rechnungswesen',
                    'healthcare' => 'Gesundheitswesen',
                    'education' => 'Bildung',
                    'manufacturing' => 'Produktion/Logistik',
                    'nonprofit' => 'Non-profit/NGO',
                    'other' => 'Andere',
                ],
                'help' => 'Zur Personalisierung der Antworten.',
            ],
        ];
    }

    /**
     * Erzeugt die Schnittstellen-Schritte (Phase C) aus den gewaehlten
     * Use-Cases mittels IntegrationRequirementMapper.
     *
     * @param array<string> $useCases
     *
     * @return array<int, array{
     *   id: string,
     *   phase: string,
     *   question: string,
     *   type: string,
     *   field: string,
     *   options?: array<int|string,string>,
     *   help?: string,
     *   required?: bool
     * }>
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
     * Vollstaendige, sortierte Schrittliste bestehend aus Basis-Schritten,
     * dynamischen Schnittstellen-Schritten (aus Use-Cases) und Abschluss.
     *
     * @param array<string> $useCases
     *
     * @return array<int, array<string, mixed>>
     */
    public function allSteps(array $useCases, IntegrationRequirementMapper $mapper): array
    {
        $base = $this->baseSteps();
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

        return array_merge($base, $integrations, $completion);
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
}
