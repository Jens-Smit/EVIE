<?php

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * RealEmbeddingPass - stellt im E2E-LLM-Modus (EVIE_LLM_E2E=1) die echte
 * Mistral-Embedding-Kette wieder her.
 *
 * config/services_test.yaml ersetzt App\AI\Rag\EmbeddingServiceInterface im
 * Test-Env durch den deterministischen Stub (DeterministicEmbeddingService),
 * damit Integrationstests ohne externe API-Calls reproduzierbar laufen. Der
 * e2e-llm-CI-Job laeuft aber genau darauf ausgelegt, ECHTE Mistral-Aufrufe
 * (Chat UND Embeddings) mit dem in den GitHub-Secrets hinterlegten Key zu
 * pruefen. Ohne diesen Pass wuerden alle Embedding-Tests stillschweigend
 * gegen den Stub laufen und echte Embedding-Fehler (400 Bad Request,
 * Null-Vektoren) nie entdeckt.
 *
 * Der Pass ist ein No-op ausserhalb des LLM-E2E-Modus.
 */
final class RealEmbeddingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$this->isLlmE2e()) {
            return;
        }

        $container->setAlias('App\AI\Rag\EmbeddingServiceInterface', 'App\AI\Rag\TenantAwareEmbeddingService')
            ->setPublic(true);
    }

    private function isLlmE2e(): bool
    {
        $value = $_ENV['EVIE_LLM_E2E'] ?? (getenv('EVIE_LLM_E2E') ?: '');

        return \in_array((string) $value, ['1', 'true', 'yes'], true);
    }
}
