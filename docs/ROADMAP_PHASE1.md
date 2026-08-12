# 🚀 EVIE Phase 1 Implementierungsplan: Kritische Lücken schließen

**Erstellt am:** 12. August 2026  
**Repository:** [Jens-Smit/EVIE](https://github.com/Jens-Smit/EVIE)  
**Referenz:** [EVIE_ANALYSE.md](EVIE_ANALYSE.md)  
**Ziel:** Behebung der **kritischen Sicherheits- und Ausführbarkeitslücken** gemäss Symfony AI Bundle Best Practices.

---

## 📋 Übersicht Phase 1

| **Maßnahme** | **Priorität** | **Aufwand** | **Impact** | **Status** |
|--------------|--------------|-------------|------------|------------|
| **1. SecurityGuard mit Whitelist erweitern** | 🔴 **Kritisch** | 1 Tag | 🔴 **Kritisch** (Sicherheitsrisiko) | ⏳ **Geplant** |
| **2. DynamicSkillRegistry mit CompilerPass erweitern** | 🔴 **Kritisch** | 2-3 Tage | 🔴 **Kritisch** (Tools nicht ausführbar) | ⏳ **Geplant** |
| **3. Unit-Tests für kritische Komponenten erstellen** | 🔴 **Kritisch** | 3-5 Tage | 🔴 **Kritisch** (Keine Validierung) | ⏳ **Geplant** |

**Gesamtaufwand Phase 1:** **6-9 Arbeitstage**  
**Ziel:** EVIE auf **~95% Blueprint-Konformität** bringen und **Sicherheitsrisiken eliminieren**.

---

## 📚 Referenz: Symfony AI Bundle Best Practices

### Tool-Registrierung (3 Methoden)
Laut [Symfony AI Bundle Docs](https://symfony.com/doc/current/ai/bundles/ai-bundle.html#register-tools):
- **Attribut-basiert (`#[AsTool]`)** - Automatische Registrierung
- **Service-Konfiguration in `ai.yaml`** - Flexible Konfiguration
- **CompilerPass für dynamische Registrierung** - Symfony-Standard

---

## 🔧 Maßnahme 1: SecurityGuard mit Whitelist erweitern

### Problem
- SecurityGuard ohne Whitelist-Konfiguration
- Risiko: Unsichere API-Aufrufe durch dynamische Tools

### Lösung
1. **Whitelist-Konfiguration in `services.yaml`**
2. **SecurityGuard mit Whitelist-Prüfung erweitern**
3. **Integration in HitlInterceptor**

### Code-Beispiele
- Whitelist-Parameter in `services.yaml`
- `isServiceAllowed()` Methode
- `assertToolAllowed()` für Exception-Handling
- Integration in HitlInterceptor

### Tests
- Unit-Tests für SecurityGuard (100% Coverage)

---

## 🔧 Maßnahme 2: DynamicSkillRegistry mit CompilerPass erweitern

### Problem
- JSON-Schemata werden nicht in ToolInterface umgewandelt
- Tools aus DB sind nicht ausführbar

### Lösung
1. **DynamicTool-Klasse mit `#[AsTool]`**
2. **DynamicToolFactory für Tool-Erstellung**
3. **AiDynamicToolsCompilerPass für Compile-Time-Registrierung**
4. **Lazy-Loading-Alternative für Laufzeit-Registrierung**

### Code-Beispiele
- DynamicTool-Klasse
- DynamicToolFactory
- CompilerPass-Implementierung
- Cache-Warmup-Command

### Tests
- Unit-Tests für DynamicToolFactory
- Integrationstests für DynamicSkillRegistry

---

## 🔧 Maßnahme 3: Unit-Tests für kritische Komponenten

### Problem
- Keine Tests für SecurityGuard, HitlInterceptor, DynamicSkillRegistry
- Keine Validierung der Sicherheitsmechanismen

### Lösung
1. **PHPUnit-Konfiguration aktualisieren**
2. **Test-Bootstrap erstellen**
3. **Test-Klassen für alle kritischen Komponenten**

### Test-Cases
- SecurityGuardTest
- HitlInterceptorTest
- DynamicToolFactoryTest
- DynamicSkillRegistryIntegrationTest

---

## 📅 Zeitplan Phase 1

### Tag 1: SecurityGuard
- Whitelist-Konfiguration
- SecurityGuard erweitern
- Integration in HitlInterceptor
- Unit-Tests erstellen

### Tag 2-3: DynamicSkillRegistry
- DynamicTool & DynamicToolFactory
- CompilerPass implementieren
- Lazy-Loading-Alternative
- Integrationstests

### Tag 4-5: Unit-Tests
- PHPUnit-Konfiguration
- Test-Bootstrap
- Alle Test-Klassen erstellen
- Tests ausführen und debuggen

---

## ✅ Abnahmekriterien

### SecurityGuard
- [ ] Whitelist-Prüfung implementiert
- [ ] Integration in HitlInterceptor
- [ ] Unit-Tests (100% Coverage)

### DynamicSkillRegistry
- [ ] CompilerPass implementiert
- [ ] DynamicTool-Klasse
- [ ] Lazy-Loading-Alternative
- [ ] Integrationstests

### Testing
- [ ] PHPUnit-Konfiguration
- [ ] Alle Tests bestehen

---

## 🔗 Referenzen
- [Symfony AI Bundle Docs](https://symfony.com/doc/current/ai/bundles/ai-bundle.html)
- [EVIE_ANALYSE.md](EVIE_ANALYSE.md)
- [blueprint.md](blueprint.md)