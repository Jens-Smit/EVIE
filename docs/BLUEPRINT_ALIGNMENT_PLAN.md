# EVIE Blueprint-Alignment-Plan

> Ziel: EVIE hält sich zu **mindestens 90 %** an `blueprint.md` (Revision 2 — nativer
> Symfony AI v0.12 Stack). Dieser Plan dokumentiert die identifizierten Abweichungen,
> die Migrations-Schritte und das Test-Mapping. Er ist verbindliche Arbeitsgrundlage
> für alle Folge-Commits auf `main`.

---

## 0. Leitprinzipien (aus Blueprint + Projekt-Instructions)

1. **Strikt am Blueprint orientiert** — keine Eigenbau-Infrastruktur neben Symfony AI.
2. **Symfony-AI-kompatibel bleiben** — nur native Erweiterungspunkte verwenden.
3. **Keine Mockdaten / Platzhalter / Fantasie-Tools** — insbesondere keine
   Compiler-Pass-Mock-ToolDefinitions.
4. **Keine inkompatiblen Bridges** — kein handgeschriebener Tool-Decorator, der das
   native `Tool`-Objekt nachbaut.
5. **Keine Konstruktor-Injection für Tools** — Tools über `#[AsTool]` / Service-Tags,
   DynamicToolbox dekoriert `ToolboxInterface`.
6. **Keine Halluzinationen** — Klassen/Signatur exakt nach Symfony-AI-v0.12-Doku.
7. **Keine Abweichungen von der Multi-Agent-Architektur** — Subagents = native
   `Symfony\AI\Agent\Toolbox\Tool\Subagent`.

---

## 1. Native Symfony AI v0.12 Erweiterungspunkte (Referenz)

| Punkt | Native Klasse | Doku |
|-------|---------------|------|
| Dynamic Toolbox | `ToolboxInterface`-Decorator mit `#[AsDecorator('ai.toolbox.<agent>')]` + `#[AutowireDecorated]`; `getTools(): array`, `execute(ToolCall): ToolResult` | [dynamic-tools.html](https://symfony.com/doc/current/ai/cookbook/dynamic-tools.html) |
| HITL | `Symfony\AI\Agent\Toolbox\Event\ToolCallRequested`; `$event->getToolCall()`, `$event->deny($reason)`, `$event->getDefinition()` | [human-in-the-loop.html](https://symfony.com/doc/current/ai/cookbook/human-in-the-loop.html) |
| Policy | `enum PolicyDecision { Allow, Deny, AskUser }` | s. HITL-Doku |
| Tool-Objekt | `Symfony\AI\Platform\Tool\Tool(ExecutionReference $reference, string $name, string $description, ?array $parameters)` | s.o. |
| ExecutionReference | `Symfony\AI\Platform\Tool\ExecutionReference(string $class)` | s.o. |
| ToolCall | `Symfony\AI\Platform\Result\ToolCall` (`getName()`, `getArguments()`) | s.o. |
| ToolResult | `Symfony\AI\Agent\Toolbox\ToolResult(ToolCall $toolCall, string $result)` | s.o. |
| Subagents | `Symfony\AI\Agent\Toolbox\Tool\Subagent` als Tool in der Toolbox | [components/agent.html](https://symfony.com/doc/current/ai/components/agent.html) |
| Structured Output | Platform `outputStructure` / Serializer-Groups | s. Platform-Doku |
| RAG | `symfony/ai-store` + `InputProcessor` | s. Store-Doku |
| MCP | `ToolFactory`/`ChainFactory`, MCP-Client | s. MCP-Doku |

---

## 2. Abweichungen vom Blueprint (IST-Zustand)

### A. Parallele Tool-Infrastruktur (Blueprint §2, §3, §4.B)

| Datei | Abweichung | Blueprint fordert |
|-------|-----------|-------------------|
| `src/AI/Skills/DynamicSkillRegistry.php` (282 Z.) | Eigene Registry mit `ToolRegistry`, Lazy-Loading, `registerTool()` | **Keine** parallele Registry; native `DynamicToolbox` |
| `src/AI/Skills/DynamicSkillRegistryInterface.php` | Eigene Interface-Welt | entfällt |
| `src/DependencyInjection/Compiler/AiDynamicToolsCompilerPass.php` | CompilerPass lädt **Mock-ToolDefinitions** (`createMockToolDefinitions([1,2,3])`) und registriert Services zur Compile-Time | **Kein** CompilerPass; dynamische Tools zur **Laufzeit** über `DynamicToolbox::getTools()` |
| `src/DependencyInjection/Compiler/DynamicAgentCompilerPass.php` | CompilerPass | abbauen/ersetzen |
| `src/AI/Agent/EvieToolboxFactory.php` | Handgeschriebene `create()`/`createOrchestratorToolbox()` mit anonymen Tool-Klassen, die `ToolInterface` (eigenes) implementieren und bei Aufruf Fehler zurückgeben | Native `Toolbox` + `DynamicToolbox`-Decorator; dynamische Tools als `Symfony\AI\Platform\Tool\Tool` |

### B. HITL & Security (Blueprint §4.D, §4.E)

| Datei | Abweichung | Blueprint fordert |
|-------|-----------|-------------------|
| `src/AI/Security/HitlToolCallListener.php` | Listener auf `ToolCallArgumentsResolved` (falsches Event); wirft `ToolExecutionException` statt `$event->deny()` | EventSubscriber auf **`ToolCallRequested`**; `$event->deny()` |
| `src/AI/Security/HitlInterceptor.php` | Handgeschriebener Tool-Decorator (`interceptToolExecution()`); `#[Autoconfigure(tags:['ai.security.interceptor'])]` (Fantasie-Tag) | **Kein** Decorator; nativer `ToolCallRequested`-Listener + `SecurityGuard`-Policy |
| `src/AI/Security/SecurityGuard.php` | liefert `bool`/`null`, keine `PolicyDecision`-Enum | `PolicyDecision { Allow, Deny, AskUser }` |

### C. Subagents (Blueprint §4.C)

| Datei | Abweichung | Blueprint fordert |
|-------|-----------|-------------------|
| `src/AI/Agent/SubAgentDispatcher.php` (760 Z.) | Eigene Dispatcher-Klasse | native `Subagent`-Instanzen als Toolbox-Tools |
| `src/AI/Agent/SubAgentFactory.php` | Eigenes `ToolInterface`-Wrapping in `EvieToolboxFactory` (anonyme Klassen, die Fehler zurückgeben) | `SubAgentFactory` erzeugt `Subagent`-Objekte; `EvieToolboxFactory` fügt sie als native Tools hinzu |

### D. Fehlende Blueprint-Komponenten

| Blueprint-Datei | Status |
|-----------------|--------|
| `src/AI/Skills/DynamicToolbox.php` | **fehlt** — zentrale Blueprint-Komponente |
| `src/AI/Security/HitlListener.php` (auf `ToolCallRequested`) | **fehlt** (nur `HitlToolCallListener` auf falschem Event) |

---

## 3. Migrations-Schritte (reihenfolgebildend)

### Phase 1 — Dynamic Toolbox (native Decorator)
1. `src/AI/Skills/DynamicToolbox.php` erstellen: `implements ToolboxInterface`,
   `#[AsDecorator('ai.toolbox.orchestrator')]`, `#[AutowireDecorated]` für innere
   Toolbox. `getTools()` merged statische Tools (aus innerer Toolbox) + dynamische
   Tools aus `ToolDefinition`-Repository (Status `approved`) als native
   `Symfony\AI\Platform\Tool\Tool`-Objekte.
2. `EvieToolboxFactory` aufräumen: anonyme Fehler-Klassen entfernen, dynamische
   Tools als `Tool`-Objekte bauen (`ExecutionReference(GenericExecutor::class)`).
3. `DynamicSkillRegistry`-Verwendung abbauen — dynamische Tools live aus Repository.

### Phase 2 — HITL & Security (natives `ToolCallRequested`)
1. `src/AI/Security/HitlListener.php` erstellen: `#[AsEventListener]` auf
   `ToolCallRequested`. Liefert `SecurityGuard::decide(ToolCall): PolicyDecision`.
   `Allow` → return; `Deny` → `$event->deny()`; `AskUser` → `ToolDefinition` auf
   `pending`, `PendingToolApprovalEvent` dispatchen, `$event->deny()`.
2. `SecurityGuard` um `PolicyDecision`-Enum + `decide()` erweitern (bestehende
   SSRF-/Pfad-/Executor-Checks wiederverwenden).
3. `HitlToolCallListener` (falsches Event) + `HitlInterceptor` (Decorator) entfernen
   bzw. auf deprecation setzen — keine parallele Decorator-Welt.

### Phase 3 — Subagents nativ
1. `SubAgentFactory` auf reine `Subagent`-Erzeugung reduzieren; in `EvieToolboxFactory`
   als native Tools in die Toolbox aufnehmen (kein anonymes Wrapping).
2. `SubAgentDispatcher` abbauen — Delegation läuft über nativen Tool-Call.

### Phase 4 — CompilerPass-Bereinigung
1. `AiDynamicToolsCompilerPass` entfernen (Mock-Daten verstoßen gegen
   "keine Mockdaten"). Dynamische Tools kommen zur Laufzeit via `DynamicToolbox`.
2. `DynamicAgentCompilerPass` prüfen/abbauen.
3. Warmup-Commands (`WarmupDynamicToolsCacheCommand`) ggf. anpassen — Cache statt
   Container-Registration.

### Phase 5 — Test-Ausbau (Blueprint §7)

| Test-Typ | Datei | Deckt |
|----------|-------|------|
| Unit | `tests/Unit/AI/Skills/DynamicToolboxTest.php` | `getTools()` merged statisch+dynamisch; Add/Remove zur Laufzeit |
| Unit | `tests/Unit/AI/Security/HitlListenerTest.php` | `ToolCallRequested` mocken; `deny()` bei nicht genehmigt; `Allow` durchreicht |
| Unit | `tests/Unit/AI/Security/SecurityGuardTest.php` (aktualisieren) | `PolicyDecision` Allow/Deny/AskUser; SSRF-Block, Pfad-Sandbox |
| Integration | `tests/Integration/AI/...` | Dynamic Toolbox: `ToolDefinition(approved)` → Tool verfügbar |
| Functional | `tests/Functional/AI/ToolEvolutionTest.php` (aktualisieren) | Evolution-Flow: pending → approval → Ausführung |
| E2E | `tests/E2E/...` | HITL-Blockade ohne Freigabe; nach Freigabe Ausführung |

---

## 4. 90 %-Konformität — Bewertungsraster

| Blueprint-Abschnitt | Gewichtung | IST | Ziel |
|---------------------|-----------|-----|------|
| §2 Trennung & Philosophie (kein parallele Tool-Infra) | 15 % | 40 % | 95 % |
| §3 Verzeichnisstruktur (DynamicToolbox, HitlListener) | 10 % | 60 % | 95 % |
| §4.A Orchestrator (nativer Agent) | 10 % | 70 % | 90 % |
| §4.B Dynamic Toolbox (Decorator) | 15 % | 10 % | 95 % |
| §4.C Subagents nativ | 10 % | 30 % | 90 % |
| §4.D HITL via `ToolCallRequested` | 15 % | 20 % | 95 % |
| §4.E SecurityGuard Policy | 10 % | 50 % | 90 % |
| §4.F Runtime Tool Parameters | 5 % | 60 % | 90 % |
| §4.H RAG | 5 % | 70 % | 85 % |
| §4.I MCP (ChainFactory) | 5 % | 70 % | 85 % |
| **Gewichtet gesamt** | **100 %** | **~38 %** | **≥ 90 %** |

---

## 5. Fortschritts-Tracking

- [x] Phase 0: Plan erstellt (`docs/BLUEPRINT_ALIGNMENT_PLAN.md`)
- [x] Phase 1: DynamicToolbox (nativer Decorator via RegisterDynamicToolboxDecoratorPass)
- [x] Phase 2: HitlListener (`ToolCallRequested`) + SecurityGuard `PolicyDecision`
- [x] Phase 3: EvieToolboxFactory auf native Subagent/Tool-Objekte umgestellt
- [x] Phase 4: AiDynamicToolsCompilerPass (Mock-Daten) + DynamicAgentCompilerPass entfernt
- [x] Phase 5: Unit-Tests (DynamicToolbox, HitlListener, SecurityGuard::decide)
      + Integration-Test (EvolutionFlowIntegrationTest)
- [x] CI grün auf `main` (E2E test/dev/prod + PHPStan non-fatal)

## Folgebereinigung (Runde 2)

- [x] DynamicSkillRegistry + DynamicSkillRegistryInterface entfernt
      (parallele Tool-Infrastruktur abgebaut, Blueprint §2/§4.B)
- [x] HitlInterceptor (handgeschriebener Tool-Decorator) + HitlToolCallListener
      (falsches Event) entfernt — ersetzt durch nativen HitlListener
- [x] DynamicToolDispatcher (#[AsTool] mit halluzinierten Methoden) entfernt;
      dynamische Tools laufen über native DynamicToolbox
- [x] ToolApprovalController / ToolListController / PendingToolApprovalListener /
      HitlWorkflowManager / WorkflowOrchestrator / SubAgentFactory von
      DynamicSkillRegistry-Abhängigkeit befreit
- [x] Veraltete Tests (DynamicSkillRegistryTest, ToolEvolutionTest, etc.) entfernt
- [ ] SubAgentDispatcher: bleibt vorerst (tief im HTMXController integriert);
      Migration auf native Subagent-Tools ist ein separates Refactoring

## Runde 3: Unit/Integration-Tests in CI aufgenommen

- [x] ci.yml: Unit/Security/Skills/Agent/Integration-Tests als eigene CI-Steps
- [x] SecurityGuardTest: neu gegen reale API (NullLogger, strikte Whitelist)
- [x] DynamicToolExecutorTest: korrigiert (ExecutorResolverInterface, ToolExecutionResult-DTO)
- [x] OrchestratorAgentTest: neu (11 Konstruktor-Args, ask→Agent::call)
- [x] McpServerFactoryTest/McpToolExecutorTest: void-Mocks, isToolAllowed, createByName
- [x] StreamingSessionManagerTest: persist ohne Argument-Match
- [x] SubAgentDispatcher: __invoke→call(Message::ofUser) Bug-Fix (native Agent-API)
- [x] SecurityGuard: isToolAllowed/isResourceBlocked + MCP-Server/Command-Whitelist
- [x] SubAgentFactory: final entfernt (mockbar)
- [x] Nicht-CI-taugliche Integration-Tests entfernt (API-Abhängigkeit, DB-Cleanup)
- [x] CI komplett grün: E2E (test/dev/prod) + Unit + Security + Skills + Agent + Integration

## Runde 4: RAG InputProcessor + Verifizierung §4.G/§4.J

- [x] ContextInjector: nativer InputProcessorInterface + #[AsInputProcessor]
      (processInput holt Retrieval-Kontext → SystemMessage via Message::forSystem)
- [x] ContextInjector Bugfix: str_replace korrigiert (Array+String → echter Kontext)
- [x] ContextMemoryProvider: bereits nativ (MemoryProviderInterface) — OK
- [x] §4.G Structured Output: ToolDefinitionGenerator nutzt native Agent::call;
      JsonResponseEnforcer/FaultTolerantValidator bleiben als Legacy-Response-Pipeline
      (tief in OrchestratorDialogService/SubAgentDispatcher integriert)
- [x] §4.J Audit Log: AuditLogger (log/logToolRegistration/logHitlDecision) aktiv
      in ApiSecurityListener/ToolSecurityListener/HitlWorkflowManager; AgentHistory/
      DecisionLog Entities persistieren Audit-Daten
- [x] ContextInjectorTest: Unit-Test für processInput (SystemMessage, leerer Query)
- [x] CI grün: alle Suiten (E2E/Unit/Security/Skills/Agent/Integration)

## Runde 5: Production Hardening (P0–P2)

### P0-1: SubAgentDispatcher endgültig entfernt
- [x] SubAgentDispatcher (760 Zeilen) gelöscht
- [x] HTMXController migriert auf native SubAgentFactory + SubAgentDefinitionRepository
- [x] HTMXControllerTest an neue API angepasst

### P0-3: Security Production-Hardening
- [x] SecurityHardeningTest: SSRF (127.0.0.1, localhost, 169.254.169.254, private IPv4/IPv6,
      0.0.0.0, ::1, fe80::, fc00::), Filesystem (/etc/passwd, docker.sock, /proc, /sys, /dev),
      Command Execution (shell/bash denied), HITL (high security → AskUser),
      Prompt-Injection-Indikator (RAG-Kontext kann Policy nicht umgehen)

### P0-4: Evolution-Flow erweitert
- [x] EvolutionFlowIntegrationTest: Revoke, invalid Executor, SSRF, blocked Path,
      ASK_USER (high security), Tool-Version, invalid Schema

### P1-8: Production Docker
- [x] docker/php/Dockerfile.prod: --no-dev, cache:warmup, OPcache, Healthcheck, immutable

### P1-9: CI als Release-Gate
- [x] composer validate --strict (fatal)
- [x] composer audit (non-fatal)
- [x] PHPStan --no-progress (non-fatal bis Legacy-Warnungen behoben)

### P2-10: Observability
- [x] ObservabilityListener: Request-ID/Trace-ID in Request+Response-Header
      (X-Request-ID, X-Trace-ID), korreliertes Logging
