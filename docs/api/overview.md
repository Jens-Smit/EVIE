# API-Übersicht

## Frontend-Routen

| Route | Methode | Beschreibung | Auth |
|-------|---------|-------------|------|
| `/` | GET | Home/Dashboard | ROLE_USER |
| `/dashboard` | GET | Dashboard | ROLE_USER |
| `/dialog` | GET | Agent-Chat | ROLE_USER |
| `/subagents/list` | GET | Sub-Agenten-Liste | ROLE_USER |
| `/tools/pending` | GET | Ausstehende Tools (HITL) | ROLE_USER |
| `/tools/list` | GET | Alle verfügbaren Tools | ROLE_USER |
| `/documents` | GET | Dokumente | ROLE_USER |
| `/history` | GET | Agent-Verlauf | ROLE_USER |
| `/profile` | GET | Profil | ROLE_USER |
| `/login` | GET/POST | Login | PUBLIC |
| `/register` | GET/POST | Registrierung | PUBLIC |

## Tool-API

| Route | Methode | Beschreibung | Auth |
|-------|---------|-------------|------|
| `/api/tools/{id}/approve` | POST | Tool freigeben (HITL) | ROLE_ADMIN |
| `/api/tools/{id}/reject` | POST | Tool ablehnen | ROLE_ADMIN |
| `/api/tools/{id}/status` | GET | Tool-Status | ROLE_ADMIN |
| `/api/tools/approved` | GET | Genehmigte Tools | ROLE_ADMIN |
| `/tools/{id}/show` | GET | Tool-Details | ROLE_USER |
| `/tools/{id}/{action}` | POST | Approve/Reject (HTML) | ROLE_USER |

## Agent-API

| Route | Methode | Beschreibung | Auth |
|-------|---------|-------------|------|
| `/api/agent/dialog` | POST | Nachricht an Agent | ROLE_USER |
| `/api/agent/history/{userIdentifier}` | GET | Dialogverlauf | ROLE_USER |

## HTMX-Endpunkte

| Route | Methode | Beschreibung |
|-------|---------|-------------|
| `/htmx/tools/execute` | POST | Tool ausführen |
| `/htmx/subagents/delegate` | POST | Sub-Agent delegieren |
| `/htmx/mcp/tools/execute` | POST | MCP-Tool ausführen |
| `/htmx/mcp/servers/list` | GET | MCP-Server-Liste |
