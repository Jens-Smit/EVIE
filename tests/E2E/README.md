# E2E Tests — Authentifizierungs- & Navigations-Flows

Diese Test-Suite prüft die grundlegenden Authentifizierungs-Funktionen von EVIE
sowie die vollständige Seiten-Abdeckung der Sidebar/Navigation
End-to-End über den Symfony Kernel (HTTP-Client).

## Abdeckung — Authentifizierung (`AuthFlowTest`)

| Test | Beschreibung |
|------|--------------|
| `testRegisterCreatesUserAndRedirectsToLogin` | Registrierung legt User an, hasht Passwort, leitet zum Login |
| `testRegisterRejectsDuplicateEmail` | Doppelte E-Mail wird abgelehnt |
| `testLoginWithValidCredentials` | Login mit korrekten Daten authentifiziert den User |
| `testLoginWithInvalidCredentialsFails` | Falsches Passwort leitet zurück zum Login |
| `testProtectedRouteRedirectsAnonymousUserToLogin` | Anonymer Zugriff wird abgewiesen |
| `testLogoutClearsSession` | Logout meldet den User ab |
| `testNavigationToProfileWhenLoggedIn` | Profilseite ist für angemeldete User erreichbar |
| `testChangePasswordUpdatesHash` | Passwort-Änderung aktualisiert den Hash |
| `testChangePasswordRejectsWrongCurrentPassword` | Falsches aktuelles Passwort wird abgelehnt |
| `testForgotPasswordRequestShowsGenericMessage` | Reset-Anfrage zeigt neutrale Meldung |
| `testForgotPasswordForUnknownEmailShowsSameMessage` | Unbekannte E-Mail zeigt gleiche Meldung (keine User-Enumeration) |
| `testResetPasswordWithValidTokenChangesPassword` | Gültiges Token setzt neues Passwort |
| `testResetPasswordWithInvalidTokenRedirectsToForgot` | Ungültiges Token leitet zum Reset-Formular |

## Abdeckung — Sidebar/Navigation (`NavigationPagesTest`)

Jeder in der Sidebar (`templates/components/_sidebar.html.twig`) sichtbare
Navigations-Eintrag wird einmal als authentifizierter Benutzer aufgerufen und
auf erfolgreiche Auslieferung (HTTP 200) sowie den erwarteten Seiteninhalt
geprüft. Zusätzlich wird sichergestellt, dass die Sidebar selbst fehlerfrei
rendert (früher schlug dies fehl, weil die Sidebar die nicht existierende Route
`app_tools` referenzierte).

| Test | Seite / Route | Beschreibung |
|------|---------------|---------------|
| `testDashboardPageLoadsAndShowsSidebar` | `/dashboard` (`app_dashboard`) | Dashboard lädt, H1 + Sidebar vorhanden |
| `testAgentDialogPageLoads` | `/dialog` (`frontend_agent_dialog`) | Agent-Chat lädt, H1 + Sidebar vorhanden |
| `testSubAgentsPageLoads` | `/subagents/list` (`app_subagents_list`) | Sub-Agenten-Übersicht lädt |
| `testToolApprovalsPageLoads` | `/tools/pending` (`app_tool_pending_list`) | Freigaben-Seite lädt |
| `testDocumentsPageLoads` | `/documents` (`app_documents`) | Dokumente-Seite lädt |
| `testToolsListPageLoads` | `/tools/list` (`app_tools_list`) | Fähigkeiten-Seite lädt |
| `testHistoryPageLoads` | `/history` (`frontend_agent_history`) | Verlauf-Seite lädt |
| `testProfilePageLoads` | `/profile` (`app_profile`) | Profilseite lädt, zeigt User-E-Mail |
| `testEverySidebarLinkPointsToALoadablePage` | alle obigen | Jeder Sidebar-Link führt zu HTTP 200 |
| `testAnonymousAccessToSidebarPagesRedirectsToLogin` | alle obigen | Anonymer Zugriff wird abgewiesen (302/401) |

### Erweiterte Frontend-Seiten-Abdeckung

Zusätzlich zu den Sidebar-Links werden alle weiteren über das Frontend
erreichbaren Vollseiten geprüft (Home, Briefing, Entscheidungen, ältere
Sub-Agenten-Seite, MCP-Server-Verwaltung). Die Passwort-zurücksetzen-Anfrage
ist bewusst ausgenommen.

| Test | Seite / Route | Beschreibung |
|------|---------------|---------------|
| `testHomePageLoads` | `/` (`app_home`) | Startseite lädt, Willkommenstext |
| `testBriefingPageLoads` | `/briefing` (`app_briefing`) | Unternehmens-Dashboard lädt |
| `testDecisionsPageLoads` | `/decisions` (`app_decisions`) | Entscheidungs-Dashboard lädt |
| `testSubAgentsIndexPageLoads` | `/subagents` (`app_subagents`) | Ältere Sub-Agenten-Seite lädt |
| `testMcpServersListPageLoadsForAdmin` | `/mcp/servers` (`mcp_servers_list`) | MCP-Server-Seite lädt für Admin |
| `testMcpServersPageDeniedForRegularUser` | `/mcp/servers` | Regulärer User wird abgewiesen (302/403) |
| `testSubAgentsListShowsToolAssignmentToggleAndForm` | `/subagents/list` | Tools-Toggle-Button + Zuweisungsformular-Markup vorhanden |
| `testSubAgentToolAssignmentEndpointAcceptsPost` | `/subagents/{name}/assign-tools` | POST-Endpunkt der Tool-Zuweisung funktioniert |
| `testEveryFrontendPageLinkPointsToALoadablePage` | Home, Briefing, Decisions, Subagents | Alle Frontend-Seiten liefern 200 |
| `testEveryFrontendPageLinkRedirectsAnonymousToLogin` | alle obigen + MCP | Anonymer Zugriff wird abgewiesen |

## Warum kein Panther?

Die Auth- und Navigations-Flows verwenden kein JavaScript, daher ist der Symfony
Kernel-HTTP-Client (`WebTestCase`) die stabilere, schnellere und CI-freundlichere
Wahl:

- Kein Chrome/Chromium-Binary nötig
- Kein separater Webserver-Prozess
- Echte Form-Submits, CSRF-Tokens und Sessions gegen den vollen Symfony-Stack
- Deterministisch und schnell

## Ausführung

```bash
# Nur E2E-Tests
APP_ENV=test php vendor/bin/phpunit --testsuite="E2E Tests"

# Nur Navigations-Seiten-Tests
APP_ENV=test php vendor/bin/phpunit --testsuite="E2E Tests" --filter NavigationPagesTest

# Alle Tests
APP_ENV=test php vendor/bin/phpunit
```

## Setup-Hinweise

- Die Tests verwenden SQLite (`var/test.db`) im Test-Env.
- `APP_SECRET`, `DATABASE_URL`, `MAILER_DSN` und `MAILER_FROM` sind in
  `phpunit.xml.dist` vorkonfiguriert.
- Die AI-Bundle-Konfiguration wird im Test-Env überschrieben
  (`config/packages/test/ai.yaml`), um eine zirkuläre Service-Abhängigkeit
  (tool_generator ↔ ToolDefinitionGenerator) aufzulösen.
- Ein Stub `NullMercureHub` (`tests/Stub/NullMercureHub.php`) verhindert, dass
  Tests einen echten Mercure-Hub benötigen.
