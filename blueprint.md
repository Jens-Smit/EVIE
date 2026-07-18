# **Architektur-Blueprint: Selbst-evolvierender AI-Agent (Symfony AI)**

Dieses Dokument beschreibt die Architektur und den Entwicklungsplan für einen **selbst-evolvierenden KI-Agenten**, basierend auf dem **Symfony AI Bundle**, angetrieben von **Mistral LLM** und abgesichert durch **Human-in-the-Loop (HITL)**.

---

## **1. Systemarchitektur & Philosophie**

Das System basiert auf einer **strikten Trennung** von:
- **Logik** (Symfony)
- **Intelligenz** (Mistral)
- **Fähigkeiten** (Tools)

**Kernphilosophie:**
- Der Agent **schreibt niemals eigenen PHP-Code**. 
- "Evolution" bedeutet die **dynamische Komposition und Registrierung** von neuen **Tool-Definitionen** (JSON-Schemata), die vordefinierte, sichere Basis-Dienste orchestrieren.

---

## **2. Verzeichnisstruktur (Domain-Driven)**

```txt
src/
├── AI/
│   ├── Agent/
│   │   ├── OrchestratorAgent.php          # Haupt-Controller
│   │   ├── SubAgentFactory.php            # Instanzierung von Spezialisten
│   │   └── Profiles/                      # Business/Private
│   ├── Skills/
│   │   ├── DynamicSkillRegistry.php       # Lädt Tools aus dem Store
│   │   ├── ToolDefinitionGenerator.php    # Generiert Tool-Schemata via LLM
│   │   └── Executor/                       # Sichere Ausführungsumgebung für Tools
│   ├── Security/
│   │   ├── HitlInterceptor.php            # Decorator für Tool-Aufrufe
│   │   ├── SecurityGuard.php              # Statische Sandbox-Regeln
│   │   └── Events/                        # PendingToolApprovalEvent etc.
│   └── Onboarding/
│       ├── ContextStoreManager.php        # Speichert/Lädt User-Kontext
│       └── OnboardingFlowManager.php      # Steuert das Initial-Setup
├── Infrastructure/
│   ├── Mistral/                           # API-Client & PlatformInterface Impl.
│   └── VectorStore/                       # AI Store Integration (z.B. pgvector)
└── Entity/
    ├── UserProfile.php                    # Relationale Basisdaten
    ├── ToolDefinition.php                 # Speichert die JSON-Schemata
    └── AgentHistory.php                    # Audit-Log aller Aktionen
```

---

## **3. Detailliertes Komponentendesign**

### **A. Orchestrator-Layer (`AgentInterface`)**
Der **OrchestratorAgent** ist das **Gehirn** des Systems. Er führt **keine konkreten Aufgaben** aus, sondern **plant und delegiert**. 

- **Funktionsweise:**
  1. Erhält den **User-Prompt** und den **Kontext** aus dem `ContextStoreManager`.
  2. **Mistral analysiert** die Intention und nutzt **"Tool Calling"**. 
  3. Der Orchestrator hat Zugriff auf die `SubAgentFactory` als Tool und ruft diese auf, um **spezialisierte Agenten** (z.B. `ResearchAgent`) zu starten.

- **Symfony AI Bundle Fokus:**
  Nutzt das Attribut `#[AsAgent]` oder die Konfiguration in `config/packages/ai.yaml`.

---

### **B. Skill-Evolution-Layer (Der "Erfinder")**
Dieser Layer steuert die **Weiterentwicklung der Fähigkeiten**. 

- **`ToolDefinitionGenerator`:**
  - Wird aktiv, wenn der Orchestrator meldet: **"Kein passendes Tool gefunden."**
  - Nutzt **Mistral**, um ein **JSON-Schema** für ein neues Tool zu entwerfen (Parameter, Typen, Description).
  - **Wichtig:** Das Tool **"bindet" keine externe API direkt an**, sondern konfiguriert z.B. einen existierenden `GenericApiExecutor`-Service mit neuen Parametern.

- **`DynamicSkillRegistry`:**
  - Implementiert einen **CompilerPass** oder eine **Factory**, die beim **Container-Compile** (oder Startup) alle `ToolDefinition`-Entities aus der Datenbank lädt.
  - Wandelt die **JSON-Schemata** in **instanziierbare Klassen** um, die das `ToolInterface` von Symfony AI implementieren.

---

### **C. Security & HITL-Layer (Das Sicherheitsnetz)**
Verhindert **unkontrollierte Aktionen**. 

- **`HitlInterceptor` (Tool Decorator):**
  - Implementiert das **Decorator-Pattern** um jedes auszuführende Tool.
  - Prüfung:
    ```php
    if (!$toolDefinition->isApproved()) { 
        dispatch(new PendingApprovalEvent()); 
        return HaltExecution(); 
    }
    ```
  - Wartet auf die **asynchrone Freigabe** durch den User via Frontend.

- **`SecurityGuard`:**
  - Eine **harte Grenze** (Sandbox).
  - Definiert, welche **Basis-Services** (z.B. `GenericApiExecutor`, `FileSystemReadExecutor`) ein dynamisch generiertes Tool **überhaupt ansprechen darf**. 

---

### **D. Onboarding & Kontext (Retrieval-Augmented Generation)**

- **`OnboardingFlowManager`:**
  - Führt den User durch ein **strukturiertes Interview** (Chat-basiert, gesteuert durch ein spezielles **Onboarding-LLM-Prompt**).
  - Kategorisiert den User: **Business** (CRM, Termine) oder **Privat** (Recherche, Notizen).

- **`ContextStoreManager`:**
  - Speichert die **Onboarding-Ergebnisse** als **Vektor-Embeddings** im AI Store.
  - Fungiert als **Retriever**: Bei jedem User-Prompt holt dieser Service die relevanten Profil-Informationen und fügt sie als **"System Prompt"** (System Message) in den Context des `OrchestratorAgent` ein.

---

## **4. Workflow: Erschaffung eines neuen Tools**

Dies ist der **kritische Pfad** für die **Selbst-Evolution**:

1. **Bedarfserkennung:** User sagt: *"Analysiere diese Excel-Datei und gib mir den Umsatz."*
2. **Fehlschlag:** Orchestrator stellt fest: **Kein Tool für Excel-Parsing vorhanden.**
3. **Ideengenerierung:** Orchestrator ruft `ToolDefinitionGenerator` auf.
4. **Schema-Entwurf:** Mistral generiert ein Schema für ein **"ExcelParserTool"**, das einen existierenden `GenericFileProcessor` nutzen soll.
5. **HITL-Blockade:** Der Entwurf wird als `ToolDefinition` (**Status: pending**) in die Datenbank gespeichert.
6. **User-Interaktion:** User erhält Push/Meldung: *"Ich benötige ein neues Tool 'ExcelParserTool', um diese Aufgabe zu lösen. Es wird die Datei X lesen. Genehmigen?"*
7. **Freigabe:** User klickt **"Ja"**. Status wird auf **approved** gesetzt.
8. **Dynamische Registrierung:** `DynamicSkillRegistry` lädt das neue Tool.
9. **Ausführung:** Der Orchestrator wiederholt den initialen Prompt, findet nun das **"ExcelParserTool"** und löst die Aufgabe.

---

## **5. Entwicklungsplan (Phasen)**

### **📌 Phase 1: Core Foundation (Woche 1-3)**
- **Ziel:** Basis-Agent läuft und kommuniziert mit Mistral.
- **Tasks:**
  - Symfony Projekt Setup & AI Bundle Installation.
  - Konfiguration der Platform (Mistral API Keys etc.).
  - Implementierung des `OrchestratorAgent`.
  - Erstellung von **2-3 statischen "Dummy"-Tools** zur Validierung des Tool-Callings.

---

### **📌 Phase 2: Onboarding & RAG (Woche 4-5)**
- **Ziel:** Agent kennt den User und speichert Kontext.
- **Tasks:**
  - Implementierung des `OnboardingFlowManager`.
  - Setup des **AI Store** (z.B. pgvector via Doctrine).
  - Einrichtung des **Retrievers**, um den User-Kontext bei jedem Chat-Request als **System-Prompt** mitzugeben.

---

### **📌 Phase 3: Dynamic Skill System (Woche 6-8)**
- **Ziel:** Agent kann theoretisch neue Tools als Schema entwerfen.
- **Tasks:**
  - Datenbankschema für `ToolDefinition` erstellen.
  - `ToolDefinitionGenerator` implementieren (**Prompt Engineering** für Mistral, um gültige JSON-Schemata zu generieren).
  - `DynamicSkillRegistry` bauen (**Wandlung von JSON in Symfony Tools**).

---

### **📌 Phase 4: HITL & Security (Woche 9-10)**
- **Ziel:** Absicherung der Tool-Erstellung und Ausführung.
- **Tasks:**
  - Implementierung des `HitlInterceptor` (**Decorator Pattern**).
  - Bau einer **simplen UI/API-Route** zur Genehmigung von Pending-Tools.
  - **Integrationstests** für den Blockade-Mechanismus.

---

### **📌 Phase 5: Orchestrierung & Sub-Agenten (Woche 11-12)**
- **Ziel:** Komplexe Aufgaben werden zerlegt.
- **Tasks:**
  - `SubAgentFactory` implementieren.
  - Dem Orchestrator beibringen, **Sub-Agenten als "Tools"** zu betrachten und aufzurufen.
  - **Zusammenführung der Ergebnisse** (Context Aggregation).

---

## **6. Testplan & Qualitätssicherung**

### **🧪 6.1 Unit Tests (PHPUnit)**
- **`DynamicSkillRegistryTest`:** Validiere, dass ein korrektes JSON-Schema erfolgreich in ein `ToolInterface` umgewandelt wird. Teste **Fehlerbehandlung** bei ungültigem JSON.
- **`HitlInterceptorTest`:** Mocke ein Tool. Validiere, dass `execute()` eine **Exception/ein Event** wirft, wenn `isApproved() === false`.
- **`SecurityGuardTest`:** Prüfe, dass die vordefinierten Basis-Services (z.B. API Caller) bestimmte URLs (z.B. `localhost`) **blockieren**. 

---

### **🧪 6.2 Integration Tests**
- **Mistral Tool Calling:** Sende einen spezifischen Prompt und prüfe, ob das **Symfony AI Bundle** korrekt die Absicht erkennt, ein bestimmtes Tool aufzurufen.
- **Context Retrieval:** Speichere *"User ist im Business Modus"* im Vektor Store. Stelle eine Anfrage und prüfe, ob der zurückgegebenene Prompt diese Information enthält.

---

### **🧪 6.3 System / E2E Tests (Acceptance)**
- **Evolution Flow Test:**
  1. Sende Anforderung für **nicht existierendes Tool**. 
  2. Prüfe Datenbank auf neuen **pending Eintrag** in `ToolDefinition`.
  3. Simuliere **User-Approval** (API Call).
  4. Sende Anforderung erneut.
  5. Prüfe, ob Aufgabe nun **erfolgreich abgeschlossen** wird.

---

## **📌 Zusammenfassung**
- **Architektur:** Domain-Driven, modular, sicher.
- **Evolution:** Dynamische Tool-Generierung via LLM, **HITL-gesteuert**. 
- **Sicherheit:** Sandbox, Decorator-Pattern, asynchrone Freigabe.
- **Kontext:** RAG-basiert, User-spezifisch.
- **Testabdeckung:** Unit, Integration, E2E.

---

**🔹 Nächste Schritte:**
- [ ] Phase 1: Core Foundation implementieren.
- [ ] Mistral API integrieren.
- [ ] Erste statische Tools testen.

---

*Dokument basierend auf dem **Symfony AI Bundle** und **Mistral LLM**.*