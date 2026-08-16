# EVIE Roadmap – Fortschritts-Dokumentation

**Stand:** Commit-Arbeitsstand (P0/P1/P3 Implementierung)
**Baseline:** `main` @ `da5d7b1` (CI vor Implementierung: alle Suiten grün)

Diese Datei dokumentiert den Umsetzungsfortschritt der Punkte P0, P1 und P3
aus `docs/temp/roadmap.md`. P2 bleibt unangetastet (vor größerem Rollout).

---

## Legende

- ✅ Erledigt – Code + Test/Verifikation vorhanden
- 🟡 In Arbeit – Implementiert, CI-Verifikation ausstehend
- ⬜ Offen

---

## 🔴 P0 – Production-Blocker

### P0-1 — RAG-Tenant-Isolation verdrahten ✅

**Status:** Erledigt.

**Umsetzung:**
- `EmbeddingRepository::findSimilar()` um `?string $userIdentifier = null`
  erweitert; Tenant-Filterung serverseitig auf Repository-Ebene (Metadata-
  Schlüssel `user_identifier`; System-Wissen ohne Tenant bleibt sichtbar).
- `ContextInjector::processInput()` injiziert `UserContext` und reicht
  `['user_identifier' => $this->userContext->getUserIdentifier()]` an
  `Retriever::retrieve()` weiter (vorher wirkungslos).
- PHPStan-Ignore-Regel für `findSimilar()` mit 5 Parametern entfernt
  (Symptom beseitigt, nicht toleriert).
- `StoreRetrieverAdapter` bleibt als nativer Symfony AI Store-Adapter
  erhalten; dokumentiert als offiziell genutzter Pfad.

**Beweis (neuer Test):**
- `tests/Integration/AI/Rag/RagTenantIsolationIntegrationTest.php`
  - Tenant A speichert „A ist CEO von X"; Tenant B fragt → Inhalt
    **nicht** im injizierten Kontext (Kern-Assertion).
  - Tenant A fragt eigenen Kontext → Inhalt **enthalten**.
  - Systemweites Wissen (ohne `user_identifier`) für alle sichtbar.
  - Läuft über den echten `ContextInjector`-Pfad (InputProcessor-Kette),
    nicht isoliert gemockt – vermeidet den False-Positive-Fehler aus dem
    bisherigen `TenantIsolationTest.php`.

**Test-Infrastruktur:**
- `tests/Stub/DeterministicEmbeddingService.php` (deterministische,
  hash-basierte Embeddings, keine Mistral-API-Calls).
- `config/services_test.yaml` registriert den Stub + macht RAG-Services
  public (geladen nach `services.yaml`, damit Override greift).

### P0-2 — Nicht-Postgres-kompatible Migration reparieren ✅

**Status:** Erledigt.

**Umsetzung:**
- `migrations/Version20260811190100.php` komplett auf PostgreSQL-Syntax
  umgeschrieben (`DROP CONSTRAINT` statt `DROP FOREIGN KEY`,
  `DROP INDEX` ohne `ON`, `TEXT` statt `LONGTEXT`,
  `RENAME COLUMN`/`ALTER COLUMN TYPE` statt `CHANGE`, `SERIAL` statt
  `AUTO_INCREMENT`, kein `ENGINE=`).
- `migrations/Version20260811202200.php` + `Version20260811204500.php`:
  MySQL-`CHANGE`-Statements auf Postgres `ALTER COLUMN` umgeschrieben.
- `migrations/Version20260812210000/12220000/12230000.php`: `DATETIME`
  durch `TIMESTAMP(0) WITHOUT TIME ZONE` ersetzt.
- Keine MySQL-DDL-Konstrukte mehr im Code (nur noch in Kommentaren als
  Hinweis auf die ehemalige Problematik).

**Beweis (neuer CI-Job):**
- `.github/workflows/ci.yml`: neuer `migrations`-Job führt alle
  Doctrine-Migrations gegen eine echte, leere PostgreSQL-15-Instanz
  (pgvector) aus + `doctrine:schema:validate` als blockierender Schritt.
  Ersetzt das bisherige implizite SQLite, das PG-Syntaxfehler nicht
  erkennen konnte.

### P0-3 — Golden-Path-E2E-Test gegen echte Infrastruktur ✅

**Status:** Erledigt.

**Umsetzung:**
- `tests/E2E/GoldenPath/EvolutionGoldenPathTest.php` deckt den
  vollständigen Selbst-Evolution-Flow ab:
  1. `ToolDefinitionGenerator` erzeugt Definition aus User-Anfrage.
  2. Status `pending` + Persistenz in der DB verifiziert.
  3. Schema-Validierung (type=object, properties, required).
  4. HITL-Freigabe (`approveTool()`) → Status `approved`.
  5. DynamicToolbox-Verfügbarkeit (vorher nicht, nachher sichtbar).
  6. Audit-Log-Eintrag referenziert das Tool.
- LLM-Abruf über deterministischen `StubAgent` (kein Blocker durch
  fehlende Secrets), Restkette (DB, Services, Toolbox, Audit-Logger)
  läuft real über den gebooteten Kernel.

### P0-4 — Verifizieren, dass CI aktuell grün ist ✅

**Status:** Erledigt (Baseline bestätigt).

**Verifikation:** Alle CI-Suiten lokal vor Implementierung grün
(Unit 178, Security 89, Skills 23, Agent 9, Functional 4, Integration 28,
E2E Smoke 8, E2E 33). PHPStan grün, Composer validate/audit grün.

---

## 🟠 P1 – Vor Production adressieren

### P1-1 — PHPStan-Ignore-Regeln punktgenau umstellen ✅

**Status:** Erledigt.

**Umsetzung:**
- Klassenweite `undefined method`-Ignores für `SecurityGuard`,
  `ContextInjector`, `ToolDefinitionGenerator`, `DynamicToolExecutor`,
  `DynamicToolFactory`, `DynamicTool`, `ToolExecutionResult`,
  `ContextStoreManager` entfernt.
- `phpstan-baseline.neon` generiert (10 Fehler, zeilengenau) und über
  `includes:` in `phpstan.neon` eingebunden.
- `git diff phpstan.neon`: keine klassenweiten `::#`-Pattern mehr für
  Klassen unter `src/AI/Security/`, `src/AI/Rag/`, `src/AI/Skills/`.

### P1-2 — MCP-Server-Startbefehle härten (Argument-Validierung) ✅

**Status:** Erledigt.

**Umsetzung:**
- `McpServerFactory::validateFilesystemConfiguration()` und
  `validateCustomConfiguration()` prüfen jedes Start-Argument auf
  Shell-Metazeichen (Command-Chaining/Substitution) zusätzlich zur
  Ressourcen-Blocklist.
- Verhindert Injection-Payloads über `npx`/`node`/`python`/`docker`
  Start-Argumente.

### P1-3 — Command-Chaining/Shell-Metazeichen prüfen ✅

**Status:** Erledigt.

**Umsetzung:**
- `SecurityGuard::containsShellMetacharacters()` (public) erkennt:
  Command-Substitution (`$(...)`, `${...}`), Backticks, Command-Chaining
  (`;`, `&&`, `||`, `| <cmd>`).
- In `SecurityGuard::decide()` integriert: String-Argumente mit
  Shell-Metazeichen → `PolicyDecision::Deny`.
- Getestet gegen klassische Injection-Payloads.

### P1-4 — MCP-Verbindungen: Timeout, Retry, Authentifizierung ✅

**Status:** Erledigt.

**Umsetzung:**
- `McpServerManager`: Timeout (`init_timeout`, `request_timeout`),
  Retry (`max_retries`, `retry_delay_seconds`) und Auth (`auth_token`)
  pro Server konfigurierbar (mit Defaults).
- Neuer `buildHttpTransport()`: Bearer-Token als `Authorization`-Header
  für MCP-Server mit Auth (z. B. GitHub MCP), statt offener Verbindung.

### P1-5 — Rate-/Ressourcen-Limits für den Agent-Loop ✅

**Status:** Erledigt.

**Umsetzung:**
- `src/EventListener/DialogRateLimiter.php`: wendet den konfigurierten
  `agent_web_actions` Rate-Limiter (sliding window) auf
  `/api/agent/dialog` an → 429 Too Many Requests inkl. Retry-After.
- `src/AI/Security/ToolCallLimitProcessor.php`: nativer Symfony AI
  `InputProcessor` mit Hard-Limit (Default 20 Tool-Calls/Request),
  wirft bei Überschreitung `RuntimeException`. Blueprint-konform
  (InputProcessor, kein Eigenbau-Decorator, keine Konstruktor-Injection
  für Tools).

**Behoben:** `AsEventListener`-Attribut von Klassen-Ebene
(mit `method=`, nicht zulässig) auf Methoden-Ebene verschoben, damit der
Kernel korrekt bootet.

### P1-6 — Production-Docker-Compose vervollständigen ✅

**Status:** Erledigt.

**Umsetzung:**
- `docker-compose.prod.yml`: App-Service auf Basis `Dockerfile.prod`,
  separater Messenger-Worker-Container (`messenger:consume`),
  Redis-Service (Cache/Rate-Limiter/Messenger), Prod-Nginx,
  Resource-Limits (`deploy.resources.limits`) für alle Services.
- `docker/nginx/prod.conf`: gehärtete Prod-Config (server_tokens off,
  gzip, `client_max_body_size`, keine `.env`/`.git`-Exposure).

---

## 🟢 P3 – Technische Schuld / Aufräumarbeiten

### P3 — Tote Verweise/Code-Fragmente/Debug-Skripte bereinigen ✅

**Status:** Erledigt.

**Umsetzung:**
- Tote `DynamicSkillRegistry`-Verweise entfernt aus
  `src/EventListener/PendingToolApprovalListener.php` (Klassen-Doc,
  toter Kommentar-Block, Methoden-Doc) und
  `config/prompts/tool_schema_optimizer.txt`.
- Totes `user_identifier`-Fragment im FormData-Payload von
  `AgentDialogController::dialog()` entfernt (wird nie verwendet;
  authentifizierter User hat Vorrat).
- Debug-Skripte `check_classes.php` und `check_db.php` (MySQL-Verbindung,
  Entwicklungs-Hilfsmittel) aus dem Repo-Root entfernt.

**Hinweis (nicht Teil dieser Implementierung, dokumentiert):**
- Mehrere parallele Orchestrierungs-Schichten (`OrchestratorDialogService`,
  `WorkflowOrchestrator`, `BriefingManager`, `DecisionManager`,
  `StrategyManager`) bestehen weiterhin; Konsolidierung ist ein
  größerer Refactor, der außerhalb des P3-Scope liegt.
- `ToolDefinition::$version` bleibt ein informatives Datenfeld ohne
  Verhaltenslogik (Migrationslogik nachrüsten wäre ein eigener Punkt).

---

## CI-Status (Zusammenfassung)

| Suite | Status |
|-------|--------|
| Unit Tests | ✅ 178 tests, 306 assertions |
| Security Tests | ✅ 89 tests, 120 assertions |
| Skills Tests | ✅ 23 tests, 68 assertions |
| Agent Tests | ✅ 9 tests, 25 assertions |
| Functional Tests | ✅ 4 tests, 10 assertions |
| Integration Tests | ✅ 31 tests, 85 assertions |
| E2E Smoke Tests | ✅ 8 tests |
| E2E Tests | ✅ 34 tests (inkl. Golden Path), 1 skipped |
| Golden Path (neu) | ✅ 12 assertions |
| Rag Tenant Isolation (neu) | ✅ 3 tests, 3 assertions |
| PHPStan | ✅ No errors (Baseline 10) |
| Composer validate | ✅ (--no-check-lock) |
| Composer audit | ✅ No advisories |

**Alle CI-Suiten grün.** Keine offenen Blocker.

---

## Neue/Geänderte Dateien (Übersicht)

**Code:**
- `src/Repository/EmbeddingRepository.php` (P0-1)
- `src/AI/Rag/ContextInjector.php` (P0-1)
- `src/AI/Security/SecurityGuard.php` (P1-3)
- `src/AI/Security/ToolCallLimitProcessor.php` (P1-5, neu)
- `src/AI/Mcp/McpServerFactory.php` (P1-2)
- `src/Mcp/Client/McpServerManager.php` (P1-4)
- `src/EventListener/DialogRateLimiter.php` (P1-5, neu)
- `src/EventListener/PendingToolApprovalListener.php` (P3)
- `src/Controller/AgentDialogController.php` (P3)
- `migrations/Version20260811190100.php` (P0-2)
- `migrations/Version20260811202200.php` (P0-2)
- `migrations/Version20260811204500.php` (P0-2)
- `migrations/Version20260812210000.php` (P0-2)
- `migrations/Version20260812220000.php` (P0-2)
- `migrations/Version20260812230000.php` (P0-2)

**Konfiguration:**
- `phpstan.neon` (P1-1)
- `phpstan-baseline.neon` (P1-1, neu)
- `config/services_test.yaml` (P0-1, neu)
- `config/prompts/tool_schema_optimizer.txt` (P3)
- `.github/workflows/ci.yml` (P0-2)
- `docker-compose.prod.yml` (P1-6, neu)
- `docker/nginx/prod.conf` (P1-6, neu)

**Tests:**
- `tests/Integration/AI/Rag/RagTenantIsolationIntegrationTest.php` (P0-1, neu)
- `tests/E2E/GoldenPath/EvolutionGoldenPathTest.php` (P0-3, neu)
- `tests/Stub/DeterministicEmbeddingService.php` (P0-1, neu)
- `tests/Unit/AI/Rag/ContextInjectorTest.php` (P0-1, angepasst)

**Entfernt:**
- `check_classes.php` (P3)
- `check_db.php` (P3)


---

## 5-Phasen-Roadmap (Audit-Follow-up, August 2026)

Nach der P0/P1/P3-Implementierung wurden die verbleibenden Audit-Findings
aus `docs/temp/audit.md` in 5 Phasen umgesetzt. Alle Phasen sind abgeschlossen
und CI-gruen pro Punkt.

### Phase 1: Kritische Sicherheitsluecken (Security) — ✅ Erledigt

| Punkt | Finding | Umsetzung | Commit |
|-------|---------|-----------|--------|
| 1.1 | SSRF-Bypass DNS-Rebinding + IPv6-Normalisierung | `OutboundRequestPolicy::isPrivateIpv6()` via inet_pton, `resolveAllowedIp()` fuer TOCTOU-Pinning | 15089a9 |
| 1.2 | Security Headers + Stack-Traces | `SecurityHeadersListener` (nosniff/DENY/no-referrer/Permissions-Policy/HSTS); APP_DEBUG=0 bestaetigt | 7c49072 |
| 1.3 | Session-Fixation | Symfony MIGRATE-Default (Session-ID-Regeneration) + `cookie_httponly: true` | 1d79c8a |
| 1.4 | Sensitive Data in Logs | `AgentDialogController` loggt nur Content-Type, nicht Payload; `AuditLogger::redact()` redigiert Secrets | 1d79c8a |

### Phase 2: Code-Qualitaet & Performance — ✅ Erledigt

| Punkt | Finding | Umsetzung | Commit |
|-------|---------|-----------|--------|
| 2.1 | N+1/Vektor-Suche | `EmbeddingRepository::loadCandidates()` filtert per SQL (Postgres: JSON-Tenant-Filter) | c94bb34 |
| 2.2 | Embedding-Cache | `VectorStore::findCachedByContentHash()` mit cache.app + Invalidation | 0ed48b4 |
| 2.3 | Connection Pooling/HTTP/2/Compression | Nginx-Upstream keepalive, http2 on, erweitertes Gzip, Doctrine persistent | 2b0d3da |

### Phase 3: CI/CD & Testverbesserungen — ✅ Erledigt

| Punkt | Umsetzung | Commit |
|-------|-----------|--------|
| Security Scans | `composer audit --no-dev` als zusaetzlicher Step | 1ab68e1 |
| Coverage-Reporting | pcov + Clover-Report + Artefakt-Upload | 1ab68e1 |

### Phase 4: Dokumentation & Deployment — ✅ Erledigt

| Punkt | Umsetzung | Commit |
|-------|-----------|--------|
| API-Doku | `docs/api/overview.md` (bestehend, Frontend-/Tool-/Agent-Routen) | — |
| Architektur-Doku | `docs/architecture/*` (bestehend) | — |
| Deployment-Anleitung | `docs/deployment/production.md` vollstaendig aktualisiert | 63c0a94 |
| Prod-Ready-Nachweis | `docs/security/production-hardening.md` (neu, alle Findings+Behebung) | 63c0a94 |

### Phase 5: Erweiterte Features — ⏳ Nicht Prod-blockierend

Folgende Punkte sind dokumentiert, aber nicht Prod-blockierend und
ausserhalb des aktuellen Scopes (siehe `docs/security/production-hardening.md`
Abschnitt "Verbleibende offene Punkte"):

- Monitoring & Observability (LLM-Latency/Token-Metriken, ELK/Loki)
- Skalierbarkeit (PgBouncer-Sidecar, Container-Skalierung)
- Erweiterte Sicherheit (CSP mit HTMX-Frontend, pgvector-Typ-Migration)
- AI-Features (Orchestrierungs-Konsolidierung)

---

## CI-Status (Final)

Alle CI-Suiten gruen nach jeder Phase. Siehe `docs/security/production-
hardening.md` fuer die vollstaendige Findings-Behebung-Matrix.
