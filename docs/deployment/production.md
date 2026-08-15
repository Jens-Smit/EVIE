# Production Deployment

## Production-Checkliste

### Anwendung

- [x] `APP_ENV=prod`, `APP_DEBUG=0`
- [x] `APP_SECRET` gesetzt (nicht Default)
- [x] `MISTRAL_API_KEY` gesetzt
- [x] `DATABASE_URL` auf Production-PostgreSQL mit pgvector
- [x] `composer install --no-dev`
- [x] `cache:warmup --env=prod`
- [x] OPcache aktiviert (`validate_timestamps=0`)

### Docker

- [x] `Dockerfile.prod` vorhanden (Multi-Stage, non-root, Healthcheck)
- [ ] nginx-Reverse-Proxy-Config
- [ ] PHP-FPM Worker-Tuning
- [ ] Messenger-Worker-Container
- [ ] Redis (Session/Messenger)
- [ ] GHCR Image Publishing

### Sicherheit

- [x] HTTPS (externer Load Balancer/Traefik)
- [x] `access_control` für `/api/tools/*` (ROLE_ADMIN)
- [x] SecurityGuard (SSRF/Filesystem/Command)
- [x] HITL für sicherheitskritische Tools
- [x] Audit-Logging
- [ ] Rate-Limiting (konfiguriert in `rate_limiter.yaml`, prüfen)

### Observability

- [x] Request-ID/Trace-ID (`ObservabilityListener`)
- [x] JSON-Logs (Monolog)
- [ ] LLM-Latency/Token-Usage-Metriken
- [ ] Externes Log-Aggregation (ELK/Loki)

### CI/CD

- [x] `composer validate` + `composer audit`
- [x] PHPStan (non-fatal bis Legacy-Warnungen behoben)
- [x] Alle Test-Suiten (E2E/Unit/Security/Skills/Agent/Integration)
- [ ] Docker Build in CI
- [ ] Docker Smoke Test
