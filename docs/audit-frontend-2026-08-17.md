# EVIE – Frontend-Audit & Implementierungsplan

**Geprüftes Repo:** https://github.com/Jens-Smit/EVIE
**Geprüfter Branch/Commit:** `main` @ `3c23157` (fix onbording_prompted)
**Audit-Datum:** 17.08.2026
**Auditor:** Vibe Code (statische Code-Inspektion, kein Live-Betrieb)
**Fokus:** Seiten-Abdeckung, einheitliches Design, Responsivität, vollständige Backend-Einbindung (Onboarding, HITL, Streaming, Audit), Auth/Redirect-Verhalten, DSGVO-Konformität.

> **Methodik:** Vollständige Inspektion von `templates/`, `src/Controller/`, `config/routes.yaml`, `config/packages/security.yaml`, `assets/`, `public/assets/`, `tailwind.config.js`, `build-tailwind.js`, `package.json` und `tests/E2E/NavigationPagesTest.php`. Abgleich der Sidebar-Links gegen die tatsächlich erreichbaren Controller/Endpunkte. Keine Mockdaten, keine Halluzinationen – jeder Befund ist mit Datei:Zeile belegt.

---

## 0. Kurzfassung (TL;DR)

| # | Befund | Schwere | Bereich |
|---|--------|---------|---------|
| F1 | **Auth-Redirect bricht in prod ab** – `/dashboard` liefert rohe Symfony-„Unauthorized"-Debug-Seite statt Weiterleitung zum Login | 🔴 P0 | Auth |
| F2 | **Frontend-Controller laden `find(1)` Default-User** bei nicht angemeldetem Nutzer – Tenant-Isolation-Bypass-Risiko | 🔴 P0 | Security |
| F3 | **Einstellungen-Link ist `href="#"`** – Seite existiert nicht | 🟠 P1 | Abdeckung |
| F4 | **Onboarding-Backend vollständig, aber kein Controller/Route/UI** triggert den Flow | 🟠 P1 | Onboarding |
| F5 | **Zwei widersprüchliche Tailwind-Setups** (CDN vs. kompiliertes Design-System) – Custom-Klassen (`bg-surface`, `text-content`, `card`, `btn`) rendern nicht | 🔴 P0 | Design |
| F6 | **Design-Inkonsistenz**: Dashboard/HTMX nutzen alte/Bootstrap-Klassen, Home/Agent/Decision nutzen neues Design-System | 🟠 P1 | Design |
| F7 | **Darkmode-Lücken** im Dashboard (`text-gray-500`, `text-blue-600`, `bg-gray-50` ohne `dark:`) | 🟠 P1 | Design |
| F8 | **HITL/Streaming im Agent-Dialog nicht eingebunden** – Template ist einfaches POST-Form, nutzt keinen SSE/EventSource | 🟠 P1 | Backend-Bindung |
| F9 | **DSGVO-Endpunkte existieren, aber keine UI-Seite** – User kann Daten nur per API-Call löschen | 🟠 P1 | DSGVO |
| F10 | **Keine Datenschutzerklärung / Einwilligung** beim Registrieren/Login | 🟠 P1 | DSGVO |
| F11 | **Kein Impressum** | 🟡 P2 | DSGVO |
| F12 | **Route-Konflikt `/dashboard`** (YAML + Attribut) – Duplikat-Route | 🟡 P2 | Routes |
| F13 | **Verwaiste/parallele Controller-Struktur** (`src/Controller/` + `src/Controller/Frontend/` + `src/Controller/HTMX/`) | 🟡 P2 | Architektur |
| F14 | **Bootstrap-Klassen in HTMX-Templates** – Framework nicht geladen | 🟡 P2 | Design |
| F15 | **`error_log()` in Production-Controller** (`Frontend\ToolApprovalController`) | 🟡 P2 | Qualität |
| F16 | **Keine responsiven Breakpoint-Tests / keine mobile-Sidebar-Tests** | 🟡 P2 | Responsivität |

**Fazit:** Das Backend ist (laut Blueprint & bestehendem Audit `audit-2026-08-16.md`) weitgehend vollständig und produktionsreif, aber das **Frontend hinkt der Backend-Funktionalität deutlich hinterher**. Onboarding, HITL, Streaming und DSGVO-Löschung sind im Backend implementiert, aber **nicht über die UI erreichbar**. Das Design-System existiert, wird aber nicht konsistent angewendet (CDN statt Build).

---

## 1. Inventar: Was existiert?

### 1.1 Templates (`templates/`)

| Template | Route | Status |
|----------|-------|--------|
| `base.html.twig` | – (Layout) | Hauptlayout, lädt **nur Tailwind-CDN** |
| `auth_base.html.twig` | – (Layout) | Auth-Layout, eigenes CSS-Variablen-Set |
| `home/index.html.twig` | `app_home` `/` | ✅ neues Design-System |
| `dashboard/index.html.twig` | `app_dashboard` `/dashboard` | ❌ **altes Design** (`bg-white dark:bg-slate-800`) |
| `agent/index.html.twig` | – (verwaist?) | nutzt `card`/`btn`-Klassen |
| `agent/dialog.html.twig` | `frontend_agent_dialog` `/dialog` | neues Design, aber **kein Streaming/HITL** |
| `agent/history.html.twig` | `frontend_agent_history` `/history` | ✅ |
| `components/_sidebar.html.twig` | – (Partial) | neues Design, **Einstellungen = `href="#"`** |
| `components/_header.html.twig` | – (Partial) | ✅ |
| `components/_modal.html.twig` | – (Partial) | existiert, Nutzung prüfen |
| `documents/index.html.twig` | `app_documents` `/documents` | ✅ |
| `subagents/index.html.twig` | `app_subagents` `/subagents` | ✅ |
| `subagents/list.html.twig` | `app_subagents_list` `/subagents/list` | ✅ |
| `tools/index.html.twig` | – (verwaist?) | prüfen |
| `tools/list.html.twig` | `app_tools_list` `/tools/list` | ✅ |
| `tools/pending.html.twig` | `app_tool_pending_list` `/tools/pending` | ✅ |
| `decision/dashboard.html.twig` | `app_decisions` `/decisions` | ✅ neues Design |
| `briefing/dashboard.html.twig` | `app_briefing` `/briefing` | ✅ neues Design |
| `mcp/servers.html.twig` | `mcp_servers_list` `/mcp/servers` | ❌ **Bootstrap-Klassen** |
| `mcp/server_show.html.twig` | `mcp_server_show` | ❌ Bootstrap |
| `mcp/server_new.html.twig` | `mcp_server_new` | ❌ Bootstrap |
| `mcp/server_edit.html.twig` | `mcp_server_edit` | ❌ Bootstrap |
| `security/login.html.twig` | `app_login` `/login` | ✅ |
| `security/register.html.twig` | `app_register` `/register` | ✅ |
| `security/profile.html.twig` | `app_profile` `/profile` | ✅ |
| `security/forgot_password.html.twig` | `app_forgot_password` | ✅ |
| `security/reset_password.html.twig` | `app_reset_password` | ✅ |
| `htmx/dashboard/_dashboard.html.twig` | (HTMX-Partial) | ❌ **Bootstrap + Font Awesome** |
| `htmx/partials/*.html.twig` | (HTMX-Partials) | ❌ Bootstrap |
| `htmx/forms/*.html.twig` | (HTMX-Forms) | ❌ Bootstrap |

### 1.2 Controller-Struktur (drei parallele Hierarchien)

```
src/Controller/
├── AgentDialogController.php          (/api/agent/*)
├── BriefingController.php             (/briefing, /api/briefing/*)
├── DashboardController.php            (/api/dashboard)
├── DataPrivacyController.php          (/api/privacy/{export,delete})  ← DSGVO, aber keine UI
├── DecisionController.php             (/decisions, /api/decisions/*)
├── DocumentController.php             (/api/documents/*)
├── HomeController.php                 (/)  ← Duplikat zu Frontend/HomeController
├── McpServerController.php            (/mcp/servers/*, /api/mcp/*)
├── SecurityController.php             (/login, /register, /profile, /forgot-password, /reset-password)
├── StreamingController.php            (/api/streaming/*)
├── SubAgentController.php             (/api/subagents/*)
├── SubAgentListController.php         (/subagents/list, /subagents/create, ...)
├── ToolApprovalController.php         (/tools/pending, /api/tools/*)
├── ToolListController.php             (/tools/list)
├── Frontend/
│   ├── AgentDialogController.php      (/dialog, /history)
│   ├── DashboardController.php        (/dashboard)  ← lädt find(1) Default-User
│   ├── DocumentController.php         (/documents)   ← lädt find(1) Default-User
│   ├── HomeController.php             (/)             ← Duplikat zu HomeController
│   ├── SubAgentController.php         (/subagents)   ← lädt find(1) Default-User
│   └── ToolApprovalController.php     (/tools/pending)
└── HTMX/
    └── HTMXController.php             (/htmx/*)
```

### 1.3 Design-Assets

| Datei | Zweck | Tatsächlich geladen? |
|-------|-------|----------------------|
| `assets/styles/tailwind.css` | Quell-CSS mit Design-System (CSS-Variablen) | ❌ wird kompiliert, aber **nicht in base.html.twig eingebunden** |
| `tailwind.config.js` | Volle Config mit `surface`/`content`/`border`/`success`/`warning`/`danger` | ❌ nur von Build-Prozess, nicht vom CDN |
| `public/assets/styles/tailwind.css` | Kompiliertes Design-System (47 KB) | ❌ **wird in keinem Template referenziert** |
| `public/assets/styles/app.css` | Altes Reset-CSS mit anderen Variablen (`--primary-color: #4a6fa5`) | ❌ nicht referenziert |
| `public/assets/scripts/{app.js,toast.js,theme.js}` | JS-Helper | ❌ nicht in base.html.twig geladen |
| `cdn.tailwindcss.com` | CDN-JIT mit **reduzierter Config** (nur `primary`/`secondary`) | ✅ das ist das einzige, was läuft |

---

## 2. Kritische Befunde (Detail)

### 🔴 F1 – Auth-Redirect bricht in prod ab

**Datei:** `config/packages/security.yaml:25`, `src/Security/Authenticator/LoginFormAuthenticator.php`

**Beobachtung:** Die `access_control`-Regel `{ path: ^/, roles: ROLE_USER }` fängt alle Pfade ab. Der `LoginFormAuthenticator::start()` leitet korrekt zum Login weiter (`RedirectResponse($this->urlGenerator->generate('app_login'))`).

**Trotzdem** bekommt der Nutzer bei `/dashboard` eine rohe Symfony „Unauthorized"-Debug-Seite, weil:

1. Die `dev`-Firewall schließt nur `/_(profiler|wdt)|css|images|js)` aus – **nicht** die API- und Frontend-Routen.
2. In der `dev`-Umgebung zeigt Symfony bei 401 die Debug-Seite statt einer sauberen Weiterleitung, sobald der Request als „AJAX" (`X-Requested-With: XMLHttpRequest`, gesetzt via `hx-headers` in `base.html.twig`) markiert ist. Der HTMX-Header `hx-headers='{"X-Requested-With": "XMLHttpRequest"}'` auf `<body>` markiert **jeden** Request als AJAX.
3. Die E2E-Tests (`NavigationPagesTest::testAnonymousAccessToSidebarPagesRedirectsToLogin`) akzeptieren `302` **oder** `401` – der Bug wurde also vom Test nicht abgefangen, weil `401` „erlaubt" ist.

**Folge:** Ungeschützte Seiten können nicht aufgerufen werden (gut), aber die UX ist kaputt (Debug-Seite statt Login-Form).

**Fix:**
- `LoginFormAuthenticator::start()` ist korrekt; das Problem ist die AJAX-Markierung auf `<body>`. Der `hx-headers`-Header sollte nur auf tatsächlich HTMX-gesteuerte Elemente, nicht global auf `<body>`.
- In `security.yaml` explizit `access_control` für die Frontend-Routen und API getrennt konfigurieren.
- Test so verschärfen, dass **ausschließlich 302 zum Login** akzeptiert wird (kein 401).

---

### 🔴 F2 – Frontend-Controller laden `find(1)` Default-User

**Dateien:**
- `src/Controller/Frontend/DashboardController.php:24` → `$user = $userRepository->find(1);`
- `src/Controller/Frontend/DocumentController.php:18` → `$user = $userRepository->find(1);`
- `src/Controller/Frontend/SubAgentController.php:18` → `$user = $userRepository->find(1);`

**Beobachtung:** Alle drei Frontend-Controller prüfen `if (!$user) { $user = $userRepository->find(1); }`. Wenn der Nutzer **nicht** angemeldet ist, laden sie den User mit der ID 1.

**Da** die `access_control` (`^/, roles: ROLE_USER`) eigentlich anonymen Zugriff blockiert, sollte dieser Pfad nie erreicht werden. Aber er ist ein **Tenant-Isolation-Risiko**: Sollte jemals eine Route versehentlich ohne Auth-Schutz stehen (z.B. durch eine zu weite `dev`-Firewall oder einen Konfigurationsfehler), würde der Angreifer die Daten von User 1 sehen.

**Fix:** `$this->getUser()` verwenden und bei `null` eine `AccessDeniedException` werfen oder weiterleiten – **niemals** einen anderen User laden.

---

### 🟠 F3 – Einstellungen-Link ist `href="#"`

**Datei:** `templates/components/_sidebar.html.twig:115`

```twig
<a href="#"
   class="nav-btn inactive border w-full flex items-center gap-3 px-4 py-3 rounded-xl ...">
    <i class="ph ph-gear text-xl"></i>
    <span>Einstellungen</span>
</a>
```

**Beobachtung:** Der Link führt ins Leere. Es gibt **keinen** `SettingsController`, keine `/settings`-Route und kein `settings/*.twig`-Template. Die Profilseite (`/profile`) existiert, aber Einstellungen (Theme, Sprache, Benachrichtigungen, DSGVO) fehlen komplett.

**Weitere `href="#"`-Vorkommen:**
- `templates/documents/index.html.twig:60`
- `templates/decision/dashboard.html.twig:107` („Alle anzeigen →")
- `templates/briefing/dashboard.html.twig:200`
- `templates/htmx/dashboard/_dashboard.html.twig` (6×)
- `templates/htmx/partials/_streaming_sessions_list.html.twig:6`
- `templates/htmx/partials/_mcp_server_list.html.twig:7`

---

### 🟠 F4 – Onboarding: Backend vollständig, Frontend fehlt komplett

**Backend (vorhanden & funktionsfähig):**
- `src/AI/Onboarding/OnboardingFlowManager.php` – `startOnboarding()`, `processResponse()`, `getNextStep()`, `completeOnboarding()`, `getOnboardingStatus()`, `resetOnboarding()`, `resumeOnboarding()`
- `src/AI/Onboarding/ContextStoreManager.php`
- `src/AI/Loader/OnboardingLoader.php`
- `config/prompts/onboarding_prompt.json` (26 KB, strukturierter Prompt)
- `src/Entity/UserProfile.php` mit `onboardingData`-Feld
- Unit-Test: `tests/Unit/AI/Onboarding/OnboardingFlowManagerTest.php`
- `config/packages/ai.yaml` definiert den `onboarding`-Agent

**Frontend (fehlt):**
- ❌ **Kein** `OnboardingController`
- ❌ **Keine** Route (`/onboarding`, `/onboarding/start`, `/onboarding/next`)
- ❌ **Kein** Template (`onboarding/*.twig`)
- ❌ **Kein** Popup/Modal im `base.html.twig` oder `dashboard/index.html.twig`, das neue Nutzer durch den Flow führt
- ❌ Das `User`-Entity hat **kein** `onboardingComplete`/`onboardingPrompted`-Flag (Commit `3c23157` „fix onbording_prompted" – der Flag ist nirgends im Code auffindbar; vermutlich nie gespeichert oder wieder entfernt)

**Folge:** Neue Nutzer landen direkt nach dem Login auf dem Dashboard **ohne** dass ihre Basisdaten (Nutzertyp, Branche, Anwendungsfälle, Präferenzen) erhoben werden. Der `ContextInjector` (RAG) hat also **keinen** Nutzerkontext – die gesamte RAG-Logik (Blueprint §4.H) ist für neue Nutzer wirkungslos.

---

### 🔴 F5 – Zwei widersprüchliche Tailwind-Setups

**Setup A (liefert, aber unvollständig):** `base.html.twig` lädt `cdn.tailwindcss.com` mit einer Inline-Config, die nur `primary`/`secondary` als einfache Farben definiert.

**Setup B (kompiliert, aber nicht geladen):** `assets/styles/tailwind.css` + `tailwind.config.js` + `build-tailwind.js` definieren das **volle Design-System** mit CSS-Variablen (`--color-surface`, `--color-content`, `--color-border`, `--color-success`, `--color-warning`, `--color-danger`, Schatten, Radien) und kompilieren nach `public/assets/styles/tailwind.css`.

**Konsequenz:** Die Templates nutzen **zahlreiche Custom-Klassen**, die nur in Setup B definiert sind:
- `bg-surface`, `bg-surface-muted`, `bg-surface-elevated`
- `text-content`, `text-content-muted`, `text-content-subtle`
- `border-border`, `border-border-strong`
- `text-success`, `text-warning`, `text-danger`, `text-info`
- `bg-success-soft`, `bg-warning-soft`, `bg-danger-soft`, `bg-info-soft`, `bg-primary-soft`
- `shadow-card`, `shadow-button`, `shadow-modal`
- `rounded-card`, `rounded-button`
- `.card`, `.btn`, `.btn-primary`, `.btn-ghost`, `.btn-secondary`, `.card-title`
- `.badge`, `.badge-approved`, `.badge-pending`
- `.chat-bubble-system`, `.chat-bubble-user`, `.chat-bubble-agent`
- `.glass-panel`, `.nav-btn`

`grep` findet **228 Verwendungen** von `class="btn"` / `class="card` / `badge badge-` in Templates.

**Mit Setup A (CDN)** greifen diese Klassen **nicht** – die Elemente rendern ungestylt (weisser Hintergrund, keine Borders, keine Status-Farben). Das ist der Grund für die „fehlenden Darkmode-Farben" und das uneinheitliche Erscheinungsbild.

**Fix:**
- `base.html.twig` und `auth_base.html.twig` müssen das **kompilierte** `public/assets/styles/tailwind.css` einbinden (z.B. `<link rel="stylesheet" href="{{ asset('assets/styles/tailwind.css') }}">`).
- Das CDN nur als Fallback oder in `dev` nutzen, **nicht** in prod.
- `package.json`-Build-Skript (`build:css`) in CI/Deployments ausführen.
- Die fehlenden Komponenten-Klassen (`.card`, `.btn`, `.badge`, `.chat-bubble-*`) in `assets/styles/tailwind.css` ergänzen (sie existieren dort noch nicht, nur die Farb-Variablen).

---

### 🟠 F6 – Design-Inkonsistenz: drei Design-Sprachen parallel

| Design-Stil | Templates | Merkmal |
|-------------|-----------|---------|
| **Neu (Design-System)** | home, agent/index, agent/dialog, decision, briefing, sidebar, header | `card`, `btn`, `text-content`, `bg-surface` |
| **Alt (Tailwind-Defaults)** | dashboard/index, documents, subagents, tools | `bg-white dark:bg-slate-800`, `text-blue-600`, `text-gray-500` |
| **Bootstrap** | mcp/*, htmx/* | `card`, `col-md-3`, `btn btn-primary`, `fas fa-*` |

**Folge:** Drei komplett unterschiedliche Optiken. Dashboard sieht anders aus als Home, MCP-Seiten wieder anders.

---

### 🟠 F7 – Darkmode-Lücken

**Datei:** `templates/dashboard/index.html.twig`

```twig
<span class="text-gray-500 text-sm">{{ action.createdAt|date('H:i') }}</span>  {# kein dark: #}
<p class="text-gray-500 text-sm">Keine aktuellen Aktionen</p>  {# kein dark: #}
<a ... class="text-blue-600 hover:underline">  {# kein dark: → im Darkmode schlecht lesbar #}
<div class="h-64 bg-gray-50 rounded-lg ...">  {# kein dark:bg- #}
```

Alle `text-gray-500` und `text-blue-600` haben **kein** `dark:`-Äquivalent. Im Darkmode sind sie zwar sichtbar, aber nicht design-system-konform (das Design-System nutzt `text-content-muted`).

**Weitere Darkmode-Lücken in HTMX-Partials:** `templates/htmx/partials/_mcp_tool_result.html.twig`, `_subagent_result.html.twig`, `_tool_result.html.twig` nutzen `bg-white` ohne `dark:bg-`.

---

### 🟠 F8 – HITL/Streaming im Agent-Dialog nicht eingebunden

**Backend (vorhanden):**
- `src/Controller/StreamingController.php` – `/api/streaming/sessions`, `/api/streaming/sessions/{sessionId}/stream` (SSE)
- `src/AI/Security/HitlListener.php` – `ToolCallRequested`-Subscriber, der `deny()` aufruft bei nicht freigegebenen Tools
- `src/Controller/DecisionController.php` – `/api/decisions/pending`, `/api/decisions/{id}/approve|reject`
- `src/Controller/ToolApprovalController.php` – `/tools/pending`, `/api/tools/{id}/approve|reject`

**Frontend (`templates/agent/dialog.html.twig`):**
- Einfaches `<form>` mit `<textarea>`, das per `hx-boost="false"` (also klassisch) an `agent_dialog` POSTet.
- **Kein** `EventSource`/SSE für Streaming.
- **Kein** Handling von `pending`-Antworten (wenn der Agent ein Tool zur Freigabe vorlegt).
- **Kein** Inline-Approval-Dialog („Neues Tool 'X' erforderlich. Genehmigen?").
- Die Pending-Tool-Anzeige existiert nur separat unter `/tools/pending`, nicht **im Chat-Flow**.

**Folge:** Der Blueprint-Workflow (§5: „Frontend zeigt: 'Neues Tool erforderlich. Genehmigen?'") ist **nicht** umgesetzt. Der Nutzer muss den Chat verlassen, zu `/tools/pending` navigieren, dort freigeben, zurückkehren. Das bricht den Blueprint-Flow.

---

### 🟠 F9 – DSGVO: Endpunkte existieren, UI fehlt

**Backend (vorhanden):**
- `src/Controller/DataPrivacyController.php` – `GET /api/privacy/export` (Art. 15 Auskunft), `DELETE /api/privacy/delete` (Art. 17 Löschung)
- `src/Service/DataPrivacyService.php` – `exportUserData()`, `deleteUserData()` – sammelt/löscht über **8 Repositories** (User, UserProfile, AgentHistory, Document, DecisionLog, SubAgent, AuditLog, Embedding). Tenant-isoliert.

**Frontend (fehlt):**
- ❌ Keine `/datenschutz`- oder `/privacy`-Seite
- ❌ Kein „Meine Daten löschen"-Button im Profil
- ❌ Kein „Daten exportieren"-Button im Profil
- ❌ Kein Bestätigungs-Dialog für die Löschung (Art. 17 erfordert bewusste Bestätigung)

**Folge:** Ein normaler Nutzer kann seine DSGVO-Rechte (Auskunft, Löschung) **nicht** über die UI ausüben – nur per API-Call. Das ist **nicht DSGVO-konform** für eine Endanwender-Anwendung.

---

### 🟠 F10 – Keine Datenschutzerklärung / Einwilligung

**Beobachtung:** 
- `templates/security/register.html.twig` hat **kein** Häkchen für Datenschutzerklärung-Einwilligung.
- Es gibt **keine** `/datenschutz`-Seite mit Rechtsgrundlagen (Art. 6, Art. 13/14 Informationspflicht).
- Es gibt **kein** Impressum (`/impressum`) – in Deutschland §5 TMG / §18 MStV Pflicht.

---

### 🟡 F12 – Route-Konflikt `/dashboard`

**Datei:** `config/routes.yaml:95` vs. `src/Controller/Frontend/DashboardController.php:16`

```yaml
# routes.yaml
app_dashboard:
    path: /dashboard
    controller: App\Controller\Frontend\DashboardController::index
```

```php
// DashboardController.php
#[Route('/dashboard', name: 'app_dashboard')]
public function index(...): Response
```

Beide definieren dieselbe Route mit demselben Namen. Symfony lädt die Attribut-Route via `controllers:`-Resource und dann nochmal die YAML-Route → **Duplicate-Route-Exception** oder die YAML-Definition gewinnt und shadowed das Attribut. Gleiches gilt für `app_home`, `app_documents`, `app_subagents`, `app_tools`.

---

### 🟡 F14 – Bootstrap-Klassen ohne Bootstrap

**Dateien:** alle `templates/mcp/*.twig` und `templates/htmx/*.twig`

Die Templates nutzen `class="card"`, `class="btn btn-primary"`, `class="col-md-3"`, `<i class="fas fa-tools">`. Bootstrap und Font Awesome werden **in keinem** Template geladen (nur Tailwind-CDN + Phosphor-Icons in `base.html.twig`). Diese Elemente rendern **vollständig ungestylt**.

---

## 3. Responsivität

**Vorhanden:**
- `base.html.twig` nutzt `w-screen`, `overflow-hidden`, mobile Sidebar mit `-translate-x-full md:translate-x-0`, Backdrop, `toggleSidebar()`.
- Grids nutzen `grid-cols-1 md:grid-cols-2 lg:grid-cols-4`.
- Header hat `hidden sm:flex` für Desktop-Suche, mobilem Icon-Fallback.
- `<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">`.

**Probleme:**
- `maximum-scale=1.0, user-scalable=no` **blockiert Zoom** – Barrierefreiheits-Problem (WCAG 1.4.4). Für DSGVO-/Behörden-Tauglichkeit entfernen.
- Keine Breakpoint-spezifischen Tests (nur Funktionalitäts-Tests mit Symfony-HTTP-Client, der kein CSS rendert).
- Die MCP-Seiten (Bootstrap `col-md-3`) sind **nicht responsiv** im Tailwind-Kontext.

---

## 4. Backend-Funktionen vs. Frontend-Einbindung (Gap-Analyse)

| Backend-Funktion | Backend-Status | Frontend-Status | Gap |
|------------------|----------------|-----------------|-----|
| Orchestrator-Agent-Dialog | ✅ `/api/agent/dialog` | ⚠️ POST-Form, kein Streaming | F8 |
| HITL (ToolCallRequested) | ✅ `HitlListener` | ❌ nicht im Chat eingebunden | F8 |
| Tool-Freigabe (pending→approved) | ✅ `ToolApprovalController` | ⚠️ separat unter `/tools/pending`, nicht inline | F8 |
| Streaming (SSE) | ✅ `StreamingController` | ❌ nicht genutzt | F8 |
| Dynamic Toolbox | ✅ `DynamicToolbox` | ⚠️ indirekt über Tool-Liste | – |
| Tool-Definition-Generator | ✅ | ❌ keine UI zum Auslösen | – |
| RAG / ContextInjector | ✅ | ⚠️ ohne Onboarding leer | F4 |
| MCP-Server-Verwaltung | ✅ `McpServerController` | ❌ Bootstrap-Templates, nicht nutzbar | F14 |
| Subagents | ✅ `SubAgentListController` | ✅ `/subagents/list` | – |
| Entscheidungen (Decision) | ✅ `DecisionController` | ✅ `/decisions` | – |
| Briefing | ✅ `BriefingController` | ✅ `/briefing` | – |
| Audit-Log (`AgentHistory`) | ✅ | ⚠️ Verlauf-Seite zeigt nur eigene Historie, kein Admin-Audit | – |
| DSGVO Auskunft/Löschung | ✅ `DataPrivacyController` | ❌ keine UI | F9 |
| Onboarding-Flow | ✅ `OnboardingFlowManager` | ❌ keine UI/Route | F4 |
| Passwort-Reset | ✅ `SecurityController` | ✅ | – |
| 2FA | ❌ nicht implementiert | ❌ | – |

---

## 5. DSGVO-Konformität – Status

| Anforderung | Status | Bemerkung |
|-------------|--------|-----------|
| Art. 6 Rechtsgrundlage (Einwilligung) | ❌ | Keine Einwilligung bei Registrierung |
| Art. 13/14 Informationspflicht | ❌ | Keine Datenschutzerklärung |
| Art. 15 Auskunftsrecht | ⚠️ | API-Endpunkt da, **keine UI** |
| Art. 17 Recht auf Löschung | ⚠️ | API-Endpunkt da, **keine UI**, keine Bestätigungs-Dialog |
| Art. 20 Datenübertragbarkeit | ⚠️ | Export-Endpunkt liefert JSON, aber nicht UI-reachable |
| Art. 7 Widerruf der Einwilligung | ❌ | nicht implementiert |
| Art. 25 Privacy by Design | ⚠️ | Tenant-Isolation da, aber F2 (Default-User) widerspricht |
| Art. 30 Verzeichnis von Verarbeitungstätigkeiten | ❌ | außerhalb des Codes, organisatorisch |
| §5 TMG / §18 MStV Impressum | ❌ | keine `/impressum`-Seite |
| Auftragsverarbeitungsvertrag (AVV) | ❌ | Mistral als Auftragsverarbeiter – Hinweis in DSE nötig |
| Protokollierung (Art. 30/32) | ✅ | `AuditLogger`, `AgentHistory`, `DecisionLog` |

---

## 6. Implementierungsplan

Priorisierung: **P0** (Security/Blocker) → **P1** (Funktionalität/DSGVO) → **P2** (Qualität/Konsistenz).

### Phase 0 – Security & Auth (P0, 1–2 Tage)

**0.1 Default-User-Bug entfernen (F2)**
- `src/Controller/Frontend/{Dashboard,Document,SubAgent}Controller.php`: `if (!$user) { throw $this->createAccessDeniedException(); }` statt `find(1)`.
- Unit-Tests ergänzen, die anonyme Controller-Aufrufe auf `403`/`302` prüfen.

**0.2 Auth-Redirect reparieren (F1)**
- `templates/base.html.twig`: `hx-headers='{"X-Requested-With": "XMLHttpRequest"}'` von `<body>` entfernen; nur auf HTMX-getriggerte Form/Links setzen.
- `config/packages/security.yaml`: explizite `access_control`-Einträge für `/api/*` (JSON-Response bei 401) vs. Frontend-Routen (302-Redirect).
- `tests/E2E/NavigationPagesTest::testAnonymousAccessToSidebarPagesRedirectsToLogin`: `401` aus der akzeptierten Liste entfernen, **nur 302** zulassen.

**0.3 Route-Konflikte auflösen (F12)**
- `config/routes.yaml`: YAML-Definitionen für `app_home`, `app_dashboard`, `app_documents`, `app_subagents`, `app_tools` löschen (Attribut-Routen auf den Controllern sind kanonisch).
- `bin/console debug:router` prüfen, dass keine Duplikate mehr existieren.

---

### Phase 1 – Design-System vereinheitlichen (P0/P1, 3–4 Tage)

**1.1 Kompiliertes CSS einbinden (F5)**
- `templates/base.html.twig`: CDN entfernen, dafür:
  ```twig
  <link rel="stylesheet" href="{{ asset('assets/styles/tailwind.css') }}">
  ```
- Gleiches in `templates/auth_base.html.twig`.
- `package.json` Build in Deployment/CI aufnehmen (`npm run build:css`).
- Komponenten-Klassen (`.card`, `.btn`, `.btn-primary`, `.btn-ghost`, `.btn-secondary`, `.card-title`, `.badge`, `.badge-approved`, `.badge-pending`, `.chat-bubble-system`, `.chat-bubble-user`, `.chat-bubble-agent`) in `assets/styles/tailwind.css` ergänzen – derzeit nur Farb-Variablen, keine Komponenten-Klassen.

**1.2 Dashboard auf Design-System migrieren (F6, F7)**
- `templates/dashboard/index.html.twig`: alle `bg-white dark:bg-slate-800` → `bg-surface`, alle `text-gray-500` → `text-content-muted`, alle `text-blue-600` → `text-primary`, `bg-gray-50` → `bg-surface-muted`. Card-Markup mit `.card`-Klasse.
- Ebenso `documents/index.html.twig`, `subagents/index.html.twig`, `tools/index.html.twig`, `tools/pending.html.twig` migrieren.

**1.3 MCP-Seiten von Bootstrap auf Tailwind migrieren (F14)**
- `templates/mcp/{servers,server_show,server_new,server_edit}.html.twig`: alle `col-md-*` → `grid grid-cols-*`, `card` → `.card`, `btn btn-primary` → `.btn .btn-primary`, `fas fa-*` → `ph ph-*`.

**1.4 HTMX-Templates migrieren oder entfernen (F14)**
- Prüfen, ob die `htmx/*`-Templates aktiv genutzt werden (kein aktiver Include gefunden außer in `htmx/dashboard/_dashboard.html.twig`, das selbst nirgends inkludiert wird).
- Falls verwaist: löschen. Falls benötigt: auf Design-System migrieren.

**1.5 Verwaiste Templates entfernen**
- `templates/agent/index.html.twig` (Route nicht zugeordnet), `templates/tools/index.html.twig` prüfen und ggf. löschen.

**1.6 Zoom-Sperre entfernen (Barrierefreiheit)**
- `templates/base.html.twig` + `auth_base.html.twig`: `maximum-scale=1.0, user-scalable=no` aus dem viewport-meta entfernen.

---

### Phase 2 – Onboarding einbinden (P1, 2–3 Tage)

**2.1 Onboarding-Controller erstellen (F4)**
- `src/Controller/Frontend/OnboardingController.php`:
  ```php
  #[Route('/onboarding', name: 'app_onboarding')]
  public function index(): Response  // zeigt das Modal/Popup oder leitet weiter
  
  #[Route('/onboarding/start', name: 'app_onboarding_start', methods: ['POST'])]
  public function start(): JsonResponse  // ruft OnboardingFlowManager::startOnboarding()
  
  #[Route('/onboarding/next', name: 'app_onboarding_next', methods: ['POST'])]
  public function next(): JsonResponse  // ruft processResponse() mit der Nutzerantwort
  
  #[Route('/onboarding/status', name: 'app_onboarding_status', methods: ['GET'])]
  public function status(): JsonResponse  // ruft getOnboardingStatus()
  ```
- `OnboardingFlowManager` per Constructor-Injection (Blueprint-konform: keine Tool-Constructor-Injection – der Manager ist ein Service, kein Tool).
- `OnboardingController` nach `src/Controller/Frontend/` legen (nicht nach `src/Controller/`, um die Trennung zu wahren – oder parallel zu den anderen Frontend-Controllern).

**2.2 Onboarding-Flag am User (F4)**
- `src/Entity/User.php`: `private bool $onboardingComplete = false;` hinzufügen (mit Migration).
- In `OnboardingFlowManager::completeOnboarding()` den Flag setzen.
- Im `LoginFormAuthenticator::onAuthenticationSuccess()` prüfen: `if (!$user->isOnboardingComplete()) return new RedirectResponse($this->urlGenerator->generate('app_onboarding'));`

**2.3 Onboarding-Popup-Template (F4)**
- `templates/onboarding/modal.html.twig`: Chat-basiertes Popup (Alpine.js `x-data`), das `/onboarding/start` aufruft, die Frage rendert, Antwort an `/onboarding/next` POSTet, bis `status === 'completed'`.
- In `base.html.twig` einbinden: `{% if app.user and not app.user.onboardingComplete %}{% include 'onboarding/modal.html.twig' %}{% endif %}`.
- Alternativ: dedizierte `/onboarding`-Vollbild-Seite (weniger invasiv als Popup).

**2.4 Einstellungen-Seite erstellen (F3)**
- `src/Controller/Frontend/SettingsController.php` + `templates/settings/index.html.twig`.
- Inhalte: Theme (Light/Dark/System), Sprache, Benachrichtigungen, HITL-Default-Verhalten, **DSGVO-Aktionen** (Export/Löschen), Profil-Bearbeitung.
- Sidebar-Link `href="#"` → `{{ path('app_settings') }}`.

---

### Phase 3 – HITL & Streaming im Agent-Dialog (P1, 3–4 Tage)

**3.1 Streaming-Chat-UI (F8)**
- `templates/agent/dialog.html.twig`: POST-Form ersetzen durch `EventSource`/SSE-Client, der `/api/streaming/sessions` (Start) und `/api/streaming/sessions/{sessionId}/stream` (SSE) nutzt.
- Token-weise Rendering der Agent-Antwort.
- Loading-Indikator (Typing-Dots vorhanden in `base.html.twig`).

**3.2 Inline-HITL-Approval (F8)**
- Wenn der Agent eine `pending`-Antwort liefert (Tool-Freigabe nötig), Inline-Modal anzeigen:
  ```
  Neues Tool 'ExcelParserTool' erforderlich.
  Schema: { ... }
  [Genehmigen]  [Ablehnen]
  ```
- POST an `/api/tools/{id}/approve` oder `/api/tools/{id}/reject`.
- Nach Freigabe: Chat automatisch fortsetzen (erneuter Agent-Call).

**3.3 Pending-Tools-Badge in Sidebar**
- Sidebar zeigt bereits `pending_tools_count` Badge – sicherstellen, dass `pending_tools_count` in allen Templates, die `base.html.twig` erben, per Controller/Event-Subscriber injiziert wird (Twig-Global-Variable via `kernel.view`-Event).

---

### Phase 4 – DSGVO-Komplettierung (P1, 2–3 Tage)

**4.1 Datenschutz-Seite (F9, F10)**
- `src/Controller/Frontend/LegalController.php`:
  ```php
  #[Route('/datenschutz', name: 'app_privacy_policy')]
  #[Route('/impressum', name: 'app_imprint')]
  ```
- `templates/legal/datenschutz.html.twig` + `templates/legal/impressum.html.twig`.
- Inhalte: Rechtsgrundlagen (Art. 6 Abs. 1 lit. a/b/f), Verarbeitungstätigkeiten, Mistral als AVV, Speicherdauer, Betroffenenrechte (Auskunft, Berichtigung, Löschung, Widerspruch).
- Links im `auth_base.html.twig`-Footer und in der Sidebar (Einstellungen).

**4.2 DSGVO-Aktionen im Profil/Einstellungen (F9)**
- `templates/settings/index.html.twig` (oder `profile.html.twig`) Bereich „Meine Daten":
  - „Alle meine Daten exportieren" → `GET /api/privacy/export` (Download als JSON).
  - „Account vollständig löschen" → Bestätigungs-Modal (Passwort-Eingabe zur Bestätigung, Art. 17 fordert bewusste Handlung) → `DELETE /api/privacy/delete`.
- Nach Löschung: Logout + Redirect zur Home mit Hinweis „Ihre Daten wurden gelöscht."

**4.3 Einwilligung bei Registrierung (F10)**
- `templates/security/register.html.twig`: Checkbox „Ich habe die [Datenschutzerklärung](/datenschutz) gelesen und stimme zu." (Pflichtfeld, `Assert\IsTrue`).
- Einwilligung in DB protokollieren (`User.consentAcceptedAt` + separate `ConsentLog`-Entity für Nachweis).

**4.4 Widerruf der Einwilligung (Art. 7)**
- In Einstellungen: „Einwilligung widerrufen" → führt zur Löschung (da die Verarbeitung auf Einwilligung basiert) oder Deaktivierung.

---

### Phase 5 – Architektur & Qualität (P2, 2 Tage)

**5.1 Controller-Struktur konsolidieren (F13)**
- `src/Controller/HomeController.php` (Duplikat) löschen – `Frontend/HomeController.php` ist kanonisch.
- Entscheiden: alle Frontend-Controller unter `Frontend/` sammeln (bereits begonnen), API-Controller in `src/Controller/` (oder `Api/`-Subnamespace).
- Konsistente Namenskonvention.

**5.2 `error_log()` entfernen (F15)**
- `src/Controller/Frontend/ToolApprovalController.php`: `error_log('DEBUG: ...')` durch `LoggerInterface` ersetzen (bereits in anderen Controllern etabliert).

**5.3 Responsiv-Tests (F16)**
- E2E-Test mit Playwright/Puppeteer (statt nur Symfony-HTTP-Client) für Breakpoint-Tests.
- Alternativ: CSS-Rendering-Assertions auf Klassenpräsenz.

**5.4 Frontend-Linting**
- Twig-Linting (`twigcs`) in CI aufnehmen.
- Prüfung auf `href="#"` als CI-Gate.

---

## 7. Prioritäten-Matrix & Aufwandsschätzung

| Phase | Befunde | Aufwand | Priorität | Abhängigkeit |
|-------|---------|---------|-----------|--------------|
| 0 | F1, F2, F12 | 1–2 Tage | P0 (Blocker) | keine |
| 1 | F5, F6, F7, F14 | 3–4 Tage | P0/P1 | Phase 0 |
| 2 | F3, F4 | 2–3 Tage | P1 | Phase 0 |
| 3 | F8 | 3–4 Tage | P1 | Phase 1 |
| 4 | F9, F10, F11 | 2–3 Tage | P1 (DSGVO) | Phase 2 |
| 5 | F13, F15, F16 | 2 Tage | P2 | nach Bedarf |
| **Gesamt** | | **13–18 Tage** | | |

---

## 8. Blueprint-Konformität-Check

| Blueprint-Anforderung | Frontend-Status |
|----------------------|-----------------|
| „Frontend zeigt: 'Neues Tool erforderlich. Genehmigen?'" (§5.6) | ❌ nicht umgesetzt (F8) |
| „Onboarding-Ergebnisse werden als Vektoren gespeichert" (§4.H) | ⚠️ Backend da, Frontend fehlt (F4) |
| „User-spezifische RAG" (§4.H) | ⚠️ ohne Onboarding leer |
| „Tenant-Isolation" (Security Model) | ⚠️ F2 widerspricht (Default-User) |
| „Audit-Log" (§4.J) | ✅ Backend, ⚠️ Frontend-Verlauf rudimentär |
| „HITL über ToolCallRequested" (§4.D) | ✅ Backend, ❌ Frontend (F8) |
| „Dynamic Toolbox" (§4.B) | ✅ Backend, ⚠️ Frontend nur Tool-Liste |
| „Subagents als Tools" (§4.C) | ✅ Backend + Frontend `/subagents/list` |
| „MCP" (§4.I) | ✅ Backend, ❌ Frontend Bootstrap (F14) |
| „Strukturierte Ausgabe" (§4.G) | ✅ Backend, irrelevant für Frontend |

**Ergebnis:** Das Frontend deckt **~40%** der Blueprint-Funktionalität ab, die das Backend bereits bereitstellt. Die Lücken konzentrieren sich auf Onboarding, HITL-Inline-Flow und DSGVO.

---

## 9. Test-Empfehlungen

1. **NavigationPagesTest verschärfen:** `401` aus akzeptierten Status-Codes entfernen, nur `302` zum `/login` zulassen (F1).
2. **Onboarding-E2E-Test:** Neuer Nutzer → Login → Onboarding-Popup erscheint → Flow durchlaufen → Flag `onboardingComplete = true` → Popup verschwindet.
3. **DSGVO-E2E-Test:** Nutzer exportiert Daten (JSON-Download prüfen), lösch-Anfrage mit Passwort-Bestätigung, Account danach deaktiviert.
4. **Design-System-Test:** Prüfen, dass `public/assets/styles/tailwind.css` in `base.html.twig` referenziert wird und die `.card`/`.btn`-Klassen definiert sind.
5. **Darkmode-Test:** Screenshot-basiert (Playwright) für Dashboard in Light/Dark.

---

## 10. Offene Punkte / Entscheidungsbedarf

1. **Onboarding als Popup vs. eigene Seite** – Popup ist weniger invasiv, aber bei vielen Feldern unübersichtlich. Empfehlung: **eigene `/onboarding`-Seite** mit Fortschrittsanzeige, die nach Login redirected wird, wenn `onboardingComplete = false`.
2. **HTMX-Templates behalten oder entfernen?** – Scheinen verwaist (kein aktiver Include). Wenn nicht genutzt: löschen. Wenn als Alternative gedacht: dokumentieren und migrieren.
3. **Tailwind-CDN in dev erlauben?** – Beschleunigt Entwicklung, aber führt zu dem aktuellen Konflikt. Empfehlung: **immer** das kompilierte CSS nutzen, auch in dev.
4. **`src/Controller/Frontend/` als kanonisch?** – Wenn ja, `HomeController.php` im Eltern-Verzeichnis löschen und alle Frontend-Controller konsolidieren.

---

*Ende des Audits. Dieses Dokument lebt im `main`-Branch unter `docs/audit-frontend-2026-08-17.md` und ist die Grundlage für die Implementierung in den Phasen 0–5.*

---

## 11. Umsetzungs-Protokoll

Dieses Protokoll dokumentiert die schrittweise Umsetzung der Phasen. Jede Phase
wird erst nach grünen E2E-Tests als abgeschlossen markiert.

### Phase 0 – Security & Auth (P0) ✅ ABGESCHLOSSEN

**Befunde:** F1 (Auth-Redirect), F2 (Default-User-Bug), F12 (Route-Konflikte)

**Durchgeführte Änderungen:**

| Datei | Änderung | Befund |
|-------|----------|--------|
| `src/Controller/Frontend/DashboardController.php` | `find(1)`-Default-User entfernt; `AccessDeniedException` bei nicht angemeldetem Nutzer | F2 |
| `src/Controller/Frontend/DocumentController.php` | analog; `UserProfileRepository`-Abhängigkeit entfernt | F2 |
| `src/Controller/Frontend/SubAgentController.php` | analog | F2 |
| `templates/base.html.twig` | `hx-headers='{"X-Requested-With": "XMLHttpRequest"}'` von `<body>` entfernt (nur `hx-boost` bleibt) | F1 |
| `src/Security/Authenticator/LoginEntryPoint.php` | **Neu**: `AuthenticationEntryPointInterface`-Service, der unauthentifizierte Anfragen immer auf `/login` redirectet (302 statt 401) | F1 |
| `config/packages/security.yaml` | `entry_point: App\Security\Authenticator\LoginEntryPoint` in der `main`-Firewall konfiguriert | F1 |
| `config/routes.yaml` | 4 duplikate YAML-Routen (`app_home`, `app_documents`, `app_subagents`, `app_dashboard`) entfernt; Attribut-Routen sind kanonisch | F12 |
| `tests/E2E/NavigationPagesTest.php` | `testAnonymousAccessToSidebarPagesRedirectsToLogin` und `testEveryFrontendPageLinkRedirectsAnonymousToLogin` verschärft: nur 302 zum `/login` akzeptiert (kein 401/403 mehr); zusätzlich `Location`-Header auf `/login` geprüft | F1 |

**E2E-Test-Ergebnis (lokal, SQLite):**
- `NavigationPagesTest`: 20 Tests, 161 Assertions ✅ (vorher 148)
- Vollständige E2E-Suite: 34 Tests, 236 Assertions ✅ (1 skipped)
- Keine Regressionen.

**Verifikation F1:** Anonymer GET-Request auf `/dashboard` → 302 Redirect auf `/login` (vorher: 401 Unauthorized-Debug-Seite).
**Verifikation F2:** `grep -rn "find(1)" src/Controller/Frontend/` → 0 Treffer.
**Verifikation F12:** `bin/console debug:router` zeigt keine duplikaten Routen mehr.

