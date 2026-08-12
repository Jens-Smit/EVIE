# 🔍 EVIE Phase 2 - Code Review Checkliste

**Erstellt am:** 12. August 2026  
**Letzte Aktualisierung:** 12. August 2026, 20:50 Uhr  
**Zweck:** Vorbereitung des Code Reviews für Phase 2 (Maßnahme 4 & 6)  
**Review-Typ:** **Technisches Code Review**  

---

## 📋 **Allgemeine Informationen**

### **Pull Request**
- **Titel:** Phase 2: Dynamische Sub-Agenten & MCP-Server
- **Branch:** `feature/phase2-dynamic-agents-mcp` (vorgeschlagen)
- **Base Branch:** `main`
- **Verantwortlich:** Jens Smit
- **Reviewers:** Team (zuweisen)

### **Umfang des Reviews**
- **Maßnahme 4:** Sub-Agenten dynamisch machen (9 Dateien, ~2.000 Zeilen)
- **Maßnahme 6:** MCP-Server dynamisch konfigurierbar machen (19 Dateien, ~2.800 Zeilen)
- **Gesamt:** 28 Dateien, ~4.800 Zeilen neuer/aktualisierter Code

---

## ✅ **Vor dem Code Review**

### **1. Code-Qualität prüfen**

- [x] **Alle Tests bestehen**
  ```bash
  php bin/phpunit
  ```
  **Status:** ✅ Alle Tests bestehen (100% Pass Rate)

- [x] **Keine PHP-Errors oder Warnings**
  ```bash
  php -l src/AI/Agent/SubAgentFactory.php
  php -l src/AI/Mcp/McpServerFactory.php
  ```
  **Status:** ✅ Keine Syntax-Errors

- [x] **Code folgt PSR-12 Standards**
  ```bash
  php-cs-fixer fix --dry-run --diff
  ```
  **Status:** ✅ PSR-12 konform

- [x] **Statische Code-Analyse**
  ```bash
  phpstan analyse src/AI/Agent/ --level=5
  phpstan analyse src/AI/Mcp/ --level=5
  ```
  **Status:** ✅ Keine kritischen Fehler

---

### **2. Abhängigkeiten prüfen**

- [x] **Composer-Dependencies sind aktuell**
  ```bash
  composer validate
  composer outdated
  ```
  **Status:** ✅ Alle Abhängigkeiten sind aktuell

- [x] **Symfony AI Bundle ist installiert und konfiguriert**
  ```bash
  composer show symfony/ai-bundle
  ```
  **Status:** ✅ Installiert

- [x] **Datenbank-Migrationen sind aktuell**
  ```bash
  php bin/console doctrine:migrations:status
  ```
  **Status:** ✅ Migrationen sind aktuell

---

### **3. Sicherheitsprüfung**

- [x] **SecurityGuard ist für alle dynamischen Komponenten integriert**
  - SubAgentFactory: ✅
  - McpServerFactory: ✅
  - McpToolExecutor: ✅

- [x] **Alle Benutzereingaben werden validiert**
  - Formulare: ✅ (Symfony Form Component)
  - API-Endpoints: ✅ (Parameter Validation)
  - Repository-Abfragen: ✅ (Doctrine Parameter Binding)

- [x] **Keine SQL-Injection-Möglichkeiten**
  - Alle DB-Abfragen verwenden Doctrine QueryBuilder: ✅
  - Keine raw SQL mit Benutzereingaben: ✅

- [x] **Keine XSS-Möglichkeiten in Templates**
  - Alle Ausgaben werden escaped: ✅
  - Raw HTML nur mit `|raw` Filter: ✅ (nur bei vertrauenswürdigen Daten)

- [x] **CSRF-Tokens für alle Formulare**
  - Alle Formulare haben CSRF-Protection: ✅
  - Delete- und Toggle-Aktionen haben separate Tokens: ✅

---

## 📊 **Code Review Checkliste**

---

### **📁 Maßnahme 4: Sub-Agenten dynamisch machen**

#### **Entity & Repository**

- [ ] **SubAgentDefinition.php**
  - [x] Entity ist korrekt mit ORM-Annotations konfiguriert
  - [x] Alle Felder haben passende Typen
  - [x] Beziehungen sind korrekt definiert (User)
  - [x] Getter/Setter sind vorhanden
  - [x] Constructor initialisiert Standardwerte

- [ ] **SubAgentDefinitionRepository.php**
  - [x] `findAllActive()` funktioniert korrekt
  - [x] `findOneByName()` funktioniert korrekt
  - [x] Abfragen sind optimiert (Indizes nutzen)

- [ ] **Migration (Version20260812210000.php)**
  - [x] Tabelle wird korrekt erstellt
  - [x] Fremdschlüssel sind definiert
  - [x] Indizes sind für Performance optimiert
  - [x] Migration kann zurückgerollt werden

#### **Factory & Dispatcher**

- [ ] **SubAgentFactory.php**
  - [x] `createFromDefinition()` lädt Sub-Agenten aus DB
  - [x] `createAllFromDatabase()` lädt alle aktiven Sub-Agenten
  - [x] `createByName()` hat Fallback zu statischer Konfiguration
  - [x] `registerSubAgent()` speichert neue Definitionen
  - [x] `registerAllFromDatabase()` implementiert Lazy-Loading
  - [x] SecurityGuard wird für Validierung verwendet
  - [x] Abwärtskompatibilität mit bestehenden Methoden
  - [x] Fehler werden korrekt geloggt

- [ ] **SubAgentDispatcher.php**
  - [x] `delegate()` delegiert Aufgaben an Sub-Agenten
  - [x] `determineSubAgent()` bestimmt passenden Sub-Agenten
  - [x] `classifyTask()` klassifiziert Aufgaben korrekt
  - [x] `delegateTo()` delegiert an bestimmten Sub-Agenten
  - [x] `getAvailableSubAgents()` gibt alle verfügbaren Sub-Agenten zurück
  - [x] `getActiveSubAgentDefinitions()` gibt DB-Definitionen zurück
  - [x] Bestehende Methoden sind beibehalten

#### **CompilerPass & Command**

- [ ] **AiSubAgentsCompilerPass.php**
  - [x] Registriert Sub-Agenten als Services
  - [x] Nutzt Cache für Compile-Time
  - [x] Service-IDs folgen Konventionen
  - [x] Tags sind korrekt gesetzt

- [ ] **WarmupSubAgentsCacheCommand.php**
  - [x] Lädt Definitionen aus DB
  - [x] Speichert im Cache
  - [x] Konsolenausgabe ist informativ
  - [x] Fehler werden behandelt

#### **Tests**

- [ ] **SubAgentFactoryTest.php**
  - [x] Alle Test-Cases bestehen
  - [x] Mocking ist korrekt
  - [x] Edge-Cases werden abgedeckt

- [ ] **SubAgentDispatcherIntegrationTest.php**
  - [x] Datenbank-Integration funktioniert
  - [x] Alle Test-Cases bestehen
  - [x] Setup/Teardown ist korrekt

#### **Konfiguration**

- [ ] **services.yaml**
  - [x] SubAgentFactory hat alle Abhängigkeiten
  - [x] SubAgentDispatcher hat alle Abhängigkeiten
  - [x] CompilerPass ist registriert
  - [x] Command ist registriert

---

### **📁 Maßnahme 6: MCP-Server dynamisch konfigurierbar machen**

#### **Entity & Repository**

- [ ] **McpServerDefinition.php**
  - [x] Entity ist korrekt mit ORM-Annotations konfiguriert
  - [x] Alle Felder haben passende Typen (JSON, UUID, etc.)
  - [x] Beziehungen sind korrekt definiert (User)
  - [x] Getter/Setter sind vorhanden
  - [x] Hilfsmethoden (`isToolAllowed`, `isResourceBlocked`) funktionieren
  - [x] Constructor initialisiert Standardwerte

- [ ] **McpServerDefinitionRepository.php**
  - [x] `findAllActive()` funktioniert korrekt
  - [x] `findOneByName()` funktioniert korrekt
  - [x] `findByType()` funktioniert korrekt
  - [x] `findOneByTypeAndName()` funktioniert korrekt
  - [x] `existsByName()` funktioniert korrekt
  - [x] `findByAllowedTool()` funktioniert korrekt
  - [x] `findByBlockedResource()` funktioniert korrekt

- [ ] **Migration (Version20260812220000.php)**
  - [x] Tabelle wird korrekt erstellt
  - [x] Fremdschlüssel sind definiert
  - [x] Indizes sind für Performance optimiert
  - [x] Migration kann zurückgerollt werden

#### **Interface & Factory**

- [ ] **McpServerInterface.php**
  - [x] Alle notwendigen Methoden sind definiert
  - [x] Type-Hints sind korrekt
  - [x] DocBlocks sind vorhanden

- [ ] **McpServerFactory.php**
  - [x] `createFromDefinition()` lädt Server aus DB
  - [x] `createAllFromDatabase()` lädt alle aktiven Server
  - [x] `createByName()` hat Fallback zu statischer Konfiguration
  - [x] `registerMcpServer()` speichert neue Definitionen
  - [x] `getAvailableServers()` gibt alle verfügbaren Server zurück
  - [x] `getActiveServerDefinitions()` gibt DB-Definitionen zurück
  - [x] `getServerDefinitionsByType()` filtert nach Typ
  - [x] SecurityGuard wird für Validierung verwendet
  - [x] Server-Konfiguration wird validiert
  - [x] Fehler werden korrekt geloggt

#### **Executor & CompilerPass**

- [ ] **McpToolExecutor.php**
  - [x] `execute()` führt Tools auf Servern aus
  - [x] `executeTool()` führt Tools aus (automatische Server-Auswahl)
  - [x] `getAvailableServers()` gibt alle Server zurück
  - [x] `getServerTools()` gibt Tools eines Servers zurück
  - [x] `hasServerTool()` prüft Tool-Verfügbarkeit
  - [x] `isToolAllowed()` prüft Tool-Berechtigungen
  - [x] `getActiveServerDefinitions()` gibt Definitionen zurück
  - [x] SecurityGuard wird für Tool-Prüfung verwendet
  - [x] Ressourcen werden gegen Blocklist geprüft

- [ ] **AiMcpServersCompilerPass.php**
  - [x] Registriert MCP-Server als Services
  - [x] Nutzt Cache für Compile-Time
  - [x] Service-IDs folgen Konventionen
  - [x] Tags sind korrekt gesetzt
  - [x] Konfiguration wird injiziert

#### **Command & Controller**

- [ ] **WarmupMcpServersCacheCommand.php**
  - [x] Lädt Definitionen aus DB
  - [x] Speichert im Cache
  - [x] Konsolenausgabe ist informativ
  - [x] Fehler werden behandelt

- [ ] **McpServerController.php**
  - [x] Alle Routen sind korrekt definiert
  - [x] Security-Annotations sind vorhanden
  - [x] Formulare werden korrekt verarbeitet
  - [x] CRUD-Operationen funktionieren
  - [x] API-Endpoints geben JSON zurück
  - [x] Fehler werden behandelt
  - [x] CSRF-Protection ist aktiv

#### **Formular & Templates**

- [ ] **McpServerDefinitionType.php**
  - [x] Alle Felder sind definiert
  - [x] Formular-Constraints sind vorhanden
  - [x] Help-Texte sind informativ
  - [x] Platzhalter sind sinnvoll

- [ ] **Templates**
  - [x] `servers.html.twig` - Liste funktioniert
  - [x] `server_show.html.twig` - Details mit Tool-Test
  - [x] `server_new.html.twig` - Formular funktioniert
  - [x] `server_edit.html.twig` - Bearbeitung funktioniert
  - [x] Templates sind responsiv
  - [x] JavaScript funktioniert (Tool-Test)

#### **Tests**

- [ ] **McpServerDefinitionTest.php**
  - [x] Alle Test-Cases bestehen
  - [x] Whitelist/Blacklist wird getestet

- [ ] **McpServerFactoryTest.php**
  - [x] Alle Test-Cases bestehen
  - [x] Mocking ist korrekt

- [ ] **McpToolExecutorTest.php**
  - [x] Alle Test-Cases bestehen
  - [x] Sicherheitsprüfungen werden getestet

- [ ] **McpServerFactoryIntegrationTest.php**
  - [x] Datenbank-Integration funktioniert
  - [x] Alle Test-Cases bestehen

#### **Konfiguration**

- [ ] **services.yaml**
  - [x] McpServerFactory hat alle Abhängigkeiten
  - [x] McpToolExecutor hat alle Abhängigkeiten
  - [x] CompilerPass ist registriert
  - [x] Command ist registriert
  - [x] Controller ist registriert

---

## 📝 **Review-Fragen**

### **Allgemeine Fragen**

1. **Architektur**
   - [ ] Ist die Architektur für dynamische Sub-Agenten/MCP-Server klar und verständlich?
   - [ ] Sind die Design Patterns angemessen eingesetzt?
   - [ ] Ist die Trennung von Concerns (Separation of Concerns) gut umgesetzt?

2. **Code-Qualität**
   - [ ] Ist der Code gut lesbar und wartbar?
   - [ ] Sind die Methoden und Klassen gut benannt?
   - [ ] Sind die DocBlocks ausreichend und korrekt?
   - [ ] Werden Best Practices (DRY, KISS, SOLID) befolgt?

3. **Performance**
   - [ ] Sind Datenbank-Abfragen optimiert?
   - [ ] Werden Indizes effektiv genutzt?
   - [ ] Gibt es potenzielle Performance-Bottlenecks?

### **Spezifische Fragen zu Maßnahme 4**

4. **SubAgentFactory**
   - [ ] Ist der Fallback-Mechanismus (DB → statisch) robust?
   - [ ] Werden Sub-Agenten korrekt im DynamicSkillRegistry registriert?
   - [ ] Funktioniert das Lazy-Loading wie erwartet?

5. **SubAgentDispatcher**
   - [ ] Ist die Delegationslogik korrekt?
   - [ ] Funktioniert die @mention-Erkennung?
   - [ ] Ist die Task-Klassifizierung ausreichend?

### **Spezifische Fragen zu Maßnahme 6**

6. **McpServerFactory**
   - [ ] Ist die Sicherheitsvalidierung ausreichend?
   - [ ] Werden Server-Konfigurationen korrekt geparsed?
   - [ ] Funktioniert der Fallback zu statischer Konfiguration?

7. **McpToolExecutor**
   - [ ] Werden Tools korrekt auf Servern ausgeführt?
   - [ ] Funktioniert die automatische Server-Auswahl?
   - [ ] Werden Sicherheitsprüfungen korrekt durchgeführt?

8. **McpServerController**
   - [ ] Ist die UI benutzerfreundlich?
   - [ ] Funktionieren alle CRUD-Operationen?
   - [ ] Werden API-Endpoints korrekt validiert?

---

## 🎯 **Nach dem Code Review**

### **Feedback umsetzen**

- [ ] Alle Kommentare wurden adressiert
- [ ] Alle Änderungen wurden getestet
- [ ] Alle Tests bestehen weiterhin
- [ ] Dokumentation wurde aktualisiert

### **Finales Testing**

- [ ] **Manuelles Testing**
  - [ ] Sub-Agenten können erstellt/gelöscht/aktualisiert werden
  - [ ] MCP-Server können erstellt/gelöscht/aktualisiert werden
  - [ ] Tools können auf MCP-Servern ausgeführt werden
  - [ ] UI funktioniert wie erwartet

- [ ] **Automatisiertes Testing**
  ```bash
  php bin/phpunit
  ```

- [ ] **Performance-Testing**
  - [ ] Ladezeiten sind akzeptabel
  - [ ] Datenbank-Abfragen sind schnell

---

## 📅 **Zeitplan für Code Review**

| **Phase** | **Dauer** | **Verantwortlich** | **Status** |
|-----------|-----------|-------------------|------------|
| Vorbereitung | 1 Tag | Jens Smit | ✅ |
| Code Review | 1-2 Tage | Team | ⏳ |
| Feedback umsetzen | 1 Tag | Jens Smit | ⏳ |
| Finales Testing | 0.5 Tage | Jens Smit | ⏳ |
| Merge nach main | 0.5 Tage | Jens Smit | ⏳ |

---

## 🔗 **Hilfreiche Links**

### **Dokumentation**
- [ROADMAP_PHASE2.md](ROADMAP_PHASE2.md) - Implementierungsplan
- [EVIE_ANALYSE.md](EVIE_ANALYSE.md) - Systemanalyse
- [blueprint.md](blueprint.md) - Architektur-Blueprint

### **Symfony Best Practices**
- [Symfony Best Practices](https://symfony.com/doc/current/best_practices.html)
- [Symfony Security](https://symfony.com/doc/current/security.html)
- [Symfony AI Bundle Docs](https://symfony.com/doc/current/ai/bundles/ai-bundle.html)

### **Code Review Tools**
- [GitHub Code Review](https://docs.github.com/en/pull-requests/collaborating-with-pull-requests/reviewing-changes-in-pull-requests/about-pull-request-reviews)
- [GitHub CodeQL](https://docs.github.com/en/code-security/code-scanning/integrating-with-codeql/about-codeql)

---

## 💡 **Tipps für Reviewers**

1. **Fokussiere dich auf die Architektur** - Ist der Code gut strukturiert?
2. **Prüfe die Sicherheit** - Gibt es potenzielle Sicherheitslücken?
3. **Teste die Funktionalität** - Funktioniert der Code wie erwartet?
4. **Achte auf Code-Qualität** - Ist der Code lesbar und wartbar?
5. **Hinterfrage Annahmen** - Gibt es Edge-Cases, die nicht abgedeckt sind?

---

## ✅ **Abschluss**

Nach erfolgreichem Code Review:

- [ ] Pull Request wird gemergt
- [ ] Phase 2 Fortschritt wird aktualisiert
- [ ] Nächste Maßnahmen werden gestartet

**Viel Erfolg mit dem Code Review!** 🎉

---

*Diese Checkliste wurde am 12. August 2026 erstellt und ist Teil der EVIE Phase 2 Dokumentation.*
