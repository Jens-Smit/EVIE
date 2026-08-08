# EVIE - AI Agent System

EVIE ist ein **AI-Agenten-System**, das auf Symfony und dem Symfony AI Bundle basiert. Es ermöglicht die Interaktion mit einem KI-Agenten, speichert Dialogverläufe in einer Datenbank und unterstützt Tools mit Human-in-the-Loop (HITL) Freigabe.

---

## 🚀 Schnellstart

### 1. Voraussetzungen
- PHP 8.2+
- Composer
- PostgreSQL (oder eine andere von Doctrine unterstützte Datenbank)
- Node.js (für MCP-Server)
- Docker & Docker Compose (optional, für lokale Entwicklung)

---

### 2. Installation

#### Klone das Repository:
```bash
git clone https://github.com/Jens-Smit/EVIE.git
cd EVIE
```

#### Installiere die Abhängigkeiten:
```bash
composer install
```

#### Konfiguriere die Umgebung:
1. Kopiere `.env.example` zu `.env`:
   ```bash
   cp .env.example .env
   ```
2. Bearbeite `.env` und setze deine **Datenbankverbindung** und **API-Schlüssel**:
   ```ini
   DATABASE_URL="postgresql://evie:evie_password@evie-db:5432/evie?serverVersion=15&charset=utf8"
   MISTRAL_API_KEY="your_mistral_api_key_here"
   ```

#### Starte die Docker-Container (PostgreSQL & MCP-Server):
```bash
docker-compose up -d
```

#### Führe die Datenbank-Migrationen aus:
```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

#### Starte den Symfony-Entwicklungsserver:
```bash
symfony serve
```

Die Anwendung ist jetzt unter **`http://localhost:8000`** erreichbar.

---

## 📂 Projektstruktur

| Verzeichnis | Beschreibung |
|-------------|--------------|
| `src/Controller/` | Controller für API- und Frontend-Routen |
| `src/Entity/` | Doctrine-Entitäten (z. B. `AgentHistory`, `UserProfile`, `ToolDefinition`) |
| `src/Repository/` | Doctrine-Repositories für Datenbankzugriffe |
| `src/AI/` | KI-spezifische Logik (Agenten, Tools, Security) |
| `templates/` | Twig-Templates für das Frontend |
| `assets/` | JavaScript und CSS |
| `config/` | Symfony-Konfiguration |
| `docker-compose.yml` | Docker-Konfiguration für PostgreSQL und MCP-Server |

---

## 🔌 API-Endpunkte

| Endpunkt | Methode | Beschreibung |
|----------|---------|--------------|
| `/api/agent/dialog` | POST | Sendet eine Nachricht an den Agenten und speichert sie in der DB |
| `/api/agent/history/{userIdentifier}` | GET | Gibt den Dialogverlauf für einen Benutzer zurück |
| `/api/tools/{toolId}/approve` | POST | Genehmigt ein Tool (HITL) |
| `/api/tools/{toolId}/reject` | POST | Lehnt ein Tool ab (HITL) |

---

## 🛠️ Wichtige Klassen

### Controller
- **`AgentDialogController`** (`src/Controller/AgentDialogController.php`)
  - Verarbeitet Dialoganfragen und speichert sie in `AgentHistory`.
  - Lädt oder erstellt ein `UserProfile` für den `user_identifier`.

### Entitäten
- **`AgentHistory`** (`src/Entity/AgentHistory.php`)
  - Speichert Dialogverläufe mit `userProfile`, `input`, `output`, `status`.
- **`UserProfile`** (`src/Entity/UserProfile.php`)
  - Enthält Benutzerinformationen wie `userIdentifier`, `userType`, `preferences`.
- **`ToolDefinition`** (`src/Entity/ToolDefinition.php`)
  - Definiert Tools mit `name`, `schema`, `status` (pending/approved/rejected).

### Repositories
- **`AgentHistoryRepository`** (`src/Repository/AgentHistoryRepository.php`)
  - Enthält `findByUserIdentifier()` zum Laden des Verlaufs eines Benutzers.
- **`UserProfileRepository`** (`src/Repository/UserProfileRepository.php`)
  - Verwaltet `UserProfile`-Einträge.

---

## 🔄 Dialogfluss

1. **Frontend** (`templates/agent/dialog.html.twig` + `assets/scripts/app.js`)
   - Sendet eine Nachricht per `fetch` an `/api/agent/dialog`.
   - Zeigt die Antwort des Agenten an.

2. **Backend** (`AgentDialogController`)
   - Empfängt die Nachricht und `user_identifier`.
   - Lädt oder erstellt ein `UserProfile`.
   - Ruft den Agenten auf und speichert die Interaktion in `AgentHistory`.

3. **Datenbank** (PostgreSQL)
   - Speichert `AgentHistory` und `UserProfile`.

---

## 🔧 Problembehebung

### ❌ Problem: Keine Daten werden in der DB gespeichert
**Ursache:**
- Der `AgentDialogController` speicherte keine `AgentHistory`-Einträge.
- `UserProfile` wurde nicht mit `AgentHistory` verknüpft.

**Lösung:**
- Der Controller speichert jetzt **automatisch** jeden Dialog in `AgentHistory`.
- `UserProfile` wird geladen oder erstellt, falls nicht vorhanden.

---

### ❌ Problem: Nachrichten werden doppelt gesendet
**Ursache:**
- Die `initChatForm()`-Funktion war **doppelt definiert** (in `app.js` und `dialog.html.twig`).

**Lösung:**
- Die doppelte Funktion in `dialog.html.twig` wurde **entfernt**.
- Eine **Request-ID** wurde hinzugefügt, um Duplikate zu verhindern.

---

## 📦 Abhängigkeiten

- **Symfony 7.x** (mit AI Bundle)
- **Doctrine ORM** (für Datenbankzugriffe)
- **PostgreSQL** (empfohlen)
- **MCP-Server** (für Tools wie Playwright, Filesystem)

---

## 🤝 Mitwirken

1. Fork das Repository.
2. Erstelle einen Feature-Branch (`git checkout -b feature/neue-funktion`).
3. Commite deine Änderungen (`git commit -am 'Füge neue Funktion hinzu'`).
4. Pushe den Branch (`git push origin feature/neue-funktion`).
5. Erstelle einen Pull Request.

---

## 📄 Lizenz

Dieses Projekt ist **privat** und gehört zu **Vision Gastro / AiCabs**.

---

## 📞 Kontakt

- **Jens Smit** – [jens-smit.de](https://jens-smit.de)
- **GitHub** – [github.com/Jens-Smit](https://github.com/Jens-Smit)
