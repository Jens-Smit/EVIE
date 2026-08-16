# Production Deployment

> **Stand:** August 2026 — alle P0/P1-Blocker aus dem Audit sind geschlossen,
> die 5-Phasen-Roadmap (Security/Performance/CI-CD) ist umgesetzt. Siehe
> `docs/temp/roadmap-progress.md` fuer den detaillierten Fortschritt.

## Production-Checkliste

### Anwendung
- [x] `APP_ENV=prod`, `APP_DEBUG=0` (Stack-Traces werden nicht an Clients ausgeliefert)
- [x] `APP_SECRET` gesetzt (nicht Default)
- [x] `MISTRAL_API_KEY` gesetzt
- [x] `DATABASE_URL` auf Production-PostgreSQL mit pgvector
- [x] `composer install --no-dev`
- [x] `cache:warmup --env=prod`
- [x] OPcache aktiviert (`validate_timestamps=0`, `Dockerfile.prod`)

### Docker
- [x] `Dockerfile.prod` vorhanden (Multi-Stage, non-root, Healthcheck)
- [x] `docker-compose.prod.yml` mit App-, Worker-, Nginx-, Postgres- und
      Redis-Container, Resource-Limits fuer alle Services
- [x] Prod-Nginx-Config (`docker/nginx/prod.conf`): hardened, HTTP/2,
      Upstream-Keepalive zum PHP-FPM (Connection Pooling), Gzip
- [x] PHP-FPM Worker-Tuning (OPcache im `Dockerfile.prod`)
- [x] Messenger-Worker-Container (`messenger:consume`, separater Container)
- [x] Redis (Cache/Rate-Limiter/Messenger-Transport, maxmemory+LRU)
- [ ] GHCR Image Publishing (manuell/Prozess)

### Sicherheit
- [x] HTTPS (externer Load Balancer/Traefik, HSTS via SecurityHeadersListener)
- [x] `access_control` fuer `/api/tools/*` (ROLE_ADMIN)
- [x] SecurityGuard (SSRF/Filesystem/Command/IP-Normalisierung)
- [x] SSRF-Abwehr mit DNS-Aufloesung + TOCTOU-Schutz (`resolveAllowedIp`)
- [x] IPv6-Normalisierung (komprimierte/IPv4-mapped Formen via inet_pton)
- [x] HITL fuer sicherheitskritische Tools
- [x] Audit-Logging mit Secret-Redaction (`AuditLogger::redact()`)
- [x] Rate-Limiting (`DialogRateLimiter`, `ToolCallLimitProcessor`)
- [x] Security Headers (`SecurityHeadersListener`: nosniff, DENY, no-referrer,
      Permissions-Policy, HSTS)
- [x] Session-Fixation-Schutz (`session_fixation_strategy: migrate` Default +
      `cookie_httponly: true`)
- [x] CSRF-Schutz (Framework-Default aktiviert, `CsrfTokenBadge` in Login)
- [x] Password-Reset-Token single-use (`ResetPasswordTokenGenerator::consume()`)

### Performance
- [x] Vektor-Suche: Kandidatenmenge per SQL filtern (Postgres JSON-Filter)
- [x] Embedding-Cache (`VectorStore::findCachedByContentHash`, cache.app)
- [x] Connection Pooling Nginx<->PHP-FPM (Upstream keepalive 32)
- [x] Persistente DB-Verbindungen (Doctrine `options.persistent: true`)
- [x] HTTP/2 (Nginx `http2 on`)
- [x] Compression (Gzip mit erweiterten Settings)
- [x] OPcache in Produktion (preload, validate_timestamps=0)

### Observability
- [x] Request-ID/Trace-ID (`ObservabilityListener`)
- [x] JSON-Logs (Monolog prod-Handler)
- [x] Audit-Logging fuer Policy-Entscheidungen, Tool-Execution, HITL
- [ ] LLM-Latency/Token-Usage-Metriken
- [ ] Externes Log-Aggregation (ELK/Loki)

### CI/CD
- [x] `composer validate --strict` + `composer audit` (mit Dev + `--no-dev`)
- [x] PHPStan (level=5, mit Baseline)
- [x] Alle Test-Suiten (E2E/Unit/Security/Skills/Agent/Integration/Smoke)
- [x] Postgres-Schema-Validierung gegen echte pgvector-Instanz
- [x] Coverage-Reporting (pcov + Clover-Report als Artefakt)
- [x] Security-Scan fuer Prod-Dependencies (`composer audit --no-dev`)
- [ ] Docker Build in CI
- [ ] Docker Smoke Test

## Deployment mit docker-compose.prod.yml

```bash
# Benoetigte Secrets setzen
export APP_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
export MISTRAL_API_KEY="..."
export POSTGRES_PASSWORD="..."

# Production-Stack starten
docker compose -f docker-compose.prod.yml up -d

# Status pruefen
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs -f app
```

### Services

| Service   | Image              | Rolle                              | Resource-Limit |
|-----------|--------------------|------------------------------------|----------------|
| app       | `Dockerfile.prod`  | PHP-FPM (Web-Requests)             | 2 CPU, 1G RAM  |
| worker    | `Dockerfile.prod`  | Messenger-Consumer (async)         | 1 CPU, 512M    |
| nginx     | `nginx:alpine`     | Reverse Proxy (HTTP/2, Gzip, HSTS) | 0.5 CPU, 128M  |
| postgres  | `pgvector/pgvector:pg15` | DB + Vektor-Store             | 2 CPU, 1G RAM  |
| redis     | `redis:7-alpine`   | Cache/Rate-Limiter/Messenger       | 0.5 CPU, 256M  |

### Empfehlung: PgBouncer fuer Connection Pooling

Bei mehreren Worker-Prozessen empfiehlt sich PgBouncer als Sidecar fuer
echtes Multi-Process-Connection-Pooling:

```yaml
# Ergaenzung zu docker-compose.prod.yml
pgbouncer:
  image: edoburu/pgbouncer:latest
  environment:
    - DB_USER=evie
    - DB_PASSWORD=${POSTGRES_PASSWORD}
    - DB_HOST=postgres
    - DB_NAME=evie
    - POOL_MODE=transaction
    - MAX_CLIENT_CONN=100
  depends_on:
    postgres:
      condition: service_healthy
  networks: [evie_prod]
```

Danach `DATABASE_URL` auf `postgresql://evie:...@pgbouncer:5432/evie` setzen.
