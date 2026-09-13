# Orchestrator-Pipeline: Goal → Intent → Plan → Capability → Execution

> Status: **Implementiert (Stufen 1–8)** / Blueprint-konform · Symfony AI
> v0.12-kompatibel · ohne Mocks oder Fantasie-Tools

## Implementierungs-Stand

Die Pipeline ist der alleinige Pfad in
`OrchestratorDialogService::ask()` (Stufen 7–8): die Fassade ist nun eine
reine Delegation an `PipelineInterface::run()` und liefert
`PipelineResult::getContent()`. Der gesamte reaktive Legacy-Pfad
(JSON-Dispatch, Regex-basierte Sub-Agent-Auswahl
`determineAndCreateSubAgent`, nachträgliche `classifyIntent` im
`no_tool_found`-Zweig, `JsonResponseEnforcer`/`ResponseNormalizer`-
Normalisierung) ist entfernt. Capability Discovery/Generierung findet
ausschliesslich in Phase 4 statt. Die fünf Phasen-Implementierungen
sind vorhanden:

| Phase | Klasse | Status |
|-------|-------|--------|
| 1 Goal | `GoalResolver` | implementiert + getestet |
| 2 Intent | `IntentClassifier` | implementiert + getestet |
| 3 Plan | `Planner` | implementiert + getestet |
| 4 Capability | `CapabilityResolver` | implementiert + getestet |
| 5 Execution | `ExecutionCoordinator` | implementiert + getestet |
| Orchestrierung | `Pipeline` | implementiert + getestet |
| Fassade | `OrchestratorDialogService::ask()` | duenne Delegation an Pipeline |

Alle Konsumenten (`AgentDialogController`, `RunAgentGoalHandler`,
`EvaluationService`, `StrategyManager`) konsumieren die Fassade, die
ausschliesslich `PipelineInterface` injiziert bekommt (Autowiring via
`services.yaml`-Alias auf `Pipeline`). CI: tests ✓, migrations ✓,
e2e-llm ✓.

---

Dieses Dokument beschreibt den Umbau der EVIE-Orchestrierung von der heutigen
**reaktiven** Pipeline (User-Request → JSON-Dispatch → ggf. Tool-Generierung)
in eine **deliberative** Pipeline mit klar getrennten Phasen. Grundlage ist der
Fehlerbefund aus der Konversation: *Capability Discovery sitzt zu früh in der
Pipeline* — EVIE springt heute bereits bei `no_tool_found` direkt in die
Tool-Generierung, obwohl es den Intent und einen Plan noch gar nicht verstanden
hat.

Die Phasen orientieren sich am Blueprint (§4.A Orchestrator als nativer
`Symfony\AI\Agent\Agent`, §5 Self-Evolution) und nutzen ausschließlich native
Symfony-AI-Primitive (`AgentInterface`, `PlatformInterface::invoke`,
`ToolboxInterface`-Decorator, `Subagent`, `ToolCallRequested`-Event). Es
werden **keine** Konstruktor-Injections für Tools, keine Mocks, keine
Platzhalter und keine inkompatiblen Bridges eingeführt.

---

## 1. Ist-Zustand und Schwachstellen

Heute ist die komplette Orchestrierung in **einem** Service
`App\AI\Agent\OrchestratorDialogService::ask()` (976 Zeilen) vereint:

1. `Agent::call()` → JSON-Antwort des LLM.
2. `ResponseNormalizer` → `JsonResponseEnforcer` → `extractResponseType`.
3. `switch($responseType)` verzweigt auf `tool_call | subagent_delegation |
   no_tool_found | dialog | website_research_result`.
4. Im `no_tool_found`-Zweig läuft **nachträglich** `classifyIntent()` (ein
   eigener LLM-Aufruf via `PlatformInterface::invoke('mistral-small-latest')`)
   und entscheidet erst dann zwischen `conversation | information | unclear |
   task_requires_tool` → `generateNewTool()`.

Konkrete Schwachstellen:

- **Capability Discovery zu früh**: Im `no_tool_found`-Pfad wird sofort
  `generateNewTool()` bzw. `determineAndCreateSubAgent()` aufgerufen — also
  Capability-Erzeugung/HITL — bevor ein Plan existiert. Die nachgeschobene
  `classifyIntent()` ist ein Symptom-Fix (Commit `3ca8e4c`), der die
  Phasenreihenfolge nicht auflöst, sondern nur die schlimmsten Fälle abfängt.
- **Kein Plan-Phase**: Es gibt kein explizites, nachvollziehbares
  Schritt-Modell. Statt zu planen ("ich brauche Website-Recherche, dann
  Datenanalyse"), wird direkt delegiert oder ein Tool erfunden.
- **Intent sitzt im falschen Zweig**: Die Intent-Klassifizierung läuft nur im
  `no_tool_found`-Pfad, nicht verlässlich vor jeder Tool-Entscheidung. Bei
  `tool_call` mit `tool_name: unknown` wird derselbe Pfad retroaktiv betreten.
- **Regex-basierte Sub-Agent-Auswahl** (`determineAndCreateSubAgent`) anstelle
  einer Capability-basierten Auswahl gegen die registrierte Toolbox.
- **Monolith**: Eine Klasse vereint Intent, Dispatch, Sub-Agent-Auswahl,
  Tool-Generierung, HITL-Auslösung und Antwortformatierung. Schwer testbar,
  schwer an das Umsatzziel (12 Monate / 100 k€) zurückzubinden.

---

## 2. Soll-Architektur: 5-Phasen-Pipeline

Jede User-Anfrage durchläuft **zwingend** die Phasen in dieser Reihenfolge.
Keine Phase darf übersprungen werden. Capability Discovery/Generierung findet
**nur in Phase 4** statt — niemals in Phase 2 oder 3.

```text
User-Message
   │
   ▼
┌──────────────────────────────────────────────────────────┐
│ 1. GOAL        Was will der Mensch (langfristig)?         │
│   GoalResolver  → AgentGoal / Erfolgs-Metrik             │
│   (nutzt bestehendes Entity AgentGoal, Repository)        │
└──────────────────────────────────────────────────────────┘
   │
   ▼
┌──────────────────────────────────────────────────────────┐
│ 2. INTENT      Was bedeutet diese konkrete Nachricht?    │
│   IntentClassifier → {conversation, information,         │
│                       task, unclear}                     │
│   nur hier wird entschieden: Dialog vs. handeln          │
│   Exit-Gate: conversation/information → direkt Dialog    │
└──────────────────────────────────────────────────────────┘
   │  (nur task/unclear)
   ▼
┌──────────────────────────────────────────────────────────┐
│ 3. PLAN        Welche Schritte lösen den Intent?         │
│   Planner → geordnete Schrittliste (Step[])              │
│   jeder Step: {type, target, params, needs_capability}   │
│   Exit-Gate: unclear → Rückfrage (keine Capability)       │
└──────────────────────────────────────────────────────────┘
   │
   ▼
┌──────────────────────────────────────────────────────────┐
│ 4. CAPABILITY  Sind die nötigen Fähigkeiten vorhanden?    │
│   CapabilityResolver gegen DynamicToolbox + Subagents    │
│   - vorhanden  → ExecutionReference (native)              │
│   - fehlt      → ToolDefinitionGenerator (HITL, §5)       │
│   Exit-Gate: fehlt + nicht freigegeben → HITL-Pending     │
└──────────────────────────────────────────────────────────┘
   │  (nur bei vorhandener/oder freigegebener Capability)
   ▼
┌──────────────────────────────────────────────────────────┐
│ 5. EXECUTION   Ausführung + Audit                         │
│   native Agent-Loop: ToolCallRequested → HitlListener →  │
│   SecurityGuard.decide() → Executor → Ergebnis → LLM      │
│   AuditLogger schreibt AgentHistory                        │
└──────────────────────────────────────────────────────────┘
   │
   ▼
Antwort an User (Dialog / Ergebnis / HITL-Freigabe-Link)
```

Wichtig: **Goal** und **Intent** sind bewusst getrennt. Das Goal kann aus dem
bestehenden `AgentGoal`-System (autonome Ziele via
`RunAgentGoalHandler`/`RunAgentGoalMessage`) kommen oder ad-hoc aus der
Nachricht abgeleitet werden. Der Intent bezieht sich immer auf die einzelne
Nachricht. Damit lässt sich das Unternehmensziel (100 k€/Jahr) als übergeordnetes
Goal modellieren, ohne dass jede Chat-Nachricht sofort Tools erzeugt.

---

## 3. Phasen-Design (Detail)

### Phase 1 — Goal

- **Neu**: `App\AI\Pipeline\GoalResolver` (Interface `GoalResolverInterface`).
- Liefert ein `Goal`-Value-Object: `{identifier, description, successMetric,
  source}`.
- Quellen-Reihenfolge (erste Treffer gewinnt):
  1. Aktives `AgentGoal` (status `active`) für den `userIdentifier`, falls
     via `RunAgentGoalMessage` angestoßen → bestehendes Entity/Repository
     wiederverwenden.
  2. Ad-hoc-Ziel aus der Nachricht via LLM (`PlatformInterface::invoke`).
- Keine Tool-Generierung, kein HITL. Reine Interpretation.
- Das Goal wird in `AgentHistory`-Kontext übernommen, damit nachfolgende
  Phasen die Erfolgs-Metrik sehen.

### Phase 2 — Intent

- **Refactor**: `classifyIntent()` wird aus dem `no_tool_found`-Zweig
  herausgehoben und zur eigenständigen Phase `App\AI\Pipeline\IntentClassifier`
  (Interface `IntentClassifierInterface`).
- Vier Klassen bleiben: `conversation | information | task | unclear`.
  (Entspricht der heutigen Logik, nur an der richtigen Stelle.)
- LLM-Klassifizierung über `PlatformInterface::invoke('mistral-small-latest')`
  mit dem bereits existierenden `buildIntentClassificationPrompt()`.
- **Exit-Gate**: `conversation`/`information` → direkt Dialog-Antwort
  (`buildConversationAnswer`), Pipeline endet. Kein Tool, kein HITL.
- `unclear` → geht weiter in Phase 3 zur Rückfrage-Bildung.

### Phase 3 — Plan

- **Neu**: `App\AI\Pipeline\Planner` (Interface `PlannerInterface`).
- Erzeugt eine `Plan`-Value-Object: geordnete `Step[]`.
- Jeder `Step`: `{type: tool|subagent|clarify, target, parameters,
  needs_capability: bool}`.
- **Exit-Gate**: `unclear` → `Step{type: clarify}` → Rückfrage
  (`buildClarifyingQuestion`), Pipeline endet. Keine Capability-Erzeugung.
- Planner ist ein **reiner LLM-Aufruf** (kein Tool-Calling), Prompt
  deklarativ in `config/prompts/planner.txt` (analog
  `tool_schema_optimizer.txt`).
- Der Planner kennt die **verfügbaren** Sub-Agent-Namen und Tool-Namen als
  Kontext (aus `ToolRegistry`/`SubAgentRegistry`), darf aber **keine** Tools
  erfinden — er plant nur gegen das, was existiert. Fehlende Fähigkeit wird
  als `needs_capability: true` markiert und in Phase 4 behandelt.

### Phase 4 — Capability

- **Neu**: `App\AI\Pipeline\CapabilityResolver`
  (Interface `CapabilityResolverInterface`).
- Pro `Step` prüfen, ob die Fähigkeit vorhanden ist:
  - Tool: Lookup in `ToolDefinitionRepository` (status `approved`) bzw. über
    die native `DynamicToolbox`.
  - Sub-Agent: Lookup in `SubAgentRegistry` (bestehend).
- **Vorhanden** → `ExecutionReference` (native Symfony-AI-Primitive, siehe
  Blueprint §A) wird im Step hinterlegt; weiter zu Phase 5.
- **Fehlt** (`needs_capability: true` oder kein Treffer):
  1. `ToolDefinitionGenerator` erzeugt JSON-Schema (bestehend, Blueprint §5.3).
  2. `ToolDefinition` (status `pending`) speichern.
  3. `SecurityGuard.decide()` → in der Regel `PolicyDecision::AskUser`
     (requiresHitl=true).
  4. `PendingToolApprovalEvent` auslösen (bestehend) → HITL-Frontend.
  5. **Pipeline pausiert** bis Freigabe. Antwort an User: Freigabe-Link
     (bestehende `generateUserResponse`-Logik), keine Execution.
- Nach Freigabe (`/api/tools/{id}/approve`) wird der pausierte Plan
  fortgesetzt (via Messenger oder erneuter `ask()`-Aufruf, siehe §6).
- **Keine** Konstruktor-Injection für Tools; Capability wird über die native
  Toolbox zur Laufzeit registriert (`DynamicToolbox`).

### Phase 5 — Execution

- **Refactor**: der heutige Dispatch-Code (`handleToolCallResponse`,
  `handleSubAgentDelegation`) wird zum `ExecutionCoordinator`.
- Nutzt die **native Agent-Loop** des `ai.agent.orchestrator`:
  `Agent::call(MessageBag)` mit `Toolbox` → `ToolCallRequested`-Event →
  `HitlListener` → `SecurityGuard.decide()` → Executor.
- Pro Step: Schritt ausführen → Ergebnis in `MessageBag` → nächsten Step.
- `AuditLogger.logToolExecution()` → `AgentHistory` (bestehend).
- Nach letztem Step: LLM fasst Ergebnisse zur User-Antwort zusammen
  (anstatt die rohen Tool-Ergebnisse zu zeigen).
- `requiresApproval`-Goals (`AgentGoal.requiresApproval`) respektieren hier
  erneut die HITL-Strecke für kritische Aktionen (§7).

---

## 4. Neues Verzeichnis-Layout (Domain-Driven, Blueprint §3)

```text
src/AI/
├── Agent/
│   ├── OrchestratorDialogService.php   # wird zur dünnen Fassade
│   ├── SubAgentFactory.php             # bestehend
│   └── EvieToolboxFactory.php          # bestehend
├── Pipeline/                           # NEU — die 5 Phasen
│   ├── Pipeline.php                    # Orchestriert Phase 1..5
│   ├── PipelineContext.php             # Value-Object: Goal, Intent, Plan
│   ├── Goal/
│   │   ├── GoalResolverInterface.php
│   │   ├── GoalResolver.php
│   │   └── Goal.php                    # VO
│   ├── Intent/
│   │   ├── IntentClassifierInterface.php
│   │   ├── IntentClassifier.php
│   │   └── Intent.php                  # VO (enum-ähnlich)
│   ├── Plan/
│   │   ├── PlannerInterface.php
│   │   ├── Planner.php
│   │   ├── Plan.php                    # VO
│   │   └── Step.php                    # VO
│   ├── Capability/
│   │   ├── CapabilityResolverInterface.php
│   │   ├── CapabilityResolver.php
│   │   └── CapabilityDecision.php     # VO (available|missing|pending)
│   └── Execution/
│       ├── ExecutionCoordinator.php
│       └── ExecutionResult.php         # bestehend verschieben
```

`OrchestratorDialogService::ask()` wird zu einer **Fassade**, die
`Pipeline::run(PipelineContext)` aufruft und nur noch Antwort-Formatting
(Teil des heutigen `generateUserResponse`) übernimmt. Die ~976 Zeilen
verteilen sich auf die Phasen.

---

## 5. Datenmodell-Erweiterungen (minimal, Doctrine)

Keine neuen Tabellen nötig — bestehende Entities werden genutzt/erweitert:

- **`AgentGoal`** (bestehend): Quelle für Phase 1. Felder `successMetric`,
  `capabilityConstraints`, `requiresApproval` bereits vorhanden und passen.
- **`AgentHistory`** (bestehend): Audit pro Step. Pro Pipeline-Lauf ein
  `AgentHistory`-Eintrag mit serialisiertem `Plan` in `metadata`.
- **`ToolDefinition`** (bestehend): Status `pending/approved/rejected`
  steuert Phase 4. Keine Schema-Änderung.
- **`DecisionLog`** (bestehend): HITL-Entscheidungen für `unclear`-Rückfragen
  und Capability-Freigaben optional hier protokollieren (bereits vorhanden).

Lediglich ein optionales Migrationsfeld für Pausen-Fortsetzung:

```text
agent_goals.last_result JSON  -- bereits vorhanden
```

Es wird **kein** neues DB-Schema erzwungen; die Fortsetzung pausierter Pläne
läuft über `AgentGoal.lastResult` (serialisierter Plan + Status) bzw. über
den Messenger (`RunAgentGoalMessage`), die beide existieren.

---

## 6. Ablauf im Code (Schema)

```php
// src/AI/Pipeline/Pipeline.php
final class Pipeline
{
    public function __construct(
        private GoalResolverInterface $goalResolver,
        private IntentClassifierInterface $intentClassifier,
        private PlannerInterface $planner,
        private CapabilityResolverInterface $capabilityResolver,
        private ExecutionCoordinator $execution,
        private ResponseFormatter $formatter,
    ) {}

    public function run(string $message, string $userIdentifier): PipelineResult
    {
        $ctx = PipelineContext::create($message, $userIdentifier);

        // Phase 1
        $ctx = $ctx->withGoal($this->goalResolver->resolve($ctx));

        // Phase 2
        $intent = $this->intentClassifier->classify($ctx);
        if ($intent->isDialog()) {
            return $this->formatter->dialog($ctx, $intent);
        }

        // Phase 3
        $plan = $this->planner->plan($ctx, $intent);
        if ($plan->isClarification()) {
            return $this->formatter->clarify($ctx, $plan);
        }

        // Phase 4 — Capability Discovery NUR hier
        foreach ($plan->steps() as $step) {
            $decision = $this->capabilityResolver->resolve($step, $ctx);
            if ($decision->isMissing() || $decision->isPending()) {
                // HITL auslösen, Pipeline pausieren
                return $this->formatter->awaitingApproval($ctx, $decision);
            }
            $step = $step->withExecutionReference($decision->reference());
        }

        // Phase 5
        return $this->execution->execute($ctx, $plan);
    }
}
```

`OrchestratorDialogService::ask()` delegiert an `Pipeline::run()` und liefert
den String zurück. `RunAgentGoalHandler` nutzt denselben `Pipeline`-Service
statt direkt `OrchestratorDialogService`, sodass autonome Ziele dieselben Phasen
durchlaufen.

---

## 7. HITL- und Freigabe-Regeln (kritische Aktionen)

Phase 4 und 5 sind die einzigen Stellen mit HITL. Die bestehenden Mechanismen
werden 1:1 wiederverwendet, nicht neu erfunden:

- **Tool-Generierung**: `PendingToolApprovalEvent` +
  `SecurityGuard.decide()::AskUser` (bestehend).
- **Tool-Ausführung**: `ToolCallRequested` → `HitlListener` →
  `SecurityGuard.decide()` → `Allow|Deny|AskUser` (bestehend).
- **Autonome Ziele**: `AgentGoal.requiresApproval` / `isApproved` (bestehend).
- **Irreversible externe Aktionen** (Nachrichten, Veröffentlichungen,
  Ausgaben, Verträge, Kundenkommunikation): weiterhin über
  `DecisionManager.createDecision()` → `DecisionLog` (bestehend) und erfordern
  menschliche Freigabe. Die Pipeline löst **keine** externe Aktion ohne
  Freigabe aus.

Freigaben, die den Menschen (User) einbeziehen, bleiben die einzige Quelle für
Capability-Erweiterung — das ist die "kontrollierte Autonomie" aus Blueprint §5.

---

## 8. Tests (蓝图-konform, ohne Mock-Fantasie)

Bestehende Tests werden erweitert, nicht durch Mock-Fantasie ersetzt:

- `tests/Unit/AI/Agent/OrchestratorAgentLlmTest.php` → wird zu
  `tests/Unit/AI/Pipeline/PipelineTest.php`. Nutzt weiterhin `StubAgent` und
  `StubDeferredResult` (bestehende Stubs) für deterministische LLM-Pfade.
- Pro Phase ein Test mit klarem Exit-Gate:
  - `GoalResolverTest`, `IntentClassifierTest`, `PlannerTest`,
    `CapabilityResolverTest`, `ExecutionCoordinatorTest`.
- `tests/E2E/GoldenPath/EvolutionGoldenPathTest.php` bleibt der End-to-End-
  Pfadnachweis und muss grün bleiben; er prüft die vollständige
  Tool-Generierung→HITL→Freigabe→Ausführung-Strecke.
- Neue Assertion: **Capability-Generierung darf bei `conversation`/
  `information`/`unclear` nicht ausgelöst werden** (kein
  `PendingToolApprovalEvent`). Das ist die Regressionssicherung gegen den
  ursprünglichen Fehler.

PHPStan (`phpstan.neon`) und `composer test` müssen durchlaufen. Keine neuen
Dependencies.

---

## 9. Implementierungs-Phasen (Reihenfolge)

Jede Phase liefert kompilierbaren, getesteten Stand. Kein Big-Bang.

1. **Value-Objects & Interfaces** (Phase 1/2-Vorbereitung)
   `Goal`, `Intent`, `Plan`, `Step`, `CapabilityDecision` + Interfaces.
   Reine Datenklassen, keine Logik. → `make test`.
2. **IntentClassifier auslagern**
   `classifyIntent()` aus `OrchestratorDialogService` in
   `IntentClassifier` heben; `ask()` ruft Phase 2 zuerst auf. Behaviour
   identisch, nur Reihenfolge fix. → `OrchestratorAgentLlmTest` anpassen,
   grün.
3. **GoalResolver**
   `AgentGoal`-Anbindung + ad-hoc-LLM. Phase 1 vor Phase 2. →
   `GoalResolverTest`.
4. **Planner**
   `planner.txt`-Prompt + `Planner`. `unclear`-Exit-Gate über
   `buildClarifyingQuestion`. → `PlannerTest`.
5. **CapabilityResolver**
   Lookup gegen `DynamicToolbox`/`ToolRegistry`/`SubAgentRegistry`;
   fehlt → bestehende `ToolDefinitionGenerator` + HITL. →
   `CapabilityResolverTest`, GoldenPath bleibt grün.
6. **ExecutionCoordinator**
   Dispatch auslagern; native Agent-Loop. → `ExecutionCoordinatorTest`.
7. **Fassade & Handler**
   `OrchestratorDialogService::ask()` → `Pipeline::run()`;
   `RunAgentGoalHandler` auf `Pipeline` umstellen. →
   `OrchestratorAgentLlmTest`, `RunAgentGoalHandlerTest` anpassen.
8. **Doku & Aufräum** ✅
   `docs/architecture/agent-architecture.md`, `data-flow.md` und
   `evolution.md` referenzieren diese Pipeline; die alte Regex-Sub-Agent-Auswahl
   (`determineAndCreateSubAgent`) und der gesamte reaktive JSON-Dispatch-Pfad
   sind entfernt. `OrchestratorDialogService` ist eine duenne Fassade, die
   ausschliesslich an `PipelineInterface` delegiert.

---

## 10. Abhängigkeiten zu bestehenden Komponenten (keine Brüche)

| Bestehend | Rolle in neuer Pipeline |
|-----------|-------------------------|
| `ai.agent.orchestrator` (native Agent) | Phase 5 Execution-Loop |
| `ToolDefinitionGenerator` | Phase 4 fehlende Capability |
| `DynamicToolbox` (Decorator) | Phase 4 Lookup + Registration |
| `SubAgentRegistry` / `SubAgentFactory` | Phase 4 Sub-Agent-Lookup |
| `SecurityGuard` + `HitlListener` | Phase 4/5 HITL (unverändert) |
| `DecisionManager` + `DecisionLog` | Freigaben kritischer Aktionen |
| `AgentGoal` + `RunAgentGoalHandler` | Phase 1 Goal-Quelle |
| `AuditLogger` / `AgentHistory` | Phase 5 Audit |
| `PlatformInterface::invoke` | LLM-Aufrufe in Phase 1-3 |

Es werden **keine** neuen Symfony-AI-Bridges erfunden, **keine**
Konstruktor-Injection für Tools, **keine** Mock-Daten. Alle Phasen nutzen
bereits vorhandene Infrastruktur.

---

## 11. Traceability zum Umsatzziel

Die Phasenstruktur erlaubt es, das übergeordnete Goal
("100 k€ Jahresumsatz in 12 Monaten") als `AgentGoal` zu modellieren. Phase 1
lädt dieses Goal, Phase 3 plant konkrete Schritte (Lead-Recherche,
Datenanalyse, E-Mail-Entwürfe), Phase 4 fordert nur dann neue Fähigkeiten an,
wenn eine konkrete ausführbare Aufgabe kein Tool hat, und Phase 5 führt
ausschließlich nach Freigabe aus. Damit wird EVIEs Autonomie nachvollziehbar
auf das Geschäftsziel zurückgebunden statt bei jedem "Hallo" Tools zu erfinden.
