<?php

declare(strict_types=1);

namespace App\AI\Onboarding;

/**
 * IntegrationRequirementMapper leitet aus den Onboarding-Angaben (insbesondere
 * den gewaehlten Use-Cases) die technischen Schnittstellen ab, die EVIE fuer
 * den Nutzer konfigurieren muss.
 *
 * Das Mapping ist deterministisch (keine LLM-Halluzination) und orientiert sich
 * an den im Blueprint verankerten Tool-/Service-Klassen von EVIE:
 *  - LLM-Anbindung (Mistral/Gemini) -> API-Key + Provider/Modell
 *  - E-Mail (EmailTool) -> SMTP (Senden) + IMAP (Empfangen) als Secrets
 *  - Research/Recherche -> Tavily-API-Key
 *  - LinkedIn -> LinkedIn-API-Token
 *  - MCP-Server (Filesystem/GitHub/Playwright) -> MCP-URLs
 *
 * Jede Anforderung ist als Requirement-Datensatz mit einem eindeutigen Key,
 * einem Anzeige-Label, einem Frage-Typ und ggf. Optionen beschrieben, sodass
 * der OnboardingFlowManager die entsprechenden Schritte dynamisch anhaengen
 * kann.
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
                    'key' => 'email_smtp',
                    'label' => 'E-Mail Versand (SMTP)',
                    'type' => 'email_smtp',
                    'required' => false,
                    'help' => 'SMTP-Zugangsdaten, damit EVIE E-Mails versenden kann.',
                ],
                [
                    'key' => 'email_imap',
                    'label' => 'E-Mail Empfang (IMAP)',
                    'type' => 'email_imap',
                    'required' => false,
                    'help' => 'IMAP-Zugangsdaten, damit EVIE E-Mails lesen/verarbeiten kann.',
                ],
            ],
            default => [],
        };
    }
}
