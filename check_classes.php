<?php
/**
 * Check-Skript für verfügbare AI Bridge-/Tool-Klassen.
 *
 * In Symfony AI 0.12 wurden Bridge-Services durch Tool-Pakete ersetzt.
 * Dieses Skript verifiziert, welche Klassen im Vendor-Verzeichnis verfügbar sind.
 */
require __DIR__ . '/vendor/autoload.php';

echo "=== Symfony AI 0.12 Tool-Verfügbarkeit ===\n\n";

// Tavily Tool (Symfony\AI\Agent\Bridge\Tavily\Tavily) — als Tool registriert via AsTool-Attribut
echo "Tavily Tool: " . (class_exists('Symfony\AI\Agent\Bridge\Tavily\Tavily') ? 'EXISTS' : 'NOT FOUND') . PHP_EOL;

// Firecrawl Tool — ebenfalls als Tool verfügbar (wird durch MCP Playwright ersetzt)
echo "Firecrawl Tool: " . (class_exists('Symfony\AI\Agent\Bridge\Firecrawl\Firecrawl') ? 'EXISTS' : 'NOT FOUND') . "\n";

// Tavily Tool Constructor prüfen
if (class_exists('Symfony\AI\Agent\Bridge\Tavily\Tavily')) {
    $r = new ReflectionClass('Symfony\AI\Agent\Bridge\Tavily\Tavily');
    $constructor = $r->getConstructor();
    if ($constructor) {
        echo "\nTavily Tool constructor params:\n";
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            echo "  - $" . $param->getName() . " (type: " . ($type ? $type->getName() : 'mixed') . ")\n";
        }
    }

    // AsTool-Attribute prüfen
    $attributes = $r->getAttributes();
    if (!empty($attributes)) {
        echo "\nTavily AsTool attributes:\n";
        foreach ($attributes as $attr) {
            $instance = $attr->newInstance();
            echo "  - " . $instance->name . " (method: " . ($instance->method ?? 'N/A') . ")\n";
        }
    }
}

// Firecrawl Tool Constructor prüfen
if (class_exists('Symfony\AI\Agent\Bridge\Firecrawl\Firecrawl')) {
    $r = new ReflectionClass('Symfony\AI\Agent\Bridge\Firecrawl\Firecrawl');
    $constructor = $r->getConstructor();
    if ($constructor) {
        echo "\nFirecrawl Tool constructor params:\n";
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            echo "  - $" . $param->getName() . " (type: " . ($type ? $type->getName() : 'mixed') . ")\n";
        }
    }
}

echo "\n=== Wichtig ===\n";
echo "Diese Klassen sind TOOLS (mit #[AsTool] Attribut), keine Bridge-Services.\n";
echo "Sie müssen als Service in services.yaml registriert werden, damit sie\n";
echo "über die AI Toolbox via AsTool-Attribut automatisch getaggt werden.\n";
echo "Sie dürfen NICHT via Constructor-Injection (Autowiring) verwendet werden.\n";
