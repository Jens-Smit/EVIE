# EVIE – Unabhängiges Audit

**Geprüftes Repo:** https://github.com/Jens-Smit/EVIE
**Geprüfter Commit:** `8a84cea` (main, Stand 16.08.2026)
**Methodik:** Vollständiger Checkout, PHP-Syntax-Lint aller Dateien (165 Dateien in `src/`,
komplett `tests/`, `migrations/`), manuelle Code-Review der sicherheitskritischen Pfade
(SecurityGuard, HitlListener, Tenant-Isolation, RAG-Pipeline), Abgleich der Blueprint-
und README-Versprechen mit dem tatsächlichen Code, Verifikation der bereits im Repo
vorhandenen Selbstauskunft (`docs/PRODUCTION_READINESS_CHECKLIST.md`) gegen den
aktuellen Codestand.

> ⚠️ **Wichtige Einschränkung dieses Audits:** In der Sandbox, in der dieses Audit
> durchgeführt wurde, ist kein Netzwerkzugriff auf `packagist.org` / `repo.packagist.org`
> möglich. **`composer install`, `vendor/bin/phpunit`, `vendor/bin/phpstan` und
> `composer audit` konnten nicht ausgeführt werden.** Alle Aussagen zu Testergebnissen
> basieren auf **statischer Code-Analyse** (Lesen der Test- und Produktionsklassen,
> Nachvollziehen der Aufrufketten), nicht auf tatsächlichen Testläufen. Wo möglich, wurde
> gegenteilig geprüft, indem Methodensignaturen, Wiring (DI/Container) und Testkörper
> gegeneinander abgeglichen wurden. Diese Einschränkung ist selbst ein Audit-Befund:
> **Ich kann nicht bestätigen, dass die CI aktuell grün ist** – das kann nur ein Lauf mit
> echtem Netzwerkzugriff (z. B. GitHub Actions selbst) zeigen.

---

## 0. Kurzfassung (TL;DR)

**Ist es sicher und hält es, was es verspricht? Nein, nicht vollständig – aber es ist auch
kein Fake-Projekt.** EVIE ist ein ambitioniertes, echtes Symfony-AI-Projekt mit einer
großen, ernsthaften Testsuite (35 Testdateien) und einer bemerkenswert ehrlichen
Selbstdokumentation (`docs/PRODUCTION_READINESS_CHECKLIST.md`), die selbst zugibt:
**„EVIE ist NICHT production-ready."** Ein Großteil der im Blueprint beschriebenen
Architektur ist tatsächlich so implementiert, wie beschrieben (native Symfony-AI-
Toolbox, `ToolCallRequested`-HITL-Event, `SecurityGuard`-Policy, Audit-Logging mit
Redaction). Mehrere P0-Sicherheitslücken, die laut Commit-Historie und Checkliste
bereits „behoben" sein sollen, sind bei genauer Prüfung des aktuellen Codes **nur
teilweise oder gar nicht behoben** – insbesondere die **Tenant-Isolation im RAG-Pfad**
ist trotz gegenteiliger Dokumentation nachweislich **kaputt** (siehe 2.1). Zusätzlich
gibt es eine **nicht lauffähige Datenbank-Migration** (MySQL-Syntax in einem
PostgreSQL-Projekt) und eine PHPStan-Konfiguration, die zentrale Sicherheitsklassen
über breite Ignore-Pattern von der Analyse ausnimmt.

**Fazit in einem Satz:** Die Architektur ist solide und größtenteils wie beschrieben
umgesetzt, aber die Behauptung „vollständig abgetestet, 100 % wie beschrieben
funktionsfähig" stimmt **nicht** – es gibt mehrere konkrete, im Code nachweisbare
Lücken zwischen Dokumentation/Commit-Botschaften und tatsächlichem Verhalten.

---

## 1. Was funktioniert wie beschrieben (verifiziert im Code)

| Blueprint-Versprechen | Fund | Bewertung |
|---|---|---|
| Nativer Symfony-AI-`Agent` statt Eigenbau-Orchestrator | `AgentDialogController` nutzt `#[Autowire(service: 'ai.agent.orchestrator')]`, `AgentInterface` | ✅ konform |
| `DynamicToolbox` als `ToolboxInterface`-Decorator | `src/AI/Skills/DynamicToolbox.php` implementiert Decorator-Pattern, merged statische + dynamische Tools | ✅ konform |
| HITL über natives `ToolCallRequested`-Event | `src/AI/Security/HitlListener.php`, `#[AsEventListener(event: ToolCallRequested::class)]`, sauber gegen `SecurityGuard::decide()` verdrahtet | ✅ konform, sauber implementiert |
| `SecurityGuard`: Allow/Deny/AskUser-Policy | `src/AI/Security/SecurityGuard.php::decide()` – vorhanden, wird tatsächlich vom `HitlListener` aufgerufen | ✅ konform |
| SSRF-Schutz inkl. nicht-kanonischer IP-Formate (Dezimal, Hex, Oktal, IPv4-in-IPv6) | `SecurityGuard::normalizeHost()` implementiert genau das im Changelog Beschriebene | ✅ verifiziert korrekt |
| Directory-Traversal-Schutz (`../`, URL-encoded, Symlink via `realpath()`) | `SecurityGuard::isPathSafe()` | ✅ verifiziert korrekt |
| IDOR-Fix: `user_identifier` kommt aus dem authentifizierten User, nicht aus dem Request-Body | `AgentDialogController::dialog()` Zeile ~71–79 – Body-Wert wird zwar noch geparst (totes Feld, siehe 3.4), aber **nicht verwendet** | ✅ Fix wirksam (mit Code-Smell) |
| Tenant-Isolation für `ToolDefinition`/HITL-Freigabe | `HitlListener::findDefinition()` nutzt `findOneByNameForUser()`, wenn User eingeloggt ist; durch `tests/Unit/AI/Security/TenantIsolationTest.php` mit 4 Testfällen sauber abgedeckt | ✅ verifiziert korrekt |
| Audit-Logging mit Secret-Redaction | `AuditLogger::redact()` filtert `password`, `secret`, `api_key`, `token`, `authorization`, `private_key`, `credentials` rekursiv; wird von `HitlListener::audit()` bei jeder Policy-Entscheidung aufgerufen | ✅ verifiziert korrekt |
| CI ohne Gate-Bypässe (`\|\| true`) | `.github/workflows/ci.yml` enthält kein `\|\| true` mehr; `composer validate --strict --no-check-publish --no-check-lock`, `composer audit`, `phpstan analyse … --level=5` laufen ungebypasst | ✅ Konfiguration korrekt (Laufergebnis nicht verifizierbar, siehe Einschränkung oben) |
| `symfony/debug-bundle`/`web-profiler-bundle` aus `require` entfernt | `composer.json`: beide stehen unter `require-dev` | ✅ verifiziert |
| Keine hartcodierten Produktions-Secrets im Repo | Grep über `*.php/*.yaml/*.yml/*.env*` findet nur Test-Platzhalter (`test-mistral-key` etc.) | ✅ verifiziert |
| PHP-Code ist syntaktisch fehlerfrei | `php -l` über alle 165 Dateien in `src/`, alle Migrationen und alle Testdateien: **0 Syntaxfehler** | ✅ verifiziert (der PHP-ParseError aus Commit `2d01dcf` wurde durch `8a84cea` tatsächlich behoben) |

Das ist kein triviales Ergebnis – die Grundarchitektur ist ernsthaft und der
Blueprint-Beschreibung angemessen umgesetzt, nicht nur behauptet.

---

## 2. Kritische Befunde (Diskrepanz zwischen Behauptung und Code)

### 2.1 🔴 P0 – Tenant-Isolation im RAG-Pfad ist NICHT wirksam (trotz gegenteiliger Doku)

`docs/PRODUCTION_READINESS_CHECKLIST.md` und mehrere Commit-Botschaften
(`35ff4bb feat: RAG Store-Adapter + Tenant-Isolation (P0-2, P1-7)`,
`e47e736 fix(security): SSRF-DNS-Schutz aktivieren, HTTP-Redirects blocken,
Tenant-Isolation reparieren`) behaupten, dass die Tenant-Isolation im RAG/Vector-Search-
Pfad hergestellt wurde. **Das stimmt nicht für den tatsächlich genutzten Codepfad:**

1. **`ContextInjector` (der aktive `InputProcessor`, der bei jedem Agent-Aufruf läuft
   und via `#[AsInputProcessor]` verdrahtet ist) ruft `retrieve()` ohne Optionen auf:**
   ```php
   // src/AI/Rag/ContextInjector.php, processInput()
   $result = $this->retriever->retrieve($query);   // <-- KEIN user_identifier!
   ```
   Der einzige Ort, an dem `user_identifier` tatsächlich gefiltert würde
   (`StoreRetrieverAdapter`), wird **nirgends im Produktionscode aufgerufen** – Grep über
   `src/` und `tests/` liefert außer der eigenen Klassendefinition und der DI-Registrierung
   in `config/services.yaml` **keinen einzigen Verwendungsort**. Der als Fix beworbene
   `StoreRetrieverAdapter` ist damit weiterhin **toter Code**, exakt das Problem, das die
   Checkliste unter Punkt 5.10 als „P0-Blocker" beschrieben hatte.

2. Selbst wenn `user_identifier` durchgereicht würde, wäre der Filter auf DB-Ebene
   **wirkungslos**, denn:
   ```php
   // src/Repository/EmbeddingRepository.php
   public function findSimilar(string $contentType, array $queryVector, int $limit = 5, float $minSimilarity = 0.5): array
   // <-- KEIN $userIdentifier-Parameter in der Signatur!
   ```
   ```php
   // src/AI/Rag/VectorStore.php::search()
   return $this->embeddingRepository->findSimilar($contentType, $queryVector, $limit, $minSimilarity, $userIdentifier);
   // 5. Argument wird von PHP klaglos verworfen (keine Exception, kein Warning) –
   //    findSimilar() akzeptiert nur 4 Parameter.
   ```
   Dieser exakte Bug ist sogar **dokumentiert und bewusst stehen gelassen** in
   `phpstan.neon`:
   ```
   # Pre-existing: EmbeddingRepository::findSimilar() wird mit 5 Parametern
   # aufgerufen, erwartet 2-4 (VectorStore.php).
   - '#Method App\\Repository\\EmbeddingRepository::findSimilar\(\) invoked with 5 parameters#'
   ```
   Es handelt sich also nicht um einen übersehenen Bug, sondern um einen **bekannten,
   per Ignore-Regel stummgeschalteten Fehler**, der die zentrale P0-5-Zusage
   „Tenant A niemals Kontext von Tenant B" faktisch aushebelt.

3. **Konsequenz:** Jede über `ContextInjector` in den System-Prompt eingefügte
   RAG-Information (Profildaten, gespeicherte Konversationen, Wissensbasis) wird
   **tenant-übergreifend** ohne Filterung abgefragt. Ein Tenant B kann über einen
   entsprechend formulierten Prompt Informationen aus dem Kontext von Tenant A
   in die Antwort injiziert bekommen. **Das ist eine reale Datenschutz-/
   Multi-Tenancy-Sicherheitslücke, keine Theorie.**

4. Der existierende Test `tests/Unit/AI/Security/TenantIsolationTest.php` prüft
   **ausschließlich** `HitlListener`/`ToolDefinition`, **nicht** `ContextInjector` oder
   `Retriever`. `tests/Unit/AI/Rag/ContextInjectorTest.php` enthält **keinen einzigen**
   Test-Bezug zu `user_identifier` oder Tenant. Die Testsuite gibt also ein falsches
   Sicherheitsgefühl – sie „beweist" Tenant-Isolation nur für den Teil des Systems,
   der tatsächlich isoliert ist, und schweigt zum Teil, der es nicht ist.

**Empfehlung:** siehe roadmap.md, P0-1.

### 2.2 🔴 P0 – Datenbank-Migration mit MySQL-Syntax in einem PostgreSQL-Projekt

`migrations/Version20260811190100.php` enthält reine MySQL-DDL:

```sql
CREATE TABLE user_profiles (id INT AUTO_INCREMENT NOT NULL, ...
  preferences LONGTEXT NOT NULL, ...) DEFAULT CHARACTER SET utf8mb4
  COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
```
```sql
ALTER TABLE agent_history DROP FOREIGN KEY FK_F5E912E16B9DD454;
DROP INDEX IDX_F5E912E16B9DD454 ON agent_history;
```

`AUTO_INCREMENT`, `LONGTEXT`, `ENGINE = InnoDB`, `DROP FOREIGN KEY <name>` und
`DROP INDEX … ON <table>` sind **keine gültige PostgreSQL-Syntax** (Postgres kennt
`SERIAL`/`IDENTITY`, `TEXT`, kein `ENGINE`, `ALTER TABLE … DROP CONSTRAINT`, `DROP INDEX
<name>` ohne `ON`). Das Projekt ist laut `composer.json` (`ext-pdo_pgsql`,
`doctrine/dbal`), `docker-compose.yml` (`postgres:15-alpine` + `pgvector`) und README
(„PostgreSQL/pgvector") vollständig auf PostgreSQL ausgelegt.

**Konsequenz:** `php bin/console doctrine:migrations:migrate` auf einer echten
PostgreSQL-Zieldatenbank **schlägt bei dieser Migration mit einem SQL-Fehler fehl**,
sobald sie in der Kette erreicht wird (die Datei stammt vom 11.08., es gibt spätere
Migrationen, die vermutlich auf dem hier erzeugten Schema aufbauen). Dass dies nicht
in der CI auffällt, bestätigt die Checkliste selbst unter 11.2: „CI nutzt SQLite
in-memory, nicht PostgreSQL → PG-spezifische Migrationen ungetestet." Das
Quick-Start-Versprechen in der README (`docker compose up -d` → `php bin/console
doctrine:migrations:migrate` → fertig) ist damit **so nicht verifiziert** und mit hoher
Wahrscheinlichkeit auf frischer PostgreSQL-DB nicht lauffähig.

**Empfehlung:** siehe roadmap.md, P0-2.

### 2.3 🟠 P1 – PHPStan-Gate ist real (kein `\|\| true` mehr), aber inhaltlich stark entschärft

`phpstan.neon` enthält **80 Ignore-Regeln**. Ein Teil davon ist unproblematisch
(spezifische, punktuelle Pre-existing-Fehler). Ein anderer Teil ist jedoch **pauschal
klassenweit**, z. B.:

```
- '#Call to an undefined method App\\AI\\Security\\SecurityGuard::#'
- '#Call to an undefined method App\\AI\\Rag\\ContextInjector::#'
- '#Call to an undefined method App\\AI\\Skills\\ToolDefinitionGenerator::#'
```

Diese Regex-Muster unterdrücken **jeden** „Call to an undefined method"-Fehler für die
gesamte Klasse – nicht nur die zum Zeitpunkt der Baseline bekannten. Das heißt: Ruft
zukünftiger Code (oder ein LLM-generierter Patch) versehentlich eine nicht existierende
Methode auf `SecurityGuard` auf, **meldet PHPStan das nicht**, obwohl `SecurityGuard`
die zentrale Sicherheits-Policy-Klasse des gesamten Projekts ist. Damit ist das Gate
zwar formal „an" (kein `\|\| true`), aber für genau die Klassen, bei denen ein
übersehener Methodenfehler am teuersten wäre, faktisch entschärft. Die Datei selbst
vermerkt das als bekannten technischen Schuldposten:
```
# TODO: Ein echtes PHPStan-Baseline-File sollte generiert werden mit:
#   vendor/bin/phpstan analyse src --level=5 --generate-baseline=phpstan-baseline.neon
```
Das ist ehrlich dokumentiert, aber noch nicht umgesetzt.

### 2.4 🟠 P1 – MCP-Server-Startbefehle enthalten dieselben Programme, die als Executor-Typ verboten sind

`SecurityGuard::$allowedServices` enthält `npx`, `node`, `python`, `docker` (für den
Start lokaler MCP-Server via `McpServerFactory`), während dieselben Namen als
**Executor-Typ** für dynamisch generierte Tools über `$allowedExecutors`
(`api/database/filesystem/http/generic`) korrekt **nicht** zugelassen sind. Die beiden
Listen dienen unterschiedlichen Zwecken (MCP-Server-Startprozess vs. Tool-Executor)
und sind im Code sauber getrennt (`isServiceAllowed()` wird nur von `McpServerFactory`
konsumiert, nicht vom `decide()`-Pfad für dynamische Tools). Das Risiko ist real, aber
geringer als die Checkliste suggeriert: Es besteht dennoch die Frage, ob jemand mit
Kontrolle über `McpServerDefinition`-Konfiguration (z. B. via kompromittiertem
Admin-Account, `roles: ROLE_ADMIN` auf `/api/tools`) beliebige `npx`/`docker`-Befehle
mit beliebigen Argumenten starten lassen kann – dafür gibt es **keine Argument-
Validierung**, nur eine Whitelist des Programmnamens selbst.

### 2.5 🟡 P2 – Prompt-Injection-Schutz ist laut eigener Dokumentation unvollständig (bestätigt)

Die README benennt dies bereits transparent unter „Limitations": „Prompt Injection ist
nicht vollständig lösbar." Der Code bestätigt das: `ContextInjector` fügt RAG-Kontext als
`SystemMessage` ein, ohne Trust-Level-Markierung; es gibt keinen Sanitizer für
MCP-Tool-Ergebnisse, keinen Test gegen typische Prompt-Injection-Phrasen
(„ignore previous instructions" o. Ä.), keinen Schutz gegen adversarielle
Tool-Call-Sequenzen. Das ist bei aktuellem Stand der Technik branchenüblich, aber es
ist wichtig festzuhalten: **Die im README behauptete Zeile „Prompt Injection
Awareness ✅ RAG-Kontext kann Policy-Entscheidung nicht umgehen" ist nur für die
Tool-Ausführungs-Policy korrekt** (das stimmt, `SecurityGuard::decide()` ist
kontext-unabhängig) – sie sagt nichts darüber aus, ob der RAG-Kontext die *Antwort*
des LLM manipulieren kann, was weiterhin ungeschützt ist.

### 2.6 🟡 P2 – Command-Injection/Chaining in Tool-Argumenten ungeprüft

`SecurityGuard::decide()` prüft String-Argumente nur auf URL- und Pfad-Muster
(`looksLikeUrl`/`looksLikePath`). Shell-Metazeichen (`&&`, `;`, `|`, `` ` ``,
`$(...)`) in Argumenten, die an einen `GenericApiExecutor`/`GenericHttpExecutor`
weitergereicht werden, werden nicht erkannt oder blockiert. Solange diese Executoren
selbst keine Shell aufrufen (nicht verifizierbar ohne Laufzeittest, aber die
Executor-Namen deuten auf HTTP-Client- statt Shell-Nutzung hin), ist das Risiko
begrenzt – es ist aber eine Lücke gegenüber der in der README suggerierten
„Command Execution Protection ✅".

---

## 3. Weitere Beobachtungen (kein Sicherheitsrisiko, aber Qualitäts-/Vertrauensfragen)

### 3.1 Abhängigkeit von Pre-1.0-Paketen

`composer.json` bindet die komplette KI-Funktionalität an
`symfony/ai-agent`, `symfony/ai-bundle`, `symfony/ai-postgres-store` etc. in Version
`^0.12`. Symfony AI ist zum Prüfzeitpunkt **nicht 1.0**, d. h. Breaking Changes
zwischen Minor-Versionen sind laut SemVer-Konvention für 0.x-Pakete jederzeit möglich
und wahrscheinlich. Das ist eine bewusste, dokumentierte Entscheidung (README:
„Symfony AI native Agent/Toolbox/Platform/Store-Interfaces"), aber ein
Produktionsbetrieb auf einer Vor-1.0-API ist ein strukturelles Stabilitätsrisiko, das im
README nicht als Risiko benannt wird.

### 3.2 Sehr hohe Zahl an Vibe-Coding-Commits mit „fix"-Präfix in kurzer Zeit

Von den letzten 40 Commits sind über 30 `fix(...)`-Commits, viele davon innerhalb
weniger Stunden am 15./16.08.2026, mehrere davon beheben PHPStan-/Test-Fehler, die der
jeweils vorherige Commit selbst eingeführt hat (z. B. `2d01dcf` → `8a84cea` behebt einen
**ParseError**, den `2d01dcf` verursacht hatte). Autor ist konsequent `Vibe Code
<vibe@mistral.ai>`. Das deutet auf einen stark automatisierten, LLM-getriebenen
Entwicklungsprozess mit kurzen Iterationszyklen hin. Das ist per se nicht unseriös,
erklärt aber das Muster „Checkliste behauptet Fix X" vs. „Fix X ist bei genauerer
Prüfung nur teilweise wirksam" (siehe 2.1): Schnelle, LLM-generierte Fixes ohne
durchgängige End-to-End-Verifikation neigen dazu, den unmittelbar getesteten
Codepfad zu reparieren, ohne alle Aufrufer/Integrationsstellen zu prüfen.

### 3.3 Tote/inkonsistente Codepfade

- `StoreRetrieverAdapter` (siehe 2.1) – registriert, dokumentiert, aber unbenutzt.
- `EmbeddingRepository::findSimilar()` Signatur-Mismatch (siehe 2.1) – bekannt, ignoriert statt behoben.
- Zeile 60 in `AgentDialogController::dialog()` liest weiterhin
  `user_identifier` aus dem Request-Body in ein `$payload`-Array, das danach nie
  für den Identifier verwendet wird (der tatsächliche Identifier kommt separat aus
  `$this->getUser()`). Funktional harmlos, aber irreführend beim Lesen des Codes.
- `docs/PRODUCTION_READINESS_CHECKLIST.md` selbst verweist auf weitere tote Referenzen
  auf `DynamicSkillRegistry` in `PendingToolApprovalListener.php` und
  `config/prompts/tool_schema_optimizer.txt` (Altlasten aus einer früheren
  Architekturrevision) – dies wurde stichprobenartig bestätigt.

### 3.4 Testabdeckung: real, aber kleiner als der Blueprint suggeriert

35 Testdateien über `tests/Unit`, `tests/Integration`, `tests/Functional`, `tests/E2E`
(inkl. eigener `E2E Smoke Tests`-Suite) – das ist eine ernsthafte, mehrschichtige
Teststruktur, kein Feigenblatt. Die Checkliste weist aber selbst korrekt darauf hin,
dass „Integration Tests" nur 2 Dateien umfassen und größtenteils mock-basiert sind
(keine echte DB-/LLM-Integration außerhalb des separat abgesicherten `E2E LLM Tests`-
Jobs, der nur mit gesetzten Secrets läuft und `continue-on-error: true` hat, also den
Hauptworkflow nicht blockieren kann). Ein vollständiger „Golden Path"-E2E-Test
(User-Anfrage → Tool fehlt → Generierung → Validierung → Policy → HITL → Freigabe →
Ausführung → Ergebnis → Audit, alles gegen echte Infrastruktur) existiert nach
Durchsicht der `tests/E2E/Smoke/EvieSmokeTest.php` **nicht als ein einziger
durchgängiger Testfall**, sondern als mehrere Einzelprüfungen der Security-Gates.

### 3.5 Datenschutz/DSGVO – README erwähnt es nicht, Code liefert nichts dazu

Es gibt keinen Lösch-/Export-Mechanismus für personenbezogene Daten, keine
Retention-Policy für Audit-Logs, keine Datenschutzerklärung im Repo. Für ein Produkt,
das laut README für „Vision Gastro / AiCabs" gedacht ist und personenbezogene Nutzer-
und Konversationsdaten sowie IP-Adressen im Audit-Log (`AuditLog`) speichert, ist das
vor echtem Produktivbetrieb in der EU ein Muss-Thema, das aktuell komplett fehlt.

---

## 4. Bewertung der Kernfrage: „Hält es, was es verspricht?"

| Frage | Antwort |
|---|---|
| Ist die Architektur so gebaut wie im Blueprint beschrieben? | **Größtenteils ja.** Native Symfony-AI-Nutzung, HITL, SecurityGuard, DynamicToolbox sind real und sauber implementiert. |
| Ist es sicher gegen die im README genannten Angriffsvektoren? | **Teilweise.** SSRF- und Traversal-Schutz sind gut gehärtet und verifiziert. Tenant-Isolation ist für Tool-Definitionen echt, für RAG-Kontext **nachweislich nicht wirksam** (2.1) – ein Kern-Sicherheitsversprechen wird gebrochen. |
| Ist „100 % abgetestet, wie beschrieben funktionsfähig"? | **Nein.** Es gibt eine ernsthafte, aber nicht lückenlose Testsuite; mindestens zwei P0-Bugs (RAG-Tenant-Leak, MySQL-Migration auf Postgres-Projekt) sind im Code nachweisbar und würden von der aktuellen CI (SQLite statt Postgres, keine RAG-Tenant-Tests) nicht erkannt. |
| Ist das Projekt production-ready? | **Nein**, und das sagt die im Repo enthaltene eigene Checkliste auch klar: „EVIE ist NICHT production-ready." Dieses Audit bestätigt diese Selbsteinschätzung im Kern und ergänzt zwei zusätzliche, bisher nicht dokumentierte P0-Funde (2.1, 2.2). |

---

## 5. Methodische Grenzen dieses Audits

- Kein `composer install` möglich → keine echten PHPUnit-/PHPStan-/`composer audit`-Läufe.
- Keine Laufzeitprüfung gegen eine echte PostgreSQL-Instanz, keine echten LLM-Aufrufe.
- Keine automatisierte SCA (Software Composition Analysis) der ca. 400 KB
  `composer.lock`-Abhängigkeiten auf bekannte CVEs möglich (kein Zugriff auf
  Advisory-Datenbanken aus der Sandbox).
- Die Bewertung stützt sich auf Lesen und Nachvollziehen von Aufrufketten; bei sehr
  dynamischem Code (Reflection, DI-Autowiring über Attribute) kann in Einzelfällen ein
  tatsächlicher Laufzeitpfad von der statischen Analyse abweichen. Wo dies relevant war
  (z. B. `#[AsInputProcessor]`, `#[AsEventListener]`), wurde geprüft, dass das jeweilige
  Attribut tatsächlich gesetzt ist.

Alle offenen Punkte, Prioritäten und konkreten Fix-Vorschläge: siehe **`roadmap.md`**.
