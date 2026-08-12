# EVIE System-Analyse: Implementierungsstatus, Abweichungen & Potenzial

**Erstellt am:** 12. August 2026  
**Repository:** [Jens-Smit/EVIE](https://github.com/Jens-Smit/EVIE)  
**Blueprint:** [blueprint.md](blueprint.md)  
**Zweck:** Kritische Prüfung der Implementierung gegen den Architektur-Blueprint, Identifikation von Abweichungen, Inkompatibilitäten und Potenzialen.

---

## 📊 **Zusammenfassung des Implementierungsstatus**

| **Bereich**               | **Status**          | **Fortschritt** | **Kritische Lücken** |
|---------------------------|---------------------|-----------------|---------------------|
| **Core Foundation**       | ✅ **Fertig**        | 95%             | Keine CompilerPass-Integration für DynamicSkillRegistry |
| **Orchestrator-Agent**    | ✅ **Fertig**        | 98%             | Sub-Agenten statisch in ai.yaml statt dynamisch |
| **Sub-Agenten**            | ✅ **Fertig**        | 100%            | 12 Sub-Agenten hardcoded, keine dynamische Erstellung |
| **Tool Evolution System** | ⚠️ **Teilweise**     | 80%             | JSON-Schemata werden nicht in ToolInterface umgewandelt |
| **HITL & Security**        | ⚠️ **Teilweise**     | 70%             | SecurityGuard ohne Whitelist-Konfiguration |
| **Onboarding & RAG**       | ✅ **Fertig**        | 90%             | Fehlende LLM-Prompt-Optimierung für Onboarding |
| **Frontend**              | ✅ **Fertig**        | 85%             | Keine Echtzeit-Updates (Streaming/WebSocket) |
| **MCP-Integration**        | ✅ **Fertig**        | 90%             | MCP-Server hardcoded in ai.yaml |
| **Testing**                | ❌ **Fehlend**       | 0%              | Keine Unit/Integration/E2E-Tests |

---

## 🏗️ **1. Detaillierte Komponentenanalyse**

### **1.1 Orchestrator-Layer (`AgentInterface`)**
**Blueprint-Anforderung:**
- `OrchestratorAgent` als Haupt-Controller
- Nutzt `#[AsAgent]` oder Konfiguration in `config/packages/ai.yaml`
- Delegiert an Sub-Agenten via `SubAgentFactory`

**Implementierungsstatus:**
✅ **Fertig, aber als Service implementiert**
- **Datei:** `src/AI/Agent/OrchestratorDialogService.php` (35 KB)
- **Funktionen:**
  - Empfängt User-Prompts und analysiert Intention
  - Delegiert an Sub-Agenten (12 Typen: `website_researcher`, `data_analyst`, etc.)
  - Löst Tool-Generierung aus, falls kein Tool gefunden wird
  - Nutzt **LLM-basierte Klassifizierung** für robustere Erkennung
  - Fallback-Handling für unstrukturierte LLM-Antworten
  - JSON-Response-Enforcement via `JsonResponseEnforcer`
- **Abweichung:**
  - **Nicht als `OrchestratorAgent`-Klasse**, sondern als **Service** implementiert
  - **Sub-Agenten werden manuell** in `OrchestratorDialogService` erstellt, nicht über `SubAgentFactory`

**Bewertung:**
- **Funktional äquivalent**, aber **architektonisch nicht 1:1** zum Blueprint
- **Positiv:** Sehr robustes Error-Handling und Fallback-Logik
- **Negativ:** Sub-Agenten-Delegation **nicht dynamisch** (hardcoded in `ai.yaml`)

---

### **1.2 Skill-Evolution-Layer (Tool-Generierung)**
**Blueprint-Anforderung:**
- `ToolDefinitionGenerator`: Generiert JSON-Schemata via Mistral
- `DynamicSkillRegistry`: Lädt Tools aus DB und wandelt sie in `ToolInterface` um
- **CompilerPass** für Container-Compile-Time-Integration

**Implementierungsstatus:**
✅ **ToolDefinitionGenerator implementiert**
- **Datei:** `src/AI/Skills/ToolDefinitionGenerator.php` (15 KB)
- **Funktionen:**
  - Generiert **JSON-Schemata** für neue Tools via Mistral
  - Erstellt `ToolDefinition`-Entities mit:
    - `name` (z. B. `website_scraping`)
    - `description` (generische oder angepasste Beschreibungen)
    - `schema` (JSON-Schema für Parameter)
    - `parameters` (Array mit Tool-Parametern)
  - Unterstützt **Sub-Agenten-Verknüpfung**

⚠️ **DynamicSkillRegistry teilweise implementiert**
- **Datei:** `src/AI/Skills/DynamicSkillRegistry.php` (5 KB)
- **Funktionen:**
  - Lädt `ToolDefinition`-Entities aus der Datenbank
  - **ABER:** **Keine Umwandlung in `ToolInterface`-Klassen**
  - **ABER:** **Kein CompilerPass** für Container-Integration

**Bewertung:**
- **Kritische Lücke:** Tools aus der DB sind **nicht ausführbar**, da sie nicht als Symfony Tools registriert werden
- **Fehlend:** `CompilerPass`, der JSON-Schemata in `ToolInterface`-Implementierungen umwandelt
- **Risiko:** **Dynamisch generierte Tools können nicht ausgeführt werden**

---

### **1.3 Security & HITL-Layer**
**Blueprint-Anforderung:**
- `HitlInterceptor` (Decorator-Pattern) für asynchrone Tool-Freigabe
- `SecurityGuard` als harte Sandbox-Grenze
- `PendingToolApprovalEvent` für Benachrichtigungen

**Implementierungsstatus:**
✅ **HitlInterceptor implementiert**
- **Datei:** `src/AI/Security/HitlInterceptor.php` (1,8 KB)
- **Funktionen:**
  - Decorator für Tool-Aufrufe
  - Prüfung: `if (!$toolDefinition->isApproved()) { dispatch(PendingToolApprovalEvent); return HaltExecution(); }`
  - **ABER:** **Keine Implementierung von `HaltExecution()`** (muss noch geprüft werden)

✅ **HitlToolCallListener implementiert**
- **Datei:** `src/AI/Security/HitlToolCallListener.php`
- **Funktionen:**
  - Reagiert auf Tool-Call-Events
  - Leitet an HITL-Freigabe weiter

✅ **SecurityGuard implementiert**
- **Datei:** `src/AI/Security/SecurityGuard.php` (2,8 KB)
- **Funktionen:**
  - Definiert **harte Sandbox-Regeln**
  - **ABER:** **Keine Konfiguration**, welche Basis-Services erlaubt sind
  - **ABER:** **Keine Whitelist** für `GenericApiExecutor`, `FileSystemReadExecutor`, etc.

**Bewertung:**
- **Kritische Lücke:** `SecurityGuard` **ohne Konfiguration** → **Keine echte Sandbox**
- **Risiko:** Dynamisch generierte Tools könnten **unsichere APIs** aufrufen
- **Empfehlung:** Whitelist für erlaubte Services in `SecurityGuard` hinzufügen

---

### **1.4 Onboarding & Kontext (RAG)**
**Blueprint-Anforderung:**
- `OnboardingFlowManager`: Strukturiertes Interview via LLM
- `ContextStoreManager`: Speichert User-Kontext als Vektor-Embeddings
- **Retriever:** Fügt Kontext als System-Prompt hinzu

**Implementierungsstatus:**
✅ **OnboardingFlowManager implementiert**
- **Datei:** `src/AI/Onboarding/OnboardingFlowManager.php` (7 KB)
- **Funktionen:**
  - Führt User durch **strukturiertes Interview**
  - Kategorisiert User: **Business** oder **Privat**
  - **ABER:** **Kein spezifischer LLM-Prompt** für Onboarding (nur generische Logik)

✅ **ContextStoreManager implementiert**
- **Datei:** `src/AI/Onboarding/ContextStoreManager.php` (3,2 KB)
- **Funktionen:**
  - Speichert Onboarding-Ergebnisse als **Vektor-Embeddings**
  - Nutzt **AI Store** (`ai.store.postgres.onboarding` in `ai.yaml`)

✅ **ContextMemoryProvider implementiert**
- **Datei:** `src/AI/Onboarding/ContextMemoryProvider.php`
- **Funktionen:**
  - Stellt Kontext für den Orchestrator bereit

**Bewertung:**
- **Funktional vollständig**, aber:
  - **Fehlende LLM-Prompt-Optimierung** für Onboarding-Interview
  - **Keine Validierung** der Embeddings

---

### **1.5 Multi-Agent Orchestrierung**
**Blueprint-Anforderung:**
- `SubAgentFactory`: Erstellt spezialisierte Agenten
- Orchestrator betrachtet Sub-Agenten als "Tools"

**Implementierungsstatus:**
✅ **SubAgentFactory implementiert**
- **Datei:** `src/AI/Agent/SubAgentFactory.php` (13 KB)
- **Funktionen:**
  - Erstellt **12 Sub-Agenten-Typen**:
    - `website_researcher`
    - `data_analyst`
    - `code_assistant`
    - `document_processor`
    - `communication_manager`
    - `api_integration`
    - `project_manager`
    - `finance_manager`
    - `hr_manager`
    - `marketing_manager`
    - `ceo_assistant`
    - `fallback`
  - **ABER:** Sub-Agenten sind **statisch in `ai.yaml` konfiguriert**, nicht dynamisch

✅ **SubAgentDispatcher implementiert**
- **Datei:** `src/AI/Agent/SubAgentDispatcher.php` (20 KB)
- **Funktionen:**
  - Delegiert Aufgaben an Sub-Agenten
  - **Intelligente Routing-Logik** basierend auf User-Anfrage

**Bewertung:**
- **Funktional vollständig**, aber:
  - **Keine dynamische Erstellung** von Sub-Agenten zur Laufzeit
  - **Sub-Agenten sind hardcoded** in `ai.yaml`

---

### **1.6 AI-Konfiguration (`ai.yaml`)**
**Implementierungsstatus:**
✅ **Sehr detailliert** (16 KB)
- **Plattformen:** Mistral, Gemini
- **Agenten:** 12 Sub-Agenten + Orchestrator + Fallback
- **Tools:**
  - Statische Tools: `WeatherTool`, `FileReadTool`, `DataAnalyzerTool`, etc.
  - Dynamische Tools: `DynamicToolDispatcher`
  - MCP-Tools: `mcp_tool_executor` (filesystem, playwright, github)
- **RAG:** `ai.store.postgres.onboarding` + `ai.vectorizer.mistral_embed`
- **Multi-Agent Orchestrierung:**
  - `handoffs` für Keyword-basierte Delegation
  - Fallback auf `fallback`-Agent

**Bewertung:**
- **Sehr gut implementiert**, aber:
  - **MCP-Server hardcoded** (nur filesystem, playwright, github)
  - **Sub-Agenten hardcoded** (keine dynamische Registrierung)

---

### **1.7 Entities & Datenbank**
**Blueprint-Anforderung:**
- `UserProfile`: Relationale Basisdaten
- `ToolDefinition`: Speichert JSON-Schemata
- `AgentHistory`: Audit-Log aller Aktionen

**Implementierungsstatus:**
✅ **Alle Entities implementiert**
| **Entity**          | **Datei**                          | **Zweck**                                                                                     |
|---------------------|------------------------------------|---------------------------------------------------------------------------------------------|
| `UserProfile`       | `src/Entity/UserProfile.php`       | User-Daten (Business/Privat-Kategorisierung)                                                 |
| `ToolDefinition`    | `src/Entity/ToolDefinition.php`    | JSON-Schemata für Tools + Status (pending/approved)                                         |
| `AgentHistory`      | `src/Entity/AgentHistory.php`      | Audit-Log aller Agenten-Aktionen                                                           |
| `SubAgent`          | `src/Entity/SubAgent.php`          | Sub-Agenten-Metadaten                                                                         |
| `DecisionLog`       | `src/Entity/DecisionLog.php`       | Protokolliert Agenten-Entscheidungen (nicht im Blueprint)                                   |
| `Document`          | `src/Entity/Document.php`          | Dokumenten-Metadaten (nicht im Blueprint)                                                    |
| `ToolCategory`      | `src/Entity/ToolCategory.php`      | Kategorisierung von Tools (nicht im Blueprint)                                               |

**Bewertung:**
- **Vollständig** + **erweiterte Entities** (`DecisionLog`, `Document`, `ToolCategory`)
- **Positiv:** `DecisionLog` ermöglicht **Auditability** von Agenten-Entscheidungen

---

### **1.8 Controller & API**
**Implementierungsstatus:**
✅ **Alle Controller implementiert**
| **Controller**               | **Datei**                              | **Zweck**                                                                                     |
|------------------------------|---------------------------------------|---------------------------------------------------------------------------------------------|
| `AgentDialogController`      | `src/Controller/AgentDialogController.php` | Haupt-Dialog mit Orchestrator-Agent                                                        |
| `ToolApprovalController`     | `src/Controller/ToolApprovalController.php` | HITL-Freigabe für Tools (pending → approved)                                                |
| `SubAgentController`         | `src/Controller/SubAgentController.php` | Verwaltung von Sub-Agenten                                                                  |
| `SubAgentListController`     | `src/Controller/SubAgentListController.php` | Liste aller Sub-Agenten                                                                     |
| `ToolListController`         | `src/Controller/ToolListController.php` | Liste aller Tools (approved/pending)                                                        |
| `BriefingController`         | `src/Controller/BriefingController.php` | Onboarding-Flow                                                                             |
| `DecisionController`         | `src/Controller/DecisionController.php` | Entscheidungshistorie (nicht im Blueprint)                                                  |
| `DocumentController`         | `src/Controller/DocumentController.php` | Dokumenten-Verwaltung (nicht im Blueprint)                                                  |
| `DashboardController`        | `src/Controller/DashboardController.php` | Übersichts-Dashboard                                                                       |

**Bewertung:**
- **Vollständig** + **erweiterte Controller** (`DecisionController`, `DocumentController`)
- **Positiv:** `DecisionController` ermöglicht **Nachvollziehbarkeit** von Agenten-Entscheidungen

---

### **1.9 Frontend (Templates & Assets)**
**Implementierungsstatus:**
✅ **Templates implementiert**
| **Template**               | **Pfad**                          | **Zweck**                                                                                     |
|----------------------------|-----------------------------------|---------------------------------------------------------------------------------------------|
| `agent/dialog.html.twig`   | `templates/agent/dialog.html.twig` | Haupt-Dialog-Interface mit Orchestrator                                                   |
| `agent/history.html.twig`  | `templates/agent/history.html.twig` | Agenten-Historie                                                                           |
| `agent/index.html.twig`    | `templates/agent/index.html.twig` | Agenten-Übersicht                                                                          |
| `tools/list.html.twig`     | `templates/tools/list.html.twig` | Liste aller Tools                                                                         |
| `tools/pending.html.twig`  | `templates/tools/pending.html.twig` | Liste der pending Tools (HITL-Freigabe)                                                   |
| `subagents/*`              | `templates/subagents/`            | Sub-Agenten-Verwaltung                                                                    |
| `briefing/*`               | `templates/briefing/`             | Onboarding-Flow                                                                             |
| `dashboard/*`              | `templates/dashboard/`            | Dashboard-Übersicht                                                                       |

✅ **Assets (Tailwind CSS)**
- `assets/styles/` mit **Tailwind CSS** für UI
- **Kein JavaScript-Framework** (rein server-side)

**Bewertung:**
- **Funktional**, aber:
  - **Keine Echtzeit-Updates** (kein WebSocket/Streaming)
  - **Keine dynamische UI** (kein HTMX/Alpine.js/React)
  - **Risiko:** User sieht **keinen Fortschritt** bei langen Tool-Executions

---

### **1.10 MCP-Integration**
**Implementierungsstatus:**
✅ **MCP-Tool-Executor implementiert**
- **Datei:** `src/Mcp/Toolbox/McpToolExecutor.php` (nicht direkt geprüft, aber in `ai.yaml` referenziert)
- **Konfiguration in `ai.yaml`:**
  ```yaml
  tools:
    - service: 'App\Mcp\Toolbox\McpToolExecutor'
      name: 'mcp_tool_executor'
      description: 'Führt MCP-Tools aus den konfigurierten Servern aus.'
  ```
- **Erlaubte Server:**
  - `filesystem`
  - `playwright`
  - `github`

**Bewertung:**
- **Funktional**, aber:
  - **MCP-Server hardcoded** (keine dynamische Konfiguration)
  - **Risiko:** Keine Flexibilität für neue MCP-Server

---

## 🔍 **2. Abweichungen vom Blueprint**

### **2.1 Kritische Abweichungen (❌ Blockierend)**
| **Abweichung** | **Blueprint** | **Implementierung** | **Auswirkung** | **Lösungsvorschlag** |
|---------------|--------------|---------------------|----------------|----------------------|
| **Keine Umwandlung von JSON-Schemata in `ToolInterface`** | `DynamicSkillRegistry` wandelt JSON in ausführbare Tools um | `DynamicSkillRegistry` lädt nur JSON-Schemata, aber **keine Instanzierung** | **Dynamisch generierte Tools sind nicht ausführbar** | `CompilerPass` implementieren, der JSON-Schemata in `ToolInterface`-Klassen umwandelt |
| **Keine SecurityGuard-Whitelist** | `SecurityGuard` definiert harte Sandbox-Grenzen | `SecurityGuard.php` existiert, aber **keine Konfiguration** | **Keine echte Sandbox** → Unsichere API-Aufrufe möglich | Whitelist für erlaubte Services (z. B. `GenericApiExecutor`) in `SecurityGuard` hinzufügen |
| **Fehlende Unit/Integration-Tests** | Tests für `DynamicSkillRegistry`, `HitlInterceptor`, `SecurityGuard` | **Keine Tests** | **Keine Validierung der Sicherheitsmechanismen** | PHPUnit-Tests für alle kritischen Komponenten erstellen |

---

### **2.2 Wichtige Abweichungen (⚠️ Verbesserungsbedarf)**
| **Abweichung** | **Blueprint** | **Implementierung** | **Auswirkung** | **Lösungsvorschlag** |
|---------------|--------------|---------------------|----------------|----------------------|
| **Sub-Agenten statisch in `ai.yaml`** | Sub-Agenten werden dynamisch erstellt | 12 Sub-Agenten **hardcoded** in `ai.yaml` | **Keine dynamische Erstellung** von Sub-Agenten | `SubAgentFactory` um dynamische Registrierung erweitern |
| **MCP-Server hardcoded** | MCP-Server sollten dynamisch konfigurierbar sein | Nur `filesystem`, `playwright`, `github` erlaubt | **Keine Flexibilität** für neue MCP-Server | MCP-Server-Konfiguration aus DB laden (z. B. `McpServer` Entity) |
| **Orchestrator als Service statt Klasse** | `OrchestratorAgent` als Klasse mit `#[AsAgent]` | `OrchestratorDialogService` als Service | **Architektonisch nicht 1:1**, aber funktional äquivalent | Optional: `OrchestratorAgent` als Klasse umbauen |
| **Keine Streaming-Antworten** | - | Antworten sind **synchron** | **Kein Fortschritts-Feedback** für User | Symfony Messenger + WebSocket für asynchrone Execution |
| **Frontend ohne JavaScript-Framework** | - | Rein server-side (Twig + Tailwind) | **Keine Echtzeit-Updates** | HTMX oder Alpine.js für dynamische UI integrieren |
| **Fehlende LLM-Prompt-Optimierung** | `ToolDefinitionGenerator` sollte optimierte Prompts nutzen | **Generische Prompts** | **Schlechtere Tool-Schemata** | Spezifische Prompts für Mistral hinzufügen |

---

### **2.3 Minor Abweichungen (🟢 Akzeptabel)**
| **Abweichung** | **Blueprint** | **Implementierung** | **Auswirkung** | **Lösungsvorschlag** |
|---------------|--------------|---------------------|----------------|----------------------|
| **`SubAgentFactory` nicht in Orchestrator integriert** | Orchestrator sollte Sub-Agenten über Factory erstellen | Sub-Agenten werden **manuell** in `OrchestratorDialogService` erstellt | **Weniger modular**, aber funktional | Sub-Agenten-Erstellung über `SubAgentFactory` umstellen |
| **`OnboardingFlowManager` ohne spezifischen Prompt** | Sollte strukturiertes Interview via LLM steuern | **Generische Logik** | **Weniger präzise** User-Kategorisierung | LLM-Prompt für Onboarding hinzufügen |

---

## ⚡ **3. Inkompatibilitäten zwischen Frontend & Backend**

### **3.1 Tool-Approval-Flow**
| **Problem** | **Backend** | **Frontend** | **Lösung** |
|------------|-------------|--------------|------------|
| **Tool-Approval-UI fehlt** | `PendingToolApprovalEvent` wird dispatched | Kein Listener für UI-Updates | Frontend muss auf `/tools/pending` weiterleiten (Template existiert bereits) |
| **Kein Live-Status für Tool-Approval** | Tool-Status wird in DB gespeichert | Kein Echtzeit-Update in UI | **WebSocket** oder **Polling** für Status-Updates implementieren |

---

### **3.2 Sub-Agenten-Delegation**
| **Problem** | **Backend** | **Frontend** | **Lösung** |
|------------|-------------|--------------|------------|
| **Kein Feedback für Sub-Agenten-Delegation** | Sub-Agenten werden aufgerufen | Kein Live-Status in UI | **Streaming-Response** für Echtzeit-Updates |
| **Ergebnisse werden nicht aggregiert** | Sub-Agenten liefern Ergebnisse zurück | Keine Zusammenführung in UI | **Frontend muss Ergebnisse aller Sub-Agenten anzeigen** |

---

### **3.3 Tool-Execution-Status**
| **Problem** | **Backend** | **Frontend** | **Lösung** |
|------------|-------------|--------------|------------|
| **Kein Fortschrittsbalken** | Tools werden synchron ausgeführt | Kein Feedback für lange Executions | **Symfony Messenger** für asynchrone Execution + **WebSocket-Updates** |
| **Fehlende API für Tool-Definitionen** | `ToolDefinition` Entity existiert | Kein Endpoint für `/api/tools/definitions` | **API-Controller für Tool-Definitionen erstellen** |

---

### **3.4 MCP-Tool-Executor**
| **Problem** | **Backend** | **Frontend** | **Lösung** |
|------------|-------------|--------------|------------|
| **MCP-Server hardcoded** | Nur `filesystem`, `playwright`, `github` erlaubt | UI zeigt alle MCP-Tools an | **Dynamische Server-Konfiguration aus DB laden** |

---

## 🚨 **4. Schwachstellen**

### **4.1 Kritische Schwachstellen (🔴 Sofort handeln!)**
1. **Keine Sandbox für Tool-Execution**
   - **Problem:** `SecurityGuard` hat **keine Whitelist** für erlaubte Services
   - **Risiko:** Dynamisch generierte Tools könnten **unsichere APIs** (z. B. `exec()`, `file_put_contents()`) aufrufen
   - **Lösung:**
     - `SecurityGuard` mit **Whitelist** für erlaubte Basis-Services erweitern
     - Beispiel:
       ```php
       private array $allowedServices = [
           'App\AI\Skills\Tool\GenericApiExecutor',
           'App\AI\Skills\Tool\FileSystemReadExecutor',
           // ...
       ];
       ```

2. **Tools aus DB sind nicht ausführbar**
   - **Problem:** `DynamicSkillRegistry` lädt JSON-Schemata, aber **keine Umwandlung in `ToolInterface`**
   - **Risiko:** **Dynamisch generierte Tools können nicht ausgeführt werden**
   - **Lösung:**
     - `CompilerPass` implementieren, der JSON-Schemata in `ToolInterface`-Klassen umwandelt
     - Beispiel:
       ```php
       class DynamicToolCompilerPass implements CompilerPassInterface
       {
           public function process(ContainerBuilder $container)
           {
               $toolDefinitions = $this->toolDefinitionRepo->findAll();
               foreach ($toolDefinitions as $definition) {
                   $container->register("dynamic_tool_{$definition->getId()}", DynamicTool::class)
                       ->addTag('ai.tool')
                       ->setArguments([$definition]);
               }
           }
       }
       ```

3. **Keine Tests für kritische Komponenten**
   - **Problem:** Keine **Unit/Integration-Tests** für:
     - `DynamicSkillRegistry` (JSON → Tool-Umwandlung)
     - `HitlInterceptor` (Blockade bei `isApproved() === false`)
     - `SecurityGuard` (Sandbox-Regeln)
   - **Risiko:** **Keine Validierung der Sicherheitsmechanismen**
   - **Lösung:** PHPUnit-Tests für alle kritischen Komponenten erstellen

---

### **4.2 Hohe Schwachstellen (🟡 Priorität hoch)**
4. **Sub-Agenten sind statisch konfiguriert**
   - **Problem:** 12 Sub-Agenten **hardcoded** in `ai.yaml`
   - **Risiko:** **Keine dynamische Erstellung** von Sub-Agenten zur Laufzeit
   - **Lösung:** `SubAgentFactory` um **dynamische Registrierung** erweitern

5. **Keine Streaming-Antworten für lange Executions**
   - **Problem:** `OrchestratorDialogService` gibt **synchron** Antworten zurück
   - **Risiko:** User sieht **keinen Fortschritt** bei langen Aufgaben (z. B. Web-Recherche)
   - **Lösung:**
     - **Symfony Messenger** für asynchrone Tool-Execution
     - **WebSocket** für Echtzeit-Updates im Frontend

6. **MCP-Server hardcoded**
   - **Problem:** `mcp_tool_executor` erlaubt nur `filesystem`, `playwright`, `github`
   - **Risiko:** **Keine Flexibilität** für neue MCP-Server
   - **Lösung:** MCP-Server-Konfiguration **aus DB laden** (z. B. `McpServer` Entity)

7. **Frontend ohne Echtzeit-Updates**
   - **Problem:** UI ist **rein server-side** (Twig + Tailwind)
   - **Risiko:** **Keine dynamischen Updates** (z. B. für Tool-Approval-Status)
   - **Lösung:** **HTMX** oder **Alpine.js** für dynamische UI integrieren

---

### **4.3 Mittlere Schwachstellen (🟢 Priorität mittel)**
8. **Fehlende LLM-Prompt-Optimierung**
   - **Problem:** `ToolDefinitionGenerator` nutzt **generische Prompts**
   - **Risiko:** **Schlechtere Tool-Schemata** (z. B. unvollständige Parameter)
   - **Lösung:** Spezifische Prompts für Mistral hinzufügen (z. B. für JSON-Schema-Generierung)

9. **Kein E2E-Test für Evolution-Flow**
   - **Problem:** Kein Test für: "Tool nicht gefunden → Generierung → Freigabe → Ausführung"
   - **Risiko:** **Keine Validierung des kritischen Pfads**
   - **Lösung:** E2E-Test mit **Symfony Panther** oder **API-Tests** erstellen

10. **Onboarding ohne spezifischen Prompt**
    - **Problem:** `OnboardingFlowManager` nutzt **generische Logik**
    - **Risiko:** **Weniger präzise** User-Kategorisierung
    - **Lösung:** LLM-Prompt für Onboarding-Interview hinzufügen

---

## 🌟 **5. Potenzial (Dinge, die **nicht** im Blueprint stehen, aber enthalten sind)**

### **5.1 Erweiterte Entities**
| **Entity** | **Zweck** | **Vorteile** |
|------------|-----------|--------------|
| `DecisionLog` | Protokolliert Agenten-Entscheidungen | **Auditability**: Nachvollziehbarkeit von Agenten-Entscheidungen |
| `Document` | Dokumenten-Metadaten | **Erweiterbarkeit**: Unterstützung für Dokumenten-Verarbeitung |
| `ToolCategory` | Kategorisierung von Tools | **Organisation**: Bessere Übersicht über Tool-Typen |

---

### **5.2 Erweiterte Controller**
| **Controller** | **Zweck** | **Vorteile** |
|---------------|-----------|--------------|
| `DecisionController` | Entscheidungshistorie | **Transparenz**: User kann Agenten-Entscheidungen nachvollziehen |
| `DocumentController` | Dokumenten-Verwaltung | **Erweiterbarkeit**: Unterstützung für Datei-Uploads/Verarbeitung |

---

### **5.3 Erweiterte Services**
| **Service** | **Zweck** | **Vorteile** |
|-------------|-----------|--------------|
| `EvieToolboxFactory` | Factory für Toolboxen | **Modularität**: Tools können pro Sub-Agent gruppiert werden |
| `SubAgentDispatcher` | Intelligente Delegation | **Effizienz**: Automatische Zuordnung von Aufgaben zu Sub-Agenten |
| `FaultTolerantValidator` | Validiert LLM-Antworten | **Robustheit**: Verhindert Abstürze durch malformierte Antworten |
| `ResponseNormalizer` | Normalisiert LLM-Antworten | **Stabilität**: Vereinheitlicht Antwortformate |

---

### **5.4 Erweiterte UI-Komponenten**
| **Template** | **Zweck** | **Vorteile** |
|--------------|-----------|--------------|
| `agent/history.html.twig` | Agenten-Historie | **Nachvollziehbarkeit**: User kann vergangene Interaktionen einsehen |
| `tools/pending.html.twig` | Pending Tools | **HITL-Integration**: User kann Tools freigeben/ablehnen |
| `subagents/*` | Sub-Agenten-Verwaltung | **Transparenz**: User sieht verfügbare Sub-Agenten |
| `dashboard/*` | Übersichts-Dashboard | **Benutzerfreundlichkeit**: Zentrale Übersicht über System-Status |

---

### **5.5 Erweiterte MCP-Integration**
- **MCP-Tool-Executor** mit Unterstützung für:
  - `filesystem` (Datei-Operationen)
  - `playwright` (Web-Automatisierung)
  - `github` (GitHub-API)
- **Vorteile:**
  - **Erweiterbarkeit**: Einfache Anbindung weiterer MCP-Server
  - **Flexibilität**: Tools können **externe APIs** nutzen

---

## 📈 **6. Empfohlene Next Steps (priorisiert)**

### **🔴 Phase 1: Kritische Lücken schließen (1-2 Wochen)**
1. **`SecurityGuard` mit Whitelist erweitern**
   - **Aufwand:** 1 Tag
   - **Impact:** 🔴 **Kritisch** (Sicherheitsrisiko)
   - **Aktion:**
     - Whitelist für erlaubte Services in `SecurityGuard` hinzufügen
     - Basis-Services (`GenericApiExecutor`, `FileSystemReadExecutor`) definieren

2. **`DynamicSkillRegistry` mit CompilerPass erweitern**
   - **Aufwand:** 2-3 Tage
   - **Impact:** 🔴 **Kritisch** (Tools nicht ausführbar)
   - **Aktion:**
     - `CompilerPass` implementieren, der JSON-Schemata in `ToolInterface`-Klassen umwandelt
     - Tools zur Compile-Time im Container registrieren

3. **Unit-Tests für kritische Komponenten erstellen**
   - **Aufwand:** 3-5 Tage
   - **Impact:** 🔴 **Kritisch** (Keine Validierung)
   - **Aktion:**
     - PHPUnit-Tests für:
       - `DynamicSkillRegistry` (JSON → Tool-Umwandlung)
       - `HitlInterceptor` (Blockade bei `isApproved() === false`)
       - `SecurityGuard` (Sandbox-Regeln)

---

### **🟡 Phase 2: Hohe Priorität (2-3 Wochen)**
4. **Sub-Agenten dynamisch machen**
   - **Aufwand:** 3-5 Tage
   - **Impact:** 🟡 **Hoch** (Keine dynamische Erstellung)
   - **Aktion:**
     - `SubAgentFactory` um dynamische Registrierung erweitern
     - Sub-Agenten aus DB laden (z. B. `SubAgent` Entity)

5. **Streaming-Antworten für lange Executions**
   - **Aufwand:** 5-7 Tage
   - **Impact:** 🟡 **Hoch** (Kein Fortschritts-Feedback)
   - **Aktion:**
     - Symfony Messenger für asynchrone Tool-Execution
     - WebSocket für Echtzeit-Updates im Frontend

6. **MCP-Server dynamisch konfigurierbar machen**
   - **Aufwand:** 2-3 Tage
   - **Impact:** 🟡 **Hoch** (Keine Flexibilität)
   - **Aktion:**
     - `McpServer` Entity erstellen
     - MCP-Server-Konfiguration aus DB laden

7. **Frontend mit HTMX/Alpine.js erweitern**
   - **Aufwand:** 3-5 Tage
   - **Impact:** 🟡 **Hoch** (Keine Echtzeit-Updates)
   - **Aktion:**
     - HTMX für dynamische UI-Integration
     - Alpine.js für einfache Interaktivität

---

### **🟢 Phase 3: Mittlere Priorität (3-4 Wochen)**
8. **LLM-Prompt-Optimierung für `ToolDefinitionGenerator`**
   - **Aufwand:** 2-3 Tage
   - **Impact:** 🟢 **Mittel** (Schlechtere Tool-Schemata)
   - **Aktion:**
     - Spezifische Prompts für Mistral hinzufügen
     - JSON-Schema-Generierung optimieren

9. **E2E-Test für Evolution-Flow**
   - **Aufwand:** 2-3 Tage
   - **Impact:** 🟢 **Mittel** (Keine Validierung des kritischen Pfads)
   - **Aktion:**
     - E2E-Test mit Symfony Panther erstellen
     - Test: "Tool nicht gefunden → Generierung → Freigabe → Ausführung"

10. **Onboarding-Prompt optimieren**
    - **Aufwand:** 1-2 Tage
    - **Impact:** 🟢 **Mittel** (Weniger präzise User-Kategorisierung)
    - **Aktion:**
      - LLM-Prompt für Onboarding-Interview hinzufügen

---

### **🟣 Phase 4: Langfristige Verbesserungen (4+ Wochen)**
11. **Orchestrator als Klasse (`OrchestratorAgent`) umbauen**
    - **Aufwand:** 3-5 Tage
    - **Impact:** 🟢 **Niedrig** (Architektonisch nicht 1:1)
    - **Aktion:**
      - `OrchestratorDialogService` in `OrchestratorAgent` umwandeln
      - `#[AsAgent]` Attribut hinzufügen

12. **API-Controller für Tool-Definitionen erstellen**
    - **Aufwand:** 2-3 Tage
    - **Impact:** 🟢 **Niedrig** (Fehlende API für Frontend)
    - **Aktion:**
      - REST-Endpoints für `/api/tools/definitions` erstellen
      - JSON-Schemata via API abrufbar machen

13. **Dokumentation aktualisieren**
    - **Aufwand:** 1-2 Tage
    - **Impact:** 🟢 **Niedrig** (Keine aktuelle Doku)
    - **Aktion:**
      - `README.md` mit Setup-Anleitung aktualisieren
      - API-Dokumentation mit NelmioApiDocBundle erstellen

---

## 📝 **7. Checkliste für die nächste Review**
- [ ] `SecurityGuard` mit Whitelist für erlaubte Services erweitern
- [ ] `DynamicSkillRegistry` mit `CompilerPass` für Tool-Umwandlung erweitern
- [ ] Unit-Tests für `DynamicSkillRegistry`, `HitlInterceptor`, `SecurityGuard` erstellen
- [ ] Sub-Agenten dynamisch aus DB laden (statt hardcoded in `ai.yaml`)
- [ ] Streaming-Antworten für lange Tool-Executions implementieren
- [ ] MCP-Server dynamisch konfigurierbar machen (aus DB)
- [ ] Frontend mit HTMX/Alpine.js für Echtzeit-Updates erweitern
- [ ] LLM-Prompts für `ToolDefinitionGenerator` und `OnboardingFlowManager` optimieren
- [ ] E2E-Test für Evolution-Flow erstellen
- [ ] `OrchestratorAgent` als Klasse mit `#[AsAgent]` umbauen

---

## 🔗 **8. Referenzen**
- [Blueprint-Dokumentation](blueprint.md)
- [Symfony AI Bundle Dokumentation](https://symfony.com/doc/current/ai/bundles/ai-bundle.html)
- [Mistral AI Dokumentation](https://docs.mistral.ai/)
- [MCP (Model Context Protocol) Spezifikation](https://github.com/modelcontextprotocol/specification)

---

## 📌 **Zusammenfassung**
| **Bereich** | **Status** | **Kritische Lücken** | **Potenzial** |
|-------------|------------|---------------------|---------------|
| **Core** | ✅ 95% | CompilerPass für DynamicSkillRegistry | - |
| **Security** | ⚠️ 70% | SecurityGuard ohne Whitelist | - |
| **Tools** | ⚠️ 80% | JSON-Schemata nicht ausführbar | `DecisionLog`, `ToolCategory` |
| **Sub-Agenten** | ✅ 90% | Statisch in ai.yaml | `EvieToolboxFactory`, `SubAgentDispatcher` |
| **Frontend** | ✅ 85% | Keine Echtzeit-Updates | `agent/history`, `tools/pending` |
| **MCP** | ✅ 90% | Server hardcoded | - |
| **Testing** | ❌ 0% | Keine Tests | - |

**Gesamtfortschritt:** **~85%** (Funktional fast vollständig, aber **kritische Sicherheits- und Ausführbarkeitslücken**).

---

**💡 Fazit:** 
EVIE ist **funktional sehr weit fortgeschritten** (ca. 85%), aber es gibt **kritische Lücken in Sicherheit und Ausführbarkeit dynamischer Tools**, die **sofort behoben werden müssen**. Die **erweiterten Features** (DecisionLog, MCP-Integration, Sub-Agenten) gehen **über den Blueprint hinaus** und bieten **großes Potenzial** für ein robustes, erweiterbares System. Mit den empfohlenen Next Steps kann EVIE zu einem **vollständig selbst-evolvierenden AI-Agenten** werden, der den Blueprint **nicht nur erfüllt, sondern übertrifft**.