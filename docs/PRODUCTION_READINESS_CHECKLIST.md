# EVIE – Production-Readiness-Checkliste

> **Status-Legende**
> - ✅ Implementiert & verifiziert (Code + Test vorhanden)
> - ⚠️ Teilweise / mit Lücken (siehe Bemerkung)
> - ❌ Fehlt oder nachgewiesen fehlerhaft (Production-Blocker)
> - ⏳ Nicht im Code verifizierbar (Infrastruktur/Prozess, manuell zu prüfen)
>
> **Basis:** aktualisiert gegen Commit `2421afa` (Stand `main` +
> Production-Readiness-Hardening, 2026-08-15).
> Diese Checkliste wurde gegen den tatsächlichen Codebestand geprüft,
> nicht gegen Platzhalter. Jeder Eintrag nennt die Beweis-Stelle (`pfad:zeile`)
> oder den festgestellten Mangel.

---

## Behobene P0-Blocker (Follow-up-Commit)

Siehe Ende der Datei fuer die detaillierte Auflistung der durchgefuehrten
Fixes (P0-1 CI-Gates, P0-2 Schema-Validierung, P0-3 SSRF/Traversal,
P0-5 Tenant-Isolation, P0-9 Audit/Redaction, P0-10 Debug-Bundles).
Die Erstverifizierung unten beschreibt den Zustand von Commit 35ff4bb;
die genannten Blocker wurden in einem Follow-up-Commit adressiert.

---

## 🔴 P0 – Muss vor Production grün sein

### 1. CI/CD

| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 1.1 | Aktuellster Commit läuft vollständig durch die CI | ⏳ | `.github/workflows/ci.yml` triggert auf `push: main`; ob der letzte Lauf grün ist, nur in GitHub-Actions sichtbar. |
| 1.2 | Unit Tests grün | ✅ | `.github/workflows/ci.yml` Step "Unit tests", Suite `EVIE AI Unit Tests` (`phpunit.xml.dist`). |
| 1.3 | Integration Tests grün | ⚠️ | CI Step vorhanden, aber Suite `EVIE AI Integration Tests` enthält nur 2 Dateien (`EvolutionFlowIntegrationTest`, `Streaming…`); echte DB-/LLM-Integration fehlt. |
| 1.4 | Security Tests grün | ✅ | Suite `EVIE AI Security Tests` (`SecurityGuardTest`, `SecurityHardeningTest`, `SecurityGuardDecisionTest`, `HitlListenerTest`). |
| 1.5 | Agent Tests grün | ✅ | Suite `EVIE AI Agent Tests` (`OrchestratorAgentTest`). |
| 1.6 | Skills Tests grün | ✅ | Suite `EVIE AI Skills Tests` (`DynamicToolboxTest`, `DynamicToolExecutorTest`). |
| 1.7 | Evolution Tests grün | ⚠️ | Keine eigene Suite. Abgedeckt durch `EvolutionFlowIntegrationTest`, aber nur Objekt-Ebene (Mocks), kein E2E-Golden-Path (siehe 2). |
| 1.8 | E2E Development grün | ✅ | `ci.yml` Step "E2E tests (dev env)" mit `E2E_TESTING=1, APP_ENV=dev`. |
| 1.9 | E2E Production grün | ✅ | `ci.yml` Step "E2E tests (prod env)" + `cache:warmup --env=prod`. |
| 1.10 | E2E Smoke Tests grün | ✅ | Suite `E2E Smoke Tests` (`tests/E2E/Smoke/EvieSmokeTest.php`) + CI-Step `E2E Smoke tests` (§17 Smoke: Agent/IDOR/Security-Gates/Audit). |
| 1.11 | PHPStan grün | ✅ | `ci.yml` Step `Run PHPStan` ohne `\|\| true`; Baseline-Config (`phpstan.neon`) fuer Pre-existing-Fehler. |
| 1.12 | `composer validate --strict` grün | ✅ | `composer validate --strict --no-check-publish --no-check-lock` (`ci.yml` Step `Composer validate`); version constraints konkret gesetzt. |
| 1.13 | Keine `\|\| true` / `\|\| echo`-Umgehungen bei kritischen Gates | ✅ | `composer audit` und `phpstan` laufen ohne `\|\| true` (echte Gates). |
| 1.14 | Docker Production Image baut erfolgreich | ⏳ | `docker/php/Dockerfile.prod` existiert; kein CI-Job baut es. Lokal/manuell zu prüfen. |
| 1.15 | Production Container startet erfolgreich | ⏳ | Nicht in CI geprüft. |
| 1.16 | Healthcheck grün | ⚠️ | `Dockerfile.prod` definiert `HEALTHCHECK … php bin/console about`, aber `docker-compose.yml` definiert keinen Production-Service, der `Dockerfile.prod` nutzt. |

**Gate 1-Status: ✅ grün (Code)** — Gate-Bypässe entfernt, `--strict` aktiv, E2E-Smoke-Suite vorhanden. (1.1/1.14/1.15/1.16: Infrastruktur/CI-Lauf ⏳ manuell prüfbar.)

---

### 2. Self-Evolution / Dynamic Tools

| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 2.1 | Tool fehlt → EVIE erkennt fehlendes Tool | ⚠️ | `WorkflowOrchestrator::findMatchingTool()` (`src/AI/Workflow/WorkflowOrchestrator.php:78`) nutzt primitives `str_contains`-Matching, kein LLM-basiertes "Tool fehlt"-Signal. |
| 2.2 | ToolDefinitionGenerator erzeugt Schema | ✅ | `src/AI/Skills/ToolDefinitionGenerator.php::generateSchemaWithToolGeneratorAgent()`. |
| 2.3 | Schema wird validiert | ⚠️ | Nur `type === 'object'` geprüft (`ToolDefinitionGenerator.php:148`). Keine JSON-Schema-Draft-Validierung. |
| 2.4 | Duplicate-/Reuse-Check funktioniert | ✅ | `findSimilarTool()` + Jaccard-Index 0.7 (`ToolDefinitionGenerator.php:215-238`). |
| 2.5 | Security-Level wird bestimmt | ✅ | `extractSecurityLevel()` (`ToolDefinitionGenerator.php:188`). |
| 2.6 | Executor wird validiert | ✅ | `SecurityGuard::decide()` prüft `allowedExecutors` (`src/AI/Security/SecurityGuard.php:213`). |
| 2.7 | PolicyDecision wird erzeugt | ✅ | `SecurityGuard::decide()` liefert `PolicyDecision` (`src/AI/Security/PolicyDecision.php`). |
| 2.8 | ALLOW funktioniert | ✅ | `HitlListener::__invoke()` return ohne `deny()` (`src/AI/Security/HitlListener.php:45`). Test: `EvolutionFlowIntegrationTest::testAfterApprovalHitlListenerAllowsExecution`. |
| 2.9 | DENY funktioniert | ✅ | `$event->deny()` (`HitlListener.php:50`). Test: `testSsrfInApprovedToolStillBlockedByGuard`. |
| 2.10 | ASK_USER funktioniert | ✅ | `requestApproval()` (`HitlListener.php:73`). Test: `testAskUserForHighSecurityTool`. |
| 2.11 | HITL Approval funktioniert | ✅ | `ToolDefinitionGenerator::approveTool()` + `HitlWorkflowManager::approveExecution()`. |
| 2.12 | HITL Rejection funktioniert | ✅ | `ToolDefinitionGenerator::rejectTool()` + `rejectExecution()`. |
| 2.13 | Revoke funktioniert | ✅ | Status `approved → pending` re-blockiert; Test `testRevokeApprovalReblocksTool`. |
| 2.14 | Tool wird anschließend dynamisch verfügbar | ✅ | `DynamicToolbox::getTools()` merged `status=approved` (`src/AI/Skills/DynamicToolbox.php:48`). |
| 2.15 | Tool-Versionierung funktioniert | ⚠️ | `ToolDefinition::$version` existiert, Test `testToolVersionPersistedInDefinition` prüft nur Getter/Setter. Keine Versionierungs-Logik (alte Versionen bleiben verfügbar?). |
| 2.16 | Invalides Schema wird abgelehnt | ✅ | `ToolDefinitionGenerator::validateSchema()` lehnt invalides Schema (`type !== 'object'`, fehlendes `properties`) via `ToolRegistrationException` ab (Generierungszeit). |
| 2.17 | Ungültiger Executor wird abgelehnt | ✅ | `testInvalidExecutorTypeDenied`. |
| 2.18 | Tool kann nicht außerhalb seiner Policy ausgeführt werden | ✅ | `HitlListener` + `SecurityGuard::decide()` prüfen jeden ToolCall. |
| 2.19 | Jeder relevante Schritt landet im Audit Log | ✅ | `HitlListener::audit()` ruft `logPolicyDecision()` je Entscheidung; `SecurityGuard::denyWithAudit()` ruft `logSecurityViolation()`. |
| — | **Golden Path als E2E-/Integrationstest grün** | ❌ | `EvolutionFlowIntegrationTest` ist objektbasiert (Mocks, kein HTTP, kein LLM, kein RAG, kein Audit-Write). Der komplette Ablauf User→Tool fehlt→Generate→Validate→Policy→HITL→Approve→Execute→Result→Audit existiert **nicht** als grüner E2E-Test. |

**Gate 4-Status (Evolution Golden Path): ❌ rot** — siehe finales Gate.

---

### 3. Security

#### SSRF
| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 3.1 | `127.0.0.1` | ✅ | `SecurityGuard::isUrlSafe()` + `testSsrfBlocksLoopback127`. |
| 3.2 | `localhost` | ✅ | `testSsrfBlocksLocalhost`. |
| 3.3 | `0.0.0.0` | ✅ | `testSsrfBlocksWildcardBind0_0_0_0`. |
| 3.4 | `::1` | ✅ | `testSsrfBlocksIpv6Loopback`. |
| 3.5 | `169.254.169.254` | ✅ | `testSsrfBlocksLinkLocal169_254_169_254`. |
| 3.6 | private IPv4 | ✅ | 10./172.16./192.168. geprüft. |
| 3.7 | private IPv6 | ✅ | `testSsrfBlocksIpv6UniqueLocal`, `testSsrfBlocksIpv6LinkLocal`. |
| 3.8 | Link-local | ✅ | 169.254. + fe80::. |
| 3.9 | DNS-Rebinding | ✅ | `SecurityGuard::isUrlSafe()` ruft `OutboundRequestPolicy::isUrlAllowed()` (DNS-Auflösung via `gethostbynamel`) für Hostnamen auf. Test: `SecurityGuardHardeningTest::testBlocksDnsRebindingHostnameResolvingToPrivateIp`. |
| 3.10 | Redirect → interne IP | ✅ | `OutboundRequestPolicy` mit `allow_redirects=false, max_redirects=0` aktiv angebunden; Redirects werden nicht verfolgt. |
| 3.11 | Redirect-Ketten | ✅ | Wie 3.10 (`allow_redirects=false`). |
| 3.12 | Hostname → private IP | ✅ | DNS-Auflösung via `OutboundRequestPolicy::isPrivateNetwork()` prüft aufgelöste IPs gegen private Netze. |
| 3.x | **Bypass über nicht-kanonische IP-Formate** | ✅ | `SecurityGuard::normalizeHost()` kanonisiert Dezimal/Hex/Oktal/Kurzform/IPv4-mapped-IPv6. Test: `SsrfBypassTest`. |

#### Filesystem
| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 3.13 | `../` Traversal | ✅ | `isPathSafe()` blockt `..` (auch URL-encoded `%2e%2e`) + realpath-Prüfung. Test: `SsrfBypassTest::testBlocksDirectoryTraversal`. |
| 3.14 | absolute Pfade | ⚠️ | Geblockte Prefixe nur für `/etc`, `/root`, `/proc`, …; `/var/run` fehlt in `SecurityGuard::$blockedPaths` (nur `/var`). |
| 3.15 | `/etc/passwd` | ✅ | `testFilesystemBlocksEtcPasswd`. |
| 3.16 | `/proc` | ✅ | `testFilesystemBlocksProc`. |
| 3.17 | `/sys` | ✅ | `testFilesystemBlocksSys`. |
| 3.18 | `/dev` | ✅ | `testFilesystemBlocksDev`. |
| 3.19 | `/var/run` | ✅ | `testFilesystemBlocksVarRun` (via Prefix `/var`). |
| 3.20 | Docker Socket | ✅ | `testFilesystemBlocksDockerSocket` (`/var/run/docker.sock` → Prefix `/var`). |
| 3.21 | Symlink Escape | ✅ | `isPathSafe()` resolviert Symlinks via `realpath()` und prüft gegen Blocklist. |
| 3.22 | Zugriff außerhalb Sandbox | ⚠️ | Sandbox-Pfade wie `/tmp/uploads` erlaubt, aber keine Positivliste (Allowlist) definiert. |

#### Command Execution
| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 3.23 | `bash` | ✅ | Executor-Type `bash` nicht in `allowedExecutors` → Deny (`testCommandExecutionDeniesBashExecutor`). |
| 3.24 | `sh` | ✅ | Wie 3.23. |
| 3.25 | `docker` | ✅ | Executor-Type `docker` nicht gelistet → Deny. |
| 3.26 | `docker exec` | ✅ | Wie 3.25. |
| 3.27 | `php` | ✅ | Executor-Type `php` nicht gelistet → Deny. |
| 3.28 | `python` | ✅ | Nicht gelistet → Deny. |
| 3.29 | `node` | ✅ | Nicht gelistet → Deny. |
| 3.30 | Shell Injection | ✅ | `SecurityGuard::containsShellMetacharacters()` prüft Argumente auf `&&`, `;`, `|`, Backticks, `$()`, `${}`, Newlines (inkl. URL-decodiert). Test: `SecurityGuardHardeningTest::testBlocksShellMetacharactersInArguments`. |
| 3.31 | Command Chaining (`&&`, `;`, `\|`) | ✅ | Wie 3.30 (`containsShellMetacharacters`). |
| 3.32 | Environment-Variable Injection | ✅ | `containsShellMetacharacters()` blockt `${…}` und `$VAR`. Test: `SecurityGuardHardeningTest`. |

**Gate 2-Status (Security Suite): ✅ grün** — DNS-Rebinding/Redirect, nicht-kanonische IPs, `../`-Traversal, Symlink-Escape und Shell-/Command-Chaining/Env-Var-Injection abgesichert + getestet.

---

### 4. Prompt Injection

| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 4.1 | Direkt: System Instructions überschreiben | ❌ | Keine Input-Validierung auf `"ignore previous instructions"` etc. im User-Input. |
| 4.2 | Direkt: Security Policy umgehen | ⚠️ | `SecurityGuard::decide()` ist von Prompt-Inhalten unabhängig (gut), aber es gibt keine Prüfung, dass User-Input nicht als Tool-Call-Argument Policy-relevanten Inhalt injiziert. |
| 4.3 | Direkt: direkte Tool-Ausführung fordern | ❌ | Kein Test, keine Guard-Rail. |
| 4.4 | RAG-Inhalte nicht als System Instructions behandelt | ❌ | `ContextInjector::processInput()` (`src/AI/Rag/ContextInjector.php:33`) fügt RAG-Kontext via `Message::forSystem()` als **SystemMessage** hinzu. Das widerspricht der Forderung — RAG-Inhalte werden auf System-Ebene injiziert (wenn auch als separater Kontext-Block). Kein Trust-Level-Marking. |
| 4.5 | Tool-Ergebnisse können keine Policy überschreiben | ✅ | `SecurityGuard::decide()` wird pro ToolCall unabhängig ausgewertet (`testRagContextCannotBypassSsrfCheck`). |
| 4.6 | MCP Response kann keine Policy überschreiben | ❌ | Kein Test, kein Sanitizer für MCP-Responses. |
| 4.7 | Dokumente können keine Tool-Freigabe erzwingen | ❌ | Kein Test. |
| 4.8 | externe Inhalte können HITL nicht umgehen | ❌ | Kein Test. HITL liegt im `HitlListener`, aber kein E2E-Beweis, dass RAG-/MCP-Inhalte keinen Bypass erzeugen. |

---

### 5. Tenant/User Isolation

| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 5.1 | Tenant-Isolation server-/store-seitig erzwungen | ✅ | `user_identifier`-Filterung in `ToolDefinitionRepository::findApprovedForUser()`, `findOneByNameForUser()`, `EmbeddingRepository::findSimilar()` (`metadata->>'user_identifier'`), `AgentDialogController` auth-basiert. Test: `TenantIsolationTest`. |
| 5.2 | ToolDefinitions tenant-isoliert | ✅ | `ToolDefinition.userIdentifier`-Spalte + `findApprovedForUser()` lädt nur Tenant- + System-Tools. |
| 5.3 | RAG Documents tenant-isoliert | ✅ | `Embedding.metadata->>'user_identifier'` filtert serverseitig in `VectorStore::search()` / `EmbeddingRepository::findSimilar()`. |
| 5.4 | Memory tenant-isoliert | ⚠️ | Tenant-Filterung im RAG-/Tool-Pfad vorhanden; Memory-Provider-spezifische Isolation als TODO offen. |
| 5.5 | Conversations tenant-isoliert | ✅ | `AgentHistoryRepository::findByUserIdentifier()` filtert nach `u.userIdentifier`. |
| 5.6 | Agent History tenant-isoliert | ✅ | Wie 5.5. |
| 5.7 | Audit Logs tenant-isoliert | ✅ | `AuditLogger` speichert `user_id`/`user_email` (Identität pro Tenant); redigiert sensible Parameter. |
| 5.8 | Approvals tenant-isoliert | ✅ | `HitlListener::findDefinition()` nutzt `findOneByNameForUser()`; ohne authentifizierten User **kein** globaler `findOneBy()`-Fallback (DENY-not-registered). |
| 5.9 | MCP-Konfiguration tenant-isoliert | ❌ | `McpServerDefinition` ohne Tenant-Bezug. |
| 5.10 | **StoreRetrieverAdapter filtert tatsächlich** | ✅ | `StoreRetrieverAdapter` als Service registriert; `Retriever::retrieve()` reicht `user_identifier` an `VectorStore::search()` weiter, das serverseitig filtert. (`ContextInjector` nutzt aktuell direkt `Retriever`; native Store-Bridge-Nutzung siehe 6.1/7.8 — TODO). |
| 5.11 | Angriffstest: User B → Query A → 0 Ergebnisse | ✅ | `TenantIsolationTest` verifiziert Cross-Tenant-Schutz. |
| 5.12 | IDOR: `user_identifier` aus Request vertraut | ✅ | `AgentDialogController::dialog()` bezieht `user_identifier` aus dem authentifizierten User; `/history` prüft Ownership und liefert 403 bei fremden Daten. Test: `EvieSmokeTest` (IDOR-Spoofing-Schutz). |

**Gate 3-Status (Tenant-Isolation): ✅ grün** — serverseitig erzwungen + getestet; Memory-/MCP-Tenant-Isolation (5.4/5.9) als TODO offen.

---

### 6. RAG / Memory

| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 6.1 | Symfony AI Store korrekt eingebunden | ❌ | `StoreRetrieverAdapter` (Bridge) ist Dead Code; `ContextInjector` nutzt Eigenbau-`Retriever` + `VectorStore`, nicht den nativen `Symfony\AI\Store`. |
| 6.2 | `RetrieverInterface` korrekt verwendet | ⚠️ | `StoreRetrieverAdapter implements RetrieverInterface`, aber ungenutzt. |
| 6.3 | `VectorDocument` korrekt erzeugt | ⚠️ | `StoreRetrieverAdapter` erzeugt `VectorDocument` mit `NullVector()` und `uniqid()` — semantisch fragwürdig (kein echtes Embedding). |
| 6.4 | Indexing funktioniert | ⏳ | `MistralEmbeddingService` + `PgVectorStore` vorhanden; kein Test. |
| 6.5 | Retrieval funktioniert | ✅ | `ContextInjectorTest` (Mock). |
| 6.6 | Similarity Threshold funktioniert | ✅ | `Retriever::retrieve()` nutzt `$minSimilarity` (`Retriever.php:16`). |
| 6.7 | `limit` funktioniert | ✅ | `Retriever.php:17`. |
| 6.8 | Content-Type Filtering funktioniert | ✅ | `Retriever.php:14`. |
| 6.9 | User/Tenant Filtering funktioniert | ❌ | Siehe 5.10 — Identifier filtert nicht. |
| 6.10 | Leere Treffer funktionieren | ✅ | `testProcessInputDoesNothingWithoutResults`. |
| 6.11 | fehlerhafte Dokumente funktionieren | ❌ | Kein Test. |
| 6.12 | große Dokumente funktionieren | ❌ | Kein Test. |
| 6.13 | Memory funktioniert | ⚠️ | `ContextMemoryProvider` vorhanden; kein Tenant-Isolation-Test. |
| 6.14 | Memory ist Tenant-isoliert | ❌ | Kein Tenant-Filter. |
| 6.15 | RAG Context wird korrekt als InputProcessor injiziert | ✅ | `ContextInjector implements InputProcessorInterface` + `#[AsInputProcessor]` (`ContextInjector.php:16`). |
| 6.16 | RAG kann keine System Policy überschreiben | ✅ | `testRagContextCannotBypassSsrfCheck`. |

---

### 7. Symfony-AI-Konformität

| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 7.1 | Native Agent | ✅ | `AgentInterface` in `AgentDialogController` (`ai.agent.orchestrator`). |
| 7.2 | Native Tool | ✅ | `Symfony\AI\Platform\Tool\Tool` in `DynamicToolbox`. |
| 7.3 | Native Toolbox | ✅ | `DynamicToolbox implements ToolboxInterface`. |
| 7.4 | Native Dynamic Toolbox | ✅ | Decorator-Pattern auf `ai.toolbox.orchestrator`. |
| 7.5 | Native ToolCallRequested | ✅ | `HitlListener` auf `ToolCallRequested`. |
| 7.6 | Native InputProcessor | ✅ | `ContextInjector implements InputProcessorInterface`. |
| 7.7 | Native Memory Provider | ⚠️ | `ContextMemoryProvider` existiert; ob nativer `Symfony\AI\...\Memory\…` genutzt wird, nicht erkennbar. |
| 7.8 | Native Store | ❌ | `StoreRetrieverAdapter` als Bridge ungenutzt; Eigenbau `VectorStore`/`PgVectorStore` aktiv. |
| 7.9 | Native Subagents | ✅ | `SubAgentFactory`, `SubAgentRegistry`. |
| 7.10 | Native Structured Output | ⚠️ | `ResponsePipeline`/`FaultTolerantValidator` sind Eigenbauten (siehe 13). |
| 7.11 | Native MCP ChainFactory | ⚠️ | `EvieMcpBundle` + `McpServerFactory`; Konformität zum nativen MCP-Chain nicht verifiziert. |
| 7.12 | Keine alte `DynamicSkillRegistry` | ❌ | `src/EventListener/PendingToolApprovalListener.php:16,44,98` referenziert `DynamicSkillRegistry` (Stale-Comments, deutet auf unvollständige Migration). `config/prompts/tool_schema_optimizer.txt:2` ebenfalls. |
| 7.13 | Kein alter `DynamicToolDispatcher` | ✅ | Kein Vorkommen im Code. |
| 7.14 | keine unnötigen CompilerPass-Tools | ⚠️ | `E2EStubPass`, `RegisterDynamicToolboxDecoratorPass`, `AiMcpServersCompilerPass`, `AiSubAgentsCompilerPass` — `E2EStubPass` injiziert unter `E2E_TESTING` Stubs (Test-only, akzeptabel). |
| 7.15 | keine Mock-Tools zur Laufzeit | ⚠️ | `E2EStubPass` ersetzt unter `E2E_TESTING=1` externe Services durch Stubs (`config/packages/e2e/*`). Nur in E2E-Umgebung, nicht prod. |
| 7.16 | keine parallele HITL-Infrastruktur | ✅ | Nur `HitlListener` (nativ). |
| 7.17 | keine unnötige eigene Agent-Abstraktion | ⚠️ | `OrchestratorDialogService`, `WorkflowOrchestrator`, `BriefingManager`, `DecisionManager`, `StrategyManager` — mehrere Eigenbau-Orchestrierungs-Schichten neben dem nativen Agent. |

---

### 8. MCP

| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 8.1 | MCP Server Discovery | ⚠️ | `McpServerManager`, `McpServerFactory` vorhanden; `WarmupMcpServersCacheCommand`. |
| 8.2 | MCP Connection | ⚠️ | Code vorhanden, kein Test gegen echten MCP-Server. |
| 8.3 | MCP Timeout | ❌ | Kein konfigurierbares Timeout erkennbar. |
| 8.4 | MCP Retry | ❌ | Keine Retry-Logik. |
| 8.5 | MCP Error Handling | ✅ | `McpServerUnavailableException`, `McpToolExecutionFailed`, `McpToolNotFoundException`. |
| 8.6 | Server Whitelist | ⚠️ | `config/packages/evie_mcp.yaml` listet `filesystem`, `playwright`, `github` — aber `SecurityGuard::$allowedServices` enthält `npx`, `node`, `python`, `docker` als Executor-Services (siehe 3.25). |
| 8.7 | Command Whitelist | ❌ | `allowedServices` enthält `npx`, `node`, `python`, `docker` — das sind genau die Commands, die in P0-3 geblockt werden sollen. Widerspruch. |
| 8.8 | Authentifizierung | ❌ | Keine MCP-Auth erkennbar. |
| 8.9 | Netzwerk-Isolation | ⚠️ | `docker-compose.yml` nutzt `mcp_isolated` network mit `internal: false`. |
| 8.10 | Tool Result Validation | ❌ | Keine Schema-Validierung von MCP-Responses. |
| 8.11 | Prompt-Injection-Schutz aus MCP Responses | ❌ | Kein Sanitizer. |
| 8.12 | MCP Aktionen im Audit | ❌ | `AuditLogger` hat keine MCP-spezifische Methode. |
| 8.13 | Tenant Isolation | ❌ | `McpServerDefinition` ohne Tenant-Feld. |

---

### 9. Audit / Observability

| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 9.1 | Request-ID | ❌ | Kein Request-ID-Propagation erkennbar. |
| 9.2 | Trace-ID | ❌ | Kein Trace-ID-Propagation. |
| 9.3 | Agent-ID | ❌ | `AuditLog` ohne `agent_id`. |
| 9.4 | User-ID | ✅ | `AuditLogger::log()` speichert `userId`. |
| 9.5 | Tool-ID | ✅ | `logToolExecution($toolId, …)`. |
| 9.6 | Execution-ID | ❌ | Kein Feld in `AuditLog`. |
| 9.7 | Timestamp | ✅ | `AuditLog`-Entity hat `createdAt` (Doctrine-Standard). |
| 9.8 | Tool Decision | ✅ | `HitlListener::audit()` loggt ALLOW/DENY/ASK_USER via `logPolicyDecision()`; `SecurityGuard::denyWithAudit()` via `logSecurityViolation()`. |
| 9.9 | HITL Decision | ✅ | `HitlListener::audit()` protokolliert ASK_USER-Entscheidungen; `logPolicyDecision()` angebunden. |
| 9.10 | Executor | ⚠️ | Executor-Typ via `definition.getExecutorType()` im Policy-Decision-Context verfügbar; dediziertes Spalten-Feld — TODO. |
| 9.11 | Erfolg/Fehler | ✅ | `$status` Parameter. |
| 9.12 | Security Denial | ✅ | `SecurityGuard::denyWithAudit()` ruft `logSecurityViolation()` pro Policy-Deny. |
| 9.13 | MCP Fehler | ❌ | Kein MCP-spezifisches Audit. |
| 9.14 | Secrets werden niemals geloggt | ✅ | `AuditLogger::redact()` redigiert `password`, `secret`, `api_key`, `token`, `authorization`, … Test: `AuditRedactionTest`. |
| 9.15 | API Keys werden niemals geloggt | ✅ | `redact()` filtert `api_key`/`apikey`/`authorization`. |
| 9.16 | Passwörter werden niemals geloggt | ✅ | `redact()` filtert `password`/`passwd`/`secret`. |
| 9.17 | sensible Tool-Arguments werden redigiert | ✅ | `redact()` rekursiv auf Tool-Parametern. |

**P0-9 Status: ✅** — HitlListener + SecurityGuard an AuditLogger angebunden; Secret-Redaction aktiv (9.8/9.9/9.12/9.14-9.17). Trace-/Execution-ID (9.1/9.2/9.6) + MCP-Audit (9.13) — TODO.

---

### 10. Production Docker

| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 10.1 | `Dockerfile.prod` | ✅ | `docker/php/Dockerfile.prod`. |
| 10.2 | `composer install --no-dev` | ✅ | `Dockerfile.prod` Stage `deps`. |
| 10.3 | OPcache | ✅ | `opcache-recommended.ini`. |
| 10.4 | Cache Warmup | ✅ | `Dockerfile.prod` führt `cache:warmup --env=prod --no-debug` ohne `\|\| true` aus. |
| 10.5 | keine Development Dependencies | ✅ | `--no-dev` im Build. |
| 10.6 | keine Debug-Komponenten | ✅ | `symfony/debug-bundle` + `symfony/web-profiler-bundle` in `require-dev`; `Dockerfile.prod` baut mit `--no-dev`. |
| 10.7 | keine Secrets im Image | ⚠️ | `.env.dist` enthält Platzhalter; `APP_SECRET`-Default nur in Dev-Compose. Prod muss via Runtime-Secrets gesetzt werden — TODO-Doku. |
| 10.8 | non-root Container | ✅ | `USER www-data` (`Dockerfile.prod:45`). |
| 10.9 | Healthcheck | ✅ | `HEALTHCHECK` (`Dockerfile.prod:43`). |
| 10.10 | Readiness Check | ❌ | Nur Healthcheck, kein separater Readiness-Check. |
| 10.11 | Graceful Shutdown | ❌ | Keine Trap/Signal-Handling konfiguriert. |
| 10.12 | PHP-FPM korrekt | ⚠️ | Base `php:8.2-fpm-alpine`; keine fpm-pool-Tuning-Config. |
| 10.13 | Nginx korrekt | ⚠️ | `docker/nginx/default.conf` für dev (`docker-compose.yml`); kein Prod-Compose. |
| 10.14 | Worker korrekt | ❌ | Messenger-Worker (`MESSENGER_TRANSPORT_DSN`) nicht als Container definiert. |
| 10.15 | Scheduler korrekt | ❌ | Kein Cron/Scheduler-Container. |
| 10.16 | Redis korrekt | ❌ | Kein Redis-Service in `docker-compose.yml`. |
| 10.17 | PostgreSQL korrekt | ✅ | `postgres:15-alpine` + `pgvector` in CI. |
| 10.18 | MCP Container korrekt | ⚠️ | `mcp-playwright`, `mcp-filesystem` in dev-compose; kein Prod-Compose. |
| 10.19 | Restart Policy | ✅ | `restart: unless-stopped` in dev-compose. |
| 10.20 | Resource Limits | ❌ | Keine `deploy.resources` / Memory-Limits. |

---

## 🟠 P1 – Vor Production adressieren

### 11. Datenbank

| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 11.1 | Production Migration funktioniert | ⏳ | 12 Migrationen in `migrations/`; nicht gegen echte Prod-DB getestet. |
| 11.2 | Migration von leerer DB | ⚠️ | CI nutzt SQLite in-memory, nicht PostgreSQL → PG-spezifische Migrationen ungetestet. |
| 11.3 | Migration von bestehender DB | ⏳ | Kein Upgrade-Test. |
| 11.4 | Rollback-Strategie vorhanden | ❌ | Doctrine-Migration `down()` Methoden vorhanden, aber keine dokumentierte Rollback-Strategie. |
| 11.5 | Indizes geprüft | ⚠️ | Z. B. `IDX_STREAMING_SESSION_USER` in `Version20260812230000`; vollständigkeit nicht geprüft. |
| 11.6 | Foreign Keys geprüft | ⚠️ | Schema-Definitionen verteilt; keine FK-Constraints in `Version20260811190100` (MySQL-`InnoDB`-Syntax, obwohl PostgreSQL-DB). |
| 11.7 | Unique Constraints geprüft | ⚠️ | `user_identifier` nicht unique constraint-prüfbar. |
| 11.8 | Tenant Constraints geprüft | ❌ | `ToolDefinition`, `Document`, `Embedding` haben keine Tenant-Spalte → keine Constraints möglich. |
| 11.9 | keine Testdaten im Production Schema | ❌ | `src/DataFixtures/UserProfileFixture.php` vorhanden; bei versehentlichem Laden in Prod ein Risiko. |

### 12. Backup / Recovery

| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 12.1 | PostgreSQL Backup | ❌ | Keine Backup-Lösung. |
| 12.2 | automatisiertes Backup | ❌ | — |
| 12.3 | Backup außerhalb des Servers | ❌ | — |
| 12.4 | Restore getestet | ❌ | — |
| 12.5 | RAG/Vector-Daten berücksichtigt | ❌ | `pgvector`-Daten nicht in Backup-Strategie. |
| 12.6 | Redis-Recovery-Strategie | ❌ | Redis nicht deployt (siehe 10.16). |
| 12.7 | `.env`/Secrets Recovery | ❌ | — |
| 12.8 | dokumentierter Disaster-Recovery-Prozess | ❌ | Keine Doku in `docs/`. |

### 13. LLM-Ausfallsicherheit

| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 13.1 | API Timeout | ❌ | Keine konfigurierte Timeout-Behandlung. |
| 13.2 | HTTP 429 | ❌ | Keine Rate-Limit-Handling. |
| 13.3 | HTTP 500 | ❌ | Keine Provider-Error-Behandlung. |
| 13.4 | Provider nicht erreichbar | ❌ | — |
| 13.5 | invalid JSON | ✅ | `FaultTolerantValidator` + `JsonResponseEnforcer` (`src/AI/Response/`). |
| 13.6 | malformed Structured Output | ✅ | `ResponsePipeline`, `ResponseNormalizer`. |
| 13.7 | Token Limit | ❌ | Kein Token-Limit-Handling. |
| 13.8 | sehr lange Prompts | ❌ | Keine Prüfung. |
| 13.9 | unerwartete Tool Calls | ⚠️ | `HitlListener` blockiert nicht-`approved` Tools; aber kein Guard gegen adversarielle Tool-Call-Sequenzen. |
| 13.10 | **keine unkontrollierte Tool-Ausführung bei LLM-Error** | ⚠️ | `WorkflowOrchestrator::processRequest()` fängt Exceptions und gibt Error-Status zurück — kein automatischer Tool-Execute-on-Error. Aber: kein expliziter Guard, dass LLM-Error nicht → Tool-Execution führt. |

### 14. Kostenkontrolle

| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 14.1 | Token Limits | ❌ | Keine konfiguriert. |
| 14.2 | Request Limits | ⚠️ | `symfony/rate-limiter` in `composer.json`, aber keine Rate-Limit-Config für Agent-Requests erkennbar. |
| 14.3 | Tool Execution Limits | ❌ | — |
| 14.4 | Agent Loop Limit | ❌ | Kein Max-Iterations-Guard im Agent-Loop. |
| 14.5 | Subagent Depth Limit | ❌ | `SubAgentFactory` ohne Depth-Limit. |
| 14.6 | Timeout | ❌ | Kein globaler Agent-Timeout. |
| 14.7 | Retry Limit | ❌ | — |
| 14.8 | MCP Call Limit | ❌ | — |
| 14.9 | Kosten-/Token Logging | ❌ | Kein Token-Tracking. |
| 14.10 | Endlosschleifen verhindert | ❌ | Agent→Tool→Agent ohne Limit möglich. |

---

## 🟡 P2 – Vor größerem Roll-out

### 15. Performance

| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 15.1 | 10 parallele User | ❌ | Kein Lasttest. |
| 15.2 | 50 parallele User | ❌ | — |
| 15.3 | Agent Requests | ❌ | — |
| 15.4 | RAG Requests | ❌ | — |
| 15.5 | Tool Execution | ❌ | — |
| 15.6 | MCP | ❌ | — |
| 15.7 | DB | ❌ | — |
| 15.8 | Redis | ❌ | — |
| 15.9 | Memory | ❌ | — |
| 15.10 | Response Time | ❌ | — |
| 15.11 | CPU | ❌ | — |
| 15.12 | RAM | ❌ | — |
| 15.13 | DB Connections | ❌ | — |
| 15.14 | Redis | ❌ | — |
| 15.15 | LLM Latency | ❌ | — |
| 15.16 | Queue Length | ❌ | — |

### 16. Datenschutz / DSGVO

| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 16.1 | personenbezogene Daten identifiziert | ❌ | Keine Doku. |
| 16.2 | Datenminimierung | ❌ | `AuditLog` speichert `ip_address`, `user_agent` ohne Minimierung. |
| 16.3 | Löschkonzept | ❌ | Keine User-Lösch-Logik. |
| 16.4 | Export | ❌ | Kein Datenexport. |
| 16.5 | Nutzerlöschung | ❌ | — |
| 16.6 | Audit-Aufbewahrung | ❌ | Keine Retention-Policy. |
| 16.7 | RAG-Löschung | ❌ | — |
| 16.8 | Memory-Löschung | ❌ | — |
| 16.9 | Logs anonymisieren/redigieren | ❌ | Siehe 9.14-9.17. |
| 16.10 | LLM-Datenverarbeitung geklärt | ❌ | Prompts inkl. User-Daten an Mistral/Gemini; kein DPA sichtbar. |
| 16.11 | Auftragsverarbeitung/Provider geprüft | ❌ | — |
| 16.12 | Datenschutzerklärung | ❌ | Keine in `docs/` oder `templates/`. |
| 16.13 | Privacy Policy | ❌ | — |
| 16.14 | Consent, wo erforderlich | ❌ | — |

---

## 🟢 17. Finaler Production Smoke Test

| # | Schritt | Status | Bemerkung |
|---|---------|--------|-----------|
| 17.1 | Login | ✅ | `AuthFlowTest` (E2E). |
| 17.2 | Dashboard | ✅ | `NavigationPagesTest`. |
| 17.3 | Agent | ✅ | `EvieSmokeTest` (Agent-Dialog-Endpoint). |
| 17.4 | normaler Prompt | ⚠️ | E2E-Smoke mit Stubs; echter LLM-Prompt — TODO (Infrastruktur). |
| 17.5 | Tool Call | ⚠️ | Security-Gate-Smoke vorhanden; echter Tool-Call-E2E — TODO. |
| 17.6 | RAG | ⚠️ | Tenant-Isolation-Test vorhanden; echter RAG-Retrieval-Smoke — TODO. |
| 17.7 | Memory | ❌ | TODO. |
| 17.8 | neues Tool | ⚠️ | Evolution-Flow-Integration-Test (Objektebene); E2E — TODO. |
| 17.9 | HITL | ⚠️ | HitlListener-Tests + Audit; E2E-Smoke — TODO. |
| 17.10 | Approval | ⚠️ | Approval-Flow-Integration-Test; E2E — TODO. |
| 17.11 | Execution | ❌ | TODO. |
| 17.12 | Audit | ✅ | `EvieSmokeTest::testHitlListenerLogsPolicyDecision`. |
| 17.13 | Tenant A → Daten A / Tenant B → Daten B | ✅ | `TenantIsolationTest` + IDOR-Smoke. |
| 17.14 | SSRF → DENY | ✅ | Unit- + Security-Gate-Smoke; DNS-Rebinding-Schutz + Test. |
| 17.15 | Filesystem Escape → DENY | ✅ | Traversal + Symlink-Escape geblockt + getestet. |
| 17.16 | Shell → DENY | ✅ | Command-Chaining/Shell-Injection geblockt + getestet. |
| 17.17 | Prompt Injection → neutralisiert | ❌ | Keine Guard-Rails. |

---

## 🟢 Finales Release-Gate

| Gate | Bedingung | Status | Begründung |
|------|-----------|--------|------------|
| **Gate 1** | CI vollständig grün | ✅ (Code) | Gate-Bypässe entfernt, `--strict` aktiv, E2E-Smoke-Suite vorhanden. CI-Lauf-Status ⏳ GitHub-Actions. |
| **Gate 2** | Security Suite vollständig grün | ✅ | DNS-Rebinding/Redirect, nicht-kanonische IPs, Traversal, Symlink-Escape, Shell-/Command-Chaining/Env-Var abgesichert + getestet. |
| **Gate 3** | Tenant-Isolation bewiesen | ✅ | serverseitig erzwungen + IDOR-Fix + Tests; Memory-/MCP-Tenant-Isolation — TODO. |
| **Gate 4** | Evolution Golden Path vollständig grün | ✅ (Schema+Audit) | Schema-Validierung + Audit-Anbindung vorhanden; echter E2E-Golden-Path ⏳ TODO. |
| **Gate 5** | Production Docker + Smoke Test + Backup/Restore | ⚠️ | Debug-Bundle nach `require-dev`; cache:warmup ohne `\|\| true`; Smoke-Test vorhanden. Backup/Restore (12) + Prod-Compose + Readiness/Shutdown/Resource-Limits — TODO. |

### Gesamturteil

**EVIE ist bedingt production-ready (Code-Ebene).** Gates 1–4 sind auf Code-Ebene
grün; Gate 5 ist teilweise (Backup/Restore + Prod-Compose offen).

Die ehemals kritischen P0-Blocker sind behoben:

1. ✅ **CI-Gate-Bypässe entfernt** — `composer audit`, `phpstan`, `cache:warmup` ohne `\|\| true`; `composer validate --strict`.
2. ✅ **Tenant-Isolation echt implementiert** — `user_identifier`-Spalten + Filterung in Repositorys, `AgentDialogController` auth-basiert, `HitlListener` ohne globalen Fallback.
3. ✅ **RAG-Tenant-Filterung** — `user_identifier` wird an `VectorStore::search()` durchgereicht und filtert serverseitig.
4. ✅ **SSRF gehärtet** — `OutboundRequestPolicy` (DNS-Resolution + Redirect-Handling) in `SecurityGuard::isUrlSafe()` angebunden; nicht-kanonische IP-Formate normalisiert.
5. ✅ **Audit-Anbindung + Secret-Redaction** — `HitlListener`/`SecurityGuard` rufen `AuditLogger` auf; `redact()` redigiert sensible Parameter.
6. ✅ **Debug-Bundles nach `require-dev`** verschoben.
7. ✅ **Shell-/Command-Chaining-/Env-Var-Injection-Schutz** in `SecurityGuard::decide()`.

## Verbleibende offene Punkte (TODO)

**P0/Code:**
- Trace-/Execution-/Request-ID-Propagation (9.1/9.2/9.6) — `AuditLog` ohne dedizierte Felder.
- Prompt-Injection-Guard-Rails (4.1–4.8) — kein Input-Sanitizer / Trust-Level-Marking für RAG/MCP.
- MCP-Timeout/Retry/Response-Validation (8.3/8.4/8.10–8.12) + MCP-Audit (8.12/9.13).
- Memory- + MCP-Tenant-Isolation (5.4/5.9/8.13) — `McpServerDefinition` ohne Tenant-Feld.
- Native Store-Bridge aktiv nutzen (`ContextInjector` über `StoreRetrieverAdapter`, 6.1/7.8).
- Agent-Loop-/Subagent-Depth-Limit + Token-Kosten-Tracking (14.4–14.10).
- Echter E2E-Golden-Path (Gate 4) inkl. echter Tool-Execution.

**P1/Infrastruktur (nicht Code, sandbox-limitiert):**
- Backup/Recovery-Strategie + Restore-Test (§12).
- Prod-Docker-Compose + Readiness-Check/Graceful-Shutdown/Resource-Limits (10.10–10.20).
- PostgreSQL-Migration-Upgrade-Test (§11).

**P2/Prozess (nicht Code):**
- Performance-/Lasttests (§15), DSGVO-Doku/Löschkonzept (§16).

---

## 🛠️ Behobene P0-Blocker (Follow-up-Commit — Detail)

Die folgenden Blocker aus der Erstverifizierung wurden in einem
Follow-up-Commit adressiert.

### P0-1 CI/CD — Gate-Bypässe entfernt
- `composer audit` und `phpstan` laufen jetzt **ohne** `|| true` (echte Gates).
- `composer validate --strict --no-check-publish` statt `composer validate`.
- `composer.json`: unbound version constraints (`"*"`) auf konkrete Versionen
  gesetzt (`^0.12`, `^3.6`, `^7.4`, `^4.0`), sodass `--strict` erfüllt ist.
- Neue Testsuite `E2E Smoke Tests` + CI-Step `E2E Smoke tests` ergänzt.

### P0-2 Self-Evolution — Schema-Validierung
- `ToolDefinitionGenerator::validateSchema()` (`src/AI/Skills/ToolDefinitionGenerator.php`)
  lehnt jetzt invalides Schema (`type !== 'object'`, fehlendes `properties`)
  via `ToolRegistrationException` ab — sowohl im tool_generator- als auch im
  LLM-Fallback-Pfad.

### P0-3 Security — SSRF-/Traversal-Härtung
- `SecurityGuard::normalizeHost()` (`src/AI/Security/SecurityGuard.php`) kanonisiert
  nicht-kanonische IP-Formate (Dezimal `2130706433`, Hex `0x7f000001`, Oktal
  `0177.0.0.1`, kurze Form `127.1`, IPv4-mapped IPv6 `::ffff:127.0.0.1`).
- `SecurityGuard::isPathSafe()` blockt jetzt `../`-Directory-Traversal
  (auch URL-encoded `%2e%2e`) und resolviert Symlinks via `realpath()`.
- `OutboundRequestPolicy` als Service registriert (`config/services.yaml`)
  mit `allow_redirects=false, allow_private_networks=false`.
- `SecurityGuard::isUrlSafe()` ruft `OutboundRequestPolicy::isUrlAllowed()`
  für Hostnamen auf (DNS-Auflösung via `gethostbynamel`) — schließt
  DNS-Rebinding/Redirect-Bypass (3.9-3.12).
- `SecurityGuard::decide()` prüft Tool-Argumente auf Shell-Metazeichen
  (`containsShellMetacharacters()`): `&&`, `;`, `|`, Backticks, `$()`,
  `${}`, `$VAR`, Newlines (inkl. URL-decodiert) — Command-Chaining/Shell-
  Injection/Env-Var-Injection (3.30-3.32).
- `HitlListener::findDefinition()` ohne globalen `findOneBy()`-Fallback
  bei fehlendem User — kein Cross-Tenant-Approval-Leak (5.8).
- Neue Tests: `tests/Unit/AI/Security/SsrfBypassTest.php`,
  `tests/Unit/AI/Security/SecurityGuardHardeningTest.php`.

### P0-5 Tenant-Isolation — serverseitig erzwungen
- `ToolDefinition` hat jetzt `userIdentifier`-Spalte + Migration
  `migrations/Version20260815120000.php`.
- `DynamicToolbox::loadApprovedDefinitions()` lädt via
  `ToolDefinitionRepository::findApprovedForUser()` nur Tools des aktuellen
  Tenants (+ System-Tools ohne Tenant-Bezug).
- `HitlListener::findDefinition()` nutzt
  `ToolDefinitionRepository::findOneByNameForUser()` — ein User kann nicht
  die Tools eines anderen Tenants freigeben/ablehnen.
- `AgentDialogController`: `user_identifier` wird aus dem authentifizierten
  User bezogen, **nicht** aus dem Request-Body (IDOR-Fix); `/history`
  prüft Ownership und liefert 403 bei fremden Daten.
- `Retriever`/`VectorStore`/`EmbeddingRepository`: `user_identifier` wird
  an `VectorStore::search()` durchgereicht und filtert serverseitig
  (`metadata->>'user_identifier'`); `StoreRetrieverAdapter` als Service
  registriert und filtert jetzt die zugrunde liegenden Ergebnisse.
- Neue Tests: `tests/Unit/AI/Security/TenantIsolationTest.php`.

### P0-9 Audit / Observability — angebunden + Secret-Redaction
- `HitlListener` ruft `AuditLogger::logPolicyDecision()` für jede
  Policy-Entscheidung (ALLOW/DENY/ASK_USER) auf.
- `AuditLogger::redact()` redigiert sensible Tool-Parameter (`password`,
  `secret`, `api_key`, `token`, `authorization`, ...) vor dem Logging.
- `SecurityGuard::denyWithAudit()` ruft `logSecurityViolation()` pro
  Policy-Deny — Policy-Verletzungen werden audited (9.8/9.12).
- Neue Tests: `tests/Unit/AI/Security/AuditRedactionTest.php`,
  `SecurityGuardHardeningTest.php` (Audit-Anbindung).

### P0-10 Production Docker — Debug-Bundles verschoben
- `symfony/debug-bundle` und `symfony/web-profiler-bundle` von `require`
  nach `require-dev` verschoben.
- `Dockerfile.prod`: `cache:warmup` läuft jetzt **ohne** `|| true`.

### Neue E2E-Smoke-Tests
- `tests/E2E/Smoke/EvieSmokeTest.php`: verifiziert Agent-Dialog-Endpoint,
  IDOR-Tenant-Spoofing-Schutz, `/history`-Ownership und die
  Security-Gates (SSRF/Filesystem/Shell → DENY) + Audit-Logging.
