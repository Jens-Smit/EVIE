<?php

namespace App\AI\Skills\Tool;

use ReflectionClass;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Registry fuer alle EVIE-Tools.
 *
 * Ermittelt den Tool-Namen nativ aus dem Symfony AI #[AsTool]-Attribut
 * (Blueprint: native Erweiterungspunkte, keine Eigenbau-Bridges). Tools,
 * die das custom ToolInterface implementieren (z. B. DynamicTool,
 * GenericToolExecutor), nutzen dessen getName() als Fallback.
 *
 * get() liefert fuer native #[AsTool]-Tools einen AttributeToolAdapter,
 * damit der Planner (Phase 3) nur Tools ankuedigt, die Phase 5 auch
 * tatsaechlich ausfuehren kann. Vor diesem Fix brach jeder statische
 * Tool-Schritt mit "ist kein ToolInterface" ab, weil die Registry den
 * Namen kannte (has() = true), das Tool aber nicht als ToolInterface
 * zurueckgeben konnte.
 */
final class ToolRegistry
{
    /** @var iterable<object> */
    private iterable $tools;

    /**
     * @param iterable<object> $tools
     */
    public function __construct(
        #[TaggedIterator('ai.tool')]
        iterable $tools
    ) {
        $this->tools = $tools;
    }

    /**
     * Gibt ein Tool nach Namen zurueck.
     *
     * Native #[AsTool]-Tools werden in einen AttributeToolAdapter
     * gekapselt und sind damit wie ToolInterface-Implementoren
     * ausfuehrbar.
     *
     * @throws \InvalidArgumentException Falls das Tool nicht gefunden wird.
     */
    public function get(string $name): ToolInterface
    {
        foreach ($this->tools as $tool) {
            if ($this->getToolName($tool) === $name) {
                if ($tool instanceof ToolInterface) {
                    return $tool;
                }
                if ($this->hasAsToolAttribute($tool)) {
                    return new AttributeToolAdapter($tool);
                }
                throw new \InvalidArgumentException(
                    sprintf('Tool "%s" ist weder ToolInterface noch mit #[AsTool] attribuiert und kann nicht ausgefuehrt werden.', $name)
                );
            }
        }
        throw new \InvalidArgumentException("Tool '$name' nicht gefunden.");
    }

    /**
     * Gibt alle registrierten Tools zurueck.
     *
     * @return array<string, object>
     */
    public function all(): array
    {
        $tools = [];
        foreach ($this->tools as $tool) {
            $tools[$this->getToolName($tool)] = $tool;
        }
        return $tools;
    }

    /**
     * Prueft, ob ein Tool mit dem gegebenen Namen existiert.
     */
    public function has(string $name): bool
    {
        foreach ($this->tools as $tool) {
            if ($this->getToolName($tool) === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ermittelt den Namen eines Tools nativ aus dem #[AsTool]-Attribut;
     * fuer ToolInterface-Implementoren (DynamicTool, GenericToolExecutor)
     * wird getName() als Fallback genutzt.
     */
    private function getToolName(object $tool): string
    {
        if ($tool instanceof ToolInterface) {
            return $tool->getName();
        }

        $reflection = new ReflectionClass($tool);
        $attributes = $reflection->getAttributes(AsTool::class);
        foreach ($attributes as $attribute) {
            /** @var AsTool $instance */
            $instance = $attribute->newInstance();
            return $instance->name;
        }

        throw new \LogicException(
            sprintf('Tool %s hat weder #[AsTool] noch ToolInterface::getName().', $tool::class)
        );
    }

    /**
     * Prueft, ob das Tool ein native #[AsTool]-Attribut traegt und damit
     * ueber den AttributeToolAdapter ausfuehrbar ist.
     */
    private function hasAsToolAttribute(object $tool): bool
    {
        return (new ReflectionClass($tool))->getAttributes(AsTool::class) !== [];
    }
}