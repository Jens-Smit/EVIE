# Deployment

## Docker

### Development

```bash
docker compose up -d
# PostgreSQL (pgvector) + MCP-Server (filesystem, playwright, github)
```

### Production

```bash
docker build -f docker/php/Dockerfile.prod -t evie-app:latest .
```

Das `Dockerfile.prod` bietet:
- `composer install --no-dev` (keine Dev-Abhängigkeiten)
- `composer dump-autoload --optimize --classmap-authoritative`
- `cache:warmup --env=prod`
- OPcache (`validate_timestamps=0`, preload)
- Healthcheck (`php bin/console about`)
- `www-data`-User (non-root)

### Noch offen

- nginx-Reverse-Proxy-Config
- PHP-FPM Worker-Tuning
- Messenger-Worker-Container
- Redis (Session/Messenger-Cache)
- GHCR Image Publishing
- Graceful Shutdown

## Konfiguration

| Variable | Prod-Wert | Hinweis |
|----------|-----------|---------|
| `APP_ENV` | `prod` | |
| `APP_DEBUG` | `0` | |
| `APP_SECRET` | (generieren) | |
| `DATABASE_URL` | `postgresql://...` | mit pgvector |
| `MISTRAL_API_KEY` | (echter Key) | |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default` | oder Redis |
