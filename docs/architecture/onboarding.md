# Onboarding-Flow: Phasen A–F mit Strategie, Capability-Aufbau und HITL

> Status: **Implementiert** · Blueprint-konform · Symfony AI v0.12-kompatibel ·
> deterministische Schritt-Engine, LLM nur als Vorschlagsdienst · keine Mocks,
> keine Fantasie-Objekte zur Laufzeit.

## Ablaufdiagramm

```text
Phase A  LLM-Grundausstattung (unveraendert)
   llm_provider -> llm_model -> llm_api_key (Secret, Live-Validierung)
        |
Phase B  Aufgabe & Brainstorming
   mission_statement (freeform_dialog, Pflicht)
   goal (optional, Choice-Chips als Vorschlag)
   Profil-Verzweigung: industry -> business_areas -> email_account_{area}
   use_cases (assist_work) / goal_detail (other)
   [Chat-Panel: POST /onboarding/chat, ingestExtracted()]
        |
Phase C  Strategie-Entwicklung
   strategy_review: OnboardingStrategyService entwirft aus
   mission_statement + Profil (LLM + Heuristik-Fallback),
   Nutzer bestaetigt/korrigiert ->
   Persistenz: initiale aktive AgentGoal (source: onboarding)
        |
Phase D  Capability-Aufbau
   sub_agent_review: nur Rollen aus dem Sub-Agent-Katalog (ai.yaml)
                     -> SubAgentDefinition (isActive=true)
   tool_review: Capability-Luecken -> ToolDefinitionGenerator
                -> ToolDefinition (status=pending, requiresHitl=true,
                executorConfig.requiredSecrets)
        |
Phase E  Credentials
   tool_secret_{toolId}_{key}: Scope tool:{toolDefinitionId}
   email_combined: Scope email:{area}
   Fallback: IntegrationRequirementMapper ohne Tool-Anlage
        |
Phase F  Abschluss
   summary + Readiness-Checkliste (OnboardingReadinessChecker)
   Button "Onboarding abschliessen": jederzeit (Sticky-Footer),
   422 bei fehlenden Pflichtangaben, Skip nur mit force=true
        |
   Nach Abschluss: pending ToolDefinitions erscheinen in der
   Tool-Freigabe-UI (/tools/pending) -> approve -> DynamicToolbox
```

## Beteiligte Komponenten

| Komponente | Datei | Rolle |
|---|---|---|
| OnboardingFlowManager | `src/AI/Onboarding/OnboardingFlowManager.php` | Phasen-Engine, Kontext, Side-Effects, `chat()`, `ingestExtracted()`, `getReadiness()` |
| OnboardingStepProvider | `src/AI/Onboarding/OnboardingStepProvider.php` | Deterministische Schrittliste Phasen A–F |
| OnboardingStrategyService | `src/AI/Onboarding/OnboardingStrategyService.php` | Strategie-Entwurf (LLM + Heuristik), AgentGoal-Persistenz, Katalog-Sub-Agent-Anlage |
| OnboardingReadinessChecker | `src/AI/Onboarding/OnboardingReadinessChecker.php` | Konsistenzprüfung vor Abschluss |
| IntegrationRequirementMapper | `src/AI/Onboarding/IntegrationRequirementMapper.php` | Fallback für Credentials ohne Tool-Anlage |
| ToolDefinitionGenerator | `src/AI/Skills/ToolDefinitionGenerator.php` | Wiederverwendet aus Pipeline-Phase 4: Tool-Schemas, status=pending |
| ToolApprovalController | `src/Controller/ToolApprovalController.php` | HITL-Freigabe: approve/reject + `PendingToolApprovalEvent` |
| OnboardingController | `src/Controller/Frontend/OnboardingController.php` | `/onboarding`, `/start`, `/next`, `/chat`, `/complete`, `/status` |
| Onboarding-UI | `templates/onboarding/index.html.twig` | Alpine.js-Wizard + Chat-Panel + Sticky-Abschluss-Button |

## Chat im Onboarding (G1/G6)

- `POST /onboarding/chat` ruft `OnboardingFlowManager::chat()`: der Onboarding-Agent
  (`ai.agent.onboarding`) erhält den `onboarding_data`-Kontext als System-Prompt und
  antwortet im Dialog; parallel extrahiert das LLM strukturiert `goal`, `industry`,
  `business_areas`, `use_cases`, `mission_statement`.
- `ingestExtracted()` schreibt nur bekannte Felder in den Onboarding-Kontext –
  ohne einen Wizard-Schritt zu konsumieren. Der deterministische Schritt-Engine-Kern
  bleibt unangetastet; das LLM liefert nur Daten, die der Nutzer sieht und bestätigt.
- Tenant-Identifier ausschließlich aus dem authentifizierten User (P0-5).

## Strategie (Phase C)

- `OnboardingStrategyService::draftStrategy()` baut einen heuristischen Entwurf
  (Ziel, Teilschritte, Erfolgsmetrik, Capability-Deskriptoren, Sub-Agent-Empfehlung)
  und fragt den Onboarding-Agenten nach einem strukturierten JSON-Vorschlag.
  LLM-Antworten werden strikt validiert: nur Katalog-Sub-Agenten und bekannte
  Struktur-Felder übernommen; sonst greift die Heuristik (keine Halluzination).
- Bestätigung im `strategy_review`-Schritt persistiert über
  `persistStrategy()` eine initiale `AgentGoal` (status `active`,
  `capabilityConstraints.source = onboarding`) und legt den Entwurf in
  `onboarding_data['strategy']` ab.

## Sub-Agents & Tools (Phase D)

- Sub-Agents werden nie erfunden: `ensureSubAgents()` instanziiert nur Rollen aus
  `OnboardingStrategyService::SUB_AGENT_CATALOG` (identisch zur statischen
  `ai.yaml`-Registrierung) als `SubAgentDefinition`-Einträge (`isActive=true`),
  die die `SubAgentFactory` als Subagent-Tool in die Orchestrator-Toolbox nimmt.
- Capability-Lücken ohne deckenden Katalog-Sub-Agenten laufen über den
  bestehenden `ToolDefinitionGenerator`-Pfad (identisch zu Pipeline-Phase 4):
  `ToolDefinition` mit `status=pending`, `requiresHitl=true`,
  `executorConfig.requiredSecrets` (keine DB-Migration nötig) plus
  `PendingToolApprovalEvent`.

## Credentials (Phase E)

- Tool-getrieben: pro `ToolDefinition` mit `requiredSecrets` erzeugt der
  StepProvider `tool_secret_*`-Schritte; `applyStepSideEffects()` speichert die
  Secrets über den `SecretService` mit Scope `tool:{toolDefinitionId}`.
- E-Mail-Fähigkeiten nutzen die bestehende `email_combined`-Maske
  (Scope `email:{area}`).
- Ohne Tool-Anlage greift der `IntegrationRequirementMapper` als Fallback.

## Abschluss & HITL-Übergang (Phase F)

- `OnboardingReadinessChecker` prüft blockierende Pflichtangaben (LLM-Provider,
  -Modell, -API-Key, mission_statement, bestätigte Strategie); Sub-Agenten/Tools
  sind nicht-blockierend.
- `POST /onboarding/complete` liefert bei fehlenden Pflichtangaben HTTP 422 mit
  Checkliste; ein harter Skip ist nur mit explizitem `force: true` möglich.
- Die im Onboarding erzeugten `pending` ToolDefinitions erscheinen nach dem
  Abschluss in der bestehenden Tool-Freigabe-UI (`/tools/pending`). Erst die
  Freigabe im Frontend (`approve`) schaltet sie über die native `DynamicToolbox`
  für den Orchestrator frei (Blueprint §4.B/§5) – derselbe Pfad wie für
  Pipeline-Tools.

## Tests

- Unit: `tests/Unit/AI/Onboarding/OnboardingFlowManagerTest.php` (Flow inkl.
  Strategie-/Review-Schritte, `ingestExtracted`, Readiness),
  `OnboardingStepProviderTest.php` (neue Schritttypen, Phasen-Reihenfolge,
  Credential-Präferenz), `OnboardingStrategyServiceTest.php`
  (Strategie-Entwurf mit StubAgent, Katalog-Validierung, Persistenz).
- E2E: `tests/E2E/OnboardingSettingsTest.php` (Readiness-Gate 422, force-Skip,
  Complete-Flag).
