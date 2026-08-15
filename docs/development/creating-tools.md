# Tool erstellen — Developer Tutorial

## Übersicht

Ein EVIE-Tool besteht aus:
1. `ToolDefinition` (persistiert in DB)
2. JSON-Schema für Parameter
3. Executor (einer von: api, database, filesystem, http, generic)
4. Security-Level (low/medium/high)
5. HITL-Policy (requiresHitl)

## Statisches Tool (#[AsTool])

```php
#[AsTool(
    'weather',
    'Liefert Wetterdaten für eine Stadt (Parameter: {"city": "Stadtname"})'
)]
final class WeatherTool
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'WEATHER_API_KEY')] private readonly string $apiKey,
    ) {
    }

    public function __invoke(string $city): array
    {
        $response = $this->httpClient->request('GET', 'https://api.weatherapi.com/v1/current.json', [
            'query' => ['key' => $this->apiKey, 'q' => $city],
        ]);

        return $response->toArray();
    }
}
```

Registrierung in `config/packages/ai.yaml`:
```yaml
ai:
    agent:
        orchestrator:
            tools:
                - { service: 'App\AI\Skills\Tool\WeatherTool' }
```

## Dynamisches Tool (via Evolution)

Dynamische Tools werden nicht als PHP-Klasse geschrieben, sondern als
`ToolDefinition` persistiert und über die `DynamicToolbox` zur Laufzeit
als native `Tool`-Objekte geladen:

```php
$definition = (new ToolDefinition())
    ->setName('csv_analyzer')
    ->setDescription('Analysiert CSV-Dateien')
    ->setSchema([
        'type' => 'object',
        'properties' => ['path' => ['type' => 'string']],
        'required' => ['path'],
    ])
    ->setExecutorType('filesystem')
    ->setSecurityLevel('low')
    ->setStatus('approved');

$this->entityManager->persist($definition);
$this->entityManager->flush();
// DynamicToolbox liefert das Tool ab dem nächsten getTools()-Call.
```

## Security-Level wählen

| Level | Verhalten |
|-------|-----------|
| `low` | Allow (keine HITL erforderlich) |
| `medium` | Allow (außer bei Policy-Verstoß) |
| `high` | AskUser (HITL-Freigabe erforderlich) |

Setze `requiresHitl: true` für Tools mit Nebenwirkungen (E-Mails, DB-Schreibzugriff).

## Tests schreiben

```php
public function testCsvAnalyzerToolExecutes(): void
{
    $definition = (new ToolDefinition())
        ->setName('csv_analyzer')
        ->setStatus('approved')
        ->setExecutorType('filesystem');

    $repo = $this->createMock(ToolDefinitionRepository::class);
    $repo->method('findBy')->willReturn([$definition]);

    $inner = $this->createMock(ToolboxInterface::class);
    $inner->method('getTools')->willReturn([]);

    $toolbox = new DynamicToolbox($inner, $repo);
    $tools = $toolbox->getTools();

    self::assertCount(1, $tools);
    self::assertSame('csv_analyzer', $tools[0]->getName());
}
```
