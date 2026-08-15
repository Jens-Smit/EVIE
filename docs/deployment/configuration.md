# Configuration

## Umgebungsvariablen

| Variable | Erforderlich | Default | Beschreibung |
|----------|-------------|---------|-------------|
| `APP_ENV` | ✅ | `dev` | `dev`/`prod`/`test` |
| `APP_DEBUG` | ✅ | `1` | Debug-Modus (prod: `0`) |
| `APP_SECRET` | ✅ | — | Symfony App-Secret |
| `DATABASE_URL` | ✅ | — | PostgreSQL mit pgvector |
| `MISTRAL_API_KEY` | ✅ | — | Mistral LLM API-Key |
| `GEMINI_API_KEY` | optional | — | Alternative Platform |
| `MESSENGER_TRANSPORT_DSN` | optional | `doctrine://default` | Messenger-Transport |
| `TAVILY_API_KEY` | optional | — | Tavily Web-Search |
| `LINKEDIN_API_TOKEN` | optional | — | LinkedIn-Integration |
| `E2E_TESTING` | optional | — | `1` aktiviert E2E-Overlay |

## AI-Konfiguration (`config/packages/ai.yaml`)

```yaml
ai:
    platform:
        mistral:
            api_key: '%env(default::MISTRAL_API_KEY)%'
    agent:
        orchestrator:
            platform: 'ai.platform.mistral'
            model: 'mistral-small-latest'
            tools:
                - { service: 'App\AI\Skills\Tool\WeatherTool' }
                # ... weitere statische Tools
                # Dynamische Tools via DynamicToolbox (automatisch)
        tool_generator:
            platform: 'ai.platform.mistral'
            model: 'mistral-small-latest'
            tools: false  # keine zirkuläre Abhängigkeit
```

## MCP-Konfiguration (`config/packages/evie_mcp.yaml`)

```yaml
evie_mcp:
    servers:
        filesystem:
            transport: stdio
            command: npx
            arguments: ['-y', '@modelcontextprotocol/server-filesystem']
        playwright:
            transport: stdio
            command: npx
            arguments: ['-y', '@modelcontextprotocol/server-playwright']
        github:
            transport: stdio
            command: npx
            arguments: ['-y', '@modelcontextprotocol/server-github']
```

## Test-Konfiguration

- `config/packages/test/ai.yaml` — überschreibt AI-Config (tools: false)
- `config/packages/e2e/ai.yaml` — E2E-Overlay (E2E_TESTING=1)
- `phpunit.xml.dist` — SQLite-In-Memory, APP_ENV=test
