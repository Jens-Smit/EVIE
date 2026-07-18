# **EVIE: Selbst-evolvierender AI-Agent**

Ein **Symfony-basierter, selbst-evolvierender KI-Agent**, der dynamisch neue Fähigkeiten (Tools) generieren und registrieren kann. Das System nutzt **Mistral LLM** für die Intelligenz und **Human-in-the-Loop (HITL)** für die Sicherheit.

---

## **📌 Features**

- **Selbst-Evolution:** Dynamische Erstellung und Registrierung von Tools basierend auf Benutzeranforderungen.
- **Sicherheit:** HITL-Mechanismus (Human-in-the-Loop) zur Freigabe neuer Tools.
- **Kontextbewusst:** Speichert und nutzt Benutzerkontext für personalisierte Interaktionen.
- **Modular:** Klare Trennung von Logik (Symfony), Intelligenz (Mistral) und Fähigkeiten (Tools).
- **Testabdeckung:** Automatisierte Unit-, Integrations- und E2E-Tests.

---

## **🛠 Technologie-Stack**

- **Backend:** Symfony 7.0
- **Datenbank:** MySQL 8.0 + Doctrine ORM
- **KI:** Mistral LLM (über Symfony AI Bundle)
- **Vector Store:** pgvector (für Kontext-Embeddings)
- **Tests:** PHPUnit + PHPStan
- **CI/CD:** GitHub Actions

---

## **📦 Installation**

### **1. Repository klonen**
```bash
git clone https://github.com/Jens-Smit/EVIE.git
cd EVIE
```

### **2. Abhängigkeiten installieren**
```bash
composer install
```

### **3. Umgebungskonfiguration**
Kopiere die `.env.dist` zu `.env` und passe die Werte an:
```bash
cp .env.dist .env
```

**Wichtige Umgebungsvariablen:**
```ini
DATABASE_URL="mysql://db_user:db_password@127.0.0.1:3306/evie_db?serverVersion=8.0"
MISTRAL_API_KEY="dein_mistral_api_key"
MISTRAL_API_URL="https://api.mistral.ai"
```

### **4. Datenbank einrichten**
```bash
# Datenbank erstellen
php bin/console doctrine:database:create

# Schema erstellen
php bin/console doctrine:schema:create
```

### **5. Tests ausführen**
```bash
./vendor/bin/phpunit
```

---

## **🏗 Architektur**

### **Verzeichnisstruktur**
```txt
src/
├── AI/
│   ├── Agent/              # Haupt-Agenten (Orchestrator, Sub-Agenten)
│   ├── Skills/             # Dynamische Fähigkeiten (Tools)
│   │   └── Executor/       # Sichere Ausführungsumgebung
│   ├── Security/           # HITL & Sandbox
│   └── Onboarding/         # Benutzerkontext & Onboarding
├── Infrastructure/
│   ├── Mistral/           # Mistral API Client
│   └── VectorStore/       # pgvector-Integration
├── Entity/                # Doctrine-Entities
└── Repository/            # Datenbank-Repositories

tests/
├── Unit/                  # Unit-Tests
└── Integration/           # Integrations-Tests

.github/workflows/
└── tests.yml              # GitHub Actions Pipeline
```

---

### **Kernkomponenten**

| Komponente | Beschreibung |
|------------|--------------|
| **`OrchestratorAgent`** | Haupt-Controller, analysiert Prompts und delegiert an Tools/Sub-Agenten |
| **`SubAgentFactory`** | Erstellt spezialisierte Sub-Agenten (z. B. Research, Analysis) |
| **`DynamicSkillRegistry`** | Lädt und verwaltet dynamische Tools aus der Datenbank |
| **`ToolDefinitionGenerator`** | Generiert neue Tool-Schemata via Mistral LLM |
| **`HitlInterceptor`** | Sicherheits-Decorator für Tool-Ausführung (HITL) |
| **`SecurityGuard`** | Sandbox-Regeln für erlaubte Services und blockierte Ressourcen |
| **`ContextStoreManager`** | Speichert und lädt Benutzerkontext (RAG) |
| **`OnboardingFlowManager`** | Steuert den Onboarding-Prozess für neue Benutzer |

---

## **🔄 Workflow: Tool-Erstellung**

1. **Benutzeranfrage:** *"Analysiere diese Excel-Datei."*
2. **Orchestrator erkennt:** Kein passendes Tool vorhanden → **`ExcelParserTool` wird benötigt**
3. **Tool-Generierung:** `ToolDefinitionGenerator` erstellt ein Schema für `ExcelParserTool`
4. **HITL-Blockade:** Tool wird als **"pending"** in der Datenbank gespeichert
5. **Benutzerfreigabe:** Benutzer erhält eine Benachrichtigung und genehmigt das Tool
6. **Dynamische Registrierung:** Tool wird geladen und ist ab sofort verfügbar
7. **Ausführung:** Orchestrator führt die ursprüngliche Anfrage mit dem neuen Tool aus

---

## **🧪 Tests**

### **Unit-Tests**
- `OrchestratorAgentTest` – Testet Prompt-Analyse und Tool-Delegation
- `DynamicSkillRegistryTest` – Testet Tool-Ladung und -Verwaltung
- `HitlInterceptorTest` – Testet HITL-Mechanismus
- `SecurityGuardTest` – Testet Sicherheitsregeln

### **Integrations-Tests**
- `ToolEvolutionFlowTest` – Testet den kompletten Workflow der Tool-Erstellung

### **Tests ausführen**
```bash
# Alle Tests
./vendor/bin/phpunit

# Nur Unit-Tests
./vendor/bin/phpunit tests/Unit

# Nur Integrations-Tests
./vendor/bin/phpunit tests/Integration
```

---

## **🚀 GitHub Actions Pipeline**

Bei jedem **Push** oder **Pull Request** wird automatisch:
1. **MySQL-Datenbank** gestartet (für Tests)
2. **Abhängigkeiten** installiert (`composer install`)
3. **Datenbank-Schema** erstellt
4. **PHPUnit-Tests** ausgeführt
5. **PHPStan-Analyse** durchgeführt (Statische Code-Analyse)

**Pipeline-Datei:** [`.github/workflows/tests.yml`](.github/workflows/tests.yml)

---

## **📝 Beispiel: Neues Tool erstellen**

### **1. Tool-Definition generieren**
```php
use App\AI\Skills\ToolDefinitionGenerator;

$generator = new ToolDefinitionGenerator($toolDefinitionRepo, $mistralApiKey);
$toolDefinition = $generator->generateToolDefinition(
    'ExcelParserTool',
    'Ein Tool zum Parsen von Excel-Dateien'
);
```

### **2. Tool freigeben**
```php
$generator->approveTool($toolDefinition);
```

### **3. Tool verwenden**
```php
use App\AI\Agent\OrchestratorAgent;

$orchestrator = new OrchestratorAgent($skillRegistry, $contextStore);
$result = $orchestrator->handlePrompt('Analysiere diese Excel-Datei', 'user123');
```

---

## **🔒 Sicherheit**

- **HITL (Human-in-the-Loop):** Jedes neue Tool muss manuell freigegeben werden
- **Sandbox:** `SecurityGuard` blockiert gefährliche Ressourcen (z. B. `localhost`, `/etc/`) und Services
- **Tool-Validierung:** `HitlInterceptor` prüft, ob ein Tool freigegeben ist

---

## **📚 Dokumentation**

- **[Architektur-Blueprint](blueprint.md)** – Detaillierte Beschreibung der Architektur und des Entwicklungsplans
- **[Symfony AI Bundle](https://github.com/symfony/ai-bundle)** – Offizielle Dokumentation
- **[Mistral API](https://docs.mistral.ai/)** – Mistral LLM API-Dokumentation

---

## **🤝 Mitwirken**

1. **Forken** Sie das Repository
2. **Feature-Branch** erstellen (`git checkout -b feature/neues-feature`)
3. **Änderungen commiten** (`git commit -m 'Füge neues Feature hinzu'`)
4. **Pushen** (`git push origin feature/neues-feature`)
5. **Pull Request** erstellen

---

## **📄 Lizenz**

Dieses Projekt steht unter der **MIT-Lizenz**. Siehe [LICENSE](LICENSE) für weitere Informationen.
