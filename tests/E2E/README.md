# E2E Tests — Authentifizierungs-Flows

Diese Test-Suite prüft die grundlegenden Authentifizierungs-Funktionen von EVIE
End-to-End über den Symfony Kernel (HTTP-Client).

## Abdeckung

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

## Warum kein Panther?

Die Auth-Flows verwenden kein JavaScript, daher ist der Symfony Kernel-HTTP-Client
(`WebTestCase`) die stabilere, schnellere und CI-freundlichere Wahl:

- Kein Chrome/Chromium-Binary nötig
- Kein separater Webserver-Prozess
- Echte Form-Submits, CSRF-Tokens und Sessions gegen den vollen Symfony-Stack
- Deterministisch und schnell (~10 s für alle 13 Tests)

## Ausführung

```bash
# Nur E2E-Tests
APP_ENV=test php vendor/bin/phpunit --testsuite="E2E Tests"

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
