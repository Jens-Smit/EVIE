# Test-Strategie

## Test-Suiten

| Suite | Verzeichnis | Zweck | CI-Step |
|-------|-----------|------|---------|
| E2E Tests | `tests/E2E/` | Vollständige App (Auth, Navigation, Pages) | `E2E tests (test/dev/prod env)` |
| Unit Tests | `tests/Unit/AI/` | Einzelne Klassen (DynamicToolbox, HitlListener, …) | `Unit tests` |
| Security Tests | `tests/Unit/AI/Security/` | Angriffsvektoren (SSRF, Filesystem, Command, Prompt-Injection) | `Security tests` |
| Skills Tests | `tests/Unit/AI/Skills/` | Tool-System (DynamicToolExecutor, DynamicToolbox) | `Skills tests` |
| Agent Tests | `tests/Unit/AI/Agent/` | Agent-Verhalten (OrchestratorAgent, SubAgentFactory) | `Agent tests` |
| Integration Tests | `tests/Integration/AI/` | Komponenten zusammen (Evolution-Flow, Streaming) | `Integration tests` |

## Test-Struktur

```text
tests/
├── E2E/
│   ├── AuthFlowTest.php          — Login, Registrierung, Logout
│   ├── NavigationPagesTest.php   — Sidebar-Seiten-Abdeckung
│   └── (EvolutionFlowIntegrationTest ersetzt E2E-Evolution)
├── Integration/AI/
│   ├── EvolutionFlowIntegrationTest.php — pending→approved→revoked, SSRF, HITL
│   └── Streaming/StreamingSessionManagerIntegrationTest.php
├── Unit/
│   ├── AI/
│   │   ├── Agent/OrchestratorAgentTest.php
│   │   ├── Mcp/McpServerFactoryTest.php, McpToolExecutorTest.php
│   │   ├── Rag/ContextInjectorTest.php
│   │   ├── Security/
│   │   │   ├── SecurityGuardTest.php
│   │   │   ├── SecurityGuardDecisionTest.php
│   │   │   ├── SecurityHardeningTest.php   — SSRF/Filesystem/Command/Prompt-Injection
│   │   │   ├── SsrfBypassTest.php
│   │   │   ├── TenantIsolationTest.php
│   │   │   └── HitlListenerTest.php
│   │   └── Skills/DynamicToolboxTest.php, DynamicToolExecutorTest.php
│   ├── Controller/HTMX/HTMXControllerTest.php
│   └── Message/StreamChunkMessageTest.php, …
└── Stub/NullMercureHub.php
```

## E2E-Umgebungen

E2E-Tests laufen gegen drei Umgebungen mit `E2E_TESTING=1`-Overlay:
- **test** — Symfony test-env, SQLite-In-Memory, `tools: false`
- **dev** — dev-env container compile, SQLite-In-Memory
- **prod** — prod-env cache:warmup, SQLite-In-Memory

Keine echten externen Services (Mercure, Mistral) erforderlich.

## CI-Pipeline

```text
composer install → Wait for PostgreSQL →
E2E (test) → E2E (dev) → E2E (prod) →
Unit → Security → Skills → Agent → Integration →
composer validate → composer audit → PHPStan
```
