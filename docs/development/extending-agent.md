# EVIE erweitern

## Neuen Executor hinzufügen

1. Klasse erstellen, die `ExecutorInterface` implementiert:
```php
final class GenericGraphExecutor implements ExecutorInterface
{
    public function execute(DynamicTool $tool, array $parameters): mixed { /* … */ }
    public function getType(): string { return 'graph'; }
}
```

2. In `SecurityGuard::$allowedExecutors` aufnehmen und `EXECUTOR_MAP` im
   `DynamicToolbox`/`SecurityGuard::resolveExecutorClass()` ergänzen.

3. Service registrieren (Autowiring übernimmt `App\`).

## Neuen AI-Provider hinzufügen

In `config/packages/ai.yaml`:
```yaml
ai:
    platform:
        openai:
            api_key: '%env(default::OPENAI_API_KEY)%'
    agent:
        orchestrator:
            platform: 'ai.platform.openai'
```

## SecurityGuard erweitern

Neue Checks in `SecurityGuard::decide()` hinzufügen. Alle Checks delegieren an
bestehende `isUrlSafe()`/`isPathSafe()`/`isServiceAllowed()` oder neue
Prüfmethoden. `PolicyDecision`-Enum: `Allow`/`Deny`/`AskUser`.

## Neuen Retriever hinzufügen

`StoreRetrieverAdapter` wrappt den EVIE-`Retriever`. Für einen alternativen
Store: eine Klasse, die `Symfony\AI\Store\RetrieverInterface` implementiert und
als Service registriert.

## Neuen Event-Listener hinzufügen

```php
#[AsEventListener(event: ToolCallRequested::class)]
final class MyToolListener
{
    public function __invoke(ToolCallRequested $event): void
    {
        // Inspect $event->getToolCall(), $event->getDefinition()
        // $event->deny($reason) zum Blockieren
    }
}
```

## Tests schreiben

- **Unit:** `tests/Unit/AI/` — einzelne Klassen mit Mocks
- **Integration:** `tests/Integration/AI/` — Komponenten zusammen
- **Security:** `tests/Unit/AI/Security/` — Angriffsvektoren
- **E2E:** `tests/E2E/` — vollständige App
