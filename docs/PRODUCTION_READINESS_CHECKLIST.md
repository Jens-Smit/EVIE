# EVIE – Production-Readiness-Checkliste

> **Status-Legende**
> - ✅ Implementiert & verifiziert (Code + Test vorhanden)
> - ⚠️ Teilweise / mit Lücken (siehe Bemerkung)
> - ❌ Fehlt oder nachgewiesen fehlerhaft (Production-Blocker)
> - ⏳ Nicht im Code verifizierbar (Infrastruktur/Prozess, manuell zu prüfen)
>
> **Basis:** Commit `35ff4bb` (Stand `main`, 2026-08-15).
> Diese Checkliste wurde gegen den tatsächlichen Codebestand geprüft,
> nicht gegen Platzhalter. Jeder Eintrag nennt die Beweis-Stelle (`pfad:zeile`)
> oder den festgestellten Mangel.

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
| 1.10 | E2E Smoke Tests grün | ❌ | Keine Smoke-Test-Suite vorhanden. E2E-Tests (`tests/E2E/*`) decken nur Auth-Flows (`AuthFlowTest`, `NavigationPagesTest`), keinen Agent/Tool/RAG/HITL-Smoke (siehe 17). |
| 1.11 | PHPStan grün | ❌ | `.github/workflows/ci.yml:113` → `vendor/bin/phpstan analyse src --level=5 --no-progress \|\| true`. Das `\|\| true` macht PHPStan zu einem **Nicht-Gate**. |
| 1.12 | `composer validate --strict` grün | ❌ | `ci.yml:109` führt `composer validate` **ohne** `--strict` aus. Bei unbound version constraints (`doctrine/orm: "*"`, `symfony/ai-*: "*"`) würde `--strict` fehlschlagen (siehe Commit `778e9aa`). |
| 1.13 | Keine `\|\| true` / `\|\| echo`-Umgehungen bei kritischen Gates | ❌ | `composer audit \|\| true` (`ci.yml:111`) und `phpstan … \|\| true` (`ci.yml:113`) sind aktive Gate-Bypässe. |
| 1.14 | Docker Production Image baut erfolgreich | ⏳ | `docker/php/Dockerfile.prod` existiert; kein CI-Job baut es. Lokal/manuell zu prüfen. |
| 1.15 | Production Container startet erfolgreich | ⏳ | Nicht in CI geprüft. |
| 1.16 | Healthcheck grün | ⚠️ | `Dockerfile.prod` definiert `HEALTHCHECK … php bin/console about`, aber `docker-compose.yml` definiert keinen Production-Service, der `Dockerfile.prod` nutzt. |

**Gate 1-Status: ❌ rot** — aktive Gate-Bypässe (`\|\| true`) und fehlendes `--strict`.

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
| 2.16 | Invalides Schema wird abgelehnt | ❌ | `testInvalidSchemaStillLoadsInToolbox` zeigt, dass Tools mit leerem Schema **geladen** werden. Schema-Validierung fehlt zur Generierungszeit. |
| 2.17 | Ungültiger Executor wird abgelehnt | ✅ | `testInvalidExecutorTypeDenied`. |
| 2.18 | Tool kann nicht außerhalb seiner Policy ausgeführt werden | ✅ | `HitlListener` + `SecurityGuard::decide()` prüfen jeden ToolCall. |
| 2.19 | Jeder relevante Schritt landet im Audit Log | ❌ | `AuditLogger` existiert, aber `HitlListener` und `SecurityGuard::decide()` rufen `AuditLogger` **nicht** auf (keine Trace-/Execution-ID). Siehe 9. |
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
| 3.9 | DNS-Rebinding | ❌ | `SecurityGuard::isUrlSafe()` resolved Hostnames **nicht** (`SecurityGuard.php:81`). `OutboundRequestPolicy::isPrivateNetwork()` würde `gethostbynamel()` nutzen, ist aber **Dead Code** (nirgends registriert, siehe 7). Kein Test. |
| 3.10 | Redirect → interne IP | ❌ | `SecurityGuard` folgt keinen Redirects und prüft nicht das Redirect-Ziel. `OutboundRequestPolicy` hat `$allowRedirects=false, $maxRedirects=0`, ist aber ungenutzt. |
| 3.11 | Redirect-Ketten | ❌ | Wie 3.10. |
| 3.12 | Hostname → private IP | ❌ | `SecurityGuard::isUrlSafe()` prüft nur String-Prefixe auf dem Hostnamen. `evil.example.com` → `127.0.0.1`-Auflösung wird nicht geprüft. |
| 3.x | **Bypass über nicht-kanonische IP-Formate** | ❌ | `isUrlSafe()` nutzt `ip2long` nur bei `FILTER_VALIDATE_IP`. Dezimal (`http://2130706433`), Hex (`http://0x7f000001`), Oktal (`http://0177.0.0.1`), `127.1` werden **nicht** als privat erkannt → **SSRF-Bypass**. |

#### Filesystem
| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 3.13 | `../` Traversal | ❌ | `SecurityGuard::isPathSafe()` prüft nur `str_starts_with` auf absolute geblockte Pfade (`/etc`, …). `../../etc/passwd` startet nicht mit `/etc` → **nicht geblockt**. `OutboundRequestPolicy::containsDirectoryTraversal` prüft `..`, ist aber ungenutzt. |
| 3.14 | absolute Pfade | ⚠️ | Geblockte Prefixe nur für `/etc`, `/root`, `/proc`, …; `/var/run` fehlt in `SecurityGuard::$blockedPaths` (nur `/var`). |
| 3.15 | `/etc/passwd` | ✅ | `testFilesystemBlocksEtcPasswd`. |
| 3.16 | `/proc` | ✅ | `testFilesystemBlocksProc`. |
| 3.17 | `/sys` | ✅ | `testFilesystemBlocksSys`. |
| 3.18 | `/dev` | ✅ | `testFilesystemBlocksDev`. |
| 3.19 | `/var/run` | ✅ | `testFilesystemBlocksVarRun` (via Prefix `/var`). |
| 3.20 | Docker Socket | ✅ | `testFilesystemBlocksDockerSocket` (`/var/run/docker.sock` → Prefix `/var`). |
| 3.21 | Symlink Escape | ❌ | Keine Symlink-Resolution / `realpath()`-Prüfung. |
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
| 3.30 | Shell Injection | ❌ | Kein Test, der Shell-Metazeichen in Tool-Argumenten prüft. |
| 3.31 | Command Chaining (`&&`, `;`, `\|`) | ❌ | `SecurityGuard::decide()` prüft Argumente nur auf URL-/Pfad-Muster, nicht auf Shell-Metazeichen. |
| 3.32 | Environment-Variable Injection | ❌ | Keine Prüfung auf `${…}` / `$VAR` in Argumenten. |

**Gate 2-Status (Security Suite): ❌ rot** — SSRF-Bypässe, Traversal-Lücke, Command-Chaining ungeprüft.

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
| 5.1 | Tenant-Isolation server-/store-seitig erzwungen | ❌ | Siehe unten. |
| 5.2 | ToolDefinitions tenant-isoliert | ❌ | `ToolDefinition` hat **kein** `user_identifier`-Feld (`src/Entity/ToolDefinition.php`). `DynamicToolbox::loadApprovedDefinitions()` (`DynamicToolbox.php:55`) lädt `findBy(['status' => 'approved'])` — **alle Tenants**. Cross-Tenant-Leak. |
| 5.3 | RAG Documents tenant-isoliert | ❌ | `Document`-Entity hat kein Tenant-Feld. `Retriever::retrieve()` ignoriert `user_identifier` vollständig (`src/AI/Rag/Retriever.php:14`). |
| 5.4 | Memory tenant-isoliert | ❌ | Kein Tenant-Filter in Memory-Pfad. |
| 5.5 | Conversations tenant-isoliert | ✅ | `AgentHistoryRepository::findByUserIdentifier()` filtert nach `u.userIdentifier`. |
| 5.6 | Agent History tenant-isoliert | ✅ | Wie 5.5. |
| 5.7 | Audit Logs tenant-isoliert | ⚠️ | `AuditLogger::log()` speichert `user_email`/`user_id`, aber `AuditLog`-Entity hat keine Tenant-Constraint. |
| 5.8 | Approvals tenant-isoliert | ❌ | `ToolDefinitionRepository::findOneBy(['name' => …])` im `HitlListener::findDefinition()` (`HitlListener.php:87`) ist **global** — ein Tenant kann das Tool eines anderen freigeben/ablehnen. |
| 5.9 | MCP-Konfiguration tenant-isoliert | ❌ | `McpServerDefinition` ohne Tenant-Bezug. |
| 5.10 | **StoreRetrieverAdapter filtert tatsächlich** | ❌ | `StoreRetrieverAdapter::retrieve()` (`src/AI/Rag/StoreRetrieverAdapter.php:39`) nimmt `user_identifier` aus `$options`, ruft aber `Retriever::retrieve()` **ungefiltert** auf und schreibt den Identifier nur in die **Output-Metadata**. Der Identifier **filtert nicht** die zugrunde liegenden Ergebnisse. Zudem ist `StoreRetrieverAdapter` **Dead Code** — nirgends als Service registriert; `ContextInjector` nutzt direkt `Retriever`. |
| 5.11 | Angriffstest: User B → Query A → 0 Ergebnisse | ❌ | Kein Test vorhanden. |
| 5.12 | IDOR: `user_identifier` aus Request vertraut | ❌ | `AgentDialogController::dialog()` (`src/Controller/AgentDialogController.php:79`) liest `user_identifier` aus dem Request-Body ohne Auth-Check → **Tenant-Wechsel per Parameter**. `/api/agent/history/{userIdentifier}` trustet den Pfad-Parameter ebenfalls. |

**Gate 3-Status (Tenant-Isolation): ❌ rot** — zentraler Production-Blocker.

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
| 9.8 | Tool Decision | ❌ | `HitlListener` ruft `AuditLogger` nicht auf → Policy-Entscheidungen nicht geloggt. |
| 9.9 | HITL Decision | ⚠️ | `AuditLogger::logHitlDecision()` existiert, aber `HitlListener::requestApproval()` ruft es **nicht** auf. |
| 9.10 | Executor | ❌ | Kein Executor-Feld im Audit-Log. |
| 9.11 | Erfolg/Fehler | ✅ | `$status` Parameter. |
| 9.12 | Security Denial | ⚠️ | `logSecurityViolation()` existiert, aber `SecurityGuard`/`HitlListener` rufen es nicht auf. |
| 9.13 | MCP Fehler | ❌ | Kein MCP-spezifisches Audit. |
| 9.14 | Secrets werden niemals geloggt | ❌ | Keine Redaction-Logik. `logToolExecution($parameters)` loggt rohe Tool-Parameter — können API-Keys/Passwörter enthalten. |
| 9.15 | API Keys werden niemals geloggt | ❌ | Keine Filterung. |
| 9.16 | Passwörter werden niemals geloggt | ❌ | Keine Filterung. |
| 9.17 | sensible Tool-Arguments werden redigiert | ❌ | Keine Redaction. |

**P0-9 Status: ❌** — Audit-Infrastruktur existiert, ist aber nicht an die kritischen Pfade (HitlListener, SecurityGuard, MCP) angebunden und ohne Secret-Redaction.

---

### 10. Production Docker

| # | Prüfpunkt | Status | Bemerkung / Beweis |
|---|-----------|--------|--------------------|
| 10.1 | `Dockerfile.prod` | ✅ | `docker/php/Dockerfile.prod`. |
| 10.2 | `composer install --no-dev` | ✅ | `Dockerfile.prod` Stage `deps`. |
| 10.3 | OPcache | ✅ | `opcache-recommended.ini`. |
| 10.4 | Cache Warmup | ⚠️ | `cache:warmup --env=prod … \|\| true` (`Dockerfile.prod:40`) — `\|\| true` verbirgt Build-Fehler. |
| 10.5 | keine Development Dependencies | ✅ | `--no-dev` im Build. |
| 10.6 | keine Debug-Komponenten | ⚠️ | `composer.json` erfordert `symfony/debug-bundle` + `symfony/web-profiler-bundle` in `require` (nicht `require-dev`) → landen im Prod-Image. |
| 10.7 | keine Secrets im Image | ⚠️ | `.env.dist` enthält Platzhalter; `APP_SECRET=${APP_SECRET:-dev_secret_change_me}` in `docker-compose.yml` mit unsicherem Default. |
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
| 17.3 | Agent | ❌ | Kein Smoke-Test. |
| 17.4 | normaler Prompt | ❌ | Kein Smoke-Test. |
| 17.5 | Tool Call | ❌ | — |
| 17.6 | RAG | ❌ | — |
| 17.7 | Memory | ❌ | — |
| 17.8 | neues Tool | ❌ | — |
| 17.9 | HITL | ❌ | — |
| 17.10 | Approval | ❌ | — |
| 17.11 | Execution | ❌ | — |
| 17.12 | Audit | ❌ | — |
| 17.13 | Tenant A → Daten A / Tenant B → Daten B | ❌ | Isolation nicht bewiesen (siehe 5). |
| 17.14 | SSRF → DENY | ⚠️ | Unit-Tests grün, aber Smoke-E2E fehlt. |
| 17.15 | Filesystem Escape → DENY | ⚠️ | Traversal-Lücke (3.13). |
| 17.16 | Shell → DENY | ⚠️ | Unit-Tests, aber Command-Chaining ungeprüft. |
| 17.17 | Prompt Injection → neutralisiert | ❌ | Keine Guard-Rails. |

---

## 🟢 Finales Release-Gate

| Gate | Bedingung | Status | Begründung |
|------|-----------|--------|------------|
| **Gate 1** | CI vollständig grün | ❌ | `composer audit \|\| true` + `phpstan \|\| true` sind aktive Bypässe; `composer validate` ohne `--strict`; E2E-Smoke-Tests fehlen. |
| **Gate 2** | Security Suite vollständig grün | ❌ | SSRF-Bypass über nicht-kanonische IPs (3.x); `../`-Traversal-Lücke (3.13); DNS-Rebinding/Redirect ungeprüft (3.9-3.11); Command-Chaining ungeprüft (3.31). |
| **Gate 3** | Tenant-Isolation bewiesen | ❌ | `user_identifier` aus Request vertrauend (IDOR, 5.12); `ToolDefinition`/`Document`/`Embedding` ohne Tenant-Feld; `StoreRetrieverAdapter` filtert nicht; `HitlListener::findDefinition()` global; kein Isolationstest. |
| **Gate 4** | Evolution Golden Path vollständig grün | ❌ | Kein E2E-Golden-Path; invalides Schema wird nicht abgelehnt (2.16); Audit-Logging im Pfad fehlt (2.19). |
| **Gate 5** | Production Docker + Smoke Test + Backup/Restore | ❌ | Debug-Bundle in `require`; `\|\| true` bei cache:warmup; kein Prod-Compose; kein Smoke-Test; kein Backup/Restore. |

### Gesamturteil

**EVIE ist NICHT production-ready.** Alle fünf Release-Gates sind rot.

Die kritischsten P0-Blocker, die vor jedem Production-Release behoben werden müssen:

1. **CI-Gate-Bypässe entfernen** — `composer audit`, `phpstan`, `cache:warmup` ohne `\|\| true`; `composer validate --strict` (ggf. version constraints fixen).
2. **Tenant-Isolation echt implementieren** — `user_identifier`-Spalten auf `ToolDefinition`/`Document`/`Embedding`/`AuditLog`/`McpServerDefinition`; `DynamicToolbox::loadApprovedDefinitions()` und `HitlListener::findDefinition()` pro Tenant filtern; `AgentDialogController` darf `user_identifier` nicht aus dem Request vertrauen (Auth-basiert).
3. **`StoreRetrieverAdapter` wirklich nutzen und filtern lassen** — als Service registrieren, `ContextInjector` darüber laufen lassen, `Retriever::retrieve()` muss `user_identifier` an `VectorStore::search()` durchreichen.
4. **SSRF härtbar machen** — `OutboundRequestPolicy` (mit DNS-Resolution + Redirect-Handling) als Service registrieren und in `SecurityGuard::decide()` verwenden; nicht-kanonische IP-Formate normalisieren.
5. **Golden-Path-E2E + Audit-Anbindung** — vollständiger Ablauf User→Tool fehlt→Generate→Validate→Policy→HITL→Approve→Execute→Result→Audit als E2E; `HitlListener`/`SecurityGuard` müssen `AuditLogger` aufrufen; Secret-Redaction für Tool-Parameter.
6. **Debug-Bundles nach `require-dev`** — `symfony/debug-bundle`, `symfony/web-profiler-bundle` aus `require` entfernen.
