<?php

declare(strict_types=1);

namespace App\AI\Skills\Tool;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
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
 *
 * Parameter-Mapping: Native Tools haben zwei Signatur-Stile, die der
 * Adapter beide bedient:
 *  - __invoke(array ...): das Tool erwartet die komplette Parameter-Map
 *    als ein Argument (z.B. StrategyDocumentTool, DataAnalyzerTool).
 *  - __invoke(string $namedParameter, ...): das Tool erwartet benannte
 *    Einzelparameter (z.B. UserTypeLookupTool::__invoke(string
 *    $userIdentifier)). Der Adapter bildet die Parameter-Map per
 *    Reflection auf die Parameter-Namen der Signatur ab und verwirft
 *    bewusst Schluessel, die die Signatur nicht kennt (z.B. den von
 *    mergeInputs() ergaenzten 'input_from'-Key). Ohne dieses Mapping
 *    erhielt das Tool das gesamte Array als erstes Argument und
 *    scheiterte an scalaren Type-Hints (TypeError im e2e-llm-Lauf,
 *    SetupTaskAutonomousE2ETest).
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
        $method = (new ReflectionClass($this->tool))->getMethod('__invoke');
        $result = $this->tool->{ '__invoke' }(...$this->buildArguments($method, $parameters));

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
     * Bildet die Parameter-Map auf die __invoke()-Signatur des Original-
     * Tools ab. Erwartet die Signatur genau einen array-typisierten
     * Parameter, erhaelt dieser die komplette Map (Registry-Stil). Andernfalls
     * werden die Map-Schluessel per Name auf die deklarierten Parameter
     * abgebildet; Schluessel ohne Gegenstueck in der Signatur werden
     * verworfen, Default-Werte fehlender Parameter werden uebernommen.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function buildArguments(ReflectionMethod $method, array $parameters): array
    {
        $signatureParameters = $method->getParameters();

        if (count($signatureParameters) === 1) {
            $type = $signatureParameters[0]->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === 'array') {
                return [$parameters];
            }
        }

        $arguments = [];
        foreach ($signatureParameters as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $parameters)) {
                $arguments[$name] = $parameters[$name];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[$name] = $parameter->getDefaultValue();
            }
        }

        return $arguments;
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
