# Debugging

## Logs

EVIE nutzt Monolog mit JSON-Formatter für prod/dev:

```bash
# Logs anzeigen
tail -f var/log/dev.log
tail -f var/log/prod.log
```

## Request-ID/Trace-ID

Jeder Request erhält `X-Request-ID` und `X-Trace-ID` Header
(`ObservabilityListener`). Diese können in Logs korreliert werden.

## Agent-Debugging

```bash
# Agent direkt aufrufen
php bin/console ai:agent:call orchestrator "Wie ist das Wetter in Berlin?"
```

## Datenbank-Inspektion

```bash
# ToolDefinitions
php bin/console doctrine:query:sql "SELECT name, status, executor_type FROM tool_definitions"

# AgentHistory
php bin/console doctrine:query:sql "SELECT * FROM agent_history ORDER BY created_at DESC LIMIT 10"

# AuditLogs
php bin/console doctrine:query:sql "SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 10"
```

## Container-Debugging

```bash
# Cache leeren
php bin/console cache:clear

# Container-Dump
php bin/console debug:container --show-arguments | grep -i toolbox

# Service-Infos
php bin/console debug:autowiring | grep -i "toolbox\|retriever\|guard"
```

## Häufige Probleme

| Problem | Lösung |
|---------|--------|
| `ServiceNotFoundException: ai.toolbox.orchestrator` | tools=false in test-env — DynamicToolbox wird nicht registriert (erwartet) |
| `MCP-Server nicht erreichbar` | `docker compose up -d` prüfen, Server-Konfiguration in `evie_mcp.yaml` |
| `AuthenticationException: Unauthorized` | `MISTRAL_API_KEY` in `.env` setzen |
| Tool nicht in Toolbox | `ToolDefinition.status` muss `approved` sein |
