# EVIE Roadmap: Phase 3 – Implementierungsplan

**Erstellt am:** 12. August 2026  
**Repository:** [Jens-Smit/EVIE](https://github.com/Jens-Smit/EVIE)  
**Status:** ✅ **In Bearbeitung**  
**Ziel:** Umsetzung der Maßnahmen aus der [EVIE_ANALYSE.md](EVIE_ANALYSE.md) unter Berücksichtigung der **Symfony AI Bundle-Dokumentation** (v0.12.0, Stand August 2026).

---

## 🎯 **Zusammenfassung der Phase 3**

Phase 3 konzentriert sich auf die **Optimierung der LLM-Prompts**, die **Erstellung von E2E-Tests für den Evolution-Flow** und die **Verbesserung des Onboarding-Prozesses**. Diese Maßnahmen sind als **mittlere Priorität** eingestuft und sollen die **Qualität der Tool-Generierung, die Benutzerfreundlichkeit und die Zuverlässigkeit des Systems** erhöhen.

**Dauer:** 3–4 Wochen  
**Aufwand:** ~7–8 Tage  
**Verantwortlich:** Jens Smit  

---

## 📋 **Maßnahmen im Detail**

### **🟢 Maßnahme 8: LLM-Prompt-Optimierung**
**Priorität:** Mittel | **Aufwand:** 2–3 Tage | **Status:** ⏳ Geplant

#### **Ziel**
Optimierung der LLM-Prompts für **bessere Tool-Schemata** und **präzisere Antworten** durch Nutzung der **Symfony AI Bundle-Features** für System Prompts, File-Based Prompts und Message Templates.

#### **Hintergrund (aus EVIE_ANALYSE.md)**
- Aktuell: **Fehlende LLM-Prompt-Optimierung für Onboarding** → Weniger präzise Tool-Schemata
- Problem: Tool-Schemata werden generisch oder unstrukturiert generiert
- Lösung: **Strukturierte Prompts** mit klaren Anweisungen und Beispielen

#### **Symfony AI Bundle-Unterstützung**
Das [Symfony AI Bundle](https://symfony.com/doc/current/ai/bundles/ai-bundle.html) bietet folgende Features für Prompt-Optimierung:

1. **System Prompt Configuration**
   - Einfache String-Konfiguration oder erweiterte Array-Syntax
   - Option `include_tools: true` fügt Tool-Definitionen automatisch am Ende des System-Prompts hinzu
   - Beispiel:
     ```yaml
     ai:
       agent:
         my_agent:
           prompt:
             text: 'You are a helpful assistant that generates precise JSON schemas for tools.'
             include_tools: true
     ```

2. **File-Based Prompts**
   - Externe Prompt-Dateien für bessere Organisation
   - Unterstützt `.txt`, `.json`, `.md` und andere Textformate
   - Beispiel:
     ```yaml
     ai:
       agent:
         my_agent:
           prompt:
             file: '%kernel.project_dir%/config/prompts/tool_schema_optimizer.txt'
     ```

3. **Translation Support**
   - Übersetzte Prompts für mehrsprachige Anwendungen
   - Benötigt `symfony/translation`
   - Beispiel:
     ```yaml
     ai:
       agent:
         my_agent:
           prompt:
             text: 'agent.system_prompt'
             enable_translation: true
             translation_domain: 'ai_prompts'
     ```

4. **Message Template Support**
   - Strukturierte Prompts mit Variablen
   - Benötigt `symfony/expression-language`

#### **Umsetzungsschritte**

| **Schritt** | **Beschreibung** | **Technische Details** | **Erwartetes Ergebnis** | **Aufwand** | **Status** |
|-------------|------------------|------------------------|-------------------------|-------------|------------|
| 1. **Prompt-Dateien erstellen** | Erstelle strukturierte Prompt-Dateien für Tool-Generierung | Dateien in `config/prompts/` ablegen (z. B. `tool_schema_optimizer.txt`, `onboarding_prompt.json`) | Wiederverwendbare, versionierte Prompts | 0.5 Tage | ⏳ |
| 2. **System Prompts konfigurieren** | Passe `ai.yaml` an, um File-Based Prompts zu nutzen | Referenzierung der Prompt-Dateien in `ai.yaml` | Zentrale Prompt-Verwaltung | 0.5 Tage | ⏳ |
| 3. **Tool-Schema-Prompt optimieren** | Erstelle spezifischen Prompt für ToolDefinitionGenerator | Prompt mit klaren Anweisungen, Beispielen und JSON-Schema-Struktur | Präzisere Tool-Schemata | 1 Tag | ⏳ |
| 4. **Onboarding-Prompt optimieren** | Erstelle strukturierten Prompt für OnboardingFlowManager | Prompt mit Schritt-für-Schritt-Anleitung für User-Kategorisierung | Bessere User-Klassifizierung | 0.5 Tage | ⏳ |
| 5. **Translation Support aktivieren** | Füge Übersetzungsunterstützung für Prompts hinzu | Installation von `symfony/translation`, Konfiguration der Prompts | Mehrsprachige Prompts | 0.5 Tage | ⏳ |

#### **Beispiel: Optimierter Tool-Schema-Prompt**

**Datei:** `config/prompts/tool_schema_optimizer.txt`
```text
You are an expert tool schema generator for the EVIE multi-agent system.

Your task is to generate precise, well-structured JSON schemas for new tools based on user requests.

## Guidelines:
1. Always generate valid JSON Schema (Draft 2020-12)
2. Include clear descriptions for each property
3. Use appropriate types (string, number, boolean, array, object)
4. Add examples where helpful
5. Follow the EVIE ToolInterface structure

## Required Schema Structure:
{
  "name": "tool_name",
  "description": "Clear description of what the tool does",
  "schema": {
    "type": "object",
    "properties": {
      "property1": {
        "type": "string",
        "description": "Description of property1",
        "example": "example_value"
      }
    },
    "required": ["required_property1", "required_property2"]
  }
}

## Example:
User Request: "Create a tool to scrape websites"
Response:
```json
{
  "name": "website_scraper",
  "description": "Scrapes content from a given URL",
  "schema": {
    "type": "object",
    "properties": {
      "url": {
        "type": "string",
        "format": "uri",
        "description": "The URL to scrape",
        "example": "https://example.com"
      },
      "depth": {
        "type": "integer",
        "minimum": 1,
        "maximum": 5,
        "description": "How many levels deep to scrape",
        "example": 2
      }
    },
    "required": ["url"]
  }
}
```

## Current Context:
- Available tools: {{ tools|join(', ') }}
- User request: {{ request }}
- Agent type: {{ agent_type }}

Generate the most appropriate tool schema for this request.
```

**Konfiguration in `ai.yaml`:**
```yaml
ai:
  agent:
    tool_generator:
      platform: 'ai.platform.mistral'
      model: 'mistral-large-latest'
      prompt:
        file: '%kernel.project_dir%/config/prompts/tool_schema_optimizer.txt'
        include_tools: true
```

#### **Erwartete Verbesserungen**
- ✅ **Präzisere Tool-Schemata** durch strukturierte Prompts
- ✅ **Wiederverwendbarkeit** durch File-Based Prompts
- ✅ **Mehrsprachigkeit** durch Translation Support
- ✅ **Bessere Wartbarkeit** durch zentrale Prompt-Verwaltung

---

### **🟢 Maßnahme 9: E2E-Test für Evolution-Flow**
**Priorität:** Mittel | **Aufwand:** 2–3 Tage | **Status:** ⏳ Geplant

#### **Ziel**
Erstellung von **End-to-End-Tests** für den **Tool-Evolution-Flow** (Tool-Generierung → Registrierung → Ausführung) zur Validierung der **dynamischen Tool-Erstellung** und **CompilerPass-Integration**.

#### **Hintergrund (aus EVIE_ANALYSE.md)**
- **Kritische Lücke:** DynamicSkillRegistry lädt JSON-Schemata, aber **keine Umwandlung in ToolInterface**
- **Fehlend:** E2E-Test für Evolution-Flow
- **Lösung:** CompilerPass implementieren + Tests erstellen

#### **Symfony AI Bundle-Unterstützung**
Das Symfony AI Bundle bietet folgende Testmöglichkeiten:

1. **Testing Agents** im Profiler
   - Sammelt Daten über Agenten-Aufrufe
   - Zeigt Tool-Calls und Responses an
   - Beispiel:
     ```php
     // In PHPUnit-Tests
     $profiler = $this->getProfiler();
     $agentData = $profiler->getCollector('ai.agent');
     ```

2. **Console Commands für Tests**
   - `ai:agent:call <agent>` – Interaktive Tests
   - `ai:platform:invoke <platform> <model> "<message>"` – Direkte Plattform-Aufrufe
   - Beispiel:
     ```bash
     php bin/console ai:agent:call tool_generator
     ```

3. **Dependency Injection für Tests**
   - Einfaches Mocken von Plattformen und Agenten
   - Beispiel:
     ```yaml
     # config/test/ai.yaml
     ai:
       platform:
         mock:
             class: 'Symfony\AI\Platform\Bridge\Test\TestPlatform'
     ```

#### **Umsetzungsschritte**

| **Schritt** | **Beschreibung** | **Technische Details** | **Erwartetes Ergebnis** | **Aufwand** | **Status** |
|-------------|------------------|------------------------|-------------------------|-------------|------------|
| 1. **Test-Umgebung vorbereiten** | Mock-Plattformen und Agenten für Tests konfigurieren | `config/test/ai.yaml` mit Test-Plattformen | Isolierte Testumgebung | 0.5 Tage | ⏳ |
| 2. **Unit-Tests für DynamicSkillRegistry** | Teste das Laden und Umwandeln von Tool-Definitionen | PHPUnit-Tests für `DynamicSkillRegistry::loadTools()` | Validierte Tool-Lademechanismen | 1 Tag | ⏳ |
| 3. **Integrationstests für CompilerPass** | Teste die Registrierung dynamischer Tools im Container | Test der `CompilerPass`-Integration | Funktionierende Tool-Registrierung | 1 Tag | ⏳ |
| 4. **E2E-Test für Evolution-Flow** | Teste den gesamten Flow: Prompt → Tool-Generierung → Registrierung → Ausführung | PHPUnit + Symfony Panther für Browser-Tests | Validierter Evolution-Flow | 1 Tag | ⏳ |
| 5. **Profiler-Tests** | Nutze den AI Profiler für Debugging | Integration des Profilers in Test-Suite | Bessere Debug-Möglichkeiten | 0.5 Tage | ⏳ |

#### **Beispiel: E2E-Test für Evolution-Flow**

**Datei:** `tests/Functional/AI/ToolEvolutionTest.php`
```php
<?php

namespace App\Tests\Functional\AI;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

class ToolEvolutionTest extends WebTestCase
{
    private AgentInterface $toolGeneratorAgent;
    private AgentInterface $orchestratorAgent;

    protected function setUp(): void
    {
        parent::setUp();
        
        $container = self::getContainer();
        $this->toolGeneratorAgent = $container->get('ai.agent.tool_generator');
        $this->orchestratorAgent = $container->get('ai.agent.orchestrator');
    }

    public function testToolEvolutionFlow(): void
    {
        // 1. Tool-Generierung anfordern
        $request = 'Create a tool to analyze CSV files';
        $messages = new MessageBag(Message::ofUser($request));
        
        $response = $this->toolGeneratorAgent->call($messages);
        $toolSchema = json_decode($response->getContent(), true);
        
        // 2. Validierung des generierten Schemas
        $this->assertArrayHasKey('name', $toolSchema);
        $this->assertArrayHasKey('description', $toolSchema);
        $this->assertArrayHasKey('schema', $toolSchema);
        $this->assertArrayHasKey('properties', $toolSchema['schema']);
        
        // 3. Tool-Registrierung testen (via CompilerPass)
        $toolDefinition = $this->getToolDefinitionFromSchema($toolSchema);
        $this->assertNotNull($toolDefinition);
        
        // 4. Tool-Ausführung testen
        $result = $this->orchestratorAgent->call(
            new MessageBag(Message::ofUser('Use the csv_analyzer tool to process test.csv'))
        );
        
        $this->assertStringContainsString('csv_analyzer', $result->getContent());
    }

    private function getToolDefinitionFromSchema(array $schema): ?object
    {
        // Logik zum Abrufen der Tool-Definition aus der Datenbank
        // (vereinfacht für das Beispiel)
        $container = self::getContainer();
        $registry = $container->get('App\AI\Skills\DynamicSkillRegistry');
        
        return $registry->getToolByName($schema['name']);
    }
}
```

#### **Beispiel: Unit-Test für DynamicSkillRegistry**

**Datei:** `tests/Unit/AI/Skills/DynamicSkillRegistryTest.php`
```php
<?php

namespace App\Tests\Unit\AI\Skills;

use PHPUnit\Framework\TestCase;
use App\AI\Skills\DynamicSkillRegistry;
use App\Entity\ToolDefinition;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class DynamicSkillRegistryTest extends TestCase
{
    public function testLoadToolsFromDatabase(): void
    {
        // Mock EntityManager
        $entityManager = $this->createMock(EntityManagerInterface::class);
        
        // Mock ToolDefinition
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('test_tool');
        $toolDefinition->setDescription('A test tool');
        $toolDefinition->setSchema(json_encode([
            'type' => 'object',
            'properties' => ['param1' => ['type' => 'string']]
        ]));
        
        $entityManager->method('getRepository')->willReturn(
            $this->getMockRepository([$toolDefinition])
        );
        
        // Test
        $registry = new DynamicSkillRegistry($entityManager);
        $tools = $registry->loadTools();
        
        $this->assertCount(1, $tools);
        $this->assertEquals('test_tool', $tools[0]->getName());
    }

    private function getMockRepository(array $tools): object
    {
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findAll')->willReturn($tools);
        return $repository;
    }
}
```

#### **Erwartete Verbesserungen**
- ✅ **Validierter Evolution-Flow** durch E2E-Tests
- ✅ **Früherkennung von Fehlern** in der Tool-Generierung
- ✅ **Bessere Wartbarkeit** durch automatisierte Tests
- ✅ **Sicherheit** durch getestete CompilerPass-Integration

---

### **🟢 Maßnahme 10: Onboarding-Prompt optimieren**
**Priorität:** Mittel | **Aufwand:** 1–2 Tage | **Status:** ⏳ Geplant

#### **Ziel**
Optimierung des **Onboarding-Prompts** für eine **präzisere User-Kategorisierung** und **bessere Datenqualität** durch Nutzung von **strukturierten Prompts** und **File-Based Prompts**.

#### **Hintergrund (aus EVIE_ANALYSE.md)**
- Aktuell: **Onboarding ohne spezifischen Prompt** → Weniger präzise User-Kategorisierung
- Problem: User-Daten werden unstrukturiert oder unvollständig gesammelt
- Lösung: **Strukturierter Onboarding-Prompt** mit klaren Anweisungen

#### **Symfony AI Bundle-Unterstützung**
Das Symfony AI Bundle bietet:
- **File-Based Prompts** für komplexe Onboarding-Flows
- **Message Template Support** für dynamische Prompts
- **Memory Provider** für Kontext-Speicherung

#### **Umsetzungsschritte**

| **Schritt** | **Beschreibung** | **Technische Details** | **Erwartetes Ergebnis** | **Aufwand** | **Status** |
|-------------|------------------|------------------------|-------------------------|-------------|------------|
| 1. **Onboarding-Prompt erstellen** | Erstelle strukturierten Prompt für User-Kategorisierung | JSON- oder Text-Prompt mit klaren Anweisungen | Präzisere User-Daten | 0.5 Tage | ⏳ |
| 2. **File-Based Prompt konfigurieren** | Referenziere Onboarding-Prompt in `ai.yaml` | Konfiguration in `config/packages/ai.yaml` | Zentrale Prompt-Verwaltung | 0.5 Tage | ⏳ |
| 3. **OnboardingFlowManager anpassen** | Integriere neuen Prompt in Onboarding-Flow | Anpassung von `OnboardingFlowManager.php` | Bessere User-Klassifizierung | 0.5 Tage | ⏳ |
| 4. **Memory Provider integrieren** | Speichere Onboarding-Daten als Kontext | Nutzung von `MemoryProviderInterface` | Persistente User-Daten | 0.5 Tage | ⏳ |

#### **Beispiel: Optimierter Onboarding-Prompt**

**Datei:** `config/prompts/onboarding_prompt.json`
```json
{
  "role": "You are an expert onboarding assistant for the EVIE multi-agent system.",
  "goal": "Collect precise user information to personalize the AI agent experience.",
  "instructions": [
    "Ask one question at a time",
    "Validate user responses before proceeding",
    "Store all information in the provided memory context",
    "Ask for clarification if responses are unclear"
  ],
  "required_information": {
    "user_type": {
      "question": "What is your primary role? (Developer, Business User, Administrator, Other)",
      "options": ["Developer", "Business User", "Administrator", "Other"],
      "validation": "Must be one of the provided options"
    },
    "technical_skills": {
      "question": "What are your technical skills? (Select all that apply)",
      "options": ["PHP", "Symfony", "JavaScript", "Python", "DevOps", "AI/ML", "None"],
      "validation": "Array of strings"
    },
    "use_case": {
      "question": "What is your primary use case for EVIE?",
      "options": [
        "Code Generation",
        "Business Process Automation",
        "Data Analysis",
        "Document Processing",
        "Other"
      ],
      "validation": "Must be one of the provided options"
    },
    "preferences": {
      "question": "Do you prefer concise or detailed responses?",
      "options": ["Concise", "Detailed", "Context-dependent"],
      "validation": "Must be one of the provided options"
    }
  },
  "memory_structure": {
    "user_profile": {
      "type": "object",
      "properties": {
        "user_type": {"type": "string"},
        "technical_skills": {"type": "array", "items": {"type": "string"}},
        "use_case": {"type": "string"},
        "preferences": {"type": "string"}
      }
    }
  },
  "example_conversation": [
    {
      "user": "I'm a Symfony developer",
      "assistant": "Thank you! I've noted your role as 'Developer'. Next, what are your technical skills?"
    },
    {
      "user": "PHP, Symfony, and a bit of JavaScript",
      "assistant": "Got it. Your technical skills are PHP, Symfony, and JavaScript. What is your primary use case for EVIE?"
    }
  ]
}
```

**Konfiguration in `ai.yaml`:**
```yaml
ai:
  agent:
    onboarding:
      platform: 'ai.platform.mistral'
      model: 'mistral-large-latest'
      prompt:
        file: '%kernel.project_dir%/config/prompts/onboarding_prompt.json'
      memory:
        service: 'App\AI\Onboarding\ContextMemoryProvider'
```

#### **Anpassung des OnboardingFlowManager**

**Datei:** `src/AI/Onboarding/OnboardingFlowManager.php` (Ausschnitt)
```php
// ...

public function startOnboarding(UserInterface $user): array
{
    $agent = $this->agentRegistry->get('onboarding');
    $memory = $this->memoryProvider->load(new Input($user->getId()));
    
    // Starte Onboarding mit strukturiertem Prompt
    $messages = new MessageBag(
        Message::ofSystem('Start onboarding for user ' . $user->getId())
    );
    
    $response = $agent->call($messages);
    
    // Speichere Kontext
    $this->contextStoreManager->store($user, $response->getContent());
    
    return $this->parseOnboardingData($response->getContent());
}

private function parseOnboardingData(string $content): array
{
    // Parsen der strukturierten Onboarding-Daten
    // (z. B. JSON-Extraktion)
    $data = json_decode($content, true);
    
    return [
        'user_type' => $data['user_type'] ?? null,
        'technical_skills' => $data['technical_skills'] ?? [],
        'use_case' => $data['use_case'] ?? null,
        'preferences' => $data['preferences'] ?? null,
    ];
}
```

#### **Erwartete Verbesserungen**
- ✅ **Präzisere User-Kategorisierung** durch strukturierte Prompts
- ✅ **Bessere Datenqualität** durch Validierung
- ✅ **Persistente User-Daten** durch Memory Provider
- ✅ **Wiederverwendbarkeit** durch File-Based Prompts

---

## 📅 **Zeitplan & Meilensteine**

| **Zeitraum** | **Maßnahme** | **Verantwortlich** | **Status** | **Meilenstein** |
|--------------|--------------|--------------------|------------|-----------------|
| **Woche 1 (12.–18.08.2026)** | LLM-Prompt-Optimierung (Schritte 1–3) | Jens Smit | ⏳ | Prompt-Dateien & Konfiguration |
| **Woche 1 (12.–18.08.2026)** | Onboarding-Prompt optimieren (Schritte 1–2) | Jens Smit | ⏳ | Onboarding-Prompt erstellt |
| **Woche 2 (19.–25.08.2026)** | E2E-Test für Evolution-Flow (Schritte 1–3) | Jens Smit | ⏳ | Unit- & Integrationstests |
| **Woche 2 (19.–25.08.2026)** | LLM-Prompt-Optimierung (Schritte 4–5) | Jens Smit | ⏳ | Translation Support |
| **Woche 3 (26.08.–01.09.2026)** | E2E-Test für Evolution-Flow (Schritte 4–5) | Jens Smit | ⏳ | E2E-Tests & Profiler |
| **Woche 3 (26.08.–01.09.2026)** | Onboarding-Prompt optimieren (Schritte 3–4) | Jens Smit | ⏳ | Integration & Tests |
| **Woche 4 (02.–08.09.2026)** | **Review & Finalisierung** | Jens Smit | ⏳ | Alle Tests grün, Dokumentation |

---

## 📊 **Erfolgsmetriken**

| **Metrik** | **Zielwert** | **Aktueller Wert** | **Messmethode** |
|------------|--------------|--------------------|-----------------|
| **Tool-Schema-Qualität** | 95% valide Schemata | ~70% | Automatisierte Validierung |
| **Onboarding-Datenqualität** | 100% vollständige Profile | ~60% | Manuelle Überprüfung |
| **Testabdeckung (Evolution-Flow)** | 90% | 0% | PHPUnit Coverage Report |
| **Prompt-Wiederverwendbarkeit** | 100% File-Based | 0% | Code-Review |

---

## 🛠 **Technische Abhängigkeiten**

### **Benötigte Pakete**
| **Paket** | **Version** | **Zweck** | **Status** |
|-----------|-------------|-----------|------------|
| `symfony/ai-bundle` | ^0.12.0 | AI Bundle-Features | ✅ Installiert |
| `symfony/translation` | ^6.4 | Translation Support für Prompts | ⏳ Optional |
| `symfony/expression-language` | ^6.4 | Message Template Support | ⏳ Optional |
| `phpunit/phpunit` | ^10.0 | Unit- & Integrationstests | ✅ Installiert |
| `symfony/panther` | ^2.0 | E2E-Tests | ⏳ Optional |

### **Benötigte Konfigurationen**
1. **`config/packages/ai.yaml`** – Anpassung für File-Based Prompts
2. **`config/test/ai.yaml`** – Test-Konfiguration mit Mock-Plattformen
3. **`config/prompts/`** – Verzeichnis für Prompt-Dateien
4. **`phpunit.xml.dist`** – Anpassung für AI-spezifische Tests

---

## 📝 **Checkliste für die Umsetzung**

### **🟢 LLM-Prompt-Optimierung**
- [ ] Verzeichnis `config/prompts/` erstellen
- [ ] Prompt-Dateien für Tool-Generierung erstellen (`tool_schema_optimizer.txt`)
- [ ] Prompt-Dateien für Onboarding erstellen (`onboarding_prompt.json`)
- [ ] `ai.yaml` für File-Based Prompts anpassen
- [ ] Translation Support aktivieren (optional)
- [ ] Message Template Support aktivieren (optional)
- [ ] Prompts in `ToolDefinitionGenerator` integrieren
- [ ] Prompts in `OnboardingFlowManager` integrieren

### **🟢 E2E-Test für Evolution-Flow**
- [ ] Test-Umgebung in `config/test/ai.yaml` konfigurieren
- [ ] Unit-Tests für `DynamicSkillRegistry` erstellen
- [ ] Integrationstests für CompilerPass erstellen
- [ ] E2E-Test für Evolution-Flow erstellen
- [ ] Profiler-Tests integrieren
- [ ] Test-Suite ausführen und Debugging

### **🟢 Onboarding-Prompt optimieren**
- [ ] Onboarding-Prompt in `config/prompts/onboarding_prompt.json` erstellen
- [ ] `ai.yaml` für Onboarding-Agent anpassen
- [ ] `OnboardingFlowManager` für neuen Prompt anpassen
- [ ] Memory Provider für Onboarding-Daten integrieren
- [ ] Onboarding-Flow testen

---

## 🔗 **Verknüpfungen zu anderen Phasen**

| **Phase** | **Verknüpfung zu Phase 3** | **Abhängigkeit** |
|-----------|-----------------------------|------------------|
| **Phase 1** | SecurityGuard & HitlInterceptor | Keine direkte Abhängigkeit |
| **Phase 2** | Sub-Agenten & Tool-Generierung | **Abhängig** – Phase 2 muss abgeschlossen sein |
| **Phase 4** | Orchestrator als Klasse | **Voraussetzung** – Phase 3 sollte abgeschlossen sein |

---

## 📌 **Zusammenfassung & Nächste Schritte**

### **Was wird erreicht?**
✅ **Bessere Tool-Qualität** durch optimierte Prompts  
✅ **Validierter Evolution-Flow** durch E2E-Tests  
✅ **Präzisere User-Daten** durch strukturiertes Onboarding  
✅ **Wartbare Codebasis** durch automatisierte Tests  

### **Nächste Schritte**
1. **Priorität setzen:** Beginne mit **LLM-Prompt-Optimierung**, da sie die Grundlage für die anderen Maßnahmen bildet.
2. **Parallel arbeiten:** Onboarding-Prompt kann parallel zur LLM-Prompt-Optimierung umgesetzt werden.
3. **Tests früh integrieren:** E2E-Tests sollten so früh wie möglich in den Entwicklungsprozess integriert werden.

### **Offene Fragen**
- Soll der **Translation Support** für Prompts aktiviert werden? (Benötigt `symfony/translation`)
- Soll **Symfony Panther** für E2E-Tests verwendet werden? (Benötigt zusätzliche Abhängigkeiten)
- Soll der **Message Template Support** aktiviert werden? (Benötigt `symfony/expression-language`)

---

## 📚 **Referenzen**
- [EVIE_ANALYSE.md](EVIE_ANALYSE.md) – Detaillierte Analyse des aktuellen Standes
- [Symfony AI Bundle Dokumentation](https://symfony.com/doc/current/ai/bundles/ai-bundle.html) – Offizielle Dokumentation
- [ROADMAP_PHASE1.md](ROADMAP_PHASE1.md) – Abgeschlossene Maßnahmen der Phase 1
- [ROADMAP_PHASE2.md](ROADMAP_PHASE2.md) – Abgeschlossene Maßnahmen der Phase 2

---

**💡 Fazit:**
Phase 3 konzentriert sich auf die **Qualitätsverbesserung** der EVIE-Implementierung durch **optimierte Prompts, E2E-Tests und strukturiertes Onboarding**. Die Maßnahmen bauen auf den **abgeschlossenen Phasen 1 und 2** auf und bereiten das System auf die **langfristigen Ziele** (Phase 4) vor. Durch die Nutzung der **Symfony AI Bundle-Features** können die Maßnahmen **effizient und nachhaltig** umgesetzt werden.
