<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Intent;

use App\AI\Pipeline\PipelineContext;

/**
 * Phase 2: Klassifiziert die konkrete Nachricht in einen Intent.
 *
 * Die Klassifizierung laeuft verlaesslich vor jeder Tool-Entscheidung,
 * nicht erst nachtraeglich im no_tool_found-Pfad. Die Implementierung
 * nutzt PlatformInterface::invoke (analog der bisherigen classifyIntent-
 * Logik), verzichtet aber auf Mocks und Fantasie-Tools.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 2
 */
interface IntentClassifierInterface
{
    public function classify(PipelineContext $context): Intent;
}
