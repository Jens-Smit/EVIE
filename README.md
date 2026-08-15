# EVIE — Self-Evolving AI Agent Platform

> **30-Sekunden-Erklärung:** EVIE ist eine AI-Agent-Plattform, die eigene Tools
> generiert, sicherheitsgeprüft freigibt und zur Laufzeit registriert — ohne
> eigenen PHP-Code zu schreiben. Sie baut auf **Symfony AI v0.12** und **Mistral LLM**
> auf und sichert jeden Tool-Aufruf durch Human-in-the-Loop (HITL), eine
> Policy-Engine (SSRF/Pfad/Command-Schutz) und Tenant-Isolation.

---

## Was ist EVIE?

EVIE ist ein **selbst-evolvierender KI-Agent**: Ein Orchestrator analysiert
User-Anfragen, wählt passende Tools aus und führt sie aus. Wenn kein passendes
Tool existiert, generiert der `ToolDefinitionGenerator` ein JSON-Schema für ein
neues Tool, das nach HITL-Freigabe über die native `DynamicToolbox` verfügbar
wird.

**Welches Problem löst EVIE?** Ein normaler LLM-Chatbot kann nur Text erzeugen.
EVIE kann **Handlungen ausführen** — API-Aufrufe, Datei-Operationen,
Datenbank-Queries — und dabei **neue Fähigkeiten erlernen**, ohne dass ein
Entwickler Code schreiben muss.

**Was unterscheidet EVIE von einem Chatbot?**

| Chatbot | EVIE |
|---------|------|
| Text → Text | Text → Tool-Ausführung → Ergebnis |
| Statisch (feste Fähigkeiten) | Dynamisch (generiert neue Tools) |
| Keine Sicherheitsprüfung | Policy-Engine + HITL vor jeder Ausführung |
| Single-Tenant | Multi-Tenant mit Isolation |
| Kein Audit | Vollständiges Audit-Logging |

**Technologien:** Symfony 7.4 · Symfony AI v0.12 · Mistral LLM · PostgreSQL/pgvector · Doctrine ORM · Messenger · MCP

---

## Architektur-Übersicht

```text
                         ┌───────────────┐
                         │     User      │
                         └───────┬───────┘
                                 │
                                 ▼
                     ┌─────────────────────┐
                     │   Agent Controller  │
                     └──────────┬──────────┘
                                │
                                ▼
                     ┌─────────────────────┐
                     │ Agent / Orchestrator│  ← Symfony AI native Agent
                     └──────────┬──────────┘
                                │
              ┌─────────────────┼─────────────────┐
              ▼                 ▼                 ▼
          RAG / Memory       Tools          Evolution Engine
          (InputProcessor)   (Toolbox)      (ToolDefinitionGenerator)
                                │                 │
                                │                 ▼
                                │          Schema Validation
                                │                 │
                                └────────────┬────┘
                                             ▼
                                    Security Guard
                                             │
                              ┌──────────────┼──────────────┐
                              ▼              ▼              ▼
                           ALLOW          ASK_USER         DENY
                              │              │
                              │              ▼
                              │             HITL
                              │              │
                              └───────┬──────┘
                                      ▼
                                   Executor
                                      │
                                      ▼
                                  Audit Log
```

→ **Vollständige Architektur-Doku:** [`docs/architecture/overview.md`](docs/architecture/overview.md)

---

## Self-Evolution Flow

Das ist das Kern-Alleinstellungsmerkmal von EVIE:

```text
User: "Analysiere diese Excel-Datei und gib mir den Umsatz."
  ↓
Orchestrator: kein passendes Tool gefunden
  ↓
ToolDefinitionGenerator (LLM-gestützt) → JSON-Schema
  ↓
SecurityGuard → PolicyDecision = ASK_USER
  ↓
ToolDefinition (status: pending) → PendingToolApprovalEvent
  ↓
Frontend: "Neues Tool 'ExcelParserTool' erforderlich. Genehmigen?"
  ↓
User: "Ja"
  ↓
ToolDefinition (status: approved)
  ↓
DynamicToolbox → Tool verfügbar beim nächsten Agent-Call
  ↓
Executor → Ergebnis
  ↓
AuditLogger
```

**Wichtig:** „Self-Evolving" bedeutet bei EVIE **keine** autonome
Quellcode-Modifikation. Evolution erfolgt auf der **Capability-Schicht** durch
kontrollierte Generierung, Validierung, Freigabe und Registrierung neuer Tools.

→ **Details:** [`docs/architecture/evolution.md`](docs/architecture/evolution.md)

---

## Capabilities

| Capability | Status | Beschreibung |
|------------|--------|--------------|
| AI Agent (Orchestrator) | ✅ | Nativer Symfony AI Agent mit Tool-Calling |
| Dynamic Tools | ✅ | `DynamicToolbox` (ToolboxInterface-Decorator) |
| Tool Generation | ✅ | LLM-gestützter `ToolDefinitionGenerator` |
| Self-Evolution | ✅ | pending → approved → verfügbar → revoked |
| HITL (Human-in-the-Loop) | ✅ | Natives `ToolCallRequested`-Event + `deny()` |
| Security Policy Engine | ✅ | `SecurityGuard` mit `PolicyDecision` (Allow/Deny/AskUser) |
| SSRF Protection | ✅ | Private IPs, localhost, IPv6, link-local |
| Filesystem Protection | ✅ | /etc, /proc, /sys, /dev, docker.sock |
| Command Execution Protection | ✅ | Executor-Whitelist (nur api/database/filesystem/http/generic) |
| Prompt Injection Awareness | ✅ | RAG-Kontext kann Policy-Entscheidung nicht umgehen |
| Tenant Isolation | ✅ | `UserContext` + Store-Level-Filtering |
| RAG | ✅ | `ContextInjector` (InputProcessor) + `StoreRetrieverAdapter` |
| Persistent Memory | ✅ | `ContextMemoryProvider` (MemoryProviderInterface) |
| Audit Logging | ✅ | `AuditLogger` + `AgentHistory`/`DecisionLog` |
| Observability | ✅ | Request-ID/Trace-ID (`ObservabilityListener`) |
| MCP | ⚠️ | Native `ChainFactory` + `McpServerManager` (Retry/Timeout), erweiterte Features offen |
| Production Docker | ⚠️ | `Dockerfile.prod` vorhanden, GHCR/Messenger-Worker offen |
| CI/CD | ✅ | E2E + Unit + Integration + Security + PHPStan + composer validate/audit |

---

## Security Model

```text
User Input
    ↓
Authentication (Symfony Security)
    ↓
Tenant Context (UserContext)
    ↓
Agent (Symfony AI)
    ↓
Tool Definition (ToolDefinition)
    ↓
Security Classification (securityLevel, requiresHitl)
    ↓
SecurityGuard → PolicyDecision
    ↓
┌─────────────────────────────────┐
│  ALLOW → Ausführung             │
│  ASK_USER → HITL-Freigabe       │
│  DENY → Blockiert + Audit       │
└─────────────────────────────────┘
    ↓
Executor (GenericApi/File/Database/Http)
    ↓
Audit Log (AuditLogger + AgentHistory)
```

**Getestete Angriffsvektoren:** SSRF (127.0.0.1, localhost, 169.254.169.254,
private IPv4/IPv6, 0.0.0.0, ::1, fe80::, fc00::), Filesystem (/etc/passwd,
docker.sock, /proc, /sys, /dev), Command Injection (shell/bash denied),
Prompt Injection (RAG-Kontext kann Policy nicht umgehen).

→ **Vollständige Security-Doku:** [`docs/security/threat-model.md`](docs/security/threat-model.md)

---

## Quick Start

```bash
# 1. Klonen
git clone https://github.com/Jens-Smit/EVIE.git
cd EVIE

# 2. Abhängigkeiten
composer install

# 3. Umgebung konfigurieren
cp .env.example .env
# .env bearbeiten: DATABASE_URL, MISTRAL_API_KEY setzen

# 4. Docker (PostgreSQL + pgvector + MCP-Server)
docker compose up -d

# 5. Datenbank-Migration
php bin/console doctrine:migrations:migrate

# 6. Server starten
symfony serve
# → http://localhost:8000
```

### Konfiguration

| Variable | Beschreibung | Beispiel |
|----------|-------------|----------|
| `DATABASE_URL` | PostgreSQL mit pgvector | `postgresql://evie:pw@localhost:5432/evie` |
| `MISTRAL_API_KEY` | Mistral LLM API-Key | `your-key` |
| `GEMINI_API_KEY` | (optional) Gemini Platform | `your-key` |
| `MESSENGER_TRANSPORT_DSN` | Messenger-Transport | `doctrine://default` |

### Verifizierung

```bash
# Alle Tests ausführen
vendor/bin/phpunit

# Nur E2E
vendor/bin/phpunit --testsuite="E2E Tests"

# Nur Security-Hardening
vendor/bin/phpunit --testsuite="EVIE AI Security Tests"
```

---

## Testing

| Suite | Zweck | CI-Step |
|-------|------|---------|
| **E2E Tests** | Vollständige App (Auth, Navigation, Evolution-Flow) | `E2E tests (test/dev/prod env)` |
| **Unit Tests** | Einzelne Klassen (DynamicToolbox, HitlListener, SecurityGuard, …) | `Unit tests` |
| **Security Tests** | Angriffsvektoren (SSRF, Filesystem, Command, Prompt-Injection) | `Security tests` |
| **Skills Tests** | Tool-System (DynamicToolExecutor, ToolExecutionResult) | `Skills tests` |
| **Agent Tests** | Agent-Verhalten (OrchestratorAgent, SubAgentFactory) | `Agent tests` |
| **Integration Tests** | Komponenten zusammen (Evolution-Flow, Streaming) | `Integration tests` |

→ **Test-Strategie:** [`docs/testing/test-strategy.md`](docs/testing/test-strategy.md)

---

## Dokumentation

| Thema | Pfad |
|-------|------|
| Architektur-Übersicht | [`docs/architecture/overview.md`](docs/architecture/overview.md) |
| Agent-Architektur | [`docs/architecture/agent-architecture.md`](docs/architecture/agent-architecture.md) |
| Self-Evolution | [`docs/architecture/evolution.md`](docs/architecture/evolution.md) |
| Tool-System | [`docs/architecture/tool-system.md`](docs/architecture/tool-system.md) |
| RAG | [`docs/architecture/rag.md`](docs/architecture/rag.md) |
| Security Model | [`docs/security/threat-model.md`](docs/security/threat-model.md) |
| Tenant Isolation | [`docs/security/tenant-isolation.md`](docs/security/tenant-isolation.md) |
| Setup & Testing | [`docs/development/setup.md`](docs/development/setup.md) |
| Tool erstellen | [`docs/development/creating-tools.md`](docs/development/creating-tools.md) |
| Production Docker | [`docs/deployment/docker.md`](docs/deployment/docker.md) |
| Architecture Decisions | [`docs/decisions/`](docs/decisions/) |
| API-Endpunkte | [`docs/api/overview.md`](docs/api/overview.md) |
| Roadmap | [`docs/roadmap.md`](docs/roadmap.md) |

---

## Project Status

| Komponente | Status |
|------------|--------|
| Core Agent (Symfony AI) | ✅ Implementiert |
| DynamicToolbox (native Decorator) | ✅ Implementiert |
| ToolDefinitionGenerator | ✅ Implementiert |
| HITL (ToolCallRequested) | ✅ Implementiert |
| SecurityGuard (PolicyDecision) | ✅ Implementiert |
| Tenant Isolation (UserContext) | ✅ Implementiert |
| RAG (InputProcessor + StoreAdapter) | ✅ Implementiert |
| Audit Logging | ✅ Implementiert |
| CI/CD (composer validate/audit, PHPStan, alle Test-Suiten) | ✅ Implementiert |
| Production Docker (Dockerfile.prod) | ⚠️ Grundgerüst vorhanden |
| Advanced MCP (Discovery, Auth, Netzwerkisolation) | ⚠️ Teilweise |
| Distributed Messenger Workers | ⏳ Geplant |
| Advanced Scheduling | ⏳ Geplant |
| GHCR Image Publishing | ⏳ Geplant |

---

## Limitations

- **Self-Evolution** betrifft Tools/Capabilities, **nicht** Core-Code — EVIE
  schreibt keinen PHP-Code, sondern generiert JSON-Schema-basierte Tool-Definitionen.
- **Prompt Injection** ist nicht vollständig lösbar — RAG-Kontext erhält nie die
  gleiche Vertrauensstufe wie System-Instructions; die Policy-Engine ist unabhängig.
- **Production Deployment** benötigt externe Infrastruktur (nginx, Redis,
  Messenger-Worker, MCP-Services).
- **LLM-Ausgaben** sind probabilistisch — Tool-Schema-Generierung kann fehlschlagen.
- **MCP** benötigt laufende MCP-Server (filesystem, playwright, github).
- **RAG-Qualität** hängt vom Embedding-/Retrieval-Modell ab.

---

## Technology Choices

| Technologie | Warum? |
|-------------|--------|
| **Symfony 7.4** | Enterprise-Framework, DI, Security, Messenger, Forms |
| **Symfony AI v0.12** | Native Agent/Toolbox/Platform/Store-Interfaces — keine Eigenbau-Infrastruktur |
| **PostgreSQL + pgvector** | Vektor-Ähnlichkeitssuche für RAG, ACID, Production-reif |
| **Doctrine ORM** | Standard-PHP-ORM, Migrations, Repository-Pattern |
| **Messenger** | Asynchrone Tool-Ausführung, Streaming, decoupled Workers |
| **MCP** | Model Context Protocol für externe Tools (filesystem, playwright, github) |
| **Mistral LLM** | Europäischer Anbieter, Tool-Calling, strukturierte Ausgabe |
| **HITL** | Kontrollierte Autonomie — sicherer als autonome Tool-Ausführung |

→ **Architectural Decision Records:** [`docs/decisions/`](docs/decisions/)

---

## License

Privat — Vision Gastro / AiCabs. Kontakt: [Jens Smit](https://jens-smit.de)
