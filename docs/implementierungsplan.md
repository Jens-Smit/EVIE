# EVIE - Implementierungsplan zur Produktionsreife

**Stand:** 13. August 2026  
**Basierend auf:** Architektur-Audit vom Commit f8c5f9c  
**Ziel:** Selbst-evolvierende Agent-Plattform nach Blueprint

---

## 📋 Zusammenfassung & Ausrichtung

**Aktueller Status:**
- EVIE ist **lauffähig** und besitzt bereits ~60% der Blueprint-Ziele
- Kernarchitektur (Symfony, Mistral, Orchestrator, Sub-Agenten) steht
- **Kritische Lücken:** Evolution-Loop nicht geschlossen, Security unzureichend, CI kaputt, RAG fehlt

**Priorität:**
1. **Stabilisierung** (CI, Grundlagen) – *Blocker für alles andere*
2. **Kern-Evolution-Engine** (Tool-Definition → Executor → Ergebnis) – *Herzstück*
3. **Security & Produktionsvorbereitung** – *ohne Auth kein Production*
4. **Echtes RAG** – *für intelligente Kontextnutzung*
5. **Validierung & Produktionsreife** – *End-to-End-Tests, Optimierung*

**Prinzipien:**
- **Kein PHP-Code-Generierung durch Agenten** (Blueprint-Invariante)
- **Executor-Abstraktion:** Tool-Definition ↔ Execution Contract ↔ Trusted Executor
- **Test-first:** Jede Phase wird mit Tests abgesichert

---

## 🎯 Phasenplan

---

### **Phase 1: Stabilisierung & Grundlagen** ⚡
**Dauer:** 2–4 Wochen  
**Ziel:** Repo wieder in funktionsfähigen Zustand bringen  
**Priorität:** 🔴 **Blocker – muss zuerst erledigt werden**

| Aufgabe | Beschreibung | Erfolgskriterium | Aufwand | Verantwortlich |
|---------|--------------|------------------|---------|----------------|
| **1.1 CI/CD reparieren** | GitHub Actions Workflow analysieren und beheben | CI-Pipeline läuft grün durch | 2–3 Tage | DevOps |
| **1.2 `bin/console` wiederherstellen** | Fehlende Symfony-Console zurück in Repo | `bin/console` existiert und ist funktionsfähig | 1 Tag | Backend |
| **1.3 `.env.dist` erstellen** | Standard-Umgebungsvariablen für Entwicklung | `.env.dist` im Repo, `.env` in `.gitignore` | 1 Tag | Backend |
| **1.4 Datenbank vereinheitlichen** | PostgreSQL als primäre DB für **alle** Umgebungen (Dev, Test, CI) | `composer.json`, `ci.yml`, `docker-compose.yml` nutzen PostgreSQL | 2–3 Tage | Backend |
| **1.5 Tests grün bekommen** | Alle bestehenden Tests reparieren | `phpunit`, `phpstan` laufen fehlerfrei | 3–5 Tage | QA/Backend |
| **1.6 Docker-Umgebung anpassen** | MCP-Services über Docker-Netzwerk erreichbar machen | `http://filesystem-mcp:8123` statt `127.0.0.1` | 1 Tag | DevOps |

**Deliverables:**
- ✅ Grüne CI-Pipeline
- ✅ Funktionsfähiges `bin/console`
- ✅ `.env.dist` im Repo
- ✅ PostgreSQL als Standard-DB
- ✅ Alle Tests passieren

---

### **Phase 2: Kern-Evolution-Engine** 🔄
**Dauer:** 4–6 Wochen  
**Ziel:** Geschlossenen Evolution-Loop herstellen (Tool-Definition → Execution → Ergebnis)  
**Priorität:** 🟡 **Kritisch – ohne diesen Loop kein selbst-evolvierender Agent**

| Aufgabe | Beschreibung | Erfolgskriterium | Aufwand | Abhängigkeiten |
|---------|--------------|------------------|---------|----------------|
| **2.1 ToolDefinition erweitern** | Schema um `executor_type`, `executor_config`, `security_policy`, `hitl_policy`, `version` ergänzen | Neue Felder in DB und Entity | 3–5 Tage | 1.1–1.5 |
| **2.2 DynamicToolFactory vereinfachen** | Verantwortlichkeiten aufteilen: `SubAgentDefinitionLoader`, `SubAgentToolFactory`, `SubAgentRegistry`, `SubAgentPromptResolver` | Keine God-Service-Logik mehr | 5–7 Tage | 2.1 |
| **2.3 Executor von Tool-Namen entkoppeln** | Keine `if (contains "excel")`-Logik mehr. Stattdessen: `ExecutorResolver` basierend auf `executor_type` | `GenericApiExecutor`, `GenericFileExecutor`, etc. | 5–7 Tage | 2.1 |
| **2.4 Schema-Typ-Konflikt beheben** | Trennung von JSON Schema (`type: object`) und EVIE Execution Metadata (`x-evie.executor: api`) | Valides JSON Schema + separate Metadaten | 2–3 Tage | 2.1 |
| **2.5 Hardcoded Demo-Ergebnisse entfernen** | Alle Mock-Implementierungen (z. B. `executeExcelAnalysis()`) durch echte Executor-Aufrufe ersetzen | Keine festen Ergebnisse mehr | 3–5 Tage | 2.3 |
| **2.6 Fehlerbehandlung verbessern** | `ToolRegistrationResult`, `ToolRegistrationException`, structured logging, metrics, audit events | Keine stillen Fehler mehr | 2–3 Tage | 2.2 |
| **2.7 Evolution E2E-Test erstellen** | **Golden Path Test:** User Request → No Tool → Tool Generator → Pending → HITL → Approve → Registry → Executor → Ergebnis | Test läuft grün | 5–7 Tage | 2.1–2.6 |

**Deliverables:**
- ✅ Geschlossener Evolution-Loop (Tool-Definition → Execution → Ergebnis)
- ✅ Saubere Trennung von Schema und Execution-Metadaten
- ✅ Keine Mock-Implementierungen mehr
- ✅ Robuste Fehlerbehandlung
- ✅ **Golden Path Test** als Beweis der Kernfunktionalität

---

### **Phase 3: Security & Produktionsvorbereitung** 🔒
**Dauer:** 3–5 Wochen  
**Ziel:** Produktionssichere Security-Infrastruktur  
**Priorität:** 🟡 **Kritisch – ohne Security kein Production**

| Aufgabe | Beschreibung | Erfolgskriterium | Aufwand | Abhängigkeiten |
|---------|--------------|------------------|---------|----------------|
| **3.1 Authentifizierung implementieren** | Echte User-Authentifizierung (z. B. OAuth2, JWT) | `/login`, `/logout`, Session-Management | 5–7 Tage | 1.1–1.5 |
| **3.2 Autorisierung & Berechtigungen** | Rollenbasierte Zugriffskontrolle (Admin, User, Guest) | `access_control` in `security.yaml` | 3–5 Tage | 3.1 |
| **3.3 SecurityGuard verbessern** | Ersetze Wildcards durch explizite Executor-IDs | Keine Wildcard-Allowlists mehr | 2–3 Tage | 2.3 |
| **3.4 OutboundRequestPolicy** | Echte SSRF-Abwehr: DNS-Auflösung, IP-Range-Prüfung, Redirect-Prüfung, Port-Allowlist | Keine String-Blocklists mehr | 3–5 Tage | 3.1 |
| **3.5 HITL-Resume-Workflow vervollständigen** | Robuster Workflow: blocked execution → approval → resume original agent task | HITL ist Workflow-Engine | 5–7 Tage | 2.7 |
| **3.6 Audit Trail implementieren** | Logging aller kritischen Aktionen | Vollständige Audit-Logs | 3–5 Tage | 3.1 |
| **3.7 API-Endpunkte sichern** | `/api/tools/*` nur für authentifizierte User | Keine öffentlichen Endpunkte mehr | 2–3 Tage | 3.2 |

**Deliverables:**
- ✅ Authentifizierung & Autorisierung
- ✅ Keine Wildcard-Allowlists mehr
- ✅ Echte SSRF-Abwehr
- ✅ Vollständiger HITL-Resume-Workflow
- ✅ Audit Trail für alle kritischen Aktionen
- ✅ Geschützte API-Endpunkte

---

### **Phase 4: Echtes RAG (Retrieval-Augmented Generation)** 🧠
**Dauer:** 3–4 Wochen  
**Ziel:** Intelligente Kontextnutzung durch semantische Suche  
**Priorität:** 🟡 **Wichtig – für intelligente Agenten unabdingbar**

| Aufgabe | Beschreibung | Erfolgskriterium | Aufwand | Abhängigkeiten |
|---------|--------------|------------------|---------|----------------|
| **4.1 pgvector einrichten** | PostgreSQL-Erweiterung für Vektorähnlichkeitssuche | `pgvector` in DB verfügbar | 1–2 Tage | 1.4 |
| **4.2 Embedding-Pipeline** | Integriere Embedding-Modell (z. B. sentence-transformers, Mistral Embeddings) | Embeddings für User Profile, Conversation Memory, Tool Memory, Knowledge | 5–7 Tage | 4.1 |
| **4.3 Vector Store implementieren** | Speichere Embeddings in PostgreSQL mit pgvector | Vektoren in DB, Index für schnelle Suche | 3–5 Tage | 4.2 |
| **4.4 Retriever integrieren** | Implementiere query embedding → vector similarity search → top-k relevant memories | Retriever liefert relevante Kontexte | 5–7 Tage | 4.3 |
| **4.5 Kontextinjektion in Prompts** | Integriere Retriever-Ergebnisse in System Prompts | Dynamischer Kontext in Orchestrator | 3–5 Tage | 4.4 |
| **4.6 Onboarding mit RAG verbinden** | Nutze RAG für personalisierte Onboarding-Flows | Onboarding nutzt semantische Suche | 2–3 Tage | 4.5 |

**Deliverables:**
- ✅ pgvector in PostgreSQL
- ✅ Embedding-Pipeline für alle Kontexttypen
- ✅ Vector Store mit schneller Suche
- ✅ Retriever integriert in Orchestrator
- ✅ Dynamische Kontextinjektion in Prompts

---

### **Phase 5: Validierung & Produktionsreife** ✅
**Dauer:** 2–3 Wochen  
**Ziel:** EVIE produktionsfertig machen  
**Priorität:** 🟢 **Finalisierung**

| Aufgabe | Beschreibung | Erfolgskriterium | Aufwand | Abhängigkeiten |
|---------|--------------|------------------|---------|----------------|
| **5.1 End-to-End-Tests erweitern** | Test aller Blueprint-Pfade | >90% Testabdeckung | 5–7 Tage | 2.7, 3.1–3.7, 4.1–4.6 |
| **5.2 Performance-Optimierung** | Latenzzeiten optimieren | Antwortzeiten < 2s für 90% der Anfragen | 3–5 Tage | 5.1 |
| **5.3 Dokumentation finalisieren** | API-Dokumentation, Architektur-Dokumentation, Deployment-Guide | Vollständige Docs in `/docs` | 3–5 Tage | Alle |
| **5.4 Deployment-Pipeline** | Automatisiertes Deployment | Ein-Klick-Deployment | 3–5 Tage | 1.1–1.6, 3.1–3.7 |
| **5.5 Monitoring & Observability** | Logging, Metriken, Alerts | Echtzeit-Monitoring aller Komponenten | 3–5 Tage | 5.1 |
| **5.6 Sicherheitsaudit** | Externes Security-Review | Keine kritischen Sicherheitslücken | 5–7 Tage | 3.1–3.7 |
| **5.7 Blue/Green Deployment** | Zero-Downtime-Deployment | Keine Ausfallzeiten bei Updates | 2–3 Tage | 5.4 |

**Deliverables:**
- ✅ >90% Testabdeckung
- ✅ Antwortzeiten < 2s für 90% der Anfragen
- ✅ Vollständige Dokumentation
- ✅ Automatisiertes Deployment
- ✅ Echtzeit-Monitoring
- ✅ Keine kritischen Sicherheitslücken
- ✅ **Produktionsreife**

---

## 📊 Zeitplan & Meilensteine

| Phase | Dauer | Start | Ende | Meilenstein |
|-------|-------|-------|-----|-------------|
| **Phase 1** | 2–4 Wochen | 14.08.2026 | 04.09.2026 | ✅ Repo stabil & funktionsfähig |
| **Phase 2** | 4–6 Wochen | 05.09.2026 | 16.10.2026 | ✅ Evolution-Loop geschlossen |
| **Phase 3** | 3–5 Wochen | 17.10.2026 | 13.11.2026 | ✅ Security produktionssicher |
| **Phase 4** | 3–4 Wochen | 14.11.2026 | 11.12.2026 | ✅ Echtes RAG implementiert |
| **Phase 5** | 2–3 Wochen | 12.12.2026 | 01.01.2027 | ✅ **Produktionsreife** |

**Gesamt:** ~14–22 Wochen (3,5–5,5 Monate)

---

## 🎯 Erfolgskriterien pro Phase

| Phase | Erfolgskriterium |
|-------|------------------|
| **Phase 1** | CI läuft grün, `bin/console` existiert, `.env.dist` im Repo, PostgreSQL als Standard-DB, alle Tests passieren |
| **Phase 2** | Golden Path Test läuft grün, kein Mock-Code mehr, saubere Executor-Abstraktion |
| **Phase 3** | Authentifizierung/Autorisierung, keine Wildcard-Allowlists, vollständiger HITL-Workflow, Audit Trail |
| **Phase 4** | pgvector, Embedding-Pipeline, Vector Store, Retriever, dynamische Kontextinjektion |
| **Phase 5** | >90% Testabdeckung, <2s Antwortzeit, vollständige Docs, automatisiertes Deployment, Monitoring, Security-Audit |

---

## 🚀 Nächste Schritte

1. **Phase 1 sofort starten** (CI, `bin/console`, `.env.dist`, PostgreSQL, Tests)
2. **Tägliches Standup** zur Priorisierung der Aufgaben
3. **Wöchentliche Reviews** der Fortschritte
4. **Meilenstein-Checks** am Ende jeder Phase

---

## 📌 Wichtige Hinweise

- **Kein PHP-Code durch Agenten:** Diese Invariante muss unter allen Umständen eingehalten werden.
- **Executor-Abstraktion:** Tool-Definition ↔ Execution Contract ↔ Trusted Executor
- **Test-first:** Jede neue Funktion muss mit Tests abgesichert werden.
- **Security-first:** Security-Maßnahmen haben höchste Priorität vor neuen Features.
- **Dokumentation:** Alle Änderungen müssen in `/docs` festgehalten werden.

---

## 🔗 Verweise
- [Architektur-Audit](https://github.com/Jens-Smit/EVIE/commit/f8c5f9c661958545b4427ab43ffac01cbc47153b)
- [Blueprint](blueprint.md)
- [GitHub Repository](https://github.com/Jens-Smit/EVIE)

---

**Erstellt am:** 13. August 2026  
**Version:** 1.0  
**Autor:** Jens Smit (basierend auf Architektur-Audit)