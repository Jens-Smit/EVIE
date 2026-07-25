<?php

namespace App\AI\Agent;

/**
 * @deprecated
 * Diese Klasse wird nicht mehr benötigt, da Sub-Agenten nun direkt über die ai.yaml konfiguriert werden.
 * Siehe: config/packages/ai.yaml
 */
final class SubAgentFactory
{
    // Diese Klasse ist veraltet und wird durch die deklarative Konfiguration in ai.yaml ersetzt.
    // Sub-Agenten wie `research`, `analysis`, und `support` werden nun direkt in der ai.yaml definiert.
}

// @deprecated
class ResearchAgent
{
    // Veraltet: Wird durch ai.agent.research in ai.yaml ersetzt.
}

// @deprecated
class AnalysisAgent
{
    // Veraltet: Wird durch ai.agent.analysis in ai.yaml ersetzt.
}

// @deprecated
class SupportAgent
{
    // Veraltet: Wird durch ai.agent.support in ai.yaml ersetzt.
}
