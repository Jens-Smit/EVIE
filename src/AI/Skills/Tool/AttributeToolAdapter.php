<?php

declare(strict_types=1);

namespace App\AI\Skills\Tool;

use ReflectionClass;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Adapter: macht native #[AsTool]-attribuierte Tools fuer Phase 5
 * (Pipeline-Ausfuehrung) als ToolInterface ausfuehrbar, ohne die
 * Tool-Klassen selbst zu aendern.
 *
 * Der Planner (Phase 3) kuendigt im Prompt alle Tools der Registry an
 * (ToolRegistry::all()). Bisher warf ToolRegistry::get() fuer native
 * #[AsTool]-Tools ohne ToolInterface die Meldung "ist kein ToolInterface",
 * sodass jeder geplante statische Tool-Schritt zur Laufzeit abbrach
 * (siehe dev-tail.log: Schritt create_businessplan, Target
 * strategy_document). Der Adapter liest Name und Beschreibung direkt
 * aus dem #[AsTool]-Attribut und delegiert den Aufruf an __invoke()
 * des Original-Tools. Skalare Rueckgabewerte werden als
 * ['result' => ...] normalisiert, damit das ToolInterface (array)
 * erfuellt bleibt.
 */
final class AttributeToolAdapter implements ToolInterface
{
    private readonly string $name;
    private readonly string $description;

    public function __construct(
        private readonly object $tool
    ) {
        $attribute = self::resolveAsToolAttribute($tool);
        $this->name = $attribute->name;
        $this->description = $attribute->description ?? '';
    }

    public function __invoke(array $parameters = []): array
    {
        $result = ($this->tool)($parameters);

        return is_array($result) ? $result : ['result' => $result];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Liest das #[AsTool]-Attribut des umschlossenen Tools nativ per
     * Reflection aus (Blueprint: native Erweiterungspunkte, keine
     * Eigenbau-Bridges).
     */
    private static function resolveAsToolAttribute(object $tool): AsTool
    {
        $attributes = (new ReflectionClass($tool))->getAttributes(AsTool::class);
        foreach ($attributes as $attribute) {
            /** @var AsTool $instance */
            $instance = $attribute->newInstance();

            return $instance;
        }

        throw new \LogicException(
            sprintf('Tool %s hat kein #[AsTool]-Attribut und kann nicht adaptiert werden.', $tool::class)
        );
    }
}