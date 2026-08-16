<?php

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use App\Tests\Stub\StubAgent;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * TestStubPass - ersetzt im Test-Env die Symfony AI Agent-Services durch
 * deterministische StubAgent-Instanzen.
 *
 * Dies ermöglicht Functional- und E2E-Tests, die den gebooteten Kernel nutzen
 * (echter Container, echte Security, echte Controller) und dabei den LLM-Abruf
 * über den nativen AgentInterface::call()-Pfad vollstÃ¤ndig durchlaufen - ohne
 * echte Mistral-API-Aufrufe (deterministisch, kostenlos, minimiert).
 *
 * Die StubAgent-Antworten werden über die Umgebungsvariablen
 *   EVIE_TEST_LLM_RESPONSE_ORCHESTRATOR
 *   EVIE_TEST_LLM_RESPONSE_TOOL_GENERATOR
 *   EVIE_TEST_LLM_RESPONSE_ONBOARDING
 * konfigurierbar gemacht, sodass verschiedene Test-Szenarien unterschiedliche
 * LLM-Antworten definieren kÃ¶nnen.
 *
 * Der Pass ist ein No-op ausserhalb des Test-Env, sodass die Production- und
 * Dev-Wiring unangetastet bleibt (wie E2EStubPass).
 *
 * Blueprint-Konform: nativ Symfony AI (AgentInterface), keine parallele
 * Tool-Infrastruktur, keine Konstruktor-Injection fÃ¼r Tools.
 */
final class TestStubPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$this->isTestEnv($container)) {
            return;
        }

        // Agent-Services durch StubAgent ersetzen.
        $this->stubAgent($container, 'ai.agent.orchestrator', 'EVIE_TEST_LLM_RESPONSE_ORCHESTRATOR');
        $this->stubAgent($container, 'ai.agent.tool_generator', 'EVIE_TEST_LLM_RESPONSE_TOOL_GENERATOR');
        $this->stubAgent($container, 'ai.agent.onboarding', 'EVIE_TEST_LLM_RESPONSE_ONBOARDING');
    }

    private function stubAgent(ContainerBuilder $container, string $serviceId, string $envVar): void
    {
        if (!$container->hasDefinition($serviceId)) {
            return;
        }

        // Antwort aus Env oder Fallback (gÃ¼ltiges JSON fÃ¼r den Orchestrator-Dialog).
        $response = (string) (getenv($envVar) ?: (
            $_ENV[$envVar] ?? $this->defaultResponse($serviceId)
        ));

        $definition = new Definition(StubAgent::class, [$response]);
        $definition->setPublic(true);
        $container->setDefinition($serviceId, $definition);
    }

    private function defaultResponse(string $serviceId): string
    {
        // Minimal gÃ¼ltige Default-Antworten je Agent, sodass Tests ohne
        // explizite Env-Konfiguration nicht an ungueltigem JSON scheitern.
        return match ($serviceId) {
            'ai.agent.orchestrator' => json_encode([
                'type' => 'dialog',
                'content' => 'Test-Antwort des Orchestrators.',
                'message' => 'Test-Antwort des Orchestrators.',
            ], JSON_THROW_ON_ERROR),
            'ai.agent.tool_generator' => json_encode([
                'type' => 'object',
                'properties' => ['input' => ['type' => 'string', 'description' => 'Eingabe']],
                'required' => ['input'],
                'security_level' => 'medium',
                'hitl_required' => true,
            ], JSON_THROW_ON_ERROR),
            'ai.agent.onboarding' => json_encode([
                'status' => 'in_progress',
                'step_id' => 'user_type',
                'question' => 'Wie mÃ¶chtest du EVIE nutzen?',
                'type' => 'multiple_choice',
                'options' => ['Business', 'Privat'],
                'next_step' => 'preferences',
                'context_updates' => ['user_type' => 'Business'],
            ], JSON_THROW_ON_ERROR),
            default => '{}',
        };
    }

    private function isTestEnv(ContainerBuilder $container): bool
    {
        // Wenn EVIE_LLM_E2E=1 gesetzt ist, werden die ECHten Agent-Services
        // (mit echtem Mistral) belassen, sodass der E2E-LLM-Test-Job echte
        // API-Aufrufe taetigt. In allen anderen Test-Env-Laefuen werden die
        // Agenten durch StubAgent ersetzt (deterministisch, kostenlos).
        if ($this->isLlmE2e()) {
            return false;
        }

        $env = $container->getParameter('kernel.environment');
        return $env === 'test';
    }

    private function isLlmE2e(): bool
    {
        $value = $_ENV['EVIE_LLM_E2E'] ?? (getenv('EVIE_LLM_E2E') ?: '');
        return \in_array((string) $value, ['1', 'true', 'yes'], true);
    }
}
