# Architektur-Blueprint: Selbst-evolvierender AI-Agent (Symfony AI v0.12)

Dieses Dokument beschreibt die Architektur und den Entwicklungsplan für einen
**selbst-evolvierenden KI-Agenten**, basierend auf dem **Symfony AI Bundle v0.12**
(`symfony/ai-agent`, `symfony/ai-bundle`, `symfony/ai-platform`,
`symfony/ai-store`), angetrieben von **Mistral LLM** und abgesichert durch
**Human-in-the-Loop (HITL)**.

> **Leitprinzip (Revision 2):** EVIE baut **keine parallele Tool-Infrastruktur**
> neben Symfony AI auf. Alles, was Symfony AI nativ bereitstellt — Dynamic
> Toolbox, `ToolCallRequested`-Event für HITL, `Subagent` als Tool, strukturierte
> Ausgabe, RAG/Store, MCP — wird **nativ** verwendet. Eigenbau-Wrapper,
> `DynamicSkillRegistry`-Kompilierungs-Pässe und handgeschriebene Tool-Decoratoren
> werden abgebaut zugunsten der nativen Erweiterungspunkte.

---

## 1. Zielarchitektur

```text
                    ┌────────────────────┐
                    │      USER / UI      │
                    └─────────┬──────────┘
                              │
                              ▼
                    ┌────────────────────┐
                    │   EVIE ORCHESTRATOR │
                    │   Symfony AI Agent  │
                    └─────────┬──────────┘
                              │
             ┌────────────────┼─────────────────┐
             │                │                 │
             ▼                ▼                 ▼
       Static Tools       Subagents       Dynamic Tools
        (#[AsTool])      (Subagent-Tool)         │
             │                │                 ▼
             │                │          ToolDefinition (DB)
             │                │                 │
             │                │                 ▼
             │                │        Dynamic Toolbox (Decorator)
             │                │                 │
             │                │                 ▼
             │                │        Symfony AI Tool (Platform\Tool)
             │                │                 │
             └────────────────┼─────────────────┘
                              │
                              ▼
                    ToolCallRequested (Event)
                              │
                    ┌─────────┴─────────┐
                    │                   │
                    ▼                   ▼
              SecurityGuard          HITL-Listener
              (Policy)              (User-Freigabe)
                    │                   │
                    └─────────┬─────────┘
                              ▼
                         Executor
                              │
               ┌──────────────┼─────────────┐
               ▼              ▼             ▼
             HTTP          Filesystem      API
                              │
                              ▼
                         Audit Log
```

Die Architektur folgt dem Symfony AI Agent-Loop: Der `Agent` wrappt ein Modell
mit einer `Toolbox` und `InputProcessor`/`OutputProcessor`, ruft Tools auf, liest
die Ergebnisse und entscheidet das weitere Vorgehen — bis die Aufgabe erledigt ist.
EVIE erweitert diesen Loop **nur** an den dafür vorgesehenen Erweiterungspunkten.

---

## 2. Trennung & Kernphilosophie

- **Logik** (Symfony)
- **Intelligenz** (Mistral via `PlatformInterface`)
- **Fähigkeiten** (Symfony AI Tools / Toolbox)
- Der Agent **schreibt niemals eigenen PHP-Code**.
- „Evolution“ bedeutet die **dynamische Komposition und Registrierung** neuer
  Tool-Definitionen als `Symfony\AI\Platform\Tool\Tool`-Objekte, die über die
  **Dynamic Toolbox** zur Laufzeit ein-/ausgehangen werden.

---

## 3. Verzeichnisstruktur (Domain-Driven)

```txt
src/
├── AI/
│   ├── Agent/
│   │   ├── EvieToolboxFactory.php        # Baut die Toolbox (statisch + dynamisch + MCP)
│   │   ├── SubAgentFactory.php           # Erzeugt Subagent-Instanzen (Symfony\AI\...\Tool\Subagent)
│   │   └── OrchestratorDialogService.php # Controller-Ebene: User-Request → Agent
│   ├── Skills/
│   │   ├── DynamicToolbox.php            # Decorator über ToolboxInterface (native Dynamic Toolbox)
│   │   ├── ToolDefinitionGenerator.php   # Nutzt LLM, um JSON-Schema zu generieren
│   │   ├── Tool/                          # #[AsTool]-Klassen (statische Tools)
│   │   │   ├── WeatherTool.php
│   │   │   ├── FileReadTool.php
│   │   │   └── ...
│   │   └── Executor/                      # Sichere Executor-Basisdienste
│   │       ├── GenericApiExecutor.php
│   │       ├── GenericFileExecutor.php
│   │       ├── GenericDatabaseExecutor.php
│   │       └── GenericHttpExecutor.php
│   ├── Security/
│   │   ├── HitlListener.php              # EventSubscriber auf ToolCallRequested
│   │   ├── SecurityGuard.php              # Policy: Allow/Deny/AskUser
│   │   └── Events/                        # PendingToolApprovalEvent etc.
│   ├── Rag/
│   │   ├── ContextInjector.php           # InputProcessor: RAG → System-Prompt
│   │   ├── Retriever.php
│   │   └── MistralEmbeddingService.php
│   ├── Onboarding/
│   │   ├── ContextStoreManager.php
│   │   └── OnboardingFlowManager.php
│   └── Response/
│       └── ...                            # Strukturierte Ausgabe / Validierung
├── Mcp/                                   # MCP-Integration (native Client + ToolFactory)
│   ├── Client/McpServerManager.php
│   ├── Toolbox/McpToolFactory.php
│   └── EvieMcpBundle.php
└── Entity/
    ├── UserProfile.php
    ├── ToolDefinition.php                 # Persistiert JSON-Schemata + Status (pending/approved)
    ├── DecisionLog.php
    └── AgentHistory.php                   # Audit-Log
```

---

## 4. Komponenten-Design (nativ Symfony AI)

### A. Orchestrator (Symfony AI `Agent`)

Der Orchestrator ist ein nativer `Symfony\AI\Agent\Agent`. Er **führt keine
konkreten Aufgaben aus**, sondern plant und delegiert über Tool-Calling.

- Erhält User-Prompt + Kontext (via `InputProcessor` / `Memory`).
- Mistral analysiert die Intention und nutzt **Tool Calling**.
- Die Toolbox enthält:
  - **Statische Tools** (`#[AsTool]`-Klassen),
  - **Subagents** als `Symfony\AI\Agent\Toolbox\Tool\Subagent`,
  - **Dynamische Tools** aus `ToolDefinition` (via Dynamic Toolbox).
- Konfiguration über `config/packages/ai.yaml` (`ai.agent.orchestrator`).

```php
// Vereinfacht: Orchestrator mit nativer Toolbox
$toolbox = $evieToolboxFactory->createOrchestratorToolbox();
$processor = new AgentProcessor($toolbox);
$agent = new Agent($platform, 'mistral-large-latest', [$processor], [$processor]);
$result = $agent->call($messages);
```

### B. Dynamic Toolbox (nativ `ToolboxInterface`-Decorator)

Symfony AI unterstützt das **Hinzufügen, Entfernen und Anpassen von Tools zur
Laufzeit** über einen Decorator, der `ToolboxInterface` implementiert
(`DynamicToolbox`). EVIE nutzt genau dieses Muster — **kein** eigener
`DynamicSkillRegistry`-CompilerPass, der eine parallele Tool-Welt aufbaut.

- `DynamicToolbox` dekoriert die native `ai.toolbox.orchestrator`.
- `getTools()` merge-t statische Tools, Subagent-Tools und dynamische
  `Tool`-Objekte, die aus `ToolDefinition`-Entities gebaut werden.
- Freigabe eines neuen Tools → `ToolDefinition` (Status `approved`) →
  `DynamicToolbox::getTools()` liefert das neue `Tool` beim nächsten Agent-Call.

```php
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

// Neues dynamisches Tool aus einer ToolDefinition anlegen
$tool = new Tool(
    new ExecutionReference(GenericApiExecutor::class),
    $definition->getName(),
    $definition->getDescription(),
    $definition->getSchema(),
);
// DynamicToolbox liefert es beim nächsten Agent-Call mit aus
```

### C. Subagents als Tools (nativ `Symfony\AI\Agent\Toolbox\Tool\Subagent`)

Sub-Agenten sind **keine** eigene Dispatcher-Klasse, sondern native
`Subagent`-Instanzen, die einen verschachtelten `Agent` kapseln und als Tool
in der Toolbox registriert werden.

```php
$researchAgent = new Agent($platform, 'mistral-large-latest', [$researchPrompt]);
$subagent = new Subagent($researchAgent, 'website_researcher', 'Webseiten-Recherche');
// Subagent landet als ganz normales Tool in der Toolbox
$toolbox = new Toolbox([$subagent]);
```

Die `SubAgentFactory` instanziiert die spezialisierten Agenten und wrappt sie
in `Subagent`-Objekte. Die `EvieToolboxFactory` fügt diese der Toolbox hinzu —
der Orchestrator ruft Sub-Agenten wie jedes andere Tool auf.

### D. HITL über `ToolCallRequested` (natives Event)

Die `Toolbox` dispatcht vor jeder Tool-Ausführung das
`Symfony\AI\Agent\Toolbox\Event\ToolCallRequested`-Event. EVIE nutzt diesen
Erweiterungspunkt für **Human-in-the-Loop** — **kein** handgeschriebener
Tool-Decorator.

- `HitlListener` (EventSubscriber auf `ToolCallRequested`) entscheidet per
  `SecurityGuard`-Policy: `Allow` / `Deny` / `AskUser`.
- Bei `AskUser` wird das Tool in der `ToolDefinition` auf `pending` gesetzt,
  ein `PendingToolApprovalEvent` versandt und die Ausführung via
  `$event->deny()` blockiert, bis der User im Frontend freigibt.
- Nach Freigabe (`status = approved`) wird der initiale Prompt wiederholt —
  das Tool steht über die Dynamic Toolbox bereit.

```php
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;

$dispatcher->addListener(ToolCallRequested::class, function (ToolCallRequested $event) use ($guard, $handler): void {
    $decision = $guard->decide($event->getToolCall());
    if (PolicyDecision::Allow === $decision) {
        return;
    }
    if (PolicyDecision::Deny === $decision) {
        $event->deny('Tool durch Policy blockiert.');
        return;
    }
    // AskUser
    if (!$handler->confirm($event->getToolCall())) {
        $event->deny('User hat die Ausführung abgelehnt.');
    }
});
```

### E. SecurityGuard (Policy-Layer)

Eine harte Grenze (Sandbox), die entscheidet, welche Basisdienste
(`GenericApiExecutor`, `GenericFileExecutor`, …) ein dynamisch generiertes Tool
überhaupt ansprechen darf. Der Guard liefert eine `PolicyDecision`, die der
`ToolCallRequested`-Listener auswertet. Zu den Prüfungen gehören:
- Executor-Whitelist (nur `api`, `database`, `filesystem`, `http`, `generic`),
- SSRF-Schutz (Blockierung privater IPs / `localhost`),
- Pfad-Sandbox (Blockierung von `/etc`, `/root`, `/proc`, …),
- Explizite Service-Whitelist (keine Wildcards).

### F. Runtime-driven Tool Parameters

Dynamisch generierte Tools definieren ihr JSON-Schema zur Laufzeit (gespeichert
in `ToolDefinition.schema`). Die native `Toolbox` generiert daraus die
JSON-Schema-Repräsentation für das LLM; Argumente werden über
`ToolCallArgumentsResolved` / Schema-Validierung geprüft. EVIE validiert die
Argumente zusätzlich über den `SecurityGuard` und die Executor-Konfiguration,
bevor das Tool tatsächlich ausgeführt wird.

### G. Strukturierte Ausgabe (Structured Output)

Symfony AI unterstützt **Structured Output** (PHP-Klassen oder
Array-Strukturen als Ausgabe, mit Serializer-Groups und Validierung). EVIE nutzt
dies für agenteninterne Antworten (z. B. Tool-Generierungs-Anfragen,
Entscheidungs-Logs) anstatt eigener `JsonResponseEnforcer`/`FaultTolerantValidator`-Logik
zu pflegen, die das LLM-Format manuell nachbaut. Wo Mistral strukturierte
Ausgabe direkt unterstützt, wird das native `outputStructure`/Schema verwendet.

### H. RAG (Retrieval-Augmented Generation)

- `ContextInjector` agiert als `InputProcessor`: Bei jedem User-Prompt holt er
  über den `Retriever` relevante Profil-/Kontext-Informationen aus dem
  Vector Store (`symfony/ai-postgres-store` / pgvector) und fügt sie als
  System-Prompt in den `MessageBag` ein.
- Embeddings über `MistralEmbeddingService` (Mistral Embed API).
- Onboarding-Ergebnisse werden als Vektoren gespeichert und bei Bedarf retrouved.

### I. MCP (Model Context Protocol)

EVIE integriert MCP nativ als weitere Tool-Quelle über die
`ToolFactory`-Chain (`ChainFactory`) in der `EvieToolboxFactory`:

```php
$chainFactory = new ChainFactory([$mcpToolFactory, $reflectionToolFactory]);
$toolbox = new Toolbox($allTools, $chainFactory);
```

- `McpToolFactory` stellt Remote-Tools (filesystem, playwright, github) bereit,
  die wie native Symfony AI Tools in der Toolbox erscheinen.
- `McpServerManager` verwaltet die MCP-Server-Verbindungen (stdio/http).
- MCP-Tools unterliegen demselben `ToolCallRequested`/HITL/SecurityGuard-Pfad
  wie alle anderen Tools.

### J. Audit Log

Jede Tool-Ausführung wird in `AgentHistory` protokolliert (wer, was, wann,
Ergebnis). Bei HITL-relevanten Aktionen zusätzlich in `DecisionLog`.

---

## 5. Workflow: Erschaffung eines neuen Tools (Selbst-Evolution)

```text
1. Bedarfserkennung
   User: "Analysiere diese Excel-Datei und gib mir den Umsatz."

2. Fehlschlag
   Orchestrator stellt fest: kein passendes Tool in der Dynamic Toolbox.

3. Ideengenerierung
   Orchestrator → ToolDefinitionGenerator (nutzt LLM-Agent `tool_generator`).

4. Schema-Entwurf
   Mistral generiert ein JSON-Schema für "ExcelParserTool", das einen
   existierenden GenericFileExecutor nutzen soll.

5. HITL-Blockade
   Entwurf wird als ToolDefinition (status: pending) gespeichert.
   ToolCallRequested-Listener blockiert die Ausführung ($event->deny())
   und versendet ein PendingToolApprovalEvent.

6. User-Interaktion
   Frontend zeigt: "Neues Tool 'ExcelParserTool' erforderlich. Genehmigen?"

7. Freigabe
   User klickt "Ja" → ToolDefinition.status = approved.

8. Dynamische Registrierung
   DynamicToolbox liefert das Tool ab dem nächsten Agent-Call via getTools().

9. Ausführung
   Orchestrator wiederholt den Prompt, findet das Tool, führt es aus
   (nach erneutem SecurityGuard-Check via ToolCallRequested).
```

---

## 6. Entwicklungsplan (Phasen)

### 📌 Phase 1: Core Foundation — nativer Symfony AI Agent
- Symfony-Projekt-Setup & AI Bundle v0.12 installiert.
- Platform-Konfiguration (Mistral API Keys).
- Orchestrator als nativer `Agent` mit `AgentProcessor` + `Toolbox`.
- 2–3 statische `#[AsTool]`-Tools zur Validierung des Tool-Callings.

### 📌 Phase 2: Onboarding & RAG (Store)
- `OnboardingFlowManager` (chat-basiertes Interview).
- Vector Store via `symfony/ai-postgres-store` (pgvector).
- `ContextInjector` als `InputProcessor` (Retrieval → System-Prompt).

### 📌 Phase 3: Dynamic Toolbox (native Decorator-Muster)
- Datenbankschema für `ToolDefinition` (JSON-Schema + Status).
- `ToolDefinitionGenerator` (LLM-gestützter Schema-Entwurf).
- `DynamicToolbox` als `ToolboxInterface`-Decorator — **kein** eigener
  CompilerPass, keine parallele `DynamicSkillRegistry` als Tool-Factory.

### 📌 Phase 4: HITL & Security (natives `ToolCallRequested`-Event)
- `HitlListener` als EventSubscriber auf `ToolCallRequested`.
- `SecurityGuard` als Policy (`Allow`/`Deny`/`AskUser`).
- UI/API zur Freigabe von Pending-Tools.
- Integrationstests für den Blockade-Mechanismus.

### 📌 Phase 5: Subagents & MCP (nativ)
- `SubAgentFactory` erzeugt `Subagent`-Instanzen (verschachtelte `Agent`).
- Subagents als Tools in der `EvieToolboxFactory` registriert.
- MCP-Integration über `McpToolFactory` in der `ChainFactory`.

---

## 7. Testplan & Qualitätssicherung

### 🧪 7.1 Unit Tests (PHPUnit)
- `DynamicToolboxTest`: Validiere, dass `getTools()` statische + dynamische +
  Subagent-Tools korrekt merge-t; Tools können zur Laufzeit hinzugefügt/entfernt werden.
- `HitlListenerTest`: Mocke `ToolCallRequested`; validiere, dass `deny()` bei
  nicht genehmigten Tools aufgerufen wird, `Allow` direkt durchreicht.
- `SecurityGuardTest`: Prüfe Allow/Deny/AskUser-Policy; SSRF-Block
  (`localhost`), Pfad-Sandbox (`/etc`).

### 🧪 7.2 Integration Tests
- **Mistral Tool Calling:** Prompt absetzen, prüfen ob Symfony AI korrekt
  den Tool-Call erkennt und die `Toolbox` das Tool ausführt.
- **Context Retrieval:** Profil im Vector Store speichern, Anfrage stellen,
  prüfen ob der System-Prompt die retrieved-Information enthält.
- **Dynamic Toolbox:** `ToolDefinition` (approved) anlegen, prüfen, dass der
  nächste Agent-Call das Tool verwenden kann.

### 🧪 7.3 System / E2E Tests (Acceptance)
- **Evolution-Flow-Test:**
  1. Anforderung für nicht existierendes Tool senden.
  2. Datenbank auf neuen `pending`-Eintrag in `ToolDefinition` prüfen.
  3. User-Approval simulieren (API-Call).
  4. Anforderung erneut senden.
  5. Prüfen, ob Aufgabe erfolgreich abgeschlossen wird.
- **HITL-Blockade-Test:** Tool-Aufruf ohne Freigabe wird via `ToolCallRequested`
  blockiert; nach Freigabe wird er ausgeführt.

---

## 8. Symfony AI Native Capabilities — Mapping

| Capability | Symfony AI v0.12 (nativ) | EVIE-Nutzung |
|------------|---------------------------|--------------|
| Dynamic Toolbox | `ToolboxInterface`-Decorator (`DynamicToolbox`) | `src/AI/Skills/DynamicToolbox.php` |
| HITL | `ToolCallRequested`-Event | `HitlListener` (EventSubscriber) + `SecurityGuard`-Policy |
| Subagents als Tools | `Symfony\AI\Agent\Toolbox\Tool\Subagent` | `SubAgentFactory` → `EvieToolboxFactory` |
| Runtime Tool Parameters | JSON-Schema aus `#[AsTool]`/`Tool`; `ToolCallArgumentsResolved` | `ToolDefinition.schema` → `Tool`-Objekt |
| Structured Output | Platform `outputStructure` / Serializer-Groups | Agenteninterne Antworten (Entscheidungen, Tool-Gen) |
| RAG | `symfony/ai-store`, `InputProcessor` | `ContextInjector` + `Retriever` + pgvector |
| MCP | `ToolFactory`/`ChainFactory`, MCP-Client | `McpToolFactory` in `EvieToolboxFactory` |

---

## 📌 Zusammenfassung
- **Architektur:** Nativ Symfony AI v0.12 — keine parallele Tool-Infrastruktur.
- **Evolution:** Dynamische Tool-Generierung via LLM, registriert über die
  native **Dynamic Toolbox**.
- **Sicherheit:** `ToolCallRequested`-Event + `SecurityGuard`-Policy, asynchrone Freigabe.
- **Subagents:** Native `Subagent`-Instanzen als Toolbox-Tools.
- **Kontext:** RAG-basiert, User-spezifisch (`InputProcessor`).
- **MCP:** Native Tool-Quelle über `ChainFactory`.
- **Testabdeckung:** Unit, Integration, E2E.

---

*Blueprint basierend auf **Symfony AI v0.12** (`symfony/ai-bundle`, `symfony/ai-agent`,
`symfony/ai-platform`, `symfony/ai-store`) und **Mistral LLM**.*
*Referenzen: [Agent Component](https://symfony.com/doc/current/ai/components/agent.html),
[Dynamic Toolbox](https://symfony.com/doc/current/ai/cookbook/dynamic-tools.html),
[Human-in-the-Loop](https://symfony.com/doc/current/ai/cookbook/human-in-the-loop.html),
[Platform Component](https://symfony.com/doc/current/ai/components/platform.html).*
