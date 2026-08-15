# Setup & Testing

## Voraussetzungen

- PHP 8.2+
- Composer
- PostgreSQL 15+ mit pgvector
- Node.js (für MCP-Server)
- Docker & Docker Compose (optional)

## Installation

```bash
git clone https://github.com/Jens-Smit/EVIE.git
cd EVIE
composer install
cp .env.example .env
# .env bearbeiten: DATABASE_URL, MISTRAL_API_KEY
docker compose up -d
php bin/console doctrine:migrations:migrate
symfony serve
```

## Konfiguration

| Variable | erforderlich | Beschreibung |
|----------|-------------|--------------|
| `DATABASE_URL` | ✅ | PostgreSQL mit pgvector |
| `MISTRAL_API_KEY` | ✅ | Mistral LLM API-Key |
| `GEMINI_API_KEY` | optional | Alternative Platform |
| `MESSENGER_TRANSPORT_DSN` | optional | Messenger-Transport |
| `TAVILY_API_KEY` | optional | Tavily Web-Search-Tool |
| `LINKEDIN_API_TOKEN` | optional | LinkedIn-Integration |

## Testing

```bash
# Alle Tests
vendor/bin/phpunit

# E2E (Auth, Navigation, Evolution-Flow)
vendor/bin/phpunit --testsuite="E2E Tests"

# Unit (DynamicToolbox, HitlListener, SecurityGuard, ContextInjector, …)
vendor/bin/phpunit --testsuite="EVIE AI Unit Tests"

# Security (SSRF, Filesystem, Command, Prompt-Injection)
vendor/bin/phpunit --testsuite="EVIE AI Security Tests"

# Integration (Evolution-Flow, Streaming)
vendor/bin/phpunit --testsuite="EVIE AI Integration Tests"

# Statische Analyse
vendor/bin/phpstan analyse src --level=5 --no-progress

# Composer-Validierung
composer validate
composer audit
```

## Test-Umgebungen

Die E2E-Tests laufen gegen drei Umgebungen (test/dev/prod) mit isolierter
SQLite-In-Memory-DB und `E2E_TESTING`-Overlay — keine echten externen Services
erforderlich.
