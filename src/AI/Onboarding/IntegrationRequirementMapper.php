<?php

declare(strict_types=1);

namespace App\AI\Onboarding;

/**
 * IntegrationRequirementMapper leitet aus den Onboarding-Angaben (insbesondere
 * den gewaehlten Use-Cases und Bereichen) die technischen Schnittstellen ab,
 * die EVIE fuer den Nutzer konfigurieren muss.
 *
 * Das Mapping ist deterministisch (keine LLM-Halluzination) und orientiert sich
 * an den im Blueprint verankerten Tool-/Service-Klassen von EVIE:
 *  - LLM-Anbindung (Mistral/Gemini) -> API-Key + Provider/Modell
 *  - E-Mail (EmailTool) -> kombinierte SMTP+IMAP-Maske als Secret
 *  - Research/Recherche -> Tavily-API-Key
 *
 * SMTP und IMAP werden in EINEM kombinierten Schritt erfasst (email_combined),
 * sodass der Nutzer beide Verbindungen in einer Maske ausfuellt.
 */
final class IntegrationRequirementMapper
{
    /**
     * Mappt eine Liste von Use-Case-IDs auf die benoetigten Integrationen.
     *
     * @param array<string> $useCases
     *
     * @return array<array{
     *   key: string,
     *   label: string,
     *   type: string,
     *   options?: array<int|string,string>,
     *   required: bool,
     *   help: string
     * }>
     */
    public function mapUseCases(array $useCases): array
    {
        $requirements = [];
        $seen = [];
        foreach ($useCases as $useCase) {
            foreach ($this->requirementsForUseCase($useCase) as $req) {
                if (isset($seen[$req['key']])) {
                    continue;
                }
                $seen[$req['key']] = true;
                $requirements[] = $req;
            }
        }

        return $requirements;
    }

    /**
     * @return array<array{
     *   key: string,
     *   label: string,
     *   type: string,
     *   options?: array<int|string,string>,
     *   required: bool,
     *   help: string
     * }>
     */
    private function requirementsForUseCase(string $useCase): array
    {
        // use_case IDs stammen aus dem onboarding_prompt.json.
        return match ($useCase) {
            'research' => [
                [
                    'key' => 'tavily_api_key',
                    'label' => 'Tavily API-Key (Recherche/Websuche)',
                    'type' => 'secret',
                    'required' => false,
                    'help' => 'Wird fuer die Web-Recherche benoetigt. Ohne Key ist das Recherche-Tool eingeschraenkt.',
                ],
            ],
            'business_automation', 'project_management' => [
                [
                    'key' => 'email_combined',
                    'label' => 'E-Mail (SMTP + IMAP)',
                    'type' => 'email_combined',
                    'required' => false,
                    'help' => 'Kombinierte SMTP-/IMAP-Eingabe. Verschiedene Bereiche koennen verschiedene E-Mail-Konten nutzen.',
                ],
            ],
            default => [],
        };
    }
}
