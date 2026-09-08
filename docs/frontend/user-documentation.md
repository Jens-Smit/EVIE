# EVIE Frontend — User-Dokumentation

**Stand:** September 2026
**Repository:** [Jens-Smit/EVIE](https://github.com/Jens-Smit/EVIE)

Diese Dokumentation beschreibt jede Frontend-Seite des EVIE-Agenten-Systems
mit Route, Bedienelementen und typischem Nutzerablauf. Die Playwright-Tests
unter `tests/Playwright/` spielen diese Ablaufe wie ein echter Nutzer durch.

---

## Uebersicht: Alle Frontend-Seiten

| Seite | Route | Auth | Template |
|-------|-------|------|----------|
| Startseite | `/` | oeffentlich | `home/index.html.twig` |
| Login | `/login` | oeffentlich | `security/login.html.twig` |
| Registrierung | `/register` | oeffentlich | `security/register.html.twig` |
| Passwort vergessen | `/forgot-password` | oeffentlich | `security/forgot_password.html.twig` |
| Passwort zuruecksetzen | `/reset-password` | oeffentlich | `security/reset_password.html.twig` |
| Dashboard | `/dashboard` | ROLE_USER | `dashboard/index.html.twig` |
| Agent-Chat | `/dialog` | ROLE_USER | `agent/dialog.html.twig` |
| Verlauf | `/history` | ROLE_USER | `agent/history.html.twig` |
| Sub-Agenten | `/subagents` | ROLE_USER | `subagents/index.html.twig` |
| Freigaben (HITL) | `/tools/pending` | ROLE_USER | `tools/pending.html.twig` |
| Faehigkeiten | `/tools/list` | ROLE_USER | `tools/list.html.twig` |
| Dokumente | `/documents` | ROLE_USER | `documents/index.html.twig` |
| Streaming-Sessions | `/streaming/sessions` | ROLE_USER | `streaming/index.html.twig` |
| Agent-Ziele | `/agent/goals` | ROLE_USER | `agent/goals.html.twig` |
| Strategie | `/strategy` | ROLE_USER | `strategy/index.html.twig` |
| Onboarding | `/onboarding` | ROLE_USER | `onboarding/index.html.twig` |
| Einstellungen | `/settings` | ROLE_USER | `settings/index.html.twig` |
| Quota | `/settings/quota` | ROLE_USER | `settings/quota.html.twig` |
| Secrets | `/settings/secrets` | ROLE_USER | `settings/secrets.html.twig` |
| Datenschutz | `/datenschutz` | oeffentlich | `legal/datenschutz.html.twig` |
| Impressum | `/impressum` | oeffentlich | `legal/impressum.html.twig` |

---

## HTMX-Endpoints (interaktiv)

| Endpoint | Methode | Beschreibung |
|----------|---------|--------------|
| `/htmx/tools/execute` | POST | Tool ausfuehren |
| `/htmx/tools/form` | GET | Tool-Formular anzeigen |
| `/htmx/subagents/delegate` | POST | Sub-Agent delegieren |
| `/htmx/mcp/tools/execute` | POST | MCP-Tool ausfuehren |
| `/htmx/mcp/servers/list` | GET | MCP-Server auflisten |

---

## API-Endpoints

| Endpoint | Methode | Beschreibung | Auth |
|----------|---------|--------------|------|
| `/api/agent/dialog` | POST | Nachricht an Agent | ROLE_USER |
| `/api/agent/history/{id}` | GET | Dialogverlauf | ROLE_USER |
| `/api/tools/{id}/approve` | POST | Tool freigeben (HITL) | ROLE_ADMIN |
| `/api/tools/{id}/reject` | POST | Tool ablehnen | ROLE_ADMIN |
| `/api/pending-tools/count` | GET | Anstehende Tools zaehlen | ROLE_USER |
| `/api/documents` | GET | Dokumente auflisten | ROLE_USER |
| `/api/documents/upload` | POST | Dokument hochladen | ROLE_USER |
| `/api/documents/{id}` | DELETE | Dokument loeschen | ROLE_USER |
| `/api/streaming/sessions` | POST | Streaming-Session starten | ROLE_USER |
| `/api/quota/usage` | GET | Token-Verbrauch | ROLE_USER |
| `/api/dashboard` | GET | Dashboard-Daten | ROLE_USER |
| `/api/subagents` | GET/POST | Sub-Agenten verwalten | ROLE_USER |

---

## Playwright-User-Journeys

| Journey | Datei | Beschreibung |
|---------|-------|--------------|
| 1 | `journey-01-register-login.spec.ts` | Registrierung, Login, falsche Credentials, anonyme Zugriff |
| 2 | `journey-02-onboarding.spec.ts` | Onboarding-Flow starten, Fragen beantworten, Reset |
| 3 | `journey-03-agent-chat-hitl.spec.ts` | Agent-Chat, HITL-Container, Approve/Reject, API-Dialog |
| 4 | `journey-04-dashboard-navigation.spec.ts` | Alle Sidebar-Seiten durchklicken, HTTP 200 pruefen |
| 5 | `journey-05-document-upload.spec.ts` | Dokument hochladen, Liste, API, loeschen |
| 6 | `journey-06-streaming-session.spec.ts` | Streaming-Session erstellen, ueberwachen |
| 7 | `journey-07-settings-secrets.spec.ts` | Profil, Quota, Secrets anlegen |
| 8 | `journey-08-password-reset.spec.ts` | Passwort vergessen, Token-Flow |

### Ausfuehrung

```bash
# Abhaengigkeiten installieren
npm install

# Playwright-Browser installieren
npx playwright install chromium

# Alle Journeys ausfuehren
npm run e2e:playwright

# Mit sichtbarem Browser
npm run e2e:playwright:headed

# Report anzeigen
npm run e2e:playwright:report
```

### Umgebungsvariablen

| Variable | Default | Beschreibung |
|----------|---------|--------------|
| `EVIE_BASE_URL` | `http://localhost:8000` | EVIE-Instanz-URL |
| `EVIE_TEST_EMAIL` | `test@beispiel.de` | Test-User E-Mail |
| `EVIE_TEST_PASSWORD` | `TestPass123!` | Test-User Passwort |

### Screenshot-Richtlinie

Jede Journey nimmt an folgenden Punkten Screenshots auf:
1. Seitenaufruf (initialer Zustand)
2. Formular ausgefuellt (pre-submit)
3. Nach Submit (Ergebnis oder Redirect)
4. Bei HITL-Interaktion (Freigabe-Dialog)
5. Bei Fehlern (Fehlermeldungen)

Screenshots werden gespeichert unter:
`tests/Playwright/screenshots/{journey-name}/{step}.png`
