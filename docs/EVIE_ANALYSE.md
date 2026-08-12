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

**Abweichung:**
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

---

### **1.3 Security & HITL-Layer**
**Blueprint-Anforderung:**
- `HitlInterceptor` (Decorator-Pattern) für asynchrone Tool-Freigabe
- `SecurityGuard` als harte Sandbox-Grenze
- `PendingToolApprovalEvent` für Benachrichtigungen

**Implementierungsstatus:**
✅ **HitlInterceptor implementiert**
- **Datei:** `src/AI/Security/HitlInterceptor.php` (1,8 KB)

✅ **HitlToolCallListener implementiert**
- **Datei:** `src/AI/Security/HitlToolCallListener.php`

✅ **SecurityGuard implementiert**
- **Datei:** `src/AI/Security/SecurityGuard.php` (2,8 KB)
- **ABER:** **Keine Konfiguration**, welche Basis-Services erlaubt sind

**Bewertung:**
- **Kritische Lücke:** `SecurityGuard` **ohne Konfiguration** → **Keine echte Sandbox**
- **Risiko:** Dynamisch generierte Tools könnten **unsichere APIs** aufrufen

---

### **1.4 Onboarding & Kontext (RAG)**
**Blueprint-Anforderung:**
- `OnboardingFlowManager`: Strukturiertes Interview via LLM
- `ContextStoreManager`: Speichert User-Kontext als Vektor-Embeddings

**Implementierungsstatus:**
✅ **OnboardingFlowManager implementiert**
- **Datei:** `src/AI/Onboarding/OnboardingFlowManager.php` (7 KB)

✅ **ContextStoreManager implementiert**
- **Datei:** `src/AI/Onboarding/ContextStoreManager.php` (3,2 KB)

✅ **ContextMemoryProvider implementiert**
- **Datei:** `src/AI/Onboarding/ContextMemoryProvider.php`

**Bewertung:**
- **Funktional vollständig**, aber:
  - **Fehlende LLM-Prompt-Optimierung** für Onboarding-Interview

---

### **1.5 Multi-Agent Orchestrierung & AI-Konfiguration**
✅ **SubAgentFactory implementiert** (13 KB) mit 12 Sub-Agenten-Typen
✅ **SubAgentDispatcher implementiert** (20 KB) für intelligente Delegation
✅ **ai.yaml** (16 KB) mit detaillierter Konfiguration:
- Plattformen: Mistral, Gemini
- 12 Sub-Agenten + Orchestrator + Fallback
- Statische Tools: WeatherTool, FileReadTool, DataAnalyzerTool, etc.
- Dynamische Tools: DynamicToolDispatcher
- MCP-Tools: mcp_tool_executor (filesystem, playwright, github)
- RAG: ai.store.postgres.onboarding + ai.vectorizer.mistral_embed

**Bewertung:**
- **Sehr gut implementiert**, aber MCP-Server und Sub-Agenten sind **hardcoded**

---

### **1.6 Entities & Datenbank**
✅ **Alle Blueprint-Entities implementiert:**
- `UserProfile`, `ToolDefinition`, `AgentHistory`, `SubAgent`

✅ **Erweiterte Entities (nicht im Blueprint):**
- `DecisionLog` (Protokolliert Agenten-Entscheidungen)
- `Document` (Dokumenten-Metadaten)
- `ToolCategory` (Kategorisierung von Tools)

---

### **1.7 Controller & API**
✅ **Alle Controller implementiert:**
- `AgentDialogController`, `ToolApprovalController`, `SubAgentController`
- `SubAgentListController`, `ToolListController`, `BriefingController`
- `DecisionController`, `DocumentController`, `DashboardController`

---

### **1.8 Frontend (Templates & Assets)**
✅ **Templates:** agent/, tools/, subagents/, briefing/, dashboard/
✅ **Assets:** Tailwind CSS für UI
⚠️ **Kein JavaScript-Framework** (rein server-side)

---

### **1.9 MCP-Integration**
✅ **MCP-Tool-Executor** für filesystem, playwright, github

---

## 🔍 **2. Abweichungen vom Blueprint**

### **2.1 Kritische Abweichungen (❌ Blockierend)**
| **Abweichung** | **Blueprint** | **Implementierung** | **Auswirkung** | **Lösung** |
|---------------|--------------|---------------------|----------------|------------|
| **Keine Umwandlung von JSON-Schemata in `ToolInterface`** | DynamicSkillRegistry wandelt JSON in ausführbare Tools um | Lädt nur JSON-Schemata, aber **keine Instanzierung** | **Dynamisch generierte Tools sind nicht ausführbar** | CompilerPass implementieren |
| **Keine SecurityGuard-Whitelist** | SecurityGuard definiert harte Sandbox-Grenzen | SecurityGuard.php existiert, aber **keine Konfiguration** | **Keine echte Sandbox** → Unsichere API-Aufrufe möglich | Whitelist für erlaubte Services hinzufügen |
| **Fehlende Unit/Integration-Tests** | Tests für kritische Komponenten | **Keine Tests** | **Keine Validierung der Sicherheitsmechanismen** | PHPUnit-Tests erstellen |

---

### **2.2 Wichtige Abweichungen (⚠️ Verbesserungsbedarf)**
| **Abweichung** | **Blueprint** | **Implementierung** | **Auswirkung** | **Lösung** |
|---------------|--------------|---------------------|----------------|------------|
| **Sub-Agenten statisch in `ai.yaml`** | Sub-Agenten dynamisch erstellen | 12 Sub-Agenten **hardcoded** | **Keine dynamische Erstellung** | SubAgentFactory um dynamische Registrierung erweitern |
| **MCP-Server hardcoded** | MCP-Server dynamisch konfigurierbar | Nur filesystem, playwright, github | **Keine Flexibilität** | MCP-Server aus DB laden |
| **Orchestrator als Service statt Klasse** | OrchestratorAgent als Klasse | OrchestratorDialogService als Service | **Architektonisch nicht 1:1** | Optional: OrchestratorAgent als Klasse umbauen |
| **Keine Streaming-Antworten** | - | Antworten sind synchron | **Kein Fortschritts-Feedback** | Symfony Messenger + WebSocket |
| **Frontend ohne JavaScript-Framework** | - | Rein server-side | **Keine Echtzeit-Updates** | HTMX oder Alpine.js integrieren |

---

## ⚡ **3. Inkompatibilitäten zwischen Frontend & Backend**

### **3.1 Tool-Approval-Flow**
- **Backend:** `PendingToolApprovalEvent` wird dispatched
- **Frontend:** Kein Listener für UI-Updates
- **Lösung:** Frontend muss auf `/tools/pending` weiterleiten (Template existiert)

### **3.2 Sub-Agenten-Delegation**
- **Backend:** Sub-Agenten werden aufgerufen
- **Frontend:** Kein Live-Status in UI
- **Lösung:** Streaming-Response für Echtzeit-Updates

### **3.3 Tool-Execution-Status**
- **Backend:** Tools werden synchron ausgeführt
- **Frontend:** Kein Fortschrittsbalken
- **Lösung:** Symfony Messenger für asynchrone Execution + WebSocket-Updates

### **3.4 MCP-Tool-Executor**
- **Backend:** Nur filesystem, playwright, github erlaubt
- **Frontend:** UI zeigt alle MCP-Tools an
- **Lösung:** Dynamische Server-Konfiguration aus DB laden

---

## 🚨 **4. Schwachstellen**

### **4.1 Kritische Schwachstellen (🔴 Sofort handeln!)**
1. **Keine Sandbox für Tool-Execution**
   - SecurityGuard hat **keine Whitelist** für erlaubte Services
   - **Lösung:** Whitelist für Basis-Services in SecurityGuard hinzufügen

2. **Tools aus DB sind nicht ausführbar**
   - DynamicSkillRegistry lädt JSON-Schemata, aber **keine Umwandlung in ToolInterface**
   - **Lösung:** CompilerPass implementieren

3. **Keine Tests für kritische Komponenten**
   - **Lösung:** PHPUnit-Tests für DynamicSkillRegistry, HitlInterceptor, SecurityGuard

---

### **4.2 Hohe Schwachstellen (🟡 Priorität hoch)**
4. **Sub-Agenten statisch konfiguriert** → Keine dynamische Erstellung
5. **Keine Streaming-Antworten** → Kein Fortschritts-Feedback
6. **MCP-Server hardcoded** → Keine Flexibilität
7. **Frontend ohne Echtzeit-Updates** → Keine dynamische UI

---

### **4.3 Mittlere Schwachstellen (🟢 Priorität mittel)**
8. **Fehlende LLM-Prompt-Optimierung** → Schlechtere Tool-Schemata
9. **Kein E2E-Test für Evolution-Flow** → Keine Validierung
10. **Onboarding ohne spezifischen Prompt** → Weniger präzise User-Kategorisierung

---

## 🌟 **5. Potenzial (Dinge, die NICHT im Blueprint stehen)**

### **5.1 Erweiterte Entities**
- `DecisionLog`: Protokolliert Agenten-Entscheidungen (**Auditability**)
- `Document`: Dokumenten-Metadaten (**Erweiterbarkeit**)
- `ToolCategory`: Kategorisierung von Tools (**Organisation**)

### **5.2 Erweiterte Services**
- `EvieToolboxFactory`: Factory für Toolboxen (**Modularität**)
- `SubAgentDispatcher`: Intelligente Delegation (**Effizienz**)
- `FaultTolerantValidator`: Validiert LLM-Antworten (**Robustheit**)
- `ResponseNormalizer`: Normalisiert LLM-Antworten (**Stabilität**)

### **5.3 Erweiterte UI-Komponenten**
- `agent/history.html.twig`: Agenten-Historie (**Nachvollziehbarkeit**)
- `tools/pending.html.twig`: Pending Tools (**HITL-Integration**)
- `subagents/*`: Sub-Agenten-Verwaltung (**Transparenz**)
- `dashboard/*`: Übersichts-Dashboard (**Benutzerfreundlichkeit**)

---

## 📈 **6. Empfohlene Next Steps (priorisiert)**

### **🔴 Phase 1: Kritische Lücken schließen (1-2 Wochen)**
1. **SecurityGuard mit Whitelist erweitern** (1 Tag, 🔴 Kritisch)
2. **DynamicSkillRegistry mit CompilerPass erweitern** (2-3 Tage, 🔴 Kritisch)
3. **Unit-Tests für kritische Komponenten erstellen** (3-5 Tage, 🔴 Kritisch)

### **🟡 Phase 2: Hohe Priorität (2-3 Wochen)**
4. **Sub-Agenten dynamisch machen** (3-5 Tage, 🟡 Hoch)
5. **Streaming-Antworten implementieren** (5-7 Tage, 🟡 Hoch)
6. **MCP-Server dynamisch konfigurierbar machen** (2-3 Tage, 🟡 Hoch)
7. **Frontend mit HTMX/Alpine.js erweitern** (3-5 Tage, 🟡 Hoch)

### **🟢 Phase 3: Mittlere Priorität (3-4 Wochen)**
8. **LLM-Prompt-Optimierung** (2-3 Tage, 🟢 Mittel)
9. **E2E-Test für Evolution-Flow** (2-3 Tage, 🟢 Mittel)
10. **Onboarding-Prompt optimieren** (1-2 Tage, 🟢 Mittel)

### **🟣 Phase 4: Langfristig (4+ Wochen)**
11. **Orchestrator als Klasse umbauen** (3-5 Tage, 🟢 Niedrig)
12. **API-Controller für Tool-Definitionen** (2-3 Tage, 🟢 Niedrig)
13. **Dokumentation aktualisieren** (1-2 Tage, 🟢 Niedrig)

---

## 📝 **7. Checkliste für die nächste Review**
- [ ] SecurityGuard mit Whitelist für erlaubte Services erweitern
- [ ] DynamicSkillRegistry mit CompilerPass für Tool-Umwandlung erweitern
- [ ] Unit-Tests für DynamicSkillRegistry, HitlInterceptor, SecurityGuard erstellen
- [ ] Sub-Agenten dynamisch aus DB laden
- [ ] Streaming-Antworten für lange Tool-Executions implementieren
- [ ] MCP-Server dynamisch konfigurierbar machen
- [ ] Frontend mit HTMX/Alpine.js für Echtzeit-Updates erweitern
- [ ] LLM-Prompts optimieren
- [ ] E2E-Test für Evolution-Flow erstellen
- [ ] OrchestratorAgent als Klasse mit #[AsAgent] umbauen

---

## 📌 **Zusammenfassung**

| **Bereich** | **Status** | **Kritische Lücken** | **Potenzial** |
|-------------|------------|---------------------|---------------|
| **Core** | ✅ 95% | CompilerPass für DynamicSkillRegistry | - |
| **Security** | ⚠️ 70% | SecurityGuard ohne Whitelist | - |
| **Tools** | ⚠️ 80% | JSON-Schemata nicht ausführbar | DecisionLog, ToolCategory |
| **Sub-Agenten** | ✅ 90% | Statisch in ai.yaml | EvieToolboxFactory, SubAgentDispatcher |
| **Frontend** | ✅ 85% | Keine Echtzeit-Updates | agent/history, tools/pending |
| **MCP** | ✅ 90% | Server hardcoded | - |
| **Testing** | ❌ 0% | Keine Tests | - |

**Gesamtfortschritt:** **~85%** (Funktional fast vollständig, aber **kritische Sicherheits- und Ausführbarkeitslücken**).

---

**💡 Fazit:** 
EVIE ist **funktional sehr weit fortgeschritten** (ca. 85%), aber es gibt **kritische Lücken in Sicherheit und Ausführbarkeit dynamischer Tools**, die **sofort behoben werden müssen**. Die **erweiterten Features** (DecisionLog, MCP-Integration, Sub-Agenten) gehen **über den Blueprint hinaus** und bieten **großes Potenzial** für ein robustes, erweiterbares System. Mit den empfohlenen Next Steps kann EVIE zu einem **vollständig selbst-evolvierenden AI-Agenten** werden, der den Blueprint **nicht nur erfüllt, sondern übertrifft**.