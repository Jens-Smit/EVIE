# Tool-System

## ToolDefinition

Eine `ToolDefinition` ist die persistente Repräsentation eines Tools:

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `name` | string | Eindeutiger Tool-Name |
| `description` | text | LLM-sichtbare Beschreibung |
| `schema` | JSON | JSON-Schema für Parameter |
| `executorType` | string | `api`/`database`/`filesystem`/`http`/`generic` |
| `executorConfig` | JSON | Executor-spezifische Konfiguration |
| `securityLevel` | string | `low`/`medium`/`high` |
| `requiresHitl` | bool | HITL-Freigabe erforderlich |
| `status` | string | `pending`/`approved`/`rejected` |
| `version` | string | Tool-Version (Default: `1.0`) |

## Tool-Typen

### Statische Tools (`#[AsTool]`)
Feste PHP-Klassen mit `#[AsTool]`-Attribut: `WeatherTool`, `FileReadTool`,
`DataAnalyzerTool`, `ExcelParserTool`, `EmailTool`, `LinkedInTool`, `OAuthTool`,
`RestApiTool`, `UserTypeLookupTool`.

### Subagent-Tools
Native `Symfony\AI\Agent\Toolbox\Tool\Subagent`-Instanzen, die einen
verschachtelten `Agent` kapseln. Die `SubAgentFactory` erzeugt sie, die
`EvieToolboxFactory` registriert sie als Tools.

### Dynamische Tools
Aus `ToolDefinition`-Entities (Status `approved`) erzeugte native
`Symfony\AI\Platform\Tool\Tool`-Objekte. Die `DynamicToolbox` liefert sie bei
jedem `getTools()`-Call live aus der Datenbank:

```php
$tool = new Tool(
    new ExecutionReference(GenericApiExecutor::class),
    $definition->getName(),
    $definition->getDescription(),
    $definition->getSchema(),
);
```

## Executor-Whitelist

Nur folgende Executor-Typen sind zugelassen:

| Executor-Typ | Klasse |
|-------------|--------|
| `api` | `GenericApiExecutor` |
| `database` | `GenericDatabaseExecutor` |
| `filesystem` | `GenericFileExecutor` |
| `http` | `GenericHttpExecutor` |
| `generic` | `GenericExecutor` |

Andere Typen (`shell`, `bash`, …) werden vom `SecurityGuard` mit
`PolicyDecision::Deny` blockiert.

## DynamicToolbox

Die `DynamicToolbox` ist ein nativer `ToolboxInterface`-Decorator
(registriert via `RegisterDynamicToolboxDecoratorPass`). Sie dekoriert
`ai.toolbox.orchestrator` und ergänzt zur Laufzeit die approved
`ToolDefinition`-Entities als native `Tool`-Objekte.
